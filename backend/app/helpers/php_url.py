"""
파일 위치: backend/app/helpers/php_url.py
역할: PHP parse_url(), parse_str() 대응 — 유튜브 주소 해석(가이드 동영상 블록)과 외부 이미지 주소의 호스트·포트

php-src php-8.2.34 ext/standard/url.c 의 php_url_parse_ex2() 를 그대로 옮겼다(C 로케일의 isalpha/isdigit/iscntrl).
parse_str() 은 main/php_variables.c 의 문자열 처리(& 로 나누고 urldecode, 이름 괄호 규칙)를 따른다.
"""
from urllib.parse import unquote_plus

from app.core.input import _set_php_key


def _isalpha(ch):
    return ('a' <= ch <= 'z') or ('A' <= ch <= 'Z')


def _isdigit(ch):
    return '0' <= ch <= '9'


def _cntrl(text):
    """php_replace_controlchars_ex(): 제어 문자(0~31, 127)를 '_' 로."""
    return ''.join('_' if (ord(ch) < 32 or ord(ch) == 127) else ch for ch in text)


def _strcspn(text, start, end, chars):
    """binary_strcspn(): start~end 안에서 chars 중 하나가 처음 나오는 위치(없으면 end)."""
    for ch in chars:
        found = text.find(ch, start, end)
        if found != -1:
            end = found
    return end


def _strtol_port(text):
    """ZEND_STRTOL(port_buf, &end, 10). 숫자를 하나도 못 읽으면 None."""
    i = 0
    while i < len(text) and text[i] in ' \t\n\v\f\r':
        i += 1
    sign = 1
    if i < len(text) and text[i] in '+-':
        sign = -1 if text[i] == '-' else 1
        i += 1
    j = i
    while j < len(text) and _isdigit(text[j]):
        j += 1
    if j == i:
        return None
    return sign * int(text[i:j])


def parse_url(url):
    """PHP parse_url($url) 대응. 실패하면 None(PHP FALSE), 성공하면 있는 항목만 담은 dict.

    키: scheme, host, port, user, pass, path, query, fragment (PHP 와 같은 이름)
    """
    s = str(url)
    ret = {}
    port = 0
    ue = len(s)
    start = 0

    def parse_path(pos):
        e = ue
        p = s.find('#', pos, e)
        if p != -1:
            p += 1
            ret['fragment'] = _cntrl(s[p:e]) if p < e else ''
            e = p - 1
        p = s.find('?', pos, e)
        if p != -1:
            p += 1
            ret['query'] = _cntrl(s[p:e]) if p < e else ''
            e = p - 1
        if pos < e or pos == ue:
            ret['path'] = _cntrl(s[pos:e])
        return ret

    def parse_port_branch(pos, e):
        """parse_port: 라벨. (다음 상태, 위치) 를 돌려준다."""
        nonlocal port
        p = e + 1
        pp = p
        while pp < ue and pp - p < 6 and _isdigit(s[pp]):
            pp += 1
        if 0 < pp - p < 6 and (pp == ue or s[pp] == '/'):
            value = _strtol_port(s[p:pp])
            if value is not None and 0 <= value <= 65535:
                port = value
                ret['port'] = value
                if pos + 1 < ue and s[pos] == '/' and s[pos + 1] == '/':
                    pos += 2
                return 'host', pos
            return 'fail', pos
        if p == pp and pp == ue:
            return 'fail', pos
        if pos + 1 < ue and s[pos] == '/' and s[pos + 1] == '/':
            return 'host', pos + 2
        return 'path', pos

    e = s.find(':')
    state = None

    if e != -1 and e != start:
        # 스킴 검사
        state = 'scheme_ok'
        for p in range(start, e):
            ch = s[p]
            if not _isalpha(ch) and not _isdigit(ch) and ch not in '+.-':
                if e + 1 < ue and e < _strcspn(s, start, ue, '?#'):
                    state, start = parse_port_branch(start, e)
                elif start + 1 < ue and s[start] == '/' and s[start + 1] == '/':
                    start += 2
                    state = 'host'
                else:
                    state = 'path'
                break

        if state == 'scheme_ok':
            if e + 1 == ue:
                ret['scheme'] = _cntrl(s[start:e])
                return ret

            if s[e + 1] != '/':
                # a.com:80 같은 포트인지 본다
                p = e + 1
                while p < ue and _isdigit(s[p]):
                    p += 1
                if (p == ue or s[p] == '/') and (p - e) < 7:
                    state, start = parse_port_branch(start, e)
                else:
                    ret['scheme'] = _cntrl(s[start:e])
                    start = e + 1
                    state = 'path'
            else:
                ret['scheme'] = _cntrl(s[start:e])
                if e + 2 < ue and s[e + 2] == '/':
                    start = e + 3
                    state = 'host'
                    if ret['scheme'].lower() == 'file' and e + 3 < ue and s[e + 3] == '/':
                        if e + 5 < ue and s[e + 5] == ':':
                            start = e + 4
                        state = 'path'
                else:
                    start = e + 1
                    state = 'path'
    elif e != -1:
        state, start = parse_port_branch(start, e)
    elif start + 1 < ue and s[start] == '/' and s[start + 1] == '/':
        start += 2
        state = 'host'
    else:
        state = 'path'

    if state == 'fail':
        return None
    if state == 'path':
        return parse_path(start)

    # parse_host
    e = _strcspn(s, start, ue, '/?#')
    p = s.rfind('@', start, e)
    if p != -1:
        pp = s.find(':', start, p)
        if pp != -1:
            ret['user'] = _cntrl(s[start:pp])
            ret['pass'] = _cntrl(s[pp + 1:p])
        else:
            ret['user'] = _cntrl(s[start:p])
        start = p + 1

    if start < ue and s[start] == '[' and e > 0 and s[e - 1] == ']':
        p = -1
    else:
        p = s.rfind(':', start, e)

    if p != -1:
        if not port:
            p += 1
            if e - p > 5:
                return None
            if e - p > 0:
                value = _strtol_port(s[p:e])
                if value is not None and 0 <= value <= 65535:
                    port = value
                    ret['port'] = value
                else:
                    return None
            p -= 1
    else:
        p = e

    if p - start < 1:
        return None

    ret['host'] = _cntrl(s[start:p])

    if e == ue:
        return ret

    return parse_path(e)


def parse_str(query):
    """PHP parse_str($query, $result) 대응. & 로 나누고 이름과 값을 urldecode 한다(+ 는 공백).

    이름의 괄호(a[b][])는 $_POST 와 같은 규칙으로 dict 가 된다. 값은 글자 하나가 바이트 하나인 문자열(latin-1)이다.
    """
    result = {}
    for pair in str(query).split('&'):
        if pair == '':
            continue
        name, sep, value = pair.partition('=')
        name = unquote_plus(name, encoding='latin-1')
        value = unquote_plus(value, encoding='latin-1') if sep else ''
        # php_register_variable_ex: 이름 앞 공백은 지우고, 빈 이름은 버린다.
        if name.lstrip(' ') == '':
            continue
        # C 문자열이라 이름은 NUL 에서 끝난다.
        name = name.split('\x00', 1)[0]
        if name.lstrip(' ') == '':
            continue
        _set_php_key(result, name, value)
    return result
