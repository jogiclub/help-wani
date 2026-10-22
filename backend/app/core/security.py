"""
파일 위치: backend/app/core/security.py
역할: CI3 입력 정리 — xss_clean(), remove_invisible_characters(), entity_decode() (system/core/Security.php, Common.php 대응)

전환 기간에 PHP 와 FastAPI 가 같은 DB 에 같은 값을 저장하도록 CI3 3.1 의 동작을 그대로 옮겼다(docs/fastapi-migration-plan.md 5.9).
- CI3 의 정규식은 UTF-8 모드(/u) 없이 바이트 단위로 돈다. 여기서도 UTF-8 바이트에 bytes 정규식을 써서
  \\s, \\w, 대소문자 무시가 PCRE 와 같은 범위(ASCII)가 되게 한다.
- html_entity_decode(ENT_COMPAT | ENT_HTML5) 의 이름 표는 PHP 8.2.34 에서 뽑은 data/php_html5_entities.json 을 쓴다.
- PCRE 의 (?U:x*) 는 x*? 로, (?<name>) 는 (?P<name>) 로, '[^\\1]' 는 '[^\\x01]'(PCRE 에서 \\1 은 클래스 안에서 8진수 1)로 옮겼다.
- 결과는 웹 컨테이너 PHP 의 CI_Security::xss_clean() 과 비교해 확인한다(계획 문서 2단계 결과).
"""
import json
import re
import secrets
from pathlib import Path
from urllib.parse import unquote_to_bytes

# ---------------------------------------------------------------- remove_invisible_characters

_INVISIBLE_URL = [re.compile(rb'%0[0-8bcef]', re.I), re.compile(rb'%1[0-9a-f]', re.I), re.compile(rb'%7f', re.I)]
_INVISIBLE_RAW = re.compile(rb'[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+')


def _remove_invisible(data, url_encoded=True):
    patterns = (_INVISIBLE_URL if url_encoded else []) + [_INVISIBLE_RAW]
    while True:
        count = 0
        for pattern in patterns:
            data, n = pattern.subn(b'', data)
            count += n
        if not count:
            return data


def remove_invisible_characters(value, url_encoded=True):
    """CI3 remove_invisible_characters(). 줄바꿈(10), 캐리지 리턴(13), 탭(9)을 뺀 제어 문자를 지운다."""
    return _decode(_remove_invisible(_encode(value), url_encoded))


# ---------------------------------------------------------------- entity_decode

_ENTITY_DATA = json.loads((Path(__file__).parent / 'data' / 'php_html5_entities.json').read_text(encoding='utf-8'))

# get_html_translation_table() 를 strtolower 한 표. array_search 는 표 순서로 처음 맞는 글자를 고른다.
_LOWER_TABLE = {}
for _char, _name in _ENTITY_DATA['translation_table']:
    _LOWER_TABLE.setdefault(_name.lower().encode('utf-8'), _char.encode('utf-8'))

# html_entity_decode(&name;) 결과(이름은 대소문자 구분)
_NAMED = {name.encode('ascii'): value.encode('utf-8') for name, value in _ENTITY_DATA['named_decode'].items()}

_SHORT_ENTITY = re.compile(rb'&[a-z]{2,}(?![a-z;])', re.I)
_NUMERIC_FIX = re.compile(rb'(&#(?:x0*[0-9a-f]{2,5}(?![0-9a-f;])|(?:0*\d{2,4}(?![0-9;]))))', re.I)
_ENTITY = re.compile(rb'&(?:#[xX]([0-9A-Fa-f]+);|#([0-9]+);|([A-Za-z0-9]+);)')


def _cp_allowed(cp):
    """PHP html_entity_decode(ENT_COMPAT | ENT_HTML5) 가 숫자 엔티티를 글자로 바꾸는 범위.

    PHP 8.2.34 에서 0 ~ 0x110010 전부를 확인해 얻은 규칙이다. 작은따옴표(39)는 ENT_COMPAT 라 바꾸지 않는다.
    """
    if cp in (0x09, 0x0A, 0x0C):
        return True
    if 0x20 <= cp <= 0x7E:
        return cp != 0x27
    if 0xA0 <= cp <= 0x10FFFF:
        if 0xD800 <= cp <= 0xDFFF or 0xFDD0 <= cp <= 0xFDEF or (cp & 0xFFFF) >= 0xFFFE:
            return False
        return True
    return False


def _html_entity_decode(data):
    def replace(match):
        hex_digits, dec_digits, name = match.groups()
        if name is not None:
            return _NAMED.get(name, match.group(0))
        try:
            cp = int(hex_digits, 16) if hex_digits is not None else int(dec_digits)
        except ValueError:
            return match.group(0)
        if not _cp_allowed(cp):
            return match.group(0)
        return chr(cp).encode('utf-8')

    return _ENTITY.sub(replace, data)


def _str_replace_many(data, pairs):
    """PHP str_replace(배열, 배열): 짝마다 차례로 전체를 바꾼다. 빈 찾기 문자열은 건너뛴다."""
    for search, replace in pairs:
        if search:
            data = data.replace(search, replace)
    return data


def _entity_decode(data):
    if b'&' not in data:
        return data

    while True:
        before = data

        # 세미콜론 없는 이름 엔티티(&lt 등). 찾은 것을 소문자로 바꿔 표에서 찾고, 대소문자를 구분해 바꾼다(PHP 와 같음).
        found = []
        for match in _SHORT_ENTITY.findall(data):
            lowered = match.lower()
            if lowered not in found:
                found.append(lowered)
        pairs = [(m, _LOWER_TABLE[m + b';']) for m in found if m + b';' in _LOWER_TABLE]
        data = _str_replace_many(data, pairs)

        # 숫자 엔티티에 빠진 세미콜론을 붙이고 디코드
        data = _html_entity_decode(_NUMERIC_FIX.sub(rb'\1;', data))

        if data == before:
            return data


def entity_decode(value):
    """CI3 Security::entity_decode() 대응."""
    return _decode(_entity_decode(_encode(value)))


# ---------------------------------------------------------------- xss_clean

_NEVER_ALLOWED_STR = [
    (b'document.cookie', b'[removed]'),
    (b'(document).cookie', b'[removed]'),
    (b'document.write', b'[removed]'),
    (b'(document).write', b'[removed]'),
    (b'.parentNode', b'[removed]'),
    (b'.innerHTML', b'[removed]'),
    (b'-moz-binding', b'[removed]'),
    (b'<!--', b'&lt;!--'),
    (b'-->', b'--&gt;'),
    (b'<![CDATA[', b'&lt;![CDATA['),
    (b'<comment>', b'&lt;comment&gt;'),
    (b'<%', b'&lt;&#37;'),
]

_NEVER_ALLOWED_REGEX = [re.compile(p, re.I | re.S) for p in (
    rb'javascript\s*:',
    rb'(\(?document\)?|\(?window\)?(\.document)?)\.(location|on\w*)',
    rb'expression\s*(\(|&\#40;)',
    rb'vbscript\s*:',
    rb'wscript\s*:',
    rb'jscript\s*:',
    rb'vbs\s*:',
    rb'Redirect\s+30\d',
    # PHP "([\"'])?data\s*:[^\\1]*?base64[^\\1]*?,[^\\1]*?\\1?" — 클래스 안의 \1 은 8진수 1(\x01)
    rb'(["\'])?data\s*:[^\x01]*?base64[^\x01]*?,[^\x01]*?\1?',
)]


def _do_never_allowed(data):
    data = _str_replace_many(data, _NEVER_ALLOWED_STR)
    for pattern in _NEVER_ALLOWED_REGEX:
        data = pattern.sub(b'[removed]', data)
    return data


_URL_SPACES = re.compile(rb'%(?:\s*[0-9a-f]){2,}', re.I)
_WS = re.compile(rb'\s+')


def _urldecodespaces(match):
    raw = match.group(0)
    nospaces = _WS.sub(b'', raw)
    return raw if nospaces == raw else unquote_to_bytes(nospaces)


_ATTR_CONVERT = re.compile(rb'[^a-z0-9>]+[a-z0-9]+=([\'"]).*?\1', re.S | re.I)
_TAG_REST = re.compile(rb'<\w+.*', re.S | re.I)
_GET_VARS = re.compile(rb'&([a-z_0-9\-]+)=([a-z_0-9\-/]+)', re.I)


def _convert_attribute(match):
    return _str_replace_many(match.group(0), [(b'>', b'&gt;'), (b'<', b'&lt;'), (b'\\', b'\\\\')])


_EXPLODED_WORDS = []
for _word in ('javascript', 'expression', 'vbscript', 'jscript', 'wscript', 'vbs', 'script', 'base64', 'applet',
              'alert', 'document', 'write', 'cookie', 'window', 'confirm', 'prompt', 'eval'):
    _spaced = rb'\s*'.join(re.escape(ch).encode('ascii') for ch in _word)
    _EXPLODED_WORDS.append(re.compile(rb'(' + _spaced + rb')(\W)', re.I | re.S))


def _compact_exploded_words(match):
    return _WS.sub(b'', match.group(1)) + match.group(2)


_FILTER_ATTRS = re.compile(rb'\s*[a-z\-]+\s*=\s*(\x22|\x27)([^\x01]*?)\1', re.I | re.S)
_CSS_COMMENT = re.compile(rb'/\*.*?\*/', re.S)


def _filter_attributes(data):
    return b''.join(_CSS_COMMENT.sub(b'', m.group(0)) for m in _FILTER_ATTRS.finditer(data))


_LINK = re.compile(rb'<a(?:rea)?[^a-z0-9>]+([^>]*?)(?:>|$)', re.S | re.I)
_LINK_JS = re.compile(rb'href=.*?(?:(?:alert|prompt|confirm)(?:\(|&\#40;|`|&\#96;)|javascript:|livescript:|mocha:|charset=|window\.|\(?document\)?\.|\.cookie|<script|<xss|d\s*a\s*t\s*a\s*:)', re.S | re.I)
_IMG = re.compile(rb'<img[^a-z0-9]+([^>]*?)(?:\s?/?>|$)', re.S | re.I)
_IMG_JS = re.compile(rb'src=.*?(?:(?:alert|prompt|confirm|eval)(?:\(|&\#40;|`|&\#96;)|javascript:|livescript:|mocha:|charset=|window\.|\(?document\)?\.|\.cookie|<script|<xss|base64\s*,)', re.S | re.I)
_HAS_A = re.compile(rb'<a', re.I)
_HAS_IMG = re.compile(rb'<img', re.I)
_HAS_SCRIPT = re.compile(rb'script|xss', re.I)
_SCRIPT_TAG = re.compile(rb'</*(?:script|xss).*?>', re.S | re.I)


def _js_link_removal(match):
    search = match.group(1)
    if not search:
        return match.group(0)
    return match.group(0).replace(search, _LINK_JS.sub(b'', _filter_attributes(search)))


def _js_img_removal(match):
    search = match.group(1)
    if not search:
        return match.group(0)
    return match.group(0).replace(search, _IMG_JS.sub(b'', _filter_attributes(search)))


_NAUGHTY = re.compile(
    rb'<((?P<slash>/*\s*)((?P<tagName>[a-z0-9]+)(?=[^a-z0-9]|$)|.+)'
    rb'[^\s\x22\x27a-z0-9>/=]*'
    rb'(?P<attributes>(?:[\s\x22\x27/=]*'
    rb'[^\s\x22\x27>/=]+'
    rb'(?:\s*='
    rb'(?:[^\s\x22\x27=><`]+|\s*\x22[^\x22]*\x22|\s*\x27[^\x27]*\x27|\s*(?:[^\s\x22\x27=><`]*?))'
    rb')?'
    rb')*)'
    rb'[^>]*)(?P<closeTag>\>)?',
    re.I | re.S)

_NAUGHTY_TAGS = {
    b'alert', b'area', b'prompt', b'confirm', b'applet', b'audio', b'basefont', b'base', b'behavior', b'bgsound',
    b'blink', b'body', b'embed', b'expression', b'form', b'frameset', b'frame', b'head', b'html', b'ilayer',
    b'iframe', b'input', b'button', b'select', b'isindex', b'layer', b'link', b'meta', b'keygen', b'object',
    b'plaintext', b'style', b'script', b'textarea', b'title', b'math', b'video', b'svg', b'xml', b'xss',
}

_ATTRIBUTE = re.compile(
    rb'(?P<name>[^\s\x22\x27>/=]+)'
    rb'(?:\s*=(?P<value>[^\s\x22\x27=><`]+|\s*\x22[^\x22]*\x22|\s*\x27[^\x27]*\x27|\s*(?:[^\s\x22\x27=><`]*?)))',
    re.I)
_EVIL_ATTRIBUTE = re.compile(rb'^(on\w+|style|xmlns|formaction|form|xlink:href|FSCommand|seekSegmentTime)$', re.I)
_LEADING_NON_ALPHA = re.compile(rb'^[^a-z]+', re.I)


def _php_trim(data):
    return data.strip(b' \t\n\r\x00\x0b')


def _sanitize_naughty_html(match):
    if not match.group('closeTag'):
        return b'&lt;' + match.group(1)

    tag_name = match.group('tagName') or b''
    if tag_name.lower() in _NAUGHTY_TAGS:
        return b'&lt;' + match.group(1) + b'&gt;'

    attributes_text = match.group('attributes')
    if attributes_text is None:
        return match.group(0)

    attributes = []
    while True:
        attributes_text = _LEADING_NON_ALPHA.sub(b'', attributes_text, count=1)
        attribute = _ATTRIBUTE.search(attributes_text)
        if attribute is None:
            break

        if _EVIL_ATTRIBUTE.search(attribute.group('name')) or _php_trim(attribute.group('value')) == b'':
            attributes.append(b'xss=removed')
        else:
            attributes.append(attribute.group(0))

        attributes_text = attributes_text[attribute.end():]
        if attributes_text == b'':
            break

    joined = b' ' + b' '.join(attributes) if attributes else b''
    return b'<' + (match.group('slash') or b'') + tag_name + joined + b'>'


_NAUGHTY_SCRIPT_CALL = re.compile(
    rb'(alert|prompt|confirm|cmd|passthru|eval|exec|expression|system|fopen|fsockopen|file|file_get_contents|readfile|unlink)(\s*)\((.*?)\)',
    re.S | re.I)
_NAUGHTY_SCRIPT_TICK = re.compile(
    rb'(alert|prompt|confirm|cmd|passthru|eval|exec|expression|system|fopen|fsockopen|file|file_get_contents|readfile|unlink)(\s*)`(.*?)`',
    re.S | re.I)

# CI3 xss_hash(): 요청마다 하나. 주소의 GET 변수(&a=b)를 엔티티 디코드에서 보호하는 표식이다.
_XSS_HASH = secrets.token_hex(16).encode('ascii')


def _xss_clean_bytes(data):
    data = _remove_invisible(data)

    # URL 디코드(rawurldecode 는 + 를 공백으로 바꾸지 않는다)
    if b'%' in data:
        while True:
            before = data
            data = unquote_to_bytes(data)
            data = _URL_SPACES.sub(_urldecodespaces, data)
            if data == before:
                break

    # 태그 안의 엔티티를 글자로
    data = _ATTR_CONVERT.sub(_convert_attribute, data)
    data = _TAG_REST.sub(
        lambda m: _entity_decode(_GET_VARS.sub(lambda g: _XSS_HASH + g.group(1) + b'=' + g.group(2), m.group(0)))
        .replace(_XSS_HASH, b'&'),
        data)

    data = _remove_invisible(data)
    data = data.replace(b'\t', b' ')

    data = _do_never_allowed(data)
    data = data.replace(b'<?', b'&lt;?').replace(b'?>', b'?&gt;')

    for pattern in _EXPLODED_WORDS:
        data = pattern.sub(_compact_exploded_words, data)

    while True:
        before = data
        if _HAS_A.search(data):
            data = _LINK.sub(_js_link_removal, data)
        if _HAS_IMG.search(data):
            data = _IMG.sub(_js_img_removal, data)
        if _HAS_SCRIPT.search(data):
            data = _SCRIPT_TAG.sub(b'[removed]', data)
        if data == before:
            break

    while True:
        before = data
        data = _NAUGHTY.sub(_sanitize_naughty_html, data)
        if data == before:
            break

    data = _NAUGHTY_SCRIPT_CALL.sub(rb'\1\2&#40;\3&#41;', data)
    data = _NAUGHTY_SCRIPT_TICK.sub(rb'\1\2&#96;\3&#96;', data)

    return _do_never_allowed(data)


def _encode(value):
    return str(value).encode('utf-8', 'surrogateescape')


def _decode(data):
    return data.decode('utf-8', 'surrogateescape')


def xss_clean(value):
    """CI3 Security::xss_clean() 대응. 배열(dict/list)은 값마다 적용하고 키는 그대로 둔다. None 은 None.

    URL 디코드로 잘못된 UTF-8 바이트(%BA 등)가 생기면 U+FFFD 로 바꾼다. PHP 는 그 바이트를 그대로 DB 에 보내
    MySQL 이 경고와 함께 잘라 내므로 저장 결과가 어차피 원문과 다르다(남는 차이로 기록).
    """
    if value is None:
        return None
    if isinstance(value, dict):
        return {key: xss_clean(item) for key, item in value.items()}
    if isinstance(value, list):
        return [xss_clean(item) for item in value]
    return _xss_clean_bytes(_encode(value)).decode('utf-8', 'replace')
