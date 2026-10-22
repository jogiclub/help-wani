"""
파일 위치: backend/app/models/visitor_model.py
역할: 상담 위젯 방문자 식별과 정보 갱신, 연락처 카드 입력 검사와 저장 (PHP models/Visitor_model.php 대응)

visitors 는 이 모델만 고친다. 입력 검사는 clean_contact() 하나, 휴대폰은 국가번호 허용 목록으로 E.164 로 저장한다(CLAUDE.md 3.18).
"""
import re
import secrets

from app import db
from app.core.i18n import country_dial_codes
from app.core.php import php_int, php_str, utc_str
from app.helpers.validation import valid_email

TABLE = 'visitors'

# 연락처 카드에서 받을 수 있는 항목
CONTACT_FIELDS = ('name', 'email', 'phone')

_KEY_RE = re.compile(r'^[a-f0-9]{64}$')


def _trim(text):
    """PHP trim() 기본 글자."""
    return text.strip(' \t\n\r\x00\x0b')


def get_by_id(visitor_id):
    return db.first_where(TABLE, {'id': php_int(visitor_id)})


def get_by_key(org_id, visitor_key):
    """방문자 키로 조회한다. 조직이 다르면 남의 대화이므로 돌려주지 않는다."""
    if not _KEY_RE.match(php_str(visitor_key)):
        return None
    return db.first_where(TABLE, {'org_id': int(org_id), 'visitor_key': visitor_key})


def ensure(org_id, visitor_key, meta=None):
    """방문자를 찾거나 새로 만든다. 키가 없거나 위조면 새 키를 발급한다."""
    meta = meta or {}
    visitor = get_by_key(org_id, visitor_key)

    info = {
        'ip': meta.get('ip'),
        'user_agent': php_str(meta['user_agent'])[:255] if meta.get('user_agent') is not None else None,
        'page_url': php_str(meta['page_url'])[:500] if meta.get('page_url') is not None else None,
    }

    if visitor is not None:
        db.update(TABLE, info, {'id': int(visitor.id)})
        return get_by_id(visitor.id)

    info['org_id'] = int(org_id)
    info['visitor_key'] = secrets.token_hex(32)
    return get_by_id(db.insert(TABLE, info))


def update_profile(visitor_id, name, email):
    """방문자가 남긴 이름과 이메일을 저장한다(예전 위젯 호환)."""
    data = {}
    if name is not None and _trim(php_str(name)) != '':
        data['name'] = _trim(php_str(name))[:60]
    if email is not None and valid_email(php_str(email)):
        data['email'] = php_str(email)[:190]
    if not data:
        return 0
    return db.update(TABLE, data, {'id': int(visitor_id)})


# ------------------------------------------------------------------ 연락처 카드

def asked_fields(org):
    """조직이 묻는 항목. 순서는 카드에 그리는 순서다."""
    return [field for field in CONTACT_FIELDS if php_int(org.get('chat_ask_' + field)) == 1]


def missing_fields(org, visitor):
    """묻는 항목 중 비어 있는 것. '연락처를 남긴 고객에게만' 이면 이것이 비어야 문의를 받는다."""
    return [field for field in asked_fields(org) if _trim(php_str(visitor.get(field))) == '']


def clean_contact(org, data_in, required):
    """연락처 카드 입력 검사. 조직이 묻지 않는 항목은 버린다.

    필수 모드면 묻는 항목을 모두, 아니면 하나 이상 받아야 한다. 오류 문구는 고객이 읽으므로 사전 키로 돌려준다.
    결과: {'ok': 저장할 값} 또는 {'error': 사전 키, 'field': 항목}
    """
    asked = asked_fields(org)
    data = {}

    if 'name' in asked:
        # PHP preg_replace('/[\x00-\x1F\x7F]+/u', ' ', ...)
        name = _trim(re.sub(r'[\x00-\x1f\x7f]+', ' ', php_str(data_in.get('name'))))
        if name != '':
            data['name'] = name[:60]

    if 'email' in asked:
        email = _trim(php_str(data_in.get('email')))
        if email != '':
            if len(email) > 190 or not valid_email(email):
                return {'error': 'widget.contact_bad_email', 'field': 'email'}
            data['email'] = email

    if 'phone' in asked:
        raw = _trim(php_str(data_in.get('phone')))
        if raw != '':
            phone = normalize_phone(data_in.get('phone_country') if data_in.get('phone_country') is not None else '', raw)
            if phone is None:
                return {'error': 'widget.contact_bad_phone', 'field': 'phone'}
            data['phone'] = phone

    for field in asked:
        if required and field not in data:
            return {'error': 'widget.contact_need_all', 'field': field}

    if not data:
        return {'error': 'widget.contact_need_one', 'field': asked[0] if asked else ''}

    return {'ok': data}


def normalize_phone(country, raw):
    """휴대폰 번호를 E.164(+821012345678)로 바꾼다. 국가번호는 허용 목록(country_dial_codes)만 받는다.

    +로 시작해 적었으면 그 국가번호가 목록에 있어야 한다. 국내 번호 앞의 0 은 뗀다.
    """
    codes = country_dial_codes()
    digits = re.sub(r'[\s\-().]', '', php_str(raw))

    # PHP '/^\+?[0-9]+$/' 의 $ 는 끝 줄바꿈 앞에서도 맞는다(공백은 위에서 지워져 남지 않는다).
    if not re.match(r'^\+?[0-9]+$', digits):
        return None

    if digits[0] == '+':
        digits = digits[1:]
        dial = None
        # 국가번호는 1~3자리. 긴 것부터 맞춰 본다.
        for length in (3, 2, 1):
            if digits[:length] in codes.values():
                dial = digits[:length]
                break
        if dial is None:
            return None
        national = digits[len(dial):]
    else:
        country = php_str(country)
        if country not in codes:
            return None
        dial = codes[country]
        national = digits.lstrip('0')

    if len(national) < 6 or len(dial) + len(national) > 15:
        return None

    return '+' + dial + national


def save_contact(visitor_id, data):
    """연락처를 저장하고 수집 안내를 본 시각을 남긴다."""
    data = {k: v for k, v in data.items() if k in CONTACT_FIELDS}
    data['contact_consent_at'] = utc_str()
    db.update(TABLE, data, {'id': int(visitor_id)})
