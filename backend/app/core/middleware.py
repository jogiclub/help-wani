"""
파일 위치: backend/app/core/middleware.py
역할: 요청 문맥 미들웨어 — 요청을 ContextVar 에 담고, 화면 언어·타임존을 정하고, 세션 만료를 연장한다

언어 결정은 PHP MY_Controller 생성자와 같다.
  1) 로그인했으면 세션의 조직 언어/타임존 (없으면 ko / Asia/Seoul)
  2) 방문자가 고른 rh_locale 쿠키가 지원 언어면 언어만 그것으로
고객 화면은 컨트롤러가 use_org_locale() 로 조직 설정을 다시 지정한다(CLAUDE.md 3.2).
SessionMiddleware 안쪽에 있어야 한다(main.py 에서 이것을 먼저 add_middleware 한다).
"""
import re
import time

from starlette.requests import Request

from app.core import session as rh_session
from app.core.errors import show_error
from app.core.i18n import supported_locales
from app.core.request import current_request

# CI3 config permitted_uri_chars. 경로 조각에 이 밖의 글자가 있으면 400 이다(CI3 URI::filter_uri, 경로는 디코드하지 않고 검사).
_PERMITTED_SEGMENT = re.compile(r'^[a-z 0-9~%.:_\-]+$', re.IGNORECASE)


def _normalize_path(scope):
    """CI3 처럼 경로 앞뒤의 / 를 무시하고 겹친 / 를 하나로 본다(/demo/ 와 /demo 는 같은 화면). 정적 파일은 건드리지 않는다.

    허용하지 않는 글자가 있으면 False.
    """
    path = scope.get('path', '/')
    if path.startswith('/assets/'):
        return True

    raw = scope.get('raw_path') or path.encode('utf-8')
    raw_text = raw.decode('latin-1').split('?', 1)[0]
    for segment in raw_text.split('/'):
        if segment and not _PERMITTED_SEGMENT.match(segment):
            return False

    cleaned = '/' + '/'.join(part for part in path.split('/') if part)
    if cleaned != path:
        scope['path'] = cleaned
        scope['raw_path'] = ('/' + '/'.join(part for part in raw_text.split('/') if part)).encode('latin-1')
    return True


def use_org_locale(request, org):
    """조직 설정에 맞춰 언어와 타임존을 바꾼다. (고객 화면에서 사용, MY_Controller::use_org_locale)"""
    if org:
        request.state.locale = org.get('locale') or 'ko'
        request.state.timezone = org.get('timezone') or 'Asia/Seoul'


class RequestContextMiddleware:

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope['type'] != 'http':
            await self.app(scope, receive, send)
            return

        if not _normalize_path(scope):
            await show_error('The URI you submitted has disallowed characters.', 400)(scope, receive, send)
            return

        request = Request(scope, receive)
        session = scope.get('session', {})

        locale, timezone = 'ko', 'Asia/Seoul'
        if session.get('locale'):
            locale = session['locale']
            timezone = session.get('timezone') or timezone

        chosen = request.cookies.get('rh_locale', '')
        if chosen in supported_locales():
            locale = chosen

        request.state.locale = locale
        request.state.timezone = timezone

        # 로그인 세션은 5분마다 활동 시각을 적어 쿠키(만료 7200초)를 새로 받게 한다(CI3 sess_time_to_update).
        if session and session.get('agent_id'):
            now = int(time.time())
            if now - int(session.get(rh_session.TOUCH_KEY, 0)) >= rh_session.TOUCH_SECONDS:
                session[rh_session.TOUCH_KEY] = now

        token = current_request.set(request)
        try:
            await self.app(scope, receive, send)
        finally:
            current_request.reset(token)
