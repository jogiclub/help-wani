"""
파일 위치: backend/app/core/php.py
역할: PHP 와 결과가 같아야 하는 기본 함수 (json_encode, date 형식)

화면에 넣는 JSON 과 서명 토큰 본문이 PHP 와 한 글자라도 다르면 서명이 맞지 않거나 화면이 달라진다.
"""
import json
import math
import re
from datetime import datetime, timezone

# PHP 는 JSON_UNESCAPED_UNICODE 가 없어도 DEL(\x7f)을 \u007f 로 바꾸지 않는다. 앞의 역슬래시 개수가 짝수일 때만 진짜 이스케이프다.
_DEL_ESCAPE = re.compile(r'(?<!\\)((?:\\\\)*)\\u007f')


def _php_numbers(value):
    """PHP 는 정수 값의 실수(1.0)를 1 로 쓴다. 그런 실수만 int 로 바꾼다.

    남는 차이: 지수 표기(PHP 1.0e+20 / 여기 1e+20)와 -0.0(PHP -0). DB 값은 문자열로 오므로 계산한 실수에만 해당한다.
    """
    if isinstance(value, float):
        if value.is_integer() and abs(value) < 1e15 and not (value == 0 and math.copysign(1, value) < 0):
            return int(value)
        return value
    if isinstance(value, dict):
        return {k: _php_numbers(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_php_numbers(v) for v in value]
    return value


def php_json_encode(value, unescaped_unicode=False, unescaped_slashes=False):
    """PHP json_encode() 와 같은 문자열을 만든다.

    - 기본으로 '/' 를 '\\/' 로 바꾼다. <script> 안에 넣어도 '</script>' 로 닫히지 않는다.
    - JSON_UNESCAPED_UNICODE 가 없으면 한글 등을 \\uXXXX(소문자 16진수)로 바꾼다.
    - JSON_UNESCAPED_UNICODE 여도 U+2028, U+2029 는 바꾼다(PHP 7.1 이후 기본 동작).
    """
    encoded = json.dumps(_php_numbers(value), ensure_ascii=not unescaped_unicode, separators=(',', ':'))

    if not unescaped_unicode:
        encoded = _DEL_ESCAPE.sub(lambda m: m.group(1) + '\x7f', encoded)

    if not unescaped_slashes:
        # '/' 는 JSON 문법 기호가 아니므로 문자열 안에서만 나온다.
        encoded = encoded.replace('/', '\\/')

    if unescaped_unicode:
        encoded = encoded.replace('\u2028', '\\u2028').replace('\u2029', '\\u2029')

    return encoded


_DAY_SHORT = ('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun')
_DAY_LONG = ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
_MONTH_SHORT = ('Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec')
_MONTH_LONG = ('January', 'February', 'March', 'April', 'May', 'June', 'July', 'August',
               'September', 'October', 'November', 'December')


def php_date(fmt, moment=None):
    """PHP date() 형식 문자로 시각을 쓴다. 포털에서 쓰는 문자만 지원하고, 모르는 문자는 오류를 낸다.

    moment 가 없으면 지금 UTC (PHP date.timezone = UTC 와 같음).
    """
    if moment is None:
        moment = datetime.now(timezone.utc)

    hour12 = moment.hour % 12 or 12
    table = {
        'Y': f'{moment.year:04d}', 'y': f'{moment.year % 100:02d}',
        'm': f'{moment.month:02d}', 'n': str(moment.month),
        'd': f'{moment.day:02d}', 'j': str(moment.day),
        'H': f'{moment.hour:02d}', 'G': str(moment.hour),
        'h': f'{hour12:02d}', 'g': str(hour12),
        'i': f'{moment.minute:02d}', 's': f'{moment.second:02d}',
        'A': 'AM' if moment.hour < 12 else 'PM', 'a': 'am' if moment.hour < 12 else 'pm',
        'D': _DAY_SHORT[moment.weekday()], 'l': _DAY_LONG[moment.weekday()],
        'N': str(moment.isoweekday()), 'w': str(moment.isoweekday() % 7),
        'M': _MONTH_SHORT[moment.month - 1], 'F': _MONTH_LONG[moment.month - 1],
        'U': str(int(moment.timestamp())),
    }

    out = []
    chars = iter(fmt)
    for ch in chars:
        if ch == '\\':
            out.append(next(chars, ''))
        elif ch in table:
            out.append(table[ch])
        elif ch.isalpha():
            raise ValueError(f'php_date: 지원하지 않는 형식 문자 {ch!r} (필요하면 table 에 추가)')
        else:
            out.append(ch)

    return ''.join(out)


def utc_now_str():
    """DB 에 넣는 지금 시각 'Y-m-d H:i:s' (PHP date('Y-m-d H:i:s'), date.timezone = UTC)."""
    return php_date('Y-m-d H:i:s')


def htmlspecialchars(value):
    """PHP htmlspecialchars(ENT_QUOTES, UTF-8) / CI3 html_escape() 와 같은 결과. 작은따옴표는 &#039;."""
    return (str(value).replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
            .replace('"', '&quot;').replace("'", '&#039;'))
