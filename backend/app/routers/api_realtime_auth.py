"""
파일 위치: backend/app/routers/api_realtime_auth.py
역할: 실시간 메시지 서버(realtime-msg)가 구독 토큰을 검증할 때 호출하는 API

GET /api/realtime/auth?token=...&ip=...
성공하면 { result: true, data: { role, org_id, conversation_id, rooms } }.
"""
import hmac
import logging

from fastapi import APIRouter, Depends, Request

from app import config
from app.core.input import PostData, query_data
from app.core.php import php_str
from app.core.request import get_request_header
from app.core.response import api_response
from app.libraries import realtime

router = APIRouter(prefix='/api/realtime')
logger = logging.getLogger('remotehelp')


def _deny(reason):
    logger.error('realtime auth denied: %s', reason)
    return api_response(False, '접속 인증에 실패했습니다.', None, 403)


@router.api_route('/auth', methods=['GET', 'POST'])
def auth(request: Request, query: PostData = Depends(query_data)):
    shared = php_str(config.env('REALTIME_SHARED_SECRET', ''))
    sent = php_str(get_request_header('X-Realtime-Secret', True, request))

    if shared != '' and not hmac.compare_digest(shared.encode('utf-8'), sent.encode('utf-8')):
        return _deny('realtime secret mismatch')

    claims = realtime.verify_token(php_str(query.get('token')))

    if not claims:
        return _deny('invalid or expired token')

    return api_response(True, '', claims)
