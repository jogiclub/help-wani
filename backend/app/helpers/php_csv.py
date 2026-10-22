"""
파일 위치: backend/app/helpers/php_csv.py
역할: PHP fputcsv / fgetcsv($fp, 0, ',', '"', '') 와 같은 결과의 CSV 쓰기·읽기 (매크로 파일 등록/내려받기)

파이썬 csv 모듈은 PHP 와 다르게 읽는다(따옴표 앞 공백, 홀로 쓴 '\r' 을 줄바꿈으로 봄 등, 3천여 개 비교에서 1/3 불일치).

fputcsv 는 php-src php-8.2.34 ext/standard/file.c 의 php_fputcsv 를 옮겼다: 구분자, 따옴표, 줄바꿈, 탭, 공백이 있으면
따옴표로 감싸고 안의 따옴표는 두 번 쓴다. 줄 끝은 '\\n'. (파이썬 csv 는 공백·탭이 있어도 감싸지 않아 결과가 다르다.)
"""

def fputcsv(fields):
    out = []
    for field in fields:
        text = '' if field is None else str(field)
        if any(ch in text for ch in (',', '"', '\n', '\r', '\t', ' ')):
            out.append('"' + text.replace('"', '""') + '"')
        else:
            out.append(text)
    return ','.join(out) + '\n'


_DELIM, _ENCL = ord(','), ord('"')
_SPACE = {9, 10, 11, 12, 13, 32}  # C isspace (C.UTF-8 로케일의 1바이트 글자)


def _at(buf, i):
    """C 문자열처럼 끝 뒤는 NUL(0)."""
    return buf[i] if i < len(buf) else 0


def _mblen(buf, pos, limit):
    """mbrlen() (C.UTF-8). 끝이면 0, NUL 은 1, 깨진 바이트 -1, 모자란 바이트 -2."""
    if pos >= limit:
        return 0
    lead = buf[pos]
    if lead == 0 or lead < 0x80:
        return 1
    if 0xC2 <= lead <= 0xDF:
        need, low, high = 2, 0x80, 0xBF
    elif lead == 0xE0:
        need, low, high = 3, 0xA0, 0xBF
    elif 0xE1 <= lead <= 0xEC or 0xEE <= lead <= 0xEF:
        need, low, high = 3, 0x80, 0xBF
    elif lead == 0xED:
        need, low, high = 3, 0x80, 0x9F
    elif lead == 0xF0:
        need, low, high = 4, 0x90, 0xBF
    elif 0xF1 <= lead <= 0xF3:
        need, low, high = 4, 0x80, 0xBF
    elif lead == 0xF4:
        need, low, high = 4, 0x80, 0x8F
    else:
        return -1
    for k in range(1, need):
        if pos + k >= limit:
            return -2
        byte = buf[pos + k]
        if not ((low if k == 1 else 0x80) <= byte <= (high if k == 1 else 0xBF)):
            return -1
    return need


def _lookup_trailing(buf, length):
    """php_fgetcsv_lookup_trailing_spaces(): 끝의 \r\n, \n, \r 하나를 뺀 위치."""
    pos = 0
    last = [0, 0]
    while length > 0:
        inc = 1 if buf[pos] == 0 else _mblen(buf, pos, pos + length)
        if inc in (-1, -2):
            inc = 1
        elif inc == 0:
            break
        else:
            last = [last[1], buf[pos]]
        pos += inc
        length -= inc
    if last[1] == 10:
        return pos - 2 if last[0] == 13 else pos - 1
    if last[1] == 13:
        return pos - 1
    return pos


def _parse(buf, next_line):
    """php_fgetcsv(stream, ',', '"', PHP_CSV_NO_ESCAPE, ...) 한 번. 빈 줄이면 None."""
    # C 는 줄 끝 NUL 까지 복사할 때가 있다(파일 끝이 따옴표 하나로 끝나면 '\x00'). 같게 하려고 NUL 을 덧붙여 두고 길이는 원래 값을 쓴다.
    size = len(buf)
    buf = buf + b'\x00'
    bptr = 0
    line_end = limit = _lookup_trailing(buf, size)
    line_end_len = size - limit
    values = []
    first_field = True

    while True:
        temp = bytearray()
        inc = _mblen(buf, bptr, limit) if bptr < limit else 0
        if bptr < limit and buf[bptr] == 0:
            inc = 1
        if inc == 1:
            tmp = bptr
            while _at(buf, tmp) != _DELIM and _at(buf, tmp) in _SPACE:
                tmp += 1
            if _at(buf, tmp) == _ENCL and tmp < limit:
                bptr = tmp

        if first_field and bptr == line_end:
            return None
        first_field = False

        if inc != 0 and _at(buf, bptr) == _ENCL:
            state = 0
            bptr += 1
            hunk = bptr
            done = False
            while not done:
                if inc == 0:
                    if state == 2:
                        temp += buf[hunk:bptr - 1]
                        hunk = bptr
                        break
                    if state == 1:
                        temp += buf[hunk:bptr]
                        hunk = bptr
                    if hunk != line_end:
                        temp += buf[hunk:bptr]
                        hunk = bptr
                    # 따옴표 안의 줄바꿈은 필드에 넣고 다음 줄을 이어 읽는다.
                    temp += buf[line_end:line_end + line_end_len]
                    new_buf = next_line()
                    if new_buf is None:
                        break
                    size = len(new_buf)
                    buf = new_buf + b'\x00'
                    bptr = hunk = 0
                    line_end = limit = _lookup_trailing(buf, size)
                    line_end_len = size - limit
                    state = 0
                elif inc in (1, -1, -2):
                    if state == 1:
                        bptr += 1
                        state = 0
                    elif state == 2:
                        if _at(buf, bptr) != _ENCL:
                            temp += buf[hunk:bptr - 1]
                            hunk = bptr
                            break
                        temp += buf[hunk:bptr]
                        bptr += 1
                        hunk = bptr
                        state = 0
                    else:
                        if _at(buf, bptr) == _ENCL:
                            state = 2
                        bptr += 1
                else:
                    if state == 2:
                        temp += buf[hunk:bptr - 1]
                        hunk = bptr
                        break
                    if state == 1:
                        bptr += inc
                        temp += buf[hunk:bptr]
                        hunk = bptr
                        state = 0
                    else:
                        bptr += inc
                inc = (1 if _at(buf, bptr) == 0 else _mblen(buf, bptr, limit)) if bptr < limit else 0

            # 닫는 따옴표 뒤에서 구분자를 찾는다.
            while True:
                if inc == 0:
                    break
                if inc in (-1, -2):
                    inc = 1
                if inc == 1 and _at(buf, bptr) == _DELIM:
                    break
                bptr += inc
                inc = (1 if _at(buf, bptr) == 0 else _mblen(buf, bptr, limit)) if bptr < limit else 0

            temp += buf[hunk:bptr]
            bptr += inc
            comp_end = len(temp)
        else:
            hunk = bptr
            while True:
                if inc == 0:
                    break
                if inc in (-1, -2):
                    inc = 1
                if inc == 1 and _at(buf, bptr) == _DELIM:
                    break
                bptr += inc
                inc = (1 if _at(buf, bptr) == 0 else _mblen(buf, bptr, limit)) if bptr < limit else 0
            temp += buf[hunk:bptr]
            comp_end = _lookup_trailing(temp, len(temp))
            if _at(buf, bptr) == _DELIM:
                bptr += 1

        values.append(bytes(temp[:comp_end]).decode('utf-8', 'replace'))
        if inc <= 0:
            return values


def fgetcsv_all(text):
    """php-src php-8.2.34 ext/standard/file.c 의 php_fgetcsv 를 옮긴 것으로 파일 끝까지 읽은 행 목록. 빈 줄은 [None].

    줄은 '\n' 으로만 나눈다(auto_detect_line_endings = 0). 따옴표 앞 공백은 건너뛰고, 따옴표 밖 '\r' 은 글자로 둔다.
    """
    data = text.encode('utf-8') if isinstance(text, str) else bytes(text)
    lines = []
    pos = 0
    while pos < len(data):
        end = data.find(b'\n', pos)
        end = len(data) if end < 0 else end + 1
        lines.append(data[pos:end])
        pos = end
    it = iter(lines)

    def next_line():
        return next(it, None)

    rows = []
    while True:
        line = next_line()
        if line is None:
            return rows
        parsed = _parse(line, next_line)
        rows.append([None] if parsed is None else parsed)
