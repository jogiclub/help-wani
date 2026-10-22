"""
파일 위치: backend/app/routers/operator.py
역할: 플랫폼 운영자 콘솔 — 조직 가입 승인·반려·이용 중지·다시 활성화, 조직 정보 수정, 전체 감사 로그

플랫폼 운영자(agents.is_super = 1)만 쓴다(Depends(require_operator)). 조직 소속 사용자 메뉴와 분리된 layouts/operator 로 그린다(CLAUDE.md 3.6).
CI3 는 경로 규칙과 상관없이 메서드를 받으므로 GET/POST 를 모두 받는다.
"""
import re

from fastapi import APIRouter, Depends, Request

from app.core.deps import require_operator
from app.core.errors import Halt
from app.core.i18n import supported_countries, supported_locales, supported_timezones
from app.core.input import PostData, post_data, query_data
from app.core.php import php_int, php_str
from app.core.request import client_ip
from app.core.response import api_response
from app.core.templates import render
from app.models import log_model, organization_model

router = APIRouter(prefix='/operator')

_METHODS = ['GET', 'POST']
_LAYOUT = 'layouts/operator'


def _trim(text):
    """PHP trim() 기본 글자."""
    return text.strip(' \t\n\r\x00\x0b')


def _ascii_lower(text):
    """PHP 8.2 strtolower(): ASCII 만 바꾼다."""
    return ''.join(chr(ord(ch) + 32) if 'A' <= ch <= 'Z' else ch for ch in text)


def _require_org(org_id):
    """대상 조직. 없으면 바로 404 로 끝낸다."""
    org = organization_model.get_by_id(org_id)
    if org is None:
        raise Halt(api_response(False, '조직을 찾을 수 없습니다.', None, 404))
    return org


# ------------------------------------------------------------------ 화면

@router.api_route('', methods=_METHODS)
def index(request: Request, agent=Depends(require_operator), query: PostData = Depends(query_data)):
    status = query.xss('status')
    if status not in organization_model.STATUSES:
        status = None

    # PHP 뷰(views/operator/organizations.php)가 만들던 그리드 행
    rows = []
    for org in organization_model.list_for_review(status):
        owner = org.owner
        rows.append({
            'id': int(org.id),
            'org_code': org.org_code,
            'name': org.name,
            'status': org.status,
            'biz_no': org.biz_no,
            'phone': org.phone,
            'plan': org.plan,
            'country': org.country,
            'locale': org.locale,
            'timezone': org.timezone,
            'owner_name': owner.name if owner else '',
            'owner_email': owner.email if owner else '',
            'agent_count': php_int(org.agent_count),
            'session_count': php_int(org.session_count),
            'created_at': org.created_at,
            'approved_at': org.approved_at,
            'status_reason': org.status_reason,
        })

    return render(request, 'operator/organizations', {
        'page_title': '조직 가입 관리',
        'use_grid': True,
        'countries': supported_countries(),
        'locales': supported_locales(),
        'timezones': supported_timezones(),
        'filter': status,
        'counts': organization_model.count_by_status(),
        'rows': rows,
    }, _LAYOUT)


@router.api_route('/audit', methods=_METHODS)
def audit(request: Request, agent=Depends(require_operator)):
    """전체 조직의 관리 행위 감사 로그."""
    rows = [{
        'created_at': log.created_at,
        'org_name': log.org_name or '-',
        'org_code': log.org_code or '',
        'agent_name': log.agent_name or '-',
        'agent_email': log.agent_email or '',
        'action': log.action,
        'detail': log.detail,
        'ip': log.ip,
    } for log in log_model.list_audit(1000)]

    return render(request, 'operator/audit', {
        'page_title': '감사 로그',
        'use_grid': True,
        'rows': rows,
    }, _LAYOUT)


# ------------------------------------------------------------------ API

@router.api_route('/api/org_save', methods=_METHODS)
def api_org_save(request: Request, agent=Depends(require_operator), post: PostData = Depends(post_data)):
    """조직 기본 정보 수정(운영자 전용)."""
    org = _require_org(php_int(post.get('org_id')))

    name = _trim(php_str(post.xss('name')))
    org_code = _ascii_lower(_trim(php_str(post.xss('org_code'))))
    biz_no = _trim(php_str(post.xss('biz_no')))
    phone = _trim(php_str(post.xss('phone')))
    plan = _trim(php_str(post.xss('plan')))

    if name == '':
        return api_response(False, '조직명을 입력해 주세요.', None, 400)

    if not re.match(r'^[a-z0-9_-]{3,32}$', org_code):
        return api_response(False, '조직 주소는 영문 소문자, 숫자, -, _ 조합 3~32자여야 합니다.', None, 400)

    # 주소를 바꾸면 기존 안내 링크가 끊기므로 중복 검사를 확실히 한다.
    duplicate = organization_model.get_by_code(org_code)
    if duplicate is not None and int(duplicate.id) != int(org.id):
        return api_response(False, '이미 사용 중인 조직 주소입니다.', None, 409)

    # 국가/언어/시간대는 허용 목록 안의 값만 받는다.
    country = php_str(post.xss('country'))
    locale = php_str(post.xss('locale'))
    timezone = php_str(post.xss('timezone'))
    country = country if country in supported_countries() else org.country
    locale = locale if locale in supported_locales() else org.locale
    timezone = timezone if timezone in supported_timezones() else org.timezone

    changes = []
    for field, value in (('name', name), ('org_code', org_code), ('biz_no', biz_no), ('phone', phone), ('plan', plan),
                         ('country', country), ('locale', locale), ('timezone', timezone)):
        if php_str(org[field]) != php_str(value):
            changes.append(field + ': ' + php_str(org[field]) + ' -> ' + php_str(value))

    organization_model.update(org.id, {
        'name': name,
        'org_code': org_code,
        'biz_no': biz_no,
        'phone': phone,
        'plan': 'basic' if plan == '' else plan,
        'country': country,
        'locale': locale,
        'timezone': timezone,
    })

    log_model.audit(org.id, agent.id, 'org_update_by_operator', ', '.join(changes) if changes else '변경 없음', client_ip(request))

    return api_response(True, name + ' 조직 정보를 저장했습니다.', {'changed': len(changes)})


@router.api_route('/api/org_approve', methods=_METHODS)
def api_org_approve(request: Request, agent=Depends(require_operator), post: PostData = Depends(post_data)):
    org = _require_org(php_int(post.get('org_id')))

    if org.status == 'active':
        return api_response(True, '이미 승인된 조직입니다.', {'status': 'active'})

    organization_model.approve(org.id, agent.id)
    log_model.audit(org.id, agent.id, 'org_approved', 'org=' + php_str(org.org_code), client_ip(request))

    return api_response(True, php_str(org.name) + ' 조직을 승인했습니다.', {'status': 'active'})


@router.api_route('/api/org_reject', methods=_METHODS)
def api_org_reject(request: Request, agent=Depends(require_operator), post: PostData = Depends(post_data)):
    org = _require_org(php_int(post.get('org_id')))
    reason = _trim(php_str(post.xss('reason')))

    if reason == '':
        return api_response(False, '반려 사유를 입력해 주세요.', None, 400)

    organization_model.set_status(org.id, 'rejected', agent.id, reason)
    log_model.audit(org.id, agent.id, 'org_rejected', 'org=' + php_str(org.org_code) + ' reason=' + reason, client_ip(request))

    return api_response(True, php_str(org.name) + ' 조직 신청을 반려했습니다.', {'status': 'rejected'})


@router.api_route('/api/org_suspend', methods=_METHODS)
def api_org_suspend(request: Request, agent=Depends(require_operator), post: PostData = Depends(post_data)):
    org = _require_org(php_int(post.get('org_id')))
    reason = _trim(php_str(post.xss('reason')))

    if reason == '':
        return api_response(False, '중지 사유를 입력해 주세요.', None, 400)

    if org.org_code == 'system':
        return api_response(False, '플랫폼 운영 조직은 중지할 수 없습니다.', None, 400)

    organization_model.set_status(org.id, 'suspended', agent.id, reason)
    log_model.audit(org.id, agent.id, 'org_suspended', 'org=' + php_str(org.org_code) + ' reason=' + reason, client_ip(request))

    return api_response(True, php_str(org.name) + ' 조직 이용을 중지했습니다.', {'status': 'suspended'})


@router.api_route('/api/org_restore', methods=_METHODS)
def api_org_restore(request: Request, agent=Depends(require_operator), post: PostData = Depends(post_data)):
    """반려되거나 중지된 조직을 다시 활성화한다."""
    org = _require_org(php_int(post.get('org_id')))

    organization_model.approve(org.id, agent.id)
    log_model.audit(org.id, agent.id, 'org_restored', 'org=' + php_str(org.org_code), client_ip(request))

    return api_response(True, php_str(org.name) + ' 조직을 다시 활성화했습니다.', {'status': 'active'})
