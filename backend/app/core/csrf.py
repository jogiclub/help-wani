"""
파일 위치: backend/app/core/csrf.py
역할: CSRF 토큰 발급과 검사 (CI3 Security::csrf_verify() 대응)

- 화면은 <meta name="csrf-name"> 의 이름(csrf_test_name)으로 <meta name="csrf-hash"> 값을 보낸다(common.js csrfData).
- POST 만 검사하고, 본문의 토큰과 쿠키 값이 같아야 한다(쿠키 이중 제출). 토큰은 32자 16진수, 요청마다 바꾸지 않는다.
- 쿠키 이름은 PHP(csrf_cookie_name)와 다르게 둔다. 같은 호스트의 다른 포트는 쿠키를 함께 쓰므로 같은 이름이면 서로 덮어쓴다.
- 제외 경로는 CI3 csrf_exclude_uris 와 같다. 위젯은 대신 Origin 허용 도메인과 방문자 토큰으로 막는다(CLAUDE.md 3.9).
"""
import hmac
import re
import secrets

from fastapi import Depends, Request
from starlette.requests import cookie_parser

from app.core.errors import Halt, show_error
from app.core.input import PostData, post_data

TOKEN_NAME = 'csrf_test_name'
COOKIE_NAME = 'rh_csrf'
EXPIRE_SECONDS = 7200
EXCLUDE_URIS = ('api/launcher/.*', 'api/relay/.*', 'api/widget/.*')

_TOKEN_RE = re.compile(r'^[0-9a-f]{32}$', re.IGNORECASE)
_EXCLUDE_RES = [re.compile('^' + pattern + '$', re.IGNORECASE) for pattern in EXCLUDE_URIS]


def is_excluded(path):
    uri = path.strip('/')
    return any(pattern.match(uri) for pattern in _EXCLUDE_RES)


def _cookie_value(scope):
    for name, value in scope.get('headers', []):
        if name == b'cookie':
            found = cookie_parser(value.decode('latin-1')).get(COOKIE_NAME)
            if found is not None:
                return found
    return None


class CSRFMiddleware:
    """요청마다 토큰을 정해 scope['state']['csrf_hash'] 에 두고 쿠키를 다시 보낸다(CI3 csrf_set_cookie)."""

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope['type'] != 'http':
            await self.app(scope, receive, send)
            return

        cookie = _cookie_value(scope)
        token = cookie if cookie and _TOKEN_RE.match(cookie) else secrets.token_hex(16)
        state = scope.setdefault('state', {})
        state['csrf_hash'] = token
        state['csrf_cookie'] = cookie

        # CI3 는 제외 경로의 POST 에는 쿠키를 보내지 않는다.
        skip_cookie = scope['method'] == 'POST' and is_excluded(scope['path'])

        async def send_wrapper(message):
            if message['type'] == 'http.response.start' and not skip_cookie:
                headers = list(message.get('headers', []))
                headers.append((
                    b'set-cookie',
                    f'{COOKIE_NAME}={token}; Max-Age={EXPIRE_SECONDS}; Path=/; HttpOnly; SameSite=Strict'.encode('latin-1'),
                ))
                message['headers'] = headers
            await send(message)

        await self.app(scope, receive, send_wrapper)


def csrf_hash(request):
    """화면 <meta name="csrf-hash"> 에 넣을 값."""
    return request.state.csrf_hash


async def verify_csrf(request: Request, post: PostData = Depends(post_data)):
    """앱 전체 의존성. 제외 경로가 아닌 POST 에서 본문 토큰과 쿠키가 다르면 403(CI3 와 같은 오류 화면)."""
    if request.method != 'POST' or is_excluded(request.url.path):
        return

    # PHP 는 JSON 본문을 $_POST 로 읽지 않으므로 JSON 안의 토큰은 인정하지 않는다.
    sent = post.fields.pop(TOKEN_NAME, None) if post.is_form else None
    cookie = request.state.csrf_cookie

    valid = (isinstance(sent, str) and isinstance(cookie, str)
             and hmac.compare_digest(sent.encode('utf-8'), cookie.encode('utf-8')))

    if not valid:
        raise Halt(show_error('The action you have requested is not allowed.', 403))
