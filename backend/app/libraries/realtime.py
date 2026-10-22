"""
파일 위치: backend/app/libraries/realtime.py
역할: 상담 실시간 메시지 서버(realtime-msg)용 구독 토큰 발급/검증과 이벤트 발행

토큰은 서명만으로 검증할 수 있게 만든다(DB 를 쓰지 않는다).
  payload(base64url) . HMAC-SHA256(payload)
서명 키는 포털만 가지며, realtime-msg 는 /api/realtime/auth 로 물어본다.
PHP 와 FastAPI 가 서로의 토큰을 검증할 수 있도록 본문은 PHP json_encode() 와 같은 바이트로 만든다(CLAUDE.md 3.11).
"""
import base64
import binascii
import hashlib
import hmac
import json
import logging
import time

import httpx

from app import config
from app.core.php import php_empty, php_int, php_json_encode
from app.core.request import site_base

logger = logging.getLogger('remotehelp')

# 토큰 유효 시간(초)
TOKEN_TTL = 7200


def _token_secret():
    return str(config.env('REALTIME_TOKEN_SECRET', '') or '')


def is_enabled():
    """서명 키가 없으면 토큰을 만들 수 없으므로 폴링으로만 동작한다."""
    return _token_secret() != ''


def _base64url(data):
    return base64.b64encode(data).decode('ascii').translate(str.maketrans('+/', '-_')).rstrip('=')


def _base64url_decode(text):
    """PHP base64_decode(strtr($text, '-_', '+/')) 대응(엄격하지 않음: 알파벳 밖 글자는 버린다)."""
    text = str(text).translate(str.maketrans('-_', '+/'))
    try:
        return base64.b64decode(text + '=' * (-len(text) % 4), validate=False)
    except (binascii.Error, ValueError):
        return b''


def _sign(body):
    return _base64url(hmac.new(_token_secret().encode('utf-8'), body.encode('utf-8'), hashlib.sha256).digest())


def issue_token(claims):
    """구독 토큰을 만든다. claims: role(visitor|agent), org_id, conversation_id."""
    payload = {
        'role': claims.get('role', ''),
        'org': php_int(claims.get('org_id', 0)),
        'conv': php_int(claims.get('conversation_id', 0)),
        'exp': int(time.time()) + TOKEN_TTL,
    }
    body = _base64url(php_json_encode(payload).encode('utf-8'))
    return body + '.' + _sign(body)


def verify_token(token):
    """토큰을 검증해 구독할 방 목록을 돌려준다. 실패하면 None."""
    token = str(token or '')
    if not is_enabled() or '.' not in token:
        return None

    body, signature = token.split('.', 1)

    if not hmac.compare_digest(_sign(body).encode('utf-8'), signature.encode('utf-8', 'surrogateescape')):
        return None

    try:
        payload = json.loads(_base64url_decode(body))
    except ValueError:
        return None

    if not isinstance(payload, dict) or php_empty(payload.get('exp')):
        return None
    try:
        if float(payload['exp']) < time.time():
            return None
    except (TypeError, ValueError):
        return None

    role = payload.get('role')
    rooms = []

    # 상담원은 조직 전체 알림을, 방문자는 자기 대화만 받는다.
    if role == 'agent' and not php_empty(payload.get('org')):
        rooms.append('org:' + str(php_int(payload['org'])))
    elif role == 'visitor' and not php_empty(payload.get('conv')):
        rooms.append('conv:' + str(php_int(payload['conv'])))

    if not rooms:
        return None

    return {
        'role': role,
        'org_id': php_int(payload.get('org')),
        'conversation_id': php_int(payload.get('conv')),
        'rooms': rooms,
    }


def ws_url():
    """브라우저가 접속할 주소. 비어 있으면 지금 접속한 호스트의 REALTIME_PUBLIC_PORT 를 쓴다.

    https 로 접속했으면(Cloudflare Tunnel) 다른 포트로 붙을 수 없으므로 포털 경유 /realtime-ws 를 쓴다(CLAUDE.md 3.12).
    """
    configured = str(config.env('REALTIME_WS_URL', '') or '')
    if configured:
        return configured

    base = site_base()
    host_port = base.split('://', 1)[1].rstrip('/')

    if base.startswith('https://'):
        return 'wss://' + host_port + '/realtime-ws'

    host = host_port.rsplit(':', 1)[0] if ':' in host_port else host_port
    return 'ws://' + host + ':' + str(config.env('REALTIME_PUBLIC_PORT', '8091')) + '/ws'


def client_config(claims):
    """화면에 넘길 접속 정보. 쓸 수 없으면 빈 dict."""
    if not is_enabled():
        return {}
    return {'url': ws_url(), 'token': issue_token(claims)}


def _publish(rooms, event):
    """서버가 없거나 실패해도 예외를 던지지 않는다. 화면은 폴링으로 따라잡는다."""
    url = str(config.env('REALTIME_PUBLISH_URL', '') or '')
    if url == '':
        return False

    payload = php_json_encode({'rooms': rooms, 'event': event}, unescaped_unicode=True)

    try:
        # 실시간 전달이 늦어도 화면 응답을 붙잡지 않는다(PHP CURLOPT_TIMEOUT 2, CONNECTTIMEOUT 1).
        response = httpx.post(
            url,
            content=payload.encode('utf-8'),
            headers={'Content-Type': 'application/json',
                     'X-Realtime-Secret': str(config.env('REALTIME_SHARED_SECRET', '') or '')},
            timeout=httpx.Timeout(2.0, connect=1.0),
        )
    except httpx.HTTPError as error:
        logger.error('실시간 발행 실패: %s', error)
        return False

    if response.status_code != 200:
        logger.error('실시간 발행 실패: HTTP %s', response.status_code)
        return False

    return True


def publish_message(org_id, conversation_id, message):
    """대화에 생긴 일을 구독자에게 알린다."""
    return _publish(
        ['org:' + str(php_int(org_id)), 'conv:' + str(php_int(conversation_id))],
        {'type': 'message', 'conversation_id': php_int(conversation_id), 'message': message})


def publish_conversation(org_id, conversation_id, data=None):
    """대화 상태가 바뀌었음을 알린다(종료, 원격 세션 연결 등)."""
    event = {'type': 'conversation', 'conversation_id': php_int(conversation_id)}
    event.update(data or {})
    return _publish(['org:' + str(php_int(org_id)), 'conv:' + str(php_int(conversation_id))], event)


def publish_org(org_id, event):
    """상담원끼리만 알면 되는 일(담당 변경, 보는 중/입력 중)을 조직 방에만 보낸다.

    방문자 방(conv:)에 보내지 않으므로 상담원 이름이 고객 화면으로 새지 않는다(CLAUDE.md 3.16).
    """
    return _publish(['org:' + str(php_int(org_id))], event)
