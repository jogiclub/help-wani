"""
파일 위치: backend/app/routers/api_relay.py
역할: 중계 서버(relay)가 웹소켓 접속마다 호출하는 토큰 검증 API (PHP controllers/api/Relay.php 대응)

GET /api/relay/auth?role=agent|viewer&token=...&ip=...
성공하면 { result: true, data: { session_id, org_id, role } }. CSRF 검사 제외 경로(api/relay/.*)다.
"""
import hmac
import logging

from fastapi import APIRouter, Depends, Request

from app import config
from app.core.input import PostData, query_data
from app.core.php import php_str
from app.core.request import get_request_header
from app.core.response import api_response
from app.models import log_model, session_model

router = APIRouter(prefix='/api/relay')
logger = logging.getLogger('remotehelp')


def _deny(reason):
    logger.error('relay auth denied: %s', reason)
    return api_response(False, '접속 인증에 실패했습니다.', None, 403)


@router.api_route('/auth', methods=['GET', 'POST'])
def auth(request: Request, query: PostData = Depends(query_data)):
    shared = php_str(config.env('RELAY_AUTH_SHARED_SECRET', ''))
    sent = php_str(get_request_header('X-Relay-Secret', True, request))

    if shared != '' and not hmac.compare_digest(shared.encode('utf-8'), sent.encode('utf-8')):
        return _deny('relay secret mismatch')

    role = php_str(query.get('role'))
    token = php_str(query.get('token'))
    ip = php_str(query.get('ip'))

    if role == 'agent':
        session = session_model.get_by_agent_token(token)
        event, detail, actor = 'agent_connected', '고객 PC 에이전트 접속', 'customer'
    elif role == 'viewer':
        # 뷰어 토큰은 1회용이므로 여기서 소진한다.
        session = session_model.consume_viewer_token(token, ip)
        event, detail, actor = 'viewer_connected', '상담원 뷰어 접속', 'agent'
    else:
        return _deny('unknown role: ' + role)

    if session is None:
        return _deny(f'invalid or used token (role={role})')

    if session.status not in ('verified', 'waiting', 'connected'):
        return _deny('session not connectable: ' + session.status)

    # 에이전트 접속은 대기 상태, 뷰어까지 붙으면 원격 중으로 본다.
    next_status = 'connected' if role == 'viewer' else 'waiting'

    if session.status != 'connected':
        session_model.set_status(session.id, next_status)

    session_model.touch_heartbeat(session.id)
    log_model.add(session.id, event, detail, actor, ip)

    return api_response(True, '', {'session_id': int(session.id), 'org_id': int(session.org_id), 'role': role})
