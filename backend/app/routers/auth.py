"""
파일 위치: backend/app/routers/auth.py
역할: 상담원 로그인/로그아웃 (PHP controllers/Auth.php 대응)

조직 가입 신청(signup)은 3단계에서 옮긴다.
"""
from fastapi import APIRouter, Depends, Request

from app.core import session as rh_session
from app.core.errors import redirect
from app.core.input import PostData, post_data
from app.core.request import base_url, client_ip
from app.core.response import api_response
from app.core.templates import render
from app.helpers import validation
from app.models import agent_model, log_model, organization_model

router = APIRouter()


def status_message(org):
    """조직 상태에 따른 로그인 실패 안내 문구."""
    reason = org.status_reason or ''
    if org.status == 'pending':
        return '가입 신청이 검토 중입니다. 운영자 승인 후 이용하실 수 있습니다.'
    if org.status == 'rejected':
        return '가입 신청이 반려되었습니다.' + (' 사유: ' + reason if reason else '') + ' 문의는 운영자에게 해 주세요.'
    if org.status == 'suspended':
        return '이용이 중지된 조직입니다.' + (' 사유: ' + reason if reason else '') + ' 문의는 운영자에게 해 주세요.'
    return '지금은 로그인할 수 없습니다. 운영자에게 문의해 주세요.'


@router.api_route('/login', methods=['GET', 'POST'])
def login(request: Request, post: PostData = Depends(post_data)):
    if rh_session.userdata(request, 'agent_id'):
        return redirect('console')

    if request.method == 'POST':
        error = validation.run(post.fields, [
            ('email', '이메일', ['required', 'valid_email']),
            ('password', '비밀번호', ['required']),
        ])
        if error is not None:
            return api_response(False, error, None, 400)

        # TODO(3단계 확인): PHP 는 post('email', TRUE) 로 xss_clean 을 거친다. 올바른 이메일 형식에서는 결과가 같다.
        email = post.str('email')
        password = post.str('password')

        agent = agent_model.authenticate(email, password)

        if agent is None:
            log_model.audit(None, None, 'login_failed', email, client_ip(request))
            return api_response(False, '이메일 또는 비밀번호가 올바르지 않습니다.', None, 401)

        org = organization_model.get_by_id(agent.org_id)

        if org is None:
            return api_response(False, '조직 정보를 찾을 수 없습니다.', None, 403)

        if org.status != 'active':
            return api_response(False, status_message(org), {'status': org.status}, 403)

        rh_session.login(request, {
            'agent_id': int(agent.id),
            'agent_name': agent.name,
            'agent_role': agent.role,
            'is_super': int(agent.is_super) == 1,
            'org_id': int(agent.org_id),
            'org_name': org.name,
            'org_code': org.org_code,
            'locale': org.locale or 'ko',
            'timezone': org.timezone or 'Asia/Seoul',
        })

        agent_model.touch_login(agent.id)
        log_model.audit(agent.org_id, agent.id, 'login', None, client_ip(request))

        return api_response(True, '로그인되었습니다.', {'redirect': base_url('console')})

    return render(request, 'auth/login', {'page_title': '상담원 로그인'}, 'layouts/blank')


@router.get('/logout')
def logout(request: Request):
    agent_id = rh_session.userdata(request, 'agent_id')
    org_id = rh_session.userdata(request, 'org_id')

    if agent_id:
        log_model.audit(org_id, agent_id, 'logout', None, client_ip(request))

    rh_session.destroy(request)
    return redirect('login')
