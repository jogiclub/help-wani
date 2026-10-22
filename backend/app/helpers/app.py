"""
파일 위치: backend/app/helpers/app.py
역할: 공통 유틸리티 함수

api_response() 는 app/core/response.py, client_ip() 는 app/core/request.py 에 있다.
check_uploaded_image(), image_file_ext() 는 PHP finfo(libmagic 5.40) + getimagesize() 판정을 그대로 옮겼다.
"""
import re
import secrets

from markupsafe import Markup

from app import config
from app.core.php import htmlspecialchars, php_int, php_round, php_str
from app.core.request import base_url, site_base


def asset_url(path):
    """포털 JS/CSS 주소에 파일 수정 시각(?v=)을 붙인다(CLAUDE.md 3.1). 앞단 Apache 가 내주는 파일과 같은 파일을 본다."""
    path = str(path).lstrip('/')
    mtime = 0
    if path.startswith('assets/'):
        try:
            mtime = int((config.ASSETS_DIR / path[len('assets/'):]).stat().st_mtime)
        except OSError:
            mtime = 0
    return base_url(path) + '?v=' + str(mtime)


def customer_url(path=''):
    """고객에게 전달하는 주소. .env 의 PUBLIC_BASE_URL 을 우선 쓰고, 비어 있으면 접속한 주소(CLAUDE.md 3.13)."""
    public = str(config.env('PUBLIC_BASE_URL', '') or '').strip()
    if public == '':
        return base_url(path)
    return public.rstrip('/') + '/' + str(path or '').lstrip('/')


def relay_viewer_ws_url():
    """상담원 뷰어가 붙을 relay 웹소켓 주소. auto 이면 접속한 포털 주소 기준 ws(s)://<호스트>/relay-viewer."""
    configured = str(config.env('RELAY_VIEWER_WS_URL', 'wss://localhost:8443/viewer'))
    if configured != 'auto':
        return configured

    base = site_base()
    scheme = 'wss' if base.startswith('https://') else 'ws'
    host_port = base.split('://', 1)[1].rstrip('/')
    return f'{scheme}://{host_port}/relay-viewer'


def random_digits(length):
    """암호학적으로 안전한 숫자 문자열."""
    return ''.join(str(secrets.randbelow(10)) for _ in range(int(length)))


def guide_image_url(src):
    """가이드 이미지 블록의 화면 주소. 외부 https 주소는 그대로, 이 조직에 올린 파일은 포털 주소로."""
    src = str(src or '')
    return src if re.match(r'^https://', src, re.IGNORECASE) else base_url(src)


# PHP '#(https?://[^\s<>"\'`]+)#u' 와 같은 범위. PHP(UCP) 의 \s 는 \x1C~\x1F 를 공백으로 보지 않는다.
_GUIDE_URL_RE = re.compile(r'(https?://(?:[^\s<>"\'`]|[\x1c-\x1f])+)')


def guide_text_html(text):
    """가이드 글 블록을 화면용 HTML 로. 먼저 전부 이스케이프하고 https 주소만 링크로 만든다."""
    parts = _GUIDE_URL_RE.split(str(text or ''))
    html = []

    for index, part in enumerate(parts):
        if index % 2 == 0:
            html.append(htmlspecialchars(part))
            continue

        # 문장 끝의 마침표, 괄호는 주소에서 뺀다.
        url = part.rstrip('.,);:!?')
        tail = part[len(url):]
        html.append('<a class="text-brand-600 underline" href="' + htmlspecialchars(url)
                    + '" target="_blank" rel="noopener">' + htmlspecialchars(url) + '</a>' + htmlspecialchars(tail))

    return Markup(''.join(html))


def format_duration(seconds):
    """초를 "12분 34초" 형태로."""
    seconds = php_int(seconds)
    if seconds <= 0:
        return '-'
    minutes, rest = divmod(seconds, 60)
    return f'{minutes}분 {rest}초' if minutes > 0 else f'{rest}초'


def status_label(status):
    labels = {
        'issued': '코드 발급',
        'verified': '고객 코드 확인',
        'waiting': '고객 연결 대기',
        'connected': '원격 중',
        'ended': '종료',
        'expired': '만료',
        'canceled': '취소',
    }
    return labels.get(status, status)


# ------------------------------------------------------------------ 이미지 업로드 검사

# PHP 는 finfo(FILEINFO_MIME_TYPE) 로 형식을 보고 getimagesize() 가 읽히는지 함께 본다. 둘을 같은 규칙으로 옮겼다.
# - finfo: PHP 8.2 에 묶인 libmagic 5.40 의 magic 원본(Magdir/images, jpeg, riff) 규칙
# - getimagesize: php-src 8.2.34 ext/standard/image.c 의 php_getimagetype(), php_handle_png/jpeg/webp(), php_get_xbm()
_PNG_SIG = b'\x89PNG\r\n\x1a\n'


def _magic_mime(data):
    """libmagic 5.40 이 이 세 형식으로 판정하는 규칙. 그 밖은 None."""
    if data[:16] == _PNG_SIG + b'\x00\x00\x00\x0dIHDR':
        return 'image/png'
    # Apple CgBI PNG
    if data[:16] == _PNG_SIG + b'\x00\x00\x00\x04CgBI' and data[24:32] == b'\x00\x00\x00\x0dIHDR':
        return 'image/png'
    if data[:2] == b'\xff\xd8':
        return 'image/jpeg'
    if data[:4] == b'RIFF' and data[8:12] == b'WEBP':
        return 'image/webp'
    return None


def _jpeg_has_size(data):
    """php_handle_jpeg(info 없음): SOS/EOI 전에 SOFn 표시를 만나면 크기를 읽은 것으로 본다."""
    sof = {0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF}
    size = len(data)
    pos = 3          # ff d8 ff 를 읽은 뒤. 첫 표시는 이미 ff 를 읽은 상태(ff_read)
    ff_read = True

    while True:
        # php_next_marker
        if not ff_read:
            while True:
                if pos >= size:
                    return False          # EOF -> M_EOI
                byte = data[pos]
                pos += 1
                if byte == 0xFF:
                    break
        ff_read = False
        while True:
            if pos >= size:
                return False              # EOF -> M_EOI
            marker = data[pos]
            pos += 1
            if marker != 0xFF:
                break

        if marker in sof:
            return True
        if marker in (0xDA, 0xD9):        # M_SOS, M_EOI
            return False

        # php_skip_variable: 길이 2바이트를 못 읽으면 0 이 되어 멈춘다. 파일 끝 너머로도 옮겨 간다.
        if pos + 2 > size:
            return False
        length = (data[pos] << 8) + data[pos + 1]
        pos += 2
        if length < 2:
            return False
        pos += length - 2


def _sscanf_int(text):
    """glibc sscanf %d: 앞 공백, 부호, 숫자. long 범위로 자른 뒤 int 로 담는다. 숫자가 없으면 None."""
    match = re.match(rb'[ \t\n\v\f\r]*([+-]?)([0-9]+)', text)
    if not match:
        return None
    value = int(match.group(2))
    value = -value if match.group(1) == b'-' else value
    value = max(-(1 << 63), min((1 << 63) - 1, value))
    value &= 0xFFFFFFFF
    return value - (1 << 32) if value >= (1 << 31) else value


def _xbm_has_size(data):
    """php_get_xbm(): '#define 이름_width 숫자' 와 '_height' 줄이 모두 있으면 XBM 으로 본다."""
    width = height = 0
    ws = b' \t\n\v\f\r'
    for line in data.split(b'\n'):
        line = line.split(b'\x00', 1)[0]  # C 문자열이라 NUL 에서 끝난다
        if not line.startswith(b'#define'):
            continue
        rest = line[len(b'#define'):].lstrip(ws)
        name = re.match(rb'[^ \t\n\v\f\r]+', rest)
        if not name:
            continue
        value = _sscanf_int(rest[name.end():])
        if value is None:
            continue
        iname = name.group(0)
        kind = iname[iname.rfind(b'_') + 1:] if b'_' in iname else iname
        if kind == b'width':
            width = value & 0xFFFFFFFF
            if height:
                break
        if kind == b'height':
            height = value & 0xFFFFFFFF
            if width:
                break
    return bool(width and height)


def _getimagesize_ok(data):
    """getimagesize() 가 FALSE 가 아닌지. 위 세 형식으로 판정된 내용에서 갈 수 있는 길만 옮겼다."""
    if len(data) < 3:
        return False
    if data[:3] == b'\xff\xd8\xff':
        return _jpeg_has_size(data)
    if data[:3] == _PNG_SIG[:3]:
        # 서명 8바이트 + 8바이트 건너뛰고 IHDR 9바이트
        return data[:8] == _PNG_SIG and len(data) >= 25
    if data[:3] == b'RIF':
        if len(data) < 12 or data[8:12] != b'WEBP':
            return False
        chunk = data[12:30]
        return len(chunk) == 18 and chunk[:3] == b'VP8' and chunk[3:4] in (b' ', b'L', b'X')
    # 여기부터는 ff d8 로 시작하지만 셋째 바이트가 ff 가 아닌 JPEG 판정 파일만 온다.
    # GIF/SWF/PSD/BMP/JPC/TIFF/IFF/ICO/JP2/WBMP 서명은 첫 바이트가 ff 가 아니므로 맞지 않는다.
    # AVIF(4~8바이트 'ftyp' 상자)는 받지 않는다(PHP 는 그런 다중 형식 파일을 받을 수도 있다. 더 엄격한 쪽).
    if len(data) < 12:
        return False
    return _xbm_has_size(data)


def image_file_ext(data):
    """파일 내용으로 형식을 판별해 확장자(png, jpg, webp)를 돌려준다. 그 밖의 형식(SVG 포함)은 None.

    올린 파일(check_uploaded_image)과 서버가 받아 온 파일(외부 이미지 저장)이 같은 검사를 쓴다.
    PHP 는 파일 경로를 받지만 여기서는 내용(bytes)을 받는다.
    """
    allowed = {'image/png': 'png', 'image/jpeg': 'jpg', 'image/webp': 'webp'}
    mime = _magic_mime(data)
    if mime not in allowed or not _getimagesize_ok(data):
        return None
    return allowed[mime]


async def check_uploaded_image(upload, max_bytes):
    """올린 이미지 검사. 확장자(png, jpg, webp) 또는 {'error': 문구} 와 내용을 (결과, 내용) 으로 돌려준다.

    upload 는 post.files 의 값(UploadFile). 파일명이 비었으면 PHP 처럼 고르지 않은 것으로 본다.
    """
    if upload is None or isinstance(upload, dict) or not upload.filename:
        return {'error': '이미지 파일을 선택해 주세요.'}, b''

    content = await upload.read()

    if len(content) > max_bytes:
        return {'error': '이미지는 ' + php_str(php_round(max_bytes / 1048576, 1)) + 'MB 이하로 올려 주세요.'}, b''

    ext = image_file_ext(content)
    if ext is None:
        return {'error': 'PNG, JPG, WEBP 이미지만 올릴 수 있습니다.'}, b''

    return ext, content
