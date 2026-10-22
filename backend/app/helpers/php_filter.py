"""
파일 위치: backend/app/helpers/php_filter.py
역할: PHP filter_var(FILTER_VALIDATE_URL) 대응 — https 주소 검사 (인사말 바로가기 버튼, 가이드 외부 이미지)

php-src php-8.2.34 의 ext/filter/logical_filters.c(php_filter_validate_url, _php_filter_validate_domain, is_userinfo_valid),
ext/filter/sanitizing_filters.c(php_filter_url), ext/standard/url.c(php_url_parse_ex2 의 호스트 해석)를 옮겼다.
두 호출처 모두 먼저 '#^https://[^\\s/?\\#]+[^\\s]*$#i' 를 통과한 값만 넘기므로 그 범위(https 주소)만 다룬다.
"""
import ipaddress
import re
import string

_HTTPS_RE = re.compile(r'^https://[^\s/?#]+[^\s]*$', re.IGNORECASE)

# FILTER_SANITIZE_URL 이 남기는 글자. 하나라도 지워지면(길이가 바뀌면) 검사 실패다.
_URL_SAFE = set(string.ascii_letters + string.digits + '$-_.+' + "!*'()," + '{}|\\^~[]`' + '<>#%"' + ';/?:@&=')

_USERINFO_VALID = set("-._~!$&'()*+,;=:")
_ALNUM = set(string.ascii_letters + string.digits)


def _userinfo_valid(text):
    i = 0
    while i < len(text):
        ch = text[i]
        if ch in _ALNUM or ch in _USERINFO_VALID:
            i += 1
        elif ch == '%' and i <= len(text) - 3 and text[i + 1] in string.digits and text[i + 2] in string.hexdigits:
            i += 3
        else:
            return False
    return True


def _domain_valid(domain):
    """_php_filter_validate_domain(..., FILTER_FLAG_HOSTNAME). 끝의 NUL 을 흉내 내려고 '\\0' 을 덧붙여 본다."""
    s = domain + '\0'
    length = len(domain)
    end = length

    # 끝 점 하나는 무시한다.
    if length > 0 and domain[-1] == '.':
        end -= 1
        length -= 1

    if length > 253:
        return False

    # 첫 글자는 영숫자
    if s[0] == '.' or s[0] not in _ALNUM:
        return False

    label = 1
    i = 0
    while i < end:
        ch = s[i]
        if ch == '.':
            # 라벨의 처음과 끝은 영숫자
            if s[i + 1] == '.' or (i == 0 or s[i - 1] not in _ALNUM) or s[i + 1] not in _ALNUM:
                return False
            label = 1
        else:
            if label > 63 or ((ch != '-' or s[i + 1] == '\0') and ch not in _ALNUM):
                return False
            label += 1
        i += 1

    return True


def _ipv6_hostname_valid(host):
    if len(host) < 2 or host[0] != '[' or host[-1] != ']':
        return False
    inner = host[1:-1]
    if '%' in inner:
        return False
    try:
        ipaddress.IPv6Address(inner)
        return True
    except ValueError:
        return False


def validate_https_url(url):
    """filter_var($url, FILTER_VALIDATE_URL) !== FALSE 와 같은 판정(https 주소만)."""
    url = str(url)
    if not _HTTPS_RE.match(url):
        return False

    # php_filter_url: 허용 밖 글자가 지워지면 길이가 달라져 실패
    if any(ch not in _URL_SAFE for ch in url):
        return False

    rest = url[len('https://'):]
    end = len(rest)
    for stop in '/?#':
        found = rest.find(stop)
        if found != -1:
            end = min(end, found)
    authority = rest[:end]

    user = password = None
    at = authority.rfind('@')
    if at != -1:
        userinfo = authority[:at]
        colon = userinfo.find(':')
        if colon != -1:
            user, password = userinfo[:colon], userinfo[colon + 1:]
        else:
            user = userinfo
        authority = authority[at + 1:]

    if authority.startswith('[') and authority.endswith(']'):
        host = authority
    else:
        colon = authority.rfind(':')
        if colon != -1:
            port_text = authority[colon + 1:]
            if len(port_text) > 5:
                return False
            if len(port_text) > 0:
                # strtol: 앞의 부호와 숫자만 읽고 나머지는 버린다. 숫자가 없거나 범위 밖이면 parse_url 실패.
                match = re.match(r'[+-]?[0-9]+', port_text)
                if not match or not (0 <= int(match.group(0)) <= 65535):
                    return False
            host = authority[:colon]
        else:
            host = authority

    if len(host) < 1:
        return False

    if not _ipv6_hostname_valid(host) and not _domain_valid(host):
        return False

    if (user is not None and not _userinfo_valid(user)) or (password is not None and not _userinfo_valid(password)):
        return False

    return True
