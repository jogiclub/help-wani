"""
파일 위치: backend/app/routers/console.py
역할: 상담원 콘솔 — 원격지원(코드 발급, 목록, 원격 화면, 메모, 로그), 상담 대화(목록, 메시지, 보내기, 담당, 보는 중/입력 중,
      알림, 종료, 원격지원 시작), 상담 중 가이드 추천·검색·보내기 (PHP controllers/Console.php 대응)

로그인한 상담원만 쓴다(Depends(current_agent)). 상담원 답장을 저장하는 곳은 모두 publish_new_messages() 를 거친다(CLAUDE.md 3.18).
담당 변경과 보는 중/입력 중은 조직 방(publish_org)에만 보낸다(CLAUDE.md 3.16). CI3 는 경로 규칙과 상관없이 메서드를 받으므로 GET/POST 를 모두 받는다.
"""
import re
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from fastapi import APIRouter, Depends, Request

from app import config
from app.core.deps import current_agent
from app.core.errors import Halt, redirect, show_404
from app.core.i18n import current_timezone, lang_text
from app.core.input import PostData, post_data, query_data
from app.core.php import php_date, php_empty, php_int, php_str, strtotime_utc, utc_str
from app.core.request import client_ip
from app.core.response import api_response
from app.core.templates import render
from app.helpers.app import customer_url, relay_viewer_ws_url, status_label
from app.libraries import chat_followup, chat_greeting, guide_matcher, realtime
from app.models import (agent_model, chat_hours_model, chat_model, guide_model, log_model, macro_model,
                        organization_model, session_model)
from app.settings import RH_CODE_TTL

router = APIRouter(prefix='/console')

_METHODS = ['GET', 'POST']


def _require_own_session(agent, session_id):
    """세션이 로그인 상담원의 조직 소속인지 확인한다(PHP Agent_Controller::require_own_session)."""
    found = session_model.get_by_id(php_int(session_id))
    if found is None or int(found.org_id) != int(agent.org_id):
        raise Halt(api_response(False, '세션을 찾을 수 없습니다.', None, 404))
    return found


def _require_own_conversation(agent, conversation_id):
    """대화가 로그인 상담원의 조직 소속인지 확인한다."""
    found = chat_model.get_conversation(conversation_id)
    if found is None or int(found.org_id) != int(agent.org_id):
        raise Halt(api_response(False, '대화를 찾을 수 없습니다.', None, 404))
    return found


# ------------------------------------------------------------------ 화면

@router.api_route('', methods=_METHODS)
def index(request: Request, agent=Depends(current_agent), query: PostData = Depends(query_data)):
    org = organization_model.get_by_id(agent.org_id)
    return render(request, 'console/queue', {
        'page_title': '원격지원',
        'use_grid': True,
        'customer_url': customer_url(org.org_code),
        # 화면에 다시 채울 검색 조건 원문(목록 조회는 api_session_list 가 허용 목록으로 거른다)
        'filters': {key: php_str(query.xss(key)) for key in ('keyword', 'status', 'date_from', 'date_to')},
    })


@router.api_route('/session/{session_id:int}', methods=_METHODS)
def session(request: Request, session_id: int, agent=Depends(current_agent)):
    found = session_model.get_by_id(session_id)
    if found is None or int(found.org_id) != int(agent.org_id):
        raise Halt(show_404())
    return render(request, 'console/viewer', {
        'page_title': '원격 화면',
        # 템플릿의 session 은 로그인 세션이므로 원격 세션은 session_row 로 넘긴다(PHP 뷰의 $session).
        'session_row': found,
        'notes': session_model.list_notes(found.id),
        'relay_ws_url': relay_viewer_ws_url(),
    })


@router.api_route('/history', methods=_METHODS)
def history(request: Request, agent=Depends(current_agent)):
    """원격 이력은 원격지원 화면(/console)에 합쳤다. 예전 주소로 오면 검색 조건을 그대로 넘긴다."""
    query = request.url.query
    return redirect('console' + ('?' + query if query != '' else ''))


# ------------------------------------------------------------------ 원격지원 API

@router.api_route('/api/session/create', methods=_METHODS)
def api_session_create(request: Request, agent=Depends(current_agent)):
    try:
        created = session_model.create_session(agent.org_id, agent.id)
    except RuntimeError as error:
        return api_response(False, str(error), None, 500)

    log_model.audit(agent.org_id, agent.id, 'session_create', 'session_id=' + str(created['id']), client_ip(request))
    org = organization_model.get_by_id(agent.org_id)

    return api_response(True, '코드가 발급되었습니다.', {
        'session_id': created['id'],
        'code': created['code'],
        'expires_at': created['expires_at'],
        'expires_in': RH_CODE_TTL,
        'customer_url': customer_url(org.org_code),
    })


def _local_date_to_utc(value, clock):
    """조직 시간대의 날짜+시각을 UTC 'Y-m-d H:i:s' 로. PHP DateTime 처럼 없는 날(2월 30일)은 다음 달로 넘긴다."""
    if not isinstance(value, str) or not re.match(r'^[0-9]{4}-[0-9]{2}-[0-9]{2}$', value):
        return ''
    year, month, day = (int(v) for v in value[:10].split('-'))
    if not (1 <= month <= 12) or day > 31 or year < 1:
        return ''
    try:
        base = date(year, month, 1) + timedelta(days=day - 1)
        hh, mm, ss = (int(v) for v in clock.split(':'))
        local = datetime(base.year, base.month, base.day, hh, mm, ss, tzinfo=ZoneInfo(current_timezone()))
    except (ValueError, KeyError, OverflowError):
        return ''
    return php_date('Y-m-d H:i:s', local.astimezone(timezone.utc))


def _list_filters(query):
    """원격지원 목록 검색 조건. 상태는 허용 목록으로 거르고, 날짜는 조직 시간대의 하루를 UTC 범위로 바꾼다(CLAUDE.md 3.4)."""
    statuses = ('active', 'issued', 'verified', 'waiting', 'connected', 'ended', 'expired', 'canceled')
    status = php_str(query.xss('status'))
    return {
        'keyword': php_str(query.xss('keyword')).strip(' \t\n\r\x00\x0b')[:50],
        'status': status if status in statuses else '',
        'date_from': _local_date_to_utc(query.xss('date_from'), '00:00:00'),
        'date_to': _local_date_to_utc(query.xss('date_to'), '23:59:59'),
    }


@router.api_route('/api/session/list', methods=_METHODS)
def api_session_list(agent=Depends(current_agent), query: PostData = Depends(query_data)):
    session_model.expire_stale()

    now = int(datetime.now(timezone.utc).timestamp())
    out = []
    for row in session_model.search_history(agent.org_id, _list_filters(query), 100):
        started, ended = row.started_at, row.ended_at
        out.append({
            'id': int(row.id),
            'code': row.code,
            'status': row.status,
            'status_label': status_label(row.status),
            'agent_name': row.agent_name,
            'pc_name': row.customer_pc_name,
            'customer_ip': row.customer_ip,
            'remain_sec': max(0, (strtotime_utc(row.expires_at) or 0) - now),
            'duration': max(0, strtotime_utc(ended) - strtotime_utc(started)) if (not php_empty(started) and not php_empty(ended)) else 0,
            'survey_score': php_int(row.survey_score) if not php_empty(row.survey_score) else None,
            'created_at': row.created_at,
            'started_at': started,
            'ended_at': ended,
            'is_mine': int(row.agent_id) == int(agent.id),
        })

    return api_response(True, '', {'sessions': out, 'server_time': utc_str()})


@router.api_route('/api/session/detail/{session_id:int}', methods=_METHODS)
def api_session_detail(session_id: int, agent=Depends(current_agent)):
    found = _require_own_session(agent, session_id)
    return api_response(True, '', {
        'id': int(found.id),
        'status': found.status,
        'status_label': status_label(found.status),
        'pc_name': found.customer_pc_name,
        'customer_ip': found.customer_ip,
        'customer_os': found.customer_os,
        'started_at': found.started_at,
        'ended_at': found.ended_at,
        'end_reason': found.end_reason,
    })


@router.api_route('/api/session/viewer-token', methods=_METHODS)
def api_viewer_token(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """중계 서버 접속에 필요한 1회용 토큰과 접속 주소."""
    found = _require_own_session(agent, php_int(post.get('session_id')))

    if found.status not in ('waiting', 'connected'):
        return api_response(False, '고객이 아직 연결되지 않았습니다.', {'status': found.status}, 409)

    token = session_model.issue_viewer_token(found.id)
    log_model.add(found.id, 'viewer_token_issued', '상담원 ' + agent.name, 'agent', client_ip(request))
    log_model.audit(agent.org_id, agent.id, 'viewer_token', 'session_id=' + str(found.id), client_ip(request))

    return api_response(True, '', {'token': token['token'], 'expires_in': token['expires_in'], 'ws_url': relay_viewer_ws_url()})


@router.api_route('/api/session/end', methods=_METHODS)
def api_session_end(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    found = _require_own_session(agent, php_int(post.get('session_id')))

    if found.status in ('ended', 'expired', 'canceled'):
        return api_response(True, '이미 종료된 세션입니다.', {'status': found.status})

    status = 'canceled' if found.status == 'issued' else 'ended'
    session_model.set_status(found.id, status, {'end_reason': 'agent_ended'})
    log_model.add(found.id, 'agent_ended', '상담원 ' + agent.name + ' 종료', 'agent', client_ip(request))
    log_model.audit(agent.org_id, agent.id, 'session_end', 'session_id=' + str(found.id), client_ip(request))

    return api_response(True, '세션을 종료했습니다.', {'status': status})


@router.api_route('/api/session/note', methods=_METHODS)
def api_session_note(agent=Depends(current_agent), post: PostData = Depends(post_data)):
    session_id = php_int(post.get('session_id'))
    content = php_str(post.xss('content')).strip(' \t\n\r\x00\x0b')
    found = _require_own_session(agent, session_id)

    if content == '':
        return api_response(False, '메모 내용을 입력해 주세요.', None, 400)

    session_model.add_note(found.id, agent.id, content)
    return api_response(True, '메모를 저장했습니다.', {'notes': session_model.list_notes(found.id)})


@router.api_route('/api/session/log', methods=_METHODS)
def api_session_log(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """뷰어 측 이벤트를 세션 로그로 남긴다."""
    session_id = php_int(post.get('session_id'))
    event = php_str(post.xss('event'))[:50]
    detail = php_str(post.xss('detail'))[:500]
    found = _require_own_session(agent, session_id)

    allowed = ('viewer_connected', 'viewer_disconnected', 'viewer_auth_failed', 'viewer_reconnecting',
               'viewer_clipboard', 'viewer_ctrl_alt_del', 'viewer_quality_changed', 'viewer_peer_lost')
    if event not in allowed:
        return api_response(False, '허용되지 않는 이벤트입니다.', None, 400)

    log_model.add(found.id, event, detail, 'agent', client_ip(request))
    return api_response(True, '')


# ------------------------------------------------------------------ 상담 위젯 대화

def _chat_row(c):
    """대화 목록 한 줄(콘솔 화면과 목록 폴링이 같은 모양)."""
    return {
        'id': int(c.id),
        'visitor_name': c.visitor_name,
        'visitor_email': c.visitor_email,
        'visitor_phone': c.visitor_phone,
        'page_url': c.page_url,
        'followup_sent_at': c.followup_sent_at,
        'agent_name': c.agent_name,
        'assigned_agent_id': php_int(c.assigned_agent_id) if not php_empty(c.assigned_agent_id) else None,
        'status': c.status,
        'unread': php_int(c.unread_for_agent),
        'last_body': c.last_body,
        'last_message_at': c.last_message_at,
        'created_at': c.created_at,
        'session_id': php_int(c.session_id) if not php_empty(c.session_id) else None,
    }


@router.api_route('/chat', methods=_METHODS)
def chat(request: Request, agent=Depends(current_agent), query: PostData = Depends(query_data)):
    # 위젯 설정 드로어는 조직 관리자에게만 보인다(저장 API 는 admin 경로가 권한을 다시 확인한다).
    is_admin = agent.role == 'admin'
    org = None

    if is_admin:
        org = organization_model.get_by_id(agent.org_id)
        # 설정을 처음 열 때 키가 없으면 바로 발급한다.
        if php_empty(org.widget_key):
            organization_model.regenerate_widget_key(org.id)
            org = organization_model.get_by_id(org.id)

    own_org = org or organization_model.get_by_id(agent.org_id)
    rows = chat_model.list_for_org(agent.org_id, {'status': 'open'})

    # PHP 뷰의 CHAT_ROWS 와 같은 모양(앞쪽 키 순서가 PHP 와 다르다: followup_sent_at 이 page_url 앞)
    chat_rows = []
    for c in rows:
        item = _chat_row(c)
        chat_rows.append({k: item[k] for k in ('id', 'visitor_name', 'visitor_email', 'visitor_phone', 'followup_sent_at',
                                               'page_url', 'agent_name', 'assigned_agent_id', 'status', 'unread',
                                               'last_body', 'last_message_at', 'created_at', 'session_id')})

    return render(request, 'console/chat', {
        'page_title': '상담 대화',
        # 매크로 편집 드로어와 변수 값(docs/macro-plan.md)
        'macro_editor': macro_model.editor_conf(agent),
        'chat_macro': {
            'orgName': own_org.name,
            'visitorFallback': lang_text('macro.visitor_fallback', {}, own_org.locale),
        },
        # 운영 상태(docs/chat-operation-plan.md F3). 목록 폴링이 이어서 갱신한다.
        'availability': chat_hours_model.console_view(own_org, chat_hours_model.availability(own_org)),
        'my_status': agent.chat_status,
        'notify': php_int(agent.chat_notify) == 1,
        # 설정 드로어의 답변 속도(실제 기록) 미리보기. 위젯과 같은 기준(최근 7일, 운영 중 문의, 10건 이상)이다.
        'reply_preview': chat_model.median_reply_seconds(org.id, chat_model.REPLY_WINDOW_DAYS, chat_model.REPLY_MIN_SAMPLES) if org else None,
        'me': agent,
        # 설정 드로어의 인사말 버튼과 팔로업 메일 안내(docs/widget-ops-plan.md)
        'greeting_buttons': chat_greeting.buttons(org) if org else [],
        'mail_ready': php_str(config.env('SMTP_HOST', '')) != '',
        'resume_days': chat_followup.RESUME_TTL // 86400,
        # 운영 시간 편집기 값 {"0": [["09:00", "18:00"]], ...} (PHP 뷰의 $hours_out)
        'widget_hours': {str(w): [[chat_hours_model.format_time(a), chat_hours_model.format_time(b)] for a, b in ranges]
                         for w, ranges in chat_hours_model.get_hours(org.id).items()} if org else {},
        'holidays': [chat_hours_model.present_holiday(h) for h in chat_hours_model.list_holidays(org.id)] if org else [],
        'use_grid': True,
        'is_admin': is_admin,
        'org': org,
        'snippet': organization_model.widget_snippet(org) if org else '',
        # ?settings=widget 이면 설정 드로어를 연 채로 시작한다(예전 /admin/widget 주소).
        'open_settings': is_admin and query.get('settings') == 'widget',
        'chat_rows': chat_rows,
        # 실시간 구독 정보. 비어 있으면 화면이 폴링으로만 동작한다.
        'realtime': realtime.client_config({'role': 'agent', 'org_id': agent.org_id}),
    })


@router.api_route('/api/chat/list', methods=_METHODS)
def api_chat_list(agent=Depends(current_agent), query: PostData = Depends(query_data)):
    """목록 폴링. 이 호출이 상담원 접속 상태도 함께 갱신한다."""
    chat_model.touch_agent(agent.id)

    filters = {key: query.xss(key) for key in ('status', 'keyword', 'date_from', 'date_to')}
    out = [_chat_row(c) for c in chat_model.list_for_org(agent.org_id, filters)]
    org = organization_model.get_by_id(agent.org_id)

    return api_response(True, '', {
        'conversations': out,
        'unread_total': chat_model.count_unread(agent.org_id),
        'availability': chat_hours_model.console_view(org, chat_hours_model.availability(org)),
    })


@router.api_route('/api/chat/my_status', methods=_METHODS)
def api_chat_my_status(agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """상담원 개인 응대 상태. '자리 비움' / '응대 종료' 이면 고객에게 응대 가능 인원으로 세지 않는다(F3)."""
    status = php_str(post.xss('status'))
    if status not in ('available', 'away', 'off'):
        return api_response(False, '상태 값이 올바르지 않습니다.', None, 400)

    chat_model.set_agent_status(agent.id, status)
    chat_model.touch_agent(agent.id)
    org = organization_model.get_by_id(agent.org_id)
    labels = {'available': '응대 가능', 'away': '자리 비움', 'off': '응대 종료'}

    return api_response(True, '내 상태: ' + labels[status], {
        'availability': chat_hours_model.console_view(org, chat_hours_model.availability(org)),
    })


@router.api_route('/api/chat/messages/{conversation_id:int}', methods=_METHODS)
def api_chat_messages(conversation_id: int, agent=Depends(current_agent), query: PostData = Depends(query_data)):
    conversation = _require_own_conversation(agent, conversation_id)
    after_id = php_int(query.get('after_id'))

    # 대화를 연 시점에 미확인 표시를 지운다.
    if after_id == 0:
        chat_model.mark_read(conversation.id)

    return api_response(True, '', {
        'conversation': {
            'id': int(conversation.id),
            'status': conversation.status,
            'session_id': php_int(conversation.session_id) if not php_empty(conversation.session_id) else None,
            'assigned_agent_id': php_int(conversation.assigned_agent_id) if not php_empty(conversation.assigned_agent_id) else None,
        },
        'messages': [chat_model.present(m) for m in chat_model.list_messages(conversation.id, after_id)],
    })


@router.api_route('/api/chat/send', methods=_METHODS)
def api_chat_send(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """mode = '' | join | take, macro_id = 매크로로 채운 글이면 그 id.

    다른 상담원이 담당인 대화에 보내면 409 와 담당자 이름을 돌려준다(F7). join 은 함께 답하기, take 는 담당을 가져와서 보내기.
    """
    conversation = _require_own_conversation(agent, post.get('conversation_id'))
    body = php_str(post.xss('body')).strip(' \t\n\r\x00\x0b')
    mode = php_str(post.xss('mode'))
    mode = mode if mode in ('join', 'take') else ''

    if body == '':
        return api_response(False, '보낼 내용을 입력해 주세요.', None, 400)

    if conversation.status != 'open':
        return api_response(False, '이미 종료된 대화입니다.', None, 409)

    owner = php_int(conversation.assigned_agent_id) if not php_empty(conversation.assigned_agent_id) else None

    if owner is not None and owner != int(agent.id):
        if mode == '':
            other = agent_model.get_by_id(owner)
            return api_response(False, '다른 상담원이 응대 중인 대화입니다.', {
                'conflict': True,
                'agent_name': other.name if other else '',
            }, 409)
        if mode == 'take':
            _take_conversation(request, agent, conversation, owner)
    else:
        chat_model.assign(conversation.id, agent.id)

    message_id = chat_model.add_message(conversation.id, 'agent', body, agent.id)
    chat_model.mark_read(conversation.id)
    chat_model.touch_agent(agent.id)

    followup = publish_new_messages(conversation, message_id)

    # 매크로로 채운 글이면 사용 횟수를 올린다(docs/macro-plan.md M2). 쓸 수 없는 매크로면 조용히 넘긴다.
    macro_id = php_int(post.get('macro_id'))
    if macro_id > 0:
        macro_model.record_use(agent, macro_id, conversation.id, message_id)

    return api_response(True, '', {'followup': followup})


@router.api_route('/api/chat/assign', methods=_METHODS)
def api_chat_assign(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """take: 담당을 가져온다. release: 담당을 비워 대기 중으로 돌린다(담당자 본인이나 조직 관리자만)."""
    conversation = _require_own_conversation(agent, post.get('conversation_id'))
    action = php_str(post.xss('action'))
    owner = php_int(conversation.assigned_agent_id) if not php_empty(conversation.assigned_agent_id) else None

    if action == 'take':
        if owner == int(agent.id):
            return api_response(True, '이미 내가 담당입니다.')
        _take_conversation(request, agent, conversation, owner)
        return api_response(True, '담당을 가져왔습니다.')

    if action == 'release':
        if owner != int(agent.id) and agent.role != 'admin':
            return api_response(False, '담당자 본인이나 조직 관리자만 대기 중으로 돌릴 수 있습니다.', None, 403)

        chat_model.set_assignee(conversation.id, None)
        log_model.audit(agent.org_id, agent.id, 'chat_release',
                        'conversation_id=' + str(conversation.id) + ' from=' + (str(owner) if owner else '-'), client_ip(request))
        _publish_assignment(agent, conversation, None, owner)
        return api_response(True, '대기 중으로 돌렸습니다.')

    return api_response(False, '요청 값이 올바르지 않습니다.', None, 400)


@router.api_route('/api/chat/presence', methods=_METHODS)
def api_chat_presence(agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """이 대화를 보고 있거나 입력 중임을 다른 상담원에게 알린다. 저장하지 않고 조직 방에만 발행한다(CLAUDE.md 3.11, 3.16)."""
    conversation = _require_own_conversation(agent, post.get('conversation_id'))
    kind = php_str(post.xss('kind'))

    if kind not in ('viewing', 'typing', 'leave'):
        return api_response(False, '요청 값이 올바르지 않습니다.', None, 400)

    realtime.publish_org(agent.org_id, {
        'type': 'presence',
        'conversation_id': int(conversation.id),
        'agent_id': int(agent.id),
        'agent_name': agent.name,
        'kind': kind,
    })
    return api_response(True, '')


@router.api_route('/api/chat/notify', methods=_METHODS)
def api_chat_notify(agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """새 문의 브라우저 알림을 받을지. 브라우저 권한은 화면이 따로 묻는다."""
    enabled = 0 if php_empty(post.get('enabled')) else 1
    chat_model.set_agent_notify(agent.id, enabled)
    return api_response(True, '새 문의 알림을 켰습니다.' if enabled else '새 문의 알림을 껐습니다.')


def _take_conversation(request, agent, conversation, previous):
    """담당을 로그인 상담원으로 바꾸고 이전 담당자에게 알린다. 다른 사람 것을 가져오면 감사 로그에 남긴다."""
    chat_model.set_assignee(conversation.id, agent.id)
    if previous is not None:
        log_model.audit(agent.org_id, agent.id, 'chat_take',
                        'conversation_id=' + str(conversation.id) + ' from=' + str(previous), client_ip(request))
    _publish_assignment(agent, conversation, int(agent.id), previous)


def _publish_assignment(agent, conversation, agent_id, previous):
    """담당이 바뀌었음을 상담원들에게 알린다(조직 방만). 이전 담당자 화면은 이 이벤트로 안내를 띄운다."""
    realtime.publish_org(conversation.org_id, {
        'type': 'assignment',
        'conversation_id': int(conversation.id),
        'assigned_agent_id': agent_id,
        'previous_agent_id': previous,
        'agent_name': '' if agent_id is None else agent.name,
        'by_agent_id': int(agent.id),
        'by_agent_name': agent.name,
    })


@router.api_route('/api/chat/close', methods=_METHODS)
def api_chat_close(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    conversation = _require_own_conversation(agent, post.get('conversation_id'))
    chat_model.set_status(conversation.id, 'closed')
    log_model.audit(agent.org_id, agent.id, 'chat_close', 'conversation_id=' + str(conversation.id), client_ip(request))
    realtime.publish_conversation(conversation.org_id, conversation.id, {'status': 'closed'})
    return api_response(True, '대화를 종료했습니다.')


@router.api_route('/api/chat/remote', methods=_METHODS)
def api_chat_remote(request: Request, agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """대화 중인 방문자에게 원격지원 코드를 발급하고 안내 메시지를 대화에 남긴다."""
    conversation = _require_own_conversation(agent, post.get('conversation_id'))

    if conversation.status != 'open':
        return api_response(False, '이미 종료된 대화입니다.', None, 409)

    try:
        created = session_model.create_session(agent.org_id, agent.id)
    except RuntimeError as error:
        return api_response(False, str(error), None, 500)

    org = organization_model.get_by_id(agent.org_id)
    url = customer_url(org.org_code)

    # 안내 문구는 방문자가 읽으므로 조직이 정한 언어로 만든다(CLAUDE.md 3.2).
    notice = lang_text('widget.remote_notice', {'code': created['code'], 'url': url}, org.locale)

    chat_model.assign(conversation.id, agent.id)
    message_id = chat_model.add_message(conversation.id, 'system', notice, agent.id)
    chat_model.link_session(conversation.id, created['id'])

    publish_new_messages(conversation, message_id)

    log_model.audit(agent.org_id, agent.id, 'chat_remote_start',
                    'conversation_id=' + str(conversation.id) + ' session_id=' + str(created['id']), client_ip(request))
    log_model.add(created['id'], 'session_create', '상담 대화에서 발급', 'agent', client_ip(request))

    return api_response(True, '코드가 발급되었습니다.', {
        'session_id': created['id'],
        'code': created['code'],
        'expires_in': RH_CODE_TTL,
        'customer_url': url,
    })


# ------------------------------------------------------------------ 상담 중 가이드 추천 (docs/guide-plan.md 6-1)

def _present_guides(agent, items, sent):
    org = organization_model.get_by_id(agent.org_id)
    return [{
        'id': item['id'],
        'title': item['title'],
        'subtitle': item['subtitle'],
        'score': item['score'],
        'url': guide_model.public_url(org, item),
        'sent': item['id'] in sent,
    } for item in items]


@router.api_route('/api/guide/suggest/{conversation_id:int}', methods=_METHODS)
def api_guide_suggest(conversation_id: int, agent=Depends(current_agent)):
    """방문자의 최근 메시지 3개로 공개 가이드를 찾는다. AI 를 부르지 않는다(guide_matcher)."""
    conversation = _require_own_conversation(agent, conversation_id)
    sent = {card['article_id'] for card in chat_model.guide_cards(conversation.id)}
    text = chat_model.recent_visitor_text(conversation.id, 3)
    items = guide_matcher.match(agent.org_id, text, 'console', 3)
    return api_response(True, '', {'items': _present_guides(agent, items, sent)})


@router.api_route('/api/guide/search', methods=_METHODS)
def api_guide_search(agent=Depends(current_agent), query: PostData = Depends(query_data)):
    q = php_str(query.get('q'))[:200]
    items = guide_matcher.match(agent.org_id, q, 'search', 10)
    sent = set()
    conversation = chat_model.get_conversation(query.get('conversation_id'))
    if conversation is not None and int(conversation.org_id) == int(agent.org_id):
        sent = {card['article_id'] for card in chat_model.guide_cards(conversation.id)}
    return api_response(True, '', {'items': _present_guides(agent, items, sent)})


@router.api_route('/api/chat/send_guide', methods=_METHODS)
def api_chat_send_guide(agent=Depends(current_agent), post: PostData = Depends(post_data)):
    """가이드 카드를 상담원 메시지로 저장하고 발행한다. 본문에도 제목과 주소를 넣어 카드를 못 그리는 화면에서도 읽힌다."""
    conversation = _require_own_conversation(agent, post.get('conversation_id'))

    if conversation.status != 'open':
        return api_response(False, '이미 종료된 대화입니다.', None, 409)

    article = guide_model.get_article(agent.org_id, post.get('article_id'))
    if article is None or article.status != 'published':
        return api_response(False, '공개된 가이드만 보낼 수 있습니다.', None, 404)

    org = organization_model.get_by_id(agent.org_id)
    card = guide_model.card(org, article)

    chat_model.assign(conversation.id, agent.id)
    message_id = chat_model.add_message(conversation.id, 'agent', php_str(card['title']) + '\n' + card['url'], agent.id, card)
    chat_model.mark_read(conversation.id)
    chat_model.touch_agent(agent.id)

    # 저장한 뒤 반드시 발행한다(CLAUDE.md 3.11). 빠지면 방문자 화면이 폴링 간격만큼 늦는다.
    followup = publish_new_messages(conversation, message_id)

    return api_response(True, '가이드를 보냈습니다.', {'followup': followup})


def publish_new_messages(conversation, from_message_id):
    """방금 저장한 메시지를 구독자(방문자 위젯, 다른 상담원)에게 전달하고, 상담원 답장이면 팔로업 메일을 판단한다.

    상담원 답장을 저장하는 곳은 모두 이 함수를 거친다(CLAUDE.md 3.18). 실패해도 화면은 폴링으로 따라잡는다.
    """
    agent_body = None
    for message in chat_model.list_messages(conversation.id, from_message_id - 1):
        realtime.publish_message(conversation.org_id, conversation.id, chat_model.present(message))
        if message.sender_type == 'agent':
            agent_body = message.body

    # 고객이 대화창을 떠났으면 상담원 답장을 이메일로 알린다(docs/widget-ops-plan.md 3-4).
    if agent_body is None:
        return False

    return chat_followup.after_agent_message(conversation, agent_body)
