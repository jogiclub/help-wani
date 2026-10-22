"""
파일 위치: backend/app/core/deps.py
역할: 로그인·권한 검사 의존성

경로 함수에서 `agent = Depends(current_agent)` 처럼 쓴다. 조직 관리자 화면은 require_admin, 운영자 화면은 require_operator.

| 권한        | 판별                     | 의존성           |
|-------------|--------------------------|------------------|
| 상담원      | 로그인 + is_active = 1   | current_agent    |
| 조직 관리자 | agents.role = 'admin'    | require_admin    |
| 플랫폼 운영자 | agents.is_super = 1    | require_operator |
"""
from fastapi import Depends, Request

from app.core import session as rh_session
from app.core.errors import Halt, redirect, show_error
from app.core.request import base_url, client_ip, is_ajax, uri_string
from app.core.response import api_response
from app.models import agent_model, log_model


def current_agent(request: Request):
    """로그인한 상담원. 로그인하지 않았으면 AJAX 는 401(+data.redirect), 화면은 로그인으로 보낸다."""
    agent_id = rh_session.userdata(request, 'agent_id')

    if not agent_id:
        if is_ajax(request):
            raise Halt(api_response(False, '로그인이 필요합니다.', {'redirect': base_url('login')}, 401))
        raise Halt(redirect('login'))

    agent = agent_model.get_by_id(agent_id)

    if agent is None or int(agent.is_active) != 1:
        rh_session.destroy(request)
        raise Halt(redirect('login'))

    return agent


def require_operator(request: Request, agent=Depends(current_agent)):
    """플랫폼 운영자만. 조직 관리자(role=admin)는 자기 조직만, 운영자(is_super=1)는 전체 조직을 다룬다."""
    if int(agent.is_super) != 1:
        log_model.audit(agent.org_id, agent.id, 'operator_access_denied', uri_string(), client_ip(request))

        if is_ajax(request):
            raise Halt(api_response(False, '플랫폼 운영자만 접근할 수 있습니다.', None, 403))
        raise Halt(show_error('플랫폼 운영자만 접근할 수 있습니다.', 403))

    return agent


def require_admin(request: Request, agent=Depends(current_agent)):
    """조직 관리자(role=admin)만 (조직 관리, 가이드 관리)."""
    if agent.role != 'admin':
        if is_ajax(request):
            raise Halt(api_response(False, '관리자만 접근할 수 있습니다.', None, 403))
        raise Halt(show_error('관리자만 접근할 수 있습니다.', 403))

    return agent
