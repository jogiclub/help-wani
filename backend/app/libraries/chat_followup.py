"""
파일 위치: backend/app/libraries/chat_followup.py
역할: 팔로업 알림 — 고객이 대화창을 떠난 뒤 상담원이 답하면 고객 이메일로 알리고, 대화를 이어 갈 주소(서명 토큰)를 만든다
      (PHP libraries/Chat_followup.php 대응, docs/widget-ops-plan.md 3-4 ~ 3-6)

예약 작업(cron)이 없으므로 상담원 답장을 저장하는 요청 안에서 바로 판단한다(console.publish_new_messages, CLAUDE.md 3.18).
토큰 본문은 PHP json_encode() 와 같은 바이트로 만든다(전환 전에 보낸 메일의 주소도 열려야 한다).
"""
import base64
import binascii
import hashlib
import hmac
import json
import re
import time

from app import config
from app.core.i18n import lang_text
from app.core.php import php_empty, php_int, php_json_encode, php_str
from app.helpers.app import customer_url
from app.helpers.validation import valid_email
from app.libraries import mailer

# 이어 가기 주소 유효 기간(초)
RESUME_TTL = 1209600

# 메일에 넣는 답장 앞부분 길이
EXCERPT_MAX = 200

_TOKEN_RE = re.compile(r'^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$')


def _secret():
    """서명 키. CHAT_RESUME_SECRET 이 없으면 실시간 토큰 키에서 용도를 나눠 만든다. 둘 다 없으면 ''."""
    secret = php_str(config.env('CHAT_RESUME_SECRET', ''))
    if secret != '':
        return secret
    base = php_str(config.env('REALTIME_TOKEN_SECRET', ''))
    # PHP hash_hmac('sha256', 'chat-resume', $base) 는 16진수 문자열이다.
    return '' if base == '' else hmac.new(base.encode('utf-8'), b'chat-resume', hashlib.sha256).hexdigest()


def _base64url(data):
    return base64.b64encode(data).decode('ascii').translate(str.maketrans('+/', '-_')).rstrip('=')


def _sign(body):
    return _base64url(hmac.new(_secret().encode('utf-8'), body.encode('utf-8'), hashlib.sha256).digest())


def issue(conversation):
    """대화와 방문자를 담은 서명 토큰. visitor_key 자체는 메일에 넣지 않는다."""
    body = _base64url(php_json_encode({
        'c': int(conversation['id']),
        'v': int(conversation['visitor_id']),
        'e': int(time.time()) + RESUME_TTL,
    }).encode('utf-8'))
    return body + '.' + _sign(body)


def verify(token):
    """토큰을 확인해 {'conversation_id', 'visitor_id'} 를 돌려준다. 틀리거나 만료면 None."""
    token = php_str(token)
    if _secret() == '' or not _TOKEN_RE.match(token) or len(token) > 300:
        return None

    body, signature = token.split('.', 1)
    if not hmac.compare_digest(_sign(body).encode('ascii'), signature.encode('ascii')):
        return None

    text = body.translate(str.maketrans('-_', '+/'))
    try:
        payload = json.loads(base64.b64decode(text + '=' * (-len(text) % 4), validate=False) or b'null')
    except (binascii.Error, ValueError):
        return None

    if (not isinstance(payload, dict) or php_empty(payload.get('c')) or php_empty(payload.get('v'))
            or php_empty(payload.get('e'))):
        return None
    try:
        if float(payload['e']) < time.time():
            return None
    except (TypeError, ValueError):
        return None

    return {'conversation_id': php_int(payload['c']), 'visitor_id': php_int(payload['v'])}


def resume_url(org, conversation):
    """대화를 이어 갈 주소. {customer_url}/{조직코드}/chat/{token} (CLAUDE.md 3.13)"""
    return customer_url(org['org_code'] + '/chat/' + issue(conversation))


def after_agent_message(conversation, message_body):
    """상담원 답장을 저장한 뒤 부른다. 보냈으면 True. 메일이 실패해도 예외를 던지지 않는다."""
    from app.models import chat_model, organization_model, visitor_model

    org = organization_model.get_by_id(conversation['org_id'])
    visitor = visitor_model.get_by_id(conversation['visitor_id'])

    if org is None or visitor is None or php_int(org.get('chat_followup_email')) != 1:
        return False

    if not valid_email(php_str(visitor.email)) or not mailer.is_configured() or _secret() == '':
        return False

    previous = chat_model.claim_followup(conversation['id'])
    if previous is False:
        return False

    body = php_str(message_body).strip(' \t\n\r\x00\x0b')
    if len(body) > EXCERPT_MAX:
        body = body[:EXCERPT_MAX] + '...'

    # 고객이 읽는 메일이므로 조직 언어로 쓴다(CLAUDE.md 3.2).
    name = php_str(visitor.name).strip(' \t\n\r\x00\x0b')
    params = {
        'org': org.name,
        'name': visitor.name if name != '' else lang_text('macro.visitor_fallback', {}, org.locale),
        'body': body,
        'url': resume_url(org, conversation),
        'days': RESUME_TTL // 86400,
    }

    sent = mailer.send(visitor.email,
                       lang_text('widget.followup.subject', params, org.locale),
                       lang_text('widget.followup.body', params, org.locale),
                       {'from_name': org.name, 'reply_to': php_str(org.widget_offline_email)})

    if not sent:
        chat_model.release_followup(conversation['id'], previous)
        return False

    return True
