"""
파일 위치: backend/app/core/middleware.py
역할: 요청 문맥 미들웨어 — 요청을 ContextVar 에 담고, 화면 언어·타임존을 정하고, 세션 만료를 연장한다

언어 결정은 PHP MY_Controller 생성자와 같다.
  1) 로그인했으면 세션의 조직 언어/타임존 (없으면 ko / Asia/Seoul)
  2) 방문자가 고른 rh_locale 쿠키가 지원 언어면 언어만 그것으로
고객 화면은 컨트롤러가 use_org_locale() 로 조직 설정을 다시 지정한다(CLAUDE.md 3.2).
SessionMiddleware 안쪽에 있어야 한다(main.py 에서 이것을 먼저 add_middleware 한다).
"""
import time

from starlette.requests import Request

from app.core import session as rh_session
from app.core.i18n import supported_locales
from app.core.request import current_request


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
