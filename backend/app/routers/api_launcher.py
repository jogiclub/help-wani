"""
파일 위치: backend/app/routers/api_launcher.py
역할: 고객 런처용 API — 코드 검증, 상태 보고, 하트비트, 버전 확인 (PHP controllers/api/Launcher.php 대응)

CSRF 검사 제외 경로(api/launcher/.*)다. CI3 는 자동 라우팅으로 메서드와 상관없이 이 주소를 받으므로 GET/POST 를 모두 받는다.
"""
import re

from fastapi import APIRouter, Depends, Request

from app import config
from app.core.input import PostData, json_input, query_data
from app.core.php import php_int, php_str
from app.core.request import base_url, client_ip
from app.core.response import api_response
from app.helpers.app import customer_url
from app.models import log_model, organization_model, session_model

router = APIRouter(prefix='/api/launcher')

_METHODS = ['GET', 'POST']


@router.api_route('/verify', methods=_METHODS)
def verify(request: Request, data: PostData = Depends(json_input)):
    """입력: code, pc_name, os_version, launcher_version"""
    ip = client_ip(request)
    code = re.sub(r'[^0-9]', '', php_str(data.get('code', '')))

    if len(code) != 6:
        return api_response(False, '6자리 숫자 코드를 입력해 주세요.', None, 400)

    if session_model.is_ip_blocked(ip):
        return api_response(False, '입력 실패가 많아 잠시 차단되었습니다. 10분 후에 다시 시도해 주세요.', None, 429)

    meta = {
        'pc_name': php_str(data.get('pc_name', ''))[:100],
        'os_version': php_str(data.get('os_version', ''))[:100],
        'launcher_version': php_str(data.get('launcher_version', ''))[:20],
    }

    verified = session_model.verify_code(code, ip, meta)

    if not verified:
        session_model.record_attempt(ip, code, False)
        return api_response(False, '코드가 올바르지 않거나 유효시간이 지났습니다.', None, 404)

    session_model.record_attempt(ip, code, True)

    session = verified['session']
    org = organization_model.get_by_id(session.org_id)

    return api_response(True, '', {
        'session_id': int(session.id),
        'relay_ws_url': config.env('RELAY_AGENT_WS_URL', 'wss://localhost:8443/agent'),
        'agent_token': verified['agent_token'],
        'org_name': org.name if org else '',
        'org_logo_url': base_url(org.logo_path) if (org and org.logo_path) else '',
        'launcher_secret': verified['launcher_secret'],
        'survey_url': customer_url(org.org_code + '/survey/' + str(session.id)) if org else '',
        'heartbeat_sec': 30,
    })


@router.api_route('/status', methods=_METHODS)
def status(request: Request, data: PostData = Depends(json_input)):
    """입력: session_id, launcher_secret, status(waiting/connected/ended), reason"""
    session_id = php_int(data.get('session_id', 0))
    secret = php_str(data.get('launcher_secret', ''))
    new_status = php_str(data.get('status', ''))
    reason = php_str(data.get('reason', ''))[:50]

    if new_status not in ('waiting', 'connected', 'ended'):
        return api_response(False, '허용되지 않는 상태값입니다.', None, 400)

    session = session_model.get_by_secret(session_id, secret)

    if session is None:
        return api_response(False, '세션 인증에 실패했습니다.', None, 401)

    if session.status in ('ended', 'expired', 'canceled'):
        return api_response(True, '이미 종료된 세션입니다.', {'status': session.status})

    extra = {}
    if new_status == 'ended':
        extra['end_reason'] = reason if reason != '' else 'customer_ended'

    session_model.set_status(session.id, new_status, extra)
    session_model.touch_heartbeat(session.id)
    log_model.add(session.id, 'launcher_' + new_status, reason, 'customer', client_ip(request))

    return api_response(True, '', {'status': new_status})


@router.api_route('/heartbeat', methods=_METHODS)
def heartbeat(query: PostData = Depends(query_data)):
    """GET ?session_id=&launcher_secret= — 상담원이 종료했으면 terminate: true 를 돌려준다."""
    session_id = php_int(query.get('session_id'))
    secret = php_str(query.get('launcher_secret'))

    session = session_model.get_by_secret(session_id, secret)

    if session is None:
        # 인증 실패도 런처는 종료해야 한다.
        return api_response(False, '세션 인증에 실패했습니다.', {'terminate': True}, 401)

    session_model.touch_heartbeat(session.id)

    return api_response(True, '', {
        'terminate': session.status in ('ended', 'expired', 'canceled'),
        'status': session.status,
        'end_reason': session.end_reason,
    })


@router.api_route('/version', methods=_METHODS)
def version():
    store_product_id = config.env('STORE_PRODUCT_ID', '')
    return api_response(True, '', {
        'min_version': config.env('LAUNCHER_MIN_VERSION', '1.0.0'),
        'store_product_id': store_product_id,
        'store_url': 'https://apps.microsoft.com/detail/' + php_str(store_product_id),
    })
