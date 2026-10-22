"""
파일 위치: backend/app/routers/auth.py
역할: 상담원 로그인/로그아웃

"""
from fastapi import APIRouter, Depends, Request

from app.core import session as rh_session
from app.core.errors import redirect
from app.core.i18n import supported_countries, supported_locales, supported_timezones
from app.core.input import PostData, post_data
from app.core.php import php_str
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


@router.api_route('/signup', methods=['GET', 'POST'])
def signup(request: Request, post: PostData = Depends(post_data)):
    """조직 가입 신청. 운영자 승인(status=active) 후 로그인할 수 있다."""
    if request.method == 'POST':
        error = validation.run(post.fields, [
            ('org_code', '조직 주소', ['required', 'alpha_dash', 'min_length[3]', 'max_length[32]']),
            ('org_name', '조직명', ['required', 'max_length[100]']),
            ('biz_no', '사업자등록번호', ['required', 'max_length[20]']),
            ('name', '담당자명', ['required', 'max_length[60]']),
            ('email', '이메일', ['required', 'valid_email', 'max_length[190]']),
            ('password', '비밀번호', ['required', 'min_length[10]']),
            ('password_confirm', '비밀번호 확인', ['required', 'matches[password]']),
        ])
        if error is not None:
            return api_response(False, error, None, 400)

        org_code = php_str(post.xss('org_code')).lower()
        email = post.xss('email')

        if organization_model.get_by_code(org_code):
            return api_response(False, '이미 사용 중인 조직 주소입니다.', None, 409)

        if agent_model.get_by_email(email):
            return api_response(False, '이미 가입된 이메일입니다.', None, 409)

        # 국가/언어/시간대는 허용 목록 안의 값만 받는다(CLAUDE.md 3.7).
        countries, locales, timezones = supported_countries(), supported_locales(), supported_timezones()

        country = php_str(post.xss('country'))
        locale = php_str(post.xss('locale'))
        timezone = php_str(post.xss('timezone'))

        country = country if country in countries else 'KR'
        locale = locale if locale in locales else countries[country]['locale']
        timezone = timezone if timezone in timezones else countries[country]['timezone']

        org_id = organization_model.insert({
            'org_code': org_code,
            'name': post.xss('org_name'),
            'biz_no': post.xss('biz_no'),
            'phone': post.xss('phone'),
            'country': country,
            'locale': locale,
            'timezone': timezone,
            'status': 'pending',
        })

        agent_id = agent_model.insert({
            'org_id': org_id,
            'email': email,
            'password': post.str('password'),
            'name': post.xss('name'),
            'phone': post.xss('phone'),
            'role': 'admin',
        })

        log_model.audit(org_id, agent_id, 'org_signup', org_code, client_ip(request))

        return api_response(True,
                            '가입 신청이 접수되었습니다. 운영자 승인 후 로그인할 수 있습니다. '
                            '승인 여부는 로그인 화면에서 확인하실 수 있습니다.',
                            {'redirect': base_url('login')})

    return render(request, 'auth/signup', {
        'page_title': '조직 가입 신청',
        'countries': supported_countries(),
        'locales': supported_locales(),
        'timezones': supported_timezones(),
    }, 'layouts/blank')
