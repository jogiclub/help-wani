"""
파일 위치: backend/app/core/i18n.py
역할: 서버 측 번역과 타임존 변환 (PHP i18n_helper 대응, JS 와 같은 사전 파일 portal/assets/lang/*.json 을 쓴다)
"""
import json
import re
from datetime import datetime, timezone
from zoneinfo import ZoneInfo

from app import config
from app.core.php import php_date
from app.core.request import current_request


def supported_locales():
    """지원 언어 목록."""
    return {
        'ko': {'name': '한국어', 'intl': 'ko-KR'},
        'en': {'name': 'English', 'intl': 'en-US'},
        'ja': {'name': '日本語', 'intl': 'ja-JP'},
    }


def supported_countries():
    """가입 화면에서 고르는 국가. 국가를 고르면 언어와 타임존 기본값이 함께 정해진다."""
    return {
        'KR': {'name': '대한민국', 'locale': 'ko', 'timezone': 'Asia/Seoul'},
        'JP': {'name': '일본', 'locale': 'ja', 'timezone': 'Asia/Tokyo'},
        'US': {'name': '미국', 'locale': 'en', 'timezone': 'America/New_York'},
        'SG': {'name': '싱가포르', 'locale': 'en', 'timezone': 'Asia/Singapore'},
        'VN': {'name': '베트남', 'locale': 'en', 'timezone': 'Asia/Ho_Chi_Minh'},
        'ID': {'name': '인도네시아', 'locale': 'en', 'timezone': 'Asia/Jakarta'},
        'AU': {'name': '호주', 'locale': 'en', 'timezone': 'Australia/Sydney'},
        'GB': {'name': '영국', 'locale': 'en', 'timezone': 'Europe/London'},
        'DE': {'name': '독일', 'locale': 'en', 'timezone': 'Europe/Berlin'},
    }


def country_dial_codes():
    """위젯 연락처 카드의 휴대폰 국가번호. 키는 supported_countries() 와 같다(허용 목록)."""
    return {
        'KR': '82', 'JP': '81', 'US': '1', 'SG': '65', 'VN': '84',
        'ID': '62', 'AU': '61', 'GB': '44', 'DE': '49',
    }


def supported_timezones():
    """선택 가능한 타임존. 값은 IANA 이름이다."""
    return {
        'Asia/Seoul': '서울 (UTC+9)',
        'Asia/Tokyo': '도쿄 (UTC+9)',
        'Asia/Shanghai': '상하이 (UTC+8)',
        'Asia/Singapore': '싱가포르 (UTC+8)',
        'Asia/Jakarta': '자카르타 (UTC+7)',
        'Asia/Ho_Chi_Minh': '호치민 (UTC+7)',
        'Asia/Kolkata': '콜카타 (UTC+5:30)',
        'Asia/Dubai': '두바이 (UTC+4)',
        'Europe/London': '런던 (UTC+0/+1)',
        'Europe/Berlin': '베를린 (UTC+1/+2)',
        'America/New_York': '뉴욕 (UTC-5/-4)',
        'America/Chicago': '시카고 (UTC-6/-5)',
        'America/Los_Angeles': '로스앤젤레스 (UTC-8/-7)',
        'Australia/Sydney': '시드니 (UTC+10/+11)',
        'UTC': 'UTC',
    }


# 사전 캐시. 파일을 고치면 수정 시각이 바뀌어 다시 읽는다(PHP 는 요청마다 읽음).
_messages_cache = {}


def load_messages(locale):
    """사전 파일을 읽는다. 없는 언어는 한국어로 되돌린다."""
    lang_dir = config.ASSETS_DIR / 'lang'
    path = lang_dir / (re.sub(r'[^a-z_-]', '', str(locale or '')) + '.json')
    if not path.is_file():
        path = lang_dir / 'ko.json'

    mtime = path.stat().st_mtime
    cached = _messages_cache.get(path)
    if cached and cached[0] == mtime:
        return cached[1]

    try:
        decoded = json.loads(path.read_text(encoding='utf-8'))
    except ValueError:
        decoded = {}
    messages = decoded if isinstance(decoded, dict) else {}
    _messages_cache[path] = (mtime, messages)
    return messages


def current_locale():
    """지금 요청의 화면 언어. 요청 밖이면 한국어."""
    request = current_request.get()
    return getattr(request.state, 'locale', 'ko') if request is not None else 'ko'


def current_timezone():
    """지금 요청의 화면 타임존. 요청 밖이면 서울."""
    request = current_request.get()
    return getattr(request.state, 'timezone', 'Asia/Seoul') if request is not None else 'Asia/Seoul'


def lang_text(key, params=None, locale=None):
    """번역 문구. 사전에 없으면 키를 그대로 돌려준다."""
    messages = load_messages(locale or current_locale())
    text = messages.get(key, key)
    if not isinstance(text, str):
        text = key

    for name, value in (params or {}).items():
        text = text.replace('{' + str(name) + '}', str(value))

    return text


def _parse_utc(value):
    """DB 의 'Y-m-d H:i:s' / 'Y-m-d' 문자열(또는 datetime)을 UTC 시각으로."""
    if isinstance(value, datetime):
        moment = value
    else:
        moment = datetime.fromisoformat(str(value).strip())
    if moment.tzinfo is None:
        moment = moment.replace(tzinfo=timezone.utc)
    return moment


def to_timezone(utc_datetime, tz='Asia/Seoul', fmt='Y-m-d H:i'):
    """UTC 로 저장된 값을 조직 타임존 문자열로 바꾼다. 비어 있으면 '-', 해석할 수 없으면 원래 값."""
    if not utc_datetime or utc_datetime == '0':
        return '-'

    try:
        return php_date(fmt, _parse_utc(utc_datetime).astimezone(ZoneInfo(tz)))
    except (ValueError, KeyError, OSError):
        return str(utc_datetime)


def timezone_offset_label(tz):
    """'Asia/Seoul' -> 'UTC+09:00' 형태의 현재 오프셋."""
    try:
        offset = int(datetime.now(ZoneInfo(tz)).utcoffset().total_seconds())
    except (ValueError, KeyError, OSError):
        return 'UTC'

    sign = '-' if offset < 0 else '+'
    offset = abs(offset)
    return 'UTC%s%02d:%02d' % (sign, offset // 3600, (offset % 3600) // 60)
