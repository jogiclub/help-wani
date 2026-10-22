"""
파일 위치: backend/app/routers/widget.py
역할: 고객사 사이트에 삽입되는 상담 위젯의 공개 API — 스니펫 배포, 메시지 송수신, 연락처 카드

이 경로만 로그인 없이 외부 도메인에서 호출된다. 보호 수단은 위젯 키, Origin 허용 목록, 방문자 토큰 세 가지다(CLAUDE.md 3.9).
CSRF 검사 제외 경로(api/widget/.*)이며, 위젯은 사전 요청을 피하려고 JSON 을 text/plain 으로 보낸다(json_input 이 읽는다).
운영 상태는 chat_hours_model.availability() 하나로 판단한다(CLAUDE.md 3.16). 인사말·자동 응답·가이드 추천은
send() 가 발행할 메시지를 읽기 전에 저장해 함께 발행한다(CLAUDE.md 3.11, 3.18).
"""
import re

from fastapi import APIRouter, Depends, Request
from fastapi.responses import Response

from app import config
from app.core.errors import Halt, show_404
from app.core.i18n import country_dial_codes, current_locale, current_timezone, lang_text, supported_locales
from app.core.input import PostData, json_input, query_data
from app.core.middleware import use_org_locale
from app.core.php import php_empty, php_int, php_str
from app.core.request import base_url, client_ip, get_request_header
from app.core.response import api_response
from app.libraries import chat_followup, chat_greeting, guide_matcher, mailer, realtime
from app.models import agent_model, chat_hours_model, chat_model, guide_model, organization_model, visitor_model

router = APIRouter()

# 대화창을 처음 열 때 가져오는 최근 메시지 수
HISTORY_LIMIT = 50

_TEXT_KEYS = ('title', 'subtitle', 'open', 'close', 'placeholder', 'send', 'name',
              'email', 'online', 'offline', 'agent', 'system',
              'error', 'flood', 'empty', 'guide_suggest', 'guide_open',
              'status_open', 'status_away', 'status_closed', 'hours_label', 'next_open',
              'closed_info', 'phone_label', 'phone_copy', 'phone_copied',
              'phone', 'contact_title', 'contact_submit', 'contact_skip', 'contact_saved',
              'contact_locked', 'contact_required')


class _Reply:
    """조직 확인 뒤 정한 CORS 헤더를 그 뒤의 모든 응답에 붙인다(PHP output->set_header 와 같음)."""

    def __init__(self):
        self.headers = {}

    def __call__(self, result, message='', data=None, status=200):
        response = api_response(result, message, data, status)
        for name, value in self.headers.items():
            response.headers[name] = value
        return response


# ------------------------------------------------------------------ 스니펫

@router.get('/widget.js')
def script():
    """고객사 사이트가 <script src=".../widget.js" data-key="..."> 로 불러간다. 포털 자산을 함께 내려보내지 않는다."""
    path = config.ASSETS_DIR / 'js' / 'widget.js'
    if not path.is_file():
        raise Halt(show_404())
    return Response(path.read_bytes(), media_type='application/javascript; charset=utf-8',
                    headers={'Cache-Control': 'public, max-age=300', 'Access-Control-Allow-Origin': '*'})


# ------------------------------------------------------------------ 대화 API

def _authorize(request, reply, key):
    """위젯 키와 Origin 을 확인하고 조직을 돌려준다. 실패하면 응답으로 멈춘다."""
    org = organization_model.get_by_widget_key(key)

    if org is None:
        # 키가 틀렸을 때 조직 존재 여부를 알려주지 않는다.
        raise Halt(reply(False, '', None, 404))

    origin = php_str(get_request_header('Origin', True, request))

    if not organization_model.widget_origin_allowed(org, origin):
        raise Halt(reply(False, '', None, 403))

    if origin != '':
        reply.headers['Access-Control-Allow-Origin'] = origin
        reply.headers['Vary'] = 'Origin'

    # 고객이 보는 화면이므로 조직이 정한 언어를 쓴다(CLAUDE.md 3.2).
    use_org_locale(request, org)
    return org


@router.post('/api/widget/init')
def init(request: Request, data: PostData = Depends(json_input)):
    """위젯을 열 때 설정, 문구, 최근 대화를 한 번에 내려준다."""
    reply = _Reply()
    org = _authorize(request, reply, data.get('key'))

    visitor = visitor_model.ensure(org.id, _resume_visitor_key(org, data), {
        'ip': client_ip(request),
        'user_agent': request.headers.get('user-agent'),
        'page_url': data.get('page_url'),
    })

    conversation = chat_model.ensure_conversation(org.id, visitor.id)
    messages = chat_model.list_messages(conversation.id, 0, HISTORY_LIMIT)
    availability = chat_hours_model.availability(org)
    talking = chat_model.has_visitor_message(conversation.id)

    # 운영 시간 외 '숨기기' 여도 이미 대화 중인 방문자에게는 보인다(답변을 못 보게 되면 안 된다).
    if availability['state'] == 'closed' and org.chat_offhours_mode == 'hide' and not talking:
        return reply(True, '', {'hidden': True})

    locale = current_locale()
    locales = supported_locales()

    return reply(True, '', {
        'visitor_key': visitor.visitor_key,
        'visitor': {'name': visitor.name, 'email': visitor.email, 'phone': visitor.phone},
        # 인사말은 대화를 시작하기 전에만 화면에 그린다. 시작하면 첫 메시지 앞에 저장된 것이 보인다.
        'greeting': None if talking else chat_greeting.render(org, visitor),
        'contact': _contact_state(org, visitor, conversation, talking),
        'config': {
            'color': org.widget_color,
            'position': org.widget_position,
            'org_name': org.name,
            'locale': locale,
            'intl': locales[locale]['intl'] if locale in locales else 'ko-KR',
            'timezone': current_timezone(),
            'online': availability['online'],
            'hours': chat_hours_model.summary(org),
        },
        'availability': _present_availability(org, availability, talking),
        'texts': {key: lang_text('widget.' + key) for key in _TEXT_KEYS},
        # 실시간 구독 정보. 비어 있으면 위젯이 폴링으로만 동작한다.
        'realtime': realtime.client_config({'role': 'visitor', 'org_id': org.id, 'conversation_id': conversation.id}),
        'messages': [chat_model.present(m) for m in messages],
    })


@router.post('/api/widget/send')
def send(request: Request, data: PostData = Depends(json_input)):
    reply = _Reply()
    org = _authorize(request, reply, data.get('key'))

    visitor = visitor_model.get_by_key(org.id, data.get('visitor_key'))
    if visitor is None:
        return reply(False, lang_text('widget.error'), {'reset': True}, 401)

    body = php_str(data.get('body', '')).strip(' \t\n\r\x00\x0b')
    if body == '':
        return reply(False, lang_text('widget.error'), None, 400)

    # 예전 위젯(위쪽 이름/이메일 칸)이 캐시에 남아 있을 수 있어 그대로 받는다. 지금 위젯은 /contact 로 보낸다.
    if data.get('name') is not None or data.get('email') is not None:
        visitor_model.update_profile(visitor.id, data.get('name'), data.get('email'))
        visitor = visitor_model.get_by_id(visitor.id)

    conversation = chat_model.ensure_conversation(org.id, visitor.id)
    availability = chat_hours_model.availability(org)
    talking = chat_model.has_visitor_message(conversation.id)

    # '안내만' / '숨기기' 는 운영 시간 외에 새 문의를 받지 않는다. 화면만 막지 않고 서버에서도 막는다.
    if not _accepting(org, availability, talking):
        return reply(False, lang_text('widget.closed_reject'), None, 409)

    # '연락처를 남긴 고객에게만' 이면 연락처 없이 새 문의를 시작할 수 없다. 이미 대화 중이면 막지 않는다.
    if not talking and _contact_blocking(org, visitor):
        return reply(False, lang_text('widget.contact_required'), {
            'contact': _contact_state(org, visitor, conversation, False),
        }, 409)

    if chat_model.is_flooding(conversation.id):
        return reply(False, lang_text('widget.flood'), None, 429)

    # 첫 메시지면 그 앞에 방문자가 본 인사말을 남긴다. 상담원도 어떤 안내를 보고 말을 걸었는지 알 수 있다.
    greeting = None if talking else chat_greeting.render(org, visitor)
    first_id = None
    if greeting is not None:
        first_id = chat_model.add_message(conversation.id, 'system', greeting['body'], None,
                                          {'type': 'greeting', 'buttons': greeting['buttons']})

    message_id = chat_model.add_message(conversation.id, 'visitor', body)
    first_id = first_id or message_id
    chat_model.touch_visitor_seen(conversation.id)
    chat_model.mark_received(conversation.id, availability['state'] != 'closed')
    _suggest_guide(org, conversation, body)
    _auto_reply(org, conversation, availability)

    messages = [chat_model.present(m) for m in chat_model.list_messages(conversation.id, first_id - 1)]

    # 상담원 콘솔에 바로 띄운다. 실패해도 폴링이 따라잡는다.
    for message in messages:
        realtime.publish_message(org.id, conversation.id, message)

    _notify_if_offline(org, conversation, visitor, body, availability)

    return reply(True, '', {
        'messages': messages,
        'availability': _present_availability(org, availability, True),
        'contact': _contact_state(org, visitor, chat_model.get_conversation(conversation.id), True),
    })


@router.post('/api/widget/contact')
def contact(request: Request, data: PostData = Depends(json_input)):
    """연락처 카드 제출(name, email, phone_country, phone | skip=1). 묻지 않는 항목은 버리고, 저장하면 이 대화에서 다시 묻지 않는다."""
    reply = _Reply()
    org = _authorize(request, reply, data.get('key'))

    visitor = visitor_model.get_by_key(org.id, data.get('visitor_key'))
    if visitor is None:
        return reply(False, lang_text('widget.error'), {'reset': True}, 401)

    conversation = chat_model.ensure_conversation(org.id, visitor.id)
    talking = chat_model.has_visitor_message(conversation.id)
    required = php_int(org.chat_contact_required) == 1 and not talking
    skip = not php_empty(data.get('skip'))

    if skip:
        if required and _contact_blocking(org, visitor):
            return reply(False, lang_text('widget.contact_required'), None, 409)
        chat_model.mark_contact_asked(conversation.id)
    else:
        result = visitor_model.clean_contact(org, {
            'name': data.get('name'),
            'email': data.get('email'),
            'phone_country': data.get('phone_country'),
            'phone': data.get('phone'),
        }, required)

        if 'error' in result:
            return reply(False, lang_text(result['error']), {'field': result['field']}, 400)

        visitor_model.save_contact(visitor.id, result['ok'])
        chat_model.mark_contact_asked(conversation.id)

    chat_model.touch_visitor_seen(conversation.id)
    visitor = visitor_model.get_by_id(visitor.id)

    return reply(True, '' if skip else lang_text('widget.contact_saved'), {
        'visitor': {'name': visitor.name, 'email': visitor.email, 'phone': visitor.phone},
        'contact': _contact_state(org, visitor, chat_model.get_conversation(conversation.id), talking),
    })


@router.get('/api/widget/poll')
def poll(request: Request, query: PostData = Depends(query_data)):
    """GET ?key=&visitor_key=&after_id=&realtime=1(새 구독 토큰이 필요할 때) — 대화창이 열려 있는 동안에만 호출된다."""
    reply = _Reply()
    org = _authorize(request, reply, query.get('key'))

    visitor = visitor_model.get_by_key(org.id, query.get('visitor_key'))
    if visitor is None:
        return reply(False, '', {'reset': True}, 401)

    conversation = chat_model.ensure_conversation(org.id, visitor.id)
    after_id = php_int(query.get('after_id'))
    availability = chat_hours_model.availability(org)

    # 위젯은 대화창을 펼치고 탭이 보일 때만 폴링한다. 이 시각으로 자리 비움을 판단한다(팔로업 메일).
    chat_model.touch_visitor_seen(conversation.id)

    data = {
        'online': availability['online'],
        'availability': _present_availability(org, availability, chat_model.has_visitor_message(conversation.id)),
        'messages': [chat_model.present(m) for m in chat_model.list_messages(conversation.id, after_id)],
    }

    # 실시간 서버가 토큰을 거절(만료)했다고 위젯이 알리면 새 구독 토큰을 내준다. 요청할 때만 넣는다.
    if query.get('realtime') == '1':
        data['realtime'] = realtime.client_config({'role': 'visitor', 'org_id': org.id, 'conversation_id': conversation.id})

    return reply(True, '', data)


# ------------------------------------------------------------------ 내부

def _suggest_guide(org, conversation, body):
    """조직이 켜 두었으면 방문자 질문에 맞는 가이드를 시스템 카드로 하나 붙인다(docs/guide-plan.md 6-2).

    고객에게 바로 보이므로 기준 점수가 높고, 같은 글은 한 번만, 대화당 3번까지. 저장만 하고 발행은 send() 가 함께 한다.
    """
    if php_int(org.guide_autosuggest) != 1:
        return

    sent, auto = [], 0
    for card in chat_model.guide_cards(conversation.id):
        sent.append(card['article_id'])
        auto += 1 if card['sender'] == 'system' else 0

    if auto >= 3:
        return

    items = guide_matcher.match(org.id, body, 'widget', 1, sent)
    if not items:
        return

    card = guide_model.card(org, items[0])
    chat_model.add_message(conversation.id, 'system',
                           lang_text('widget.guide_suggest') + '\n' + php_str(card['title']) + '\n' + card['url'], None, card)


def _auto_reply(org, conversation, availability):
    """운영 시간 외이거나 응대 가능한 상담원이 없으면 방문자에게 자동 응답을 남긴다(F4). 같은 상황에서는 한 번만."""
    if availability['state'] == 'open':
        return

    if availability['state'] == 'away':
        kind, custom = 'away', org.chat_msg_away
    elif availability['reason'] == 'holiday':
        kind, custom = 'holiday', org.chat_msg_holiday
    else:
        kind, custom = 'closed', org.chat_msg_closed

    if not chat_model.claim_auto_reply(conversation.id, kind):
        return

    nxt = chat_hours_model.format_moment(availability['next_open_at'], org)
    params = {
        'next_open': nxt if nxt != '' else lang_text('chat.next_open_unknown'),
        'holiday': php_str(availability['holiday']),
    }

    if php_str(custom).strip(' \t\n\r\x00\x0b') != '':
        text = php_str(custom)
        # PHP strtr(배열): 한 번 훑으며 바꾼다.
        reps = {'{next_open}': params['next_open'], '{holiday}': params['holiday']}
        text = re.sub(r'\{next_open\}|\{holiday\}', lambda m: reps[m.group(0)], text)
    else:
        # 다음 운영 시각을 모르면(앞으로 2주 안에 운영일이 없음) 시각 없는 문구를 쓴다.
        key = 'widget.auto.' + kind + ('_nonext' if kind != 'away' and nxt == '' else '')
        text = lang_text(key, params)

    chat_model.add_message(conversation.id, 'system', text, None, {'type': 'auto_reply', 'kind': kind})


def _resume_visitor_key(org, data):
    """팔로업 메일의 이어 가기 주소로 왔으면(resume 토큰) 그 대화의 방문자 키를 쓴다. 틀리면 브라우저에 저장된 키."""
    token = php_str(data.get('resume', ''))
    if token != '':
        claims = chat_followup.verify(token)
        conversation = chat_model.get_conversation(claims['conversation_id']) if claims else None
        if (conversation is not None and int(conversation.org_id) == int(org.id)
                and int(conversation.visitor_id) == claims['visitor_id']):
            visitor = visitor_model.get_by_id(claims['visitor_id'])
            if visitor is not None:
                return visitor.visitor_key
    return data.get('visitor_key')


def _contact_state(org, visitor, conversation, talking):
    """연락처 카드 상태. 위젯은 이 값대로만 그린다(fields, required, ask, notice, countries, country)."""
    fields = visitor_model.asked_fields(org)
    if not fields:
        return {'fields': [], 'required': False, 'ask': False}

    missing = visitor_model.missing_fields(org, visitor)
    required = not talking and php_int(org.chat_contact_required) == 1 and bool(missing)
    ask = required or (talking and bool(missing) and php_empty(conversation.contact_asked_at))
    notice = php_str(org.chat_contact_notice).strip(' \t\n\r\x00\x0b')
    codes = country_dial_codes()

    return {
        'fields': fields,
        'required': required,
        'ask': ask,
        'notice': notice if notice != '' else lang_text('widget.contact_notice'),
        'countries': [{'code': code, 'dial': '+' + dial} for code, dial in codes.items()],
        'country': org.country if org.country in codes else 'KR',
    }


def _contact_blocking(org, visitor):
    """'연락처를 남긴 고객에게만' 인데 묻는 항목이 비어 있으면 True."""
    return (php_int(org.chat_contact_required) == 1 and bool(visitor_model.asked_fields(org))
            and bool(visitor_model.missing_fields(org, visitor)))


def _accepting(org, availability, talking):
    """운영 시간 외 '안내만' / '숨기기' 에서는 새 문의를 받지 않는다. 이미 대화 중인 방문자는 계속 쓴다."""
    return availability['state'] != 'closed' or org.chat_offhours_mode == 'accept' or talking


def _present_availability(org, availability, talking):
    """위젯에 보낼 운영 상태. 다음 운영 시각은 조직 시간대 문구로(CLAUDE.md 3.4).

    답변 속도는 상담 가능할 때만, 전화 번호는 조직이 켰고 (운영 시간에만이면) 운영 중일 때만 보낸다.
    """
    minutes = chat_model.reply_minutes(org) if availability['state'] == 'open' else None
    phone_text = php_str(org.phone).strip(' \t\n\r\x00\x0b')
    phone = (php_int(org.chat_phone_button) == 1 and phone_text != ''
             and (php_int(org.chat_phone_hours_only) != 1 or availability['state'] != 'closed'))

    return {
        'state': availability['state'],
        'reason': availability['reason'],
        'holiday': availability['holiday'],
        'next_open': chat_hours_model.format_moment(availability['next_open_at'], org),
        'accepting': _accepting(org, availability, talking),
        'reply': '' if minutes is None else lang_text('widget.reply.' + str(minutes)),
        'phone': phone_text if phone else '',
    }


def _notify_if_offline(org, conversation, visitor, body, availability):
    """응대할 수 있는 상담원이 없으면(운영 시간 외 포함) 조직에 메일로 알린다. 같은 대화에서 두 번 보내지 않는다."""
    if availability['state'] == 'open' or not mailer.is_configured():
        return

    if not chat_model.claim_offline_notice(conversation.id):
        return

    to = org.widget_offline_email
    if php_empty(to):
        to = agent_model.admin_email(org.id)
    if php_empty(to):
        return

    params = {
        'org': org.name,
        'name': visitor.name if not php_empty(visitor.name) else '-',
        'email': visitor.email if not php_empty(visitor.email) else '-',
        'page': visitor.page_url if not php_empty(visitor.page_url) else '-',
        'body': body,
        'console': base_url('console/chat'),
    }
    mailer.send(to, lang_text('widget.mail.subject', params, org.locale), lang_text('widget.mail.body', params, org.locale))
