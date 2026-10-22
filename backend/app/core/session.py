"""
파일 위치: backend/app/core/session.py
역할: 상담원 로그인 세션 (CI3 Session 라이브러리 대응)

- Starlette SessionMiddleware(서명 쿠키)를 쓴다. 세션 값은 쿠키에 서명해 담으므로 DB 스키마를 바꾸지 않는다.
- PHP 세션(ci_session 파일)과 공유하지 않는다. 전환 때 상담원은 한 번 다시 로그인한다(docs/fastapi-migration-plan.md 5.2).
- 쿠키 이름은 PHP 와 다르게 둔다(같은 호스트의 다른 포트는 쿠키를 함께 씀).
- 만료는 CI3 와 같이 마지막 활동부터 7200초. Starlette 는 값이 바뀔 때만 쿠키를 다시 보내므로
  RequestContextMiddleware 가 5분(CI3 sess_time_to_update)마다 활동 시각을 적어 쿠키를 새로 받게 한다.
- 서명 키는 새 비밀값을 만들지 않고 ENCRYPTION_KEY 에서 용도를 나눠 만든다.
"""
import hashlib
import hmac

from app import config

COOKIE_NAME = 'rh_session'
EXPIRE_SECONDS = 7200
TOUCH_SECONDS = 300
TOUCH_KEY = '_touched'

# CI3 세션 값 목록(Auth::login). 옮긴 코드가 읽는 키는 이것과 같아야 한다.
#   agent_id, agent_name, agent_role, is_super, org_id, org_name, org_code, locale, timezone
# 그 밖에 guide_seen(Guide 컨트롤러)이 쓰인다.


def secret_key():
    raw = config.env('ENCRYPTION_KEY', '') or ''
    try:
        key = bytes.fromhex(raw)
    except ValueError:
        key = raw.encode('utf-8')

    if not key:
        raise RuntimeError('ENCRYPTION_KEY 가 비어 있어 세션을 서명할 수 없습니다')

    return hmac.new(key, b'remotehelp-fastapi-session', hashlib.sha256).hexdigest()


def middleware_options():
    """SessionMiddleware 인자. CI3 설정(sess_expiration 7200, SameSite Lax, cookie_secure FALSE)과 같다."""
    return {
        'secret_key': secret_key(),
        'session_cookie': COOKIE_NAME,
        'max_age': EXPIRE_SECONDS,
        'same_site': 'lax',
        'https_only': False,
    }


def login(request, data):
    """CI3 sess_regenerate(TRUE) + set_userdata() 대응. 이전 세션 값을 모두 버리고 새로 넣는다."""
    request.session.clear()
    request.session.update(data)


def destroy(request):
    """CI3 sess_destroy() 대응."""
    request.session.clear()


def userdata(request, key, default=None):
    """CI3 session->userdata() 대응."""
    return request.session.get(key, default)
