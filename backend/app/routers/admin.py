"""
파일 위치: backend/app/routers/admin.py
역할: 조직 관리 — 통계, 상담원 관리, 조직 정보·로고, 상담 위젯 설정 API, 상담 운영 시간·휴일·운영 상태 API

조직 관리자만 쓴다(Depends(require_admin)). 관리 행위는 audit_logs 에 남긴다(CLAUDE.md 3.7).
상담 위젯 설정 화면은 상담 대화의 설정 드로어(console/_widget_settings)이며, 여기는 그 저장 API 만 있다.
CI3 는 경로 규칙과 상관없이 메서드를 받으므로 GET/POST 를 모두 받는다.
"""
import os
import re
import secrets

from fastapi import APIRouter, Depends, Request

from app.core.deps import require_admin
from app.core.errors import redirect
from app.core.i18n import supported_locales, supported_timezones
from app.core.input import PostData, post_data
from app.core.php import php_empty, php_int, php_json_encode, php_str, utc_str
from app.core.request import base_url, client_ip
from app.core.response import api_response
from app.core.templates import render
from app.helpers.app import check_uploaded_image
from app.helpers.validation import valid_email
from app.libraries import chat_greeting
from app.models import agent_model, chat_hours_model, chat_model, guide_model, log_model, macro_model, organization_model
from app.settings import RH_LOGO_MAX_BYTES

router = APIRouter(prefix='/admin')

_METHODS = ['GET', 'POST']


def _trim(text):
    """PHP trim() 기본 글자."""
    return text.strip(' \t\n\r\x00\x0b')


def _flag(post, key):
    """PHP $this->input->post(key) ? 1 : 0"""
    return 0 if php_empty(post.get(key)) else 1


# ------------------------------------------------------------------ 화면

@router.api_route('', methods=_METHODS)
def index(request: Request, agent=Depends(require_admin)):
    return render(request, 'admin/dashboard', {
        'page_title': '조직 관리',
        'org': organization_model.get_by_id(agent.org_id),
        'stats': organization_model.statistics(agent.org_id, 30),
        # 상담 대화(위젯) 숫자. 원격지원 숫자와 섞지 않는다(CLAUDE.md 3.10).
        'chat_stats': chat_model.reply_stats(agent.org_id, 30),
        # 매크로 사용(docs/macro-plan.md M8). 개인 매크로는 이름 없이 합계만.
        'macro_stats': macro_model.usage_stats(agent.org_id, utc_str(-30 * 86400)),
    })


@router.api_route('/agents', methods=_METHODS)
def agents(request: Request, agent=Depends(require_admin)):
    # PHP 뷰(views/admin/agents.php)가 만들던 그리드 행
    rows = [{
        'id': int(a.id),
        'name': a.name,
        'email': a.email,
        'phone': a.phone,
        'role': a.role,
        'is_active': php_int(a.is_active),
        'last_login_at': a.last_login_at,
        'created_at': a.created_at,
    } for a in agent_model.list_by_org(agent.org_id)]

    return render(request, 'admin/agents', {
        'page_title': '상담원 관리',
        'use_grid': True,
        'rows': rows,
    })


@router.api_route('/organization', methods=_METHODS)
def organization(request: Request, agent=Depends(require_admin)):
    return render(request, 'admin/organization', {
        'page_title': '조직 정보',
        'use_dropzone': True,
        'org': organization_model.get_by_id(agent.org_id),
        'locales': supported_locales(),
        'timezones': supported_timezones(),
    })


@router.api_route('/widget', methods=_METHODS)
def widget(agent=Depends(require_admin)):
    """상담 위젯 설정은 상담 대화 화면의 설정 드로어로 옮겼다. 예전 주소로 오면 드로어를 연 채로 보낸다."""
    return redirect('console/chat?settings=widget')


# ------------------------------------------------------------------ 상담원

@router.api_route('/api/agent_save', methods=_METHODS)
def api_agent_save(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    agent_id = php_int(post.get('id'))
    name = post.xss('name')
    email = post.xss('email')
    role = 'admin' if post.xss('role') == 'admin' else 'agent'
    password = php_str(post.get('password'))

    # PHP: $name === '' 만 막는다(값이 없으면 NULL 이라 통과하지만 이메일 검사에서 걸린다).
    if name == '' or not isinstance(email, str) or not valid_email(email):
        return api_response(False, '이름과 올바른 이메일을 입력해 주세요.', None, 400)

    dup = agent_model.get_by_email(email)
    if dup is not None and int(dup.id) != agent_id:
        return api_response(False, '이미 사용 중인 이메일입니다.', None, 409)

    if agent_id > 0:
        target = agent_model.get_by_id(agent_id)
        if target is None or int(target.org_id) != int(agent.org_id):
            return api_response(False, '대상을 찾을 수 없습니다.', None, 404)

        data = {'name': name, 'email': email, 'role': role, 'phone': post.xss('phone')}

        if password != '':
            if len(password.encode('utf-8')) < 10:
                return api_response(False, '비밀번호는 10자 이상이어야 합니다.', None, 400)
            data['password'] = password

        agent_model.update(agent_id, data)
        log_model.audit(agent.org_id, agent.id, 'agent_update', 'agent_id=' + str(agent_id), client_ip(request))
        return api_response(True, '상담원 정보를 저장했습니다.')

    if len(password.encode('utf-8')) < 10:
        return api_response(False, '비밀번호는 10자 이상이어야 합니다.', None, 400)

    new_id = agent_model.insert({
        'org_id': agent.org_id,
        'email': email,
        'password': password,
        'name': name,
        'phone': post.xss('phone'),
        'role': role,
        'is_verified': 1,
    })

    log_model.audit(agent.org_id, agent.id, 'agent_create', 'agent_id=' + str(new_id), client_ip(request))
    return api_response(True, '상담원을 추가했습니다.', {'id': new_id})


@router.api_route('/api/agent_toggle', methods=_METHODS)
def api_agent_toggle(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    agent_id = php_int(post.get('id'))
    target = agent_model.get_by_id(agent_id)

    if target is None or int(target.org_id) != int(agent.org_id):
        return api_response(False, '대상을 찾을 수 없습니다.', None, 404)

    if int(target.id) == int(agent.id):
        return api_response(False, '본인 계정은 비활성화할 수 없습니다.', None, 400)

    nxt = 0 if php_int(target.is_active) == 1 else 1
    agent_model.update(agent_id, {'is_active': nxt})
    log_model.audit(agent.org_id, agent.id, 'agent_toggle', f'agent_id={agent_id} active={nxt}', client_ip(request))

    return api_response(True, '계정을 활성화했습니다.' if nxt else '계정을 비활성화했습니다.', {'is_active': nxt})


# ------------------------------------------------------------------ 조직 정보

@router.api_route('/api/org_save', methods=_METHODS)
def api_org_save(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    name = _trim(php_str(post.xss('name')))
    phone = post.xss('phone')
    # 소개 문구는 화면에서 이스케이프하므로 원문을 받고 길이만 자른다(줄바꿈 유지).
    intro = _trim(php_str(post.get('intro')))[:500]

    if name == '':
        return api_response(False, '조직명을 입력해 주세요.', None, 400)

    # 언어와 시간대는 허용 목록 안의 값만 받는다.
    locale = php_str(post.xss('locale'))
    timezone = php_str(post.xss('timezone'))
    locale = locale if locale in supported_locales() else 'ko'
    timezone = timezone if timezone in supported_timezones() else 'Asia/Seoul'

    organization_model.update(agent.org_id, {
        'name': name,
        'phone': phone,
        'intro': None if intro == '' else intro,
        'locale': locale,
        'timezone': timezone,
    })

    request.session['org_name'] = name
    request.session['locale'] = locale
    request.session['timezone'] = timezone
    log_model.audit(agent.org_id, agent.id, 'org_update', None, client_ip(request))

    return api_response(True, '조직 정보를 저장했습니다.')


_LOGO_RE = re.compile(r'^assets/uploads/logos/[A-Za-z0-9_\-]+\.(png|jpg|webp)$')


def _remove_logo_file(logo_path):
    """예전 로고 파일을 지운다. 로고 폴더 밖의 경로는 건드리지 않는다."""
    if php_empty(logo_path) or not _LOGO_RE.match(php_str(logo_path)):
        return
    target = guide_model.asset_file(logo_path)
    if os.path.isfile(target):
        try:
            os.unlink(target)
        except OSError:
            pass


@router.api_route('/api/logo_upload', methods=_METHODS)
async def api_logo_upload(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """multipart(필드명 logo). SVG 는 받지 않고, 형식은 확장자가 아니라 파일 내용으로 판별한다."""
    ext, content = await check_uploaded_image(post.files.get('logo'), RH_LOGO_MAX_BYTES)

    if isinstance(ext, dict):
        return api_response(False, ext['error'], None, 400)

    org = organization_model.get_by_id(agent.org_id)
    folder = guide_model.asset_file('assets/uploads/logos/')
    # 이름을 매번 바꿔 브라우저와 런처가 예전 로고를 캐시에서 보여주지 않게 한다.
    name = php_str(org.org_code) + '-' + secrets.token_hex(6) + '.' + ext

    try:
        os.makedirs(folder, mode=0o775, exist_ok=True)
        with open(os.path.join(folder, name), 'xb') as handle:
            handle.write(content)
    except OSError:
        return api_response(False, '로고를 저장하지 못했습니다. 업로드 폴더 권한을 확인해 주세요.', None, 500)

    _remove_logo_file(org.logo_path)
    logo_path = 'assets/uploads/logos/' + name
    organization_model.update(org.id, {'logo_path': logo_path})
    log_model.audit(org.id, agent.id, 'org_logo_update', logo_path, client_ip(request))

    return api_response(True, '로고를 저장했습니다.', {'logo_url': base_url(logo_path)})


@router.api_route('/api/logo_delete', methods=_METHODS)
def api_logo_delete(request: Request, agent=Depends(require_admin)):
    org = organization_model.get_by_id(agent.org_id)

    _remove_logo_file(org.logo_path)
    organization_model.update(org.id, {'logo_path': None})
    log_model.audit(org.id, agent.id, 'org_logo_delete', None, client_ip(request))

    return api_response(True, '로고를 삭제했습니다.')


# ------------------------------------------------------------------ 상담 위젯 설정

@router.api_route('/api/widget_save', methods=_METHODS)
def api_widget_save(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    # 색상과 위치는 허용 목록 검사를 거친다(CLAUDE.md 3.7).
    color = php_str(post.xss('color'))
    color = color.lower() if re.match(r'^#[0-9a-fA-F]{6}$', color) else '#2563eb'

    position = php_str(post.xss('position'))
    position = position if position in ('right', 'left') else 'right'

    email = php_str(post.xss('offline_email'))
    email = email if valid_email(email) else None

    # 운영 시간(docs/chat-operation-plan.md F1). 형식이 틀리면 아무것도 저장하지 않는다.
    hours = chat_hours_model.clean_hours(post.get('hours'))
    if isinstance(hours, str):
        return api_response(False, hours, None, 400)

    hours_enabled = _flag(post, 'hours_enabled')
    open_days = sum(1 for ranges in hours.values() if ranges)

    if hours_enabled and open_days == 0:
        return api_response(False, '운영 시간을 쓰려면 운영하는 요일을 하루 이상 넣어 주세요.', None, 400)

    offhours = php_str(post.xss('offhours_mode'))
    offhours = offhours if offhours in ('accept', 'info', 'hide') else 'accept'

    # 답변 속도 안내(F6). 직접 지정 값은 위젯이 보여 주는 구간 중 하나만 받는다.
    reply_mode = php_str(post.xss('reply_mode'))
    reply_mode = reply_mode if reply_mode in ('off', 'manual', 'auto') else 'off'
    reply_minutes = php_int(post.get('reply_minutes'))
    reply_minutes = reply_minutes if reply_minutes in chat_model.REPLY_BUCKETS else None

    if reply_mode == 'manual' and reply_minutes is None:
        return api_response(False, '답변 속도를 골라 주세요.', None, 400)

    # 자동 응답 문구는 위젯이 textContent 로 그리므로 원문을 받고 길이만 자른다. 비우면 기본 문구.
    messages = {}
    for kind in ('closed', 'holiday', 'away'):
        text = _trim(php_str(post.get('msg_' + kind)))[:500]
        messages['chat_msg_' + kind] = None if text == '' else text

    # 인사말(docs/widget-ops-plan.md 2장). 위젯이 textContent 로 그리므로 원문을 받고, 버튼 주소는 https 만 받는다.
    greeting = php_str(post.get('greeting')).replace('\r\n', '\n').replace('\r', '\n')
    greeting = _trim(re.sub(r'[\x00-\x08\x0b-\x1f\x7f]', '', greeting))

    if len(greeting) > chat_greeting.BODY_MAX:
        return api_response(False, f'인사말은 {chat_greeting.BODY_MAX}자까지 쓸 수 있습니다.', None, 400)

    buttons = chat_greeting.clean_buttons(post.get('greeting_buttons'))
    if isinstance(buttons, str):
        return api_response(False, buttons, None, 400)

    # 연락처 카드와 팔로업 메일(3장)
    ask = {'chat_ask_' + field: _flag(post, 'ask_' + field) for field in ('name', 'email', 'phone')}
    contact_required = _flag(post, 'contact_required')

    if contact_required and sum(ask.values()) == 0:
        return api_response(False, '연락처를 받아야 문의를 받으려면 묻는 항목을 하나 이상 골라 주세요.', None, 400)

    contact_notice = _trim(php_str(post.get('contact_notice')))[:500]

    # 도메인은 줄 단위로 받아 호스트만 남긴다(PHP 8.2 strtolower 는 ASCII 만 바꾼다).
    domains = []
    for line in re.split(r'[\r\n,]+', php_str(post.xss('domains'))):
        line = guide_model._ascii_lower(_trim(line))
        line = re.sub(r'^https?://', '', line, count=1)
        line = _trim(re.sub(r'/.*$', '', line, count=1))
        if line != '' and re.match(r'^[a-z0-9.\-*]+$', line):
            domains.append(line)

    data = {
        'widget_enabled': _flag(post, 'enabled'),
        'widget_color': color,
        'widget_position': position,
        'widget_domains': None if not domains else '\n'.join(domains)[:500],
        'widget_offline_email': email,
        'guide_autosuggest': _flag(post, 'guide_autosuggest'),
        'chat_hours_enabled': hours_enabled,
        'chat_offhours_mode': offhours,
        'chat_reply_mode': reply_mode,
        'chat_reply_minutes': reply_minutes,
        # 방식을 바꾸면 실제 기록을 다음 요청에서 다시 계산한다.
        'chat_reply_calc_at': None,
        'chat_phone_button': _flag(post, 'phone_button'),
        'chat_phone_hours_only': _flag(post, 'phone_hours_only'),
        'chat_greeting': None if greeting == '' else greeting,
        'chat_greeting_buttons': None if not buttons else php_json_encode(buttons, unescaped_unicode=True, unescaped_slashes=True),
        'chat_contact_required': contact_required,
        'chat_contact_notice': None if contact_notice == '' else contact_notice,
        'chat_followup_email': _flag(post, 'followup_email'),
    }
    data.update(ask)
    data.update(messages)
    organization_model.update(agent.org_id, data)

    chat_hours_model.save_hours(agent.org_id, hours)

    ask_text = ''.join(field[0] if ask['chat_ask_' + field] else '-' for field in ('name', 'email', 'phone'))
    log_model.audit(
        agent.org_id, agent.id, 'widget_update',
        'domains=' + str(len(domains)) + ' hours=' + (str(open_days) + 'days' if hours_enabled else 'off')
        + ' offhours=' + offhours + ' reply=' + reply_mode + ' phone=' + str(_flag(post, 'phone_button'))
        + ' greeting=' + ('off' if greeting == '' else str(len(greeting)) + 'chars/' + str(len(buttons)) + 'btn')
        + ' ask=' + ask_text + ' required=' + str(contact_required) + ' followup=' + str(_flag(post, 'followup_email')),
        client_ip(request))

    # 모르는 변수는 저장은 하되 알려 준다(매크로와 같은 방식).
    unknown = chat_greeting.unknown_variables(greeting)
    message = ('저장했습니다. 인사말의 {' + '}, {'.join(unknown) + '} 는 알 수 없는 변수라 글자 그대로 보입니다.'
               if unknown else '위젯 설정을 저장했습니다.')

    return api_response(True, message)


@router.api_route('/api/widget_regenerate', methods=_METHODS)
def api_widget_regenerate(request: Request, agent=Depends(require_admin)):
    """키를 새로 발급하면 기존 스니펫은 즉시 동작하지 않는다."""
    key = organization_model.regenerate_widget_key(agent.org_id)
    org = organization_model.get_by_id(agent.org_id)

    log_model.audit(agent.org_id, agent.id, 'widget_key_regenerate', None, client_ip(request))

    return api_response(True, '새 키를 발급했습니다. 기존 스니펫은 더 이상 동작하지 않습니다.', {
        'widget_key': key,
        'snippet': organization_model.widget_snippet(org),
    })


# ------------------------------------------------------------------ 상담 운영 상태, 휴일

@router.api_route('/api/chat_state', methods=_METHODS)
def api_chat_state(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """state = auto | open | closed. 자동 판정이 같은 값이 되는 때 '자동' 으로 돌아간다(F3)."""
    state = php_str(post.xss('state'))

    if state not in ('auto', 'open', 'closed'):
        return api_response(False, '운영 상태 값이 올바르지 않습니다.', None, 400)

    org = organization_model.get_by_id(agent.org_id)
    availability = chat_hours_model.set_manual(org, state)

    log_model.audit(agent.org_id, agent.id, 'chat_state',
                    'state=' + state + ' applied=' + availability['manual']
                    + ' until=' + (availability['manual_until'] if not php_empty(availability['manual_until']) else '-'),
                    client_ip(request))

    labels = {'auto': '운영 시간대로 돌렸습니다.', 'open': '지금부터 상담을 받습니다.', 'closed': '지금부터 상담을 받지 않습니다.'}
    message = ('지금 운영 시간 설정과 같은 상태라 운영 시간대로 둡니다.'
               if availability['manual'] == 'auto' and state != 'auto' else labels[state])

    return api_response(True, message, {'availability': chat_hours_model.console_view(org, availability)})


@router.api_route('/api/holiday_candidates', methods=_METHODS)
def api_holiday_candidates(agent=Depends(require_admin)):
    """조직 국가의 공휴일 후보(settings/holidays.py). 자동으로 넣지 않고 관리자가 고른다."""
    org = organization_model.get_by_id(agent.org_id)
    return api_response(True, '', {'candidates': chat_hours_model.holiday_candidates(org)})


def _holiday_rows(agent):
    return [chat_hours_model.present_holiday(found) for found in chat_hours_model.list_holidays(agent.org_id)]


@router.api_route('/api/holiday_add', methods=_METHODS)
def api_holiday_add(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """한 건(start_date, end_date, name, mode, start, end, repeat_yearly) 또는 공휴일 후보 여러 건(items = JSON [{date, name}])."""
    from app.core.php import php_json_decode

    items = php_json_decode(php_str(post.get('items')))

    if not isinstance(items, (list, dict)):
        items = [{
            'start_date': post.xss('start_date'),
            'end_date': post.xss('end_date'),
            'name': post.xss('name'),
            'mode': post.xss('mode'),
            'start': post.xss('start'),
            'end': post.xss('end'),
            'repeat_yearly': post.get('repeat_yearly'),
        }]
    else:
        items = list(items.values()) if isinstance(items, dict) else items
        converted = []
        for item in items[:50]:
            day = item['date'] if isinstance(item, dict) and item.get('date') is not None else ''
            name = item['name'] if isinstance(item, dict) and item.get('name') is not None else ''
            converted.append({'start_date': day, 'end_date': day, 'name': name, 'mode': 'closed'})
        items = converted

    rows = []
    for item in items:
        found = chat_hours_model.clean_holiday(item)
        if isinstance(found, str):
            return api_response(False, found, None, 400)
        rows.append(found)

    if not rows:
        return api_response(False, '추가할 휴일을 골라 주세요.', None, 400)

    for found in rows:
        chat_hours_model.add_holiday(agent.org_id, found)

    log_model.audit(agent.org_id, agent.id, 'chat_holiday_add',
                    'count=' + str(len(rows)) + ' first=' + rows[0]['start_date'], client_ip(request))

    return api_response(True, '휴일 ' + str(len(rows)) + '건을 추가했습니다.', {'holidays': _holiday_rows(agent)})


@router.api_route('/api/holiday_delete', methods=_METHODS)
def api_holiday_delete(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    holiday_id = php_int(post.get('id'))

    if not chat_hours_model.delete_holiday(agent.org_id, holiday_id):
        return api_response(False, '휴일을 찾을 수 없습니다.', None, 404)

    log_model.audit(agent.org_id, agent.id, 'chat_holiday_delete', 'id=' + str(holiday_id), client_ip(request))

    return api_response(True, '휴일을 지웠습니다.', {'holidays': _holiday_rows(agent)})
