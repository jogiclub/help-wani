"""
파일 위치: backend/app/helpers/app.py
역할: 공통 유틸리티 함수 (PHP helpers/app_helper.php 대응)

api_response() 는 app/core/response.py, client_ip() 는 app/core/request.py 에 있다.
check_uploaded_image(), image_file_ext() 는 이미지 업로드를 옮길 때(6단계) 여기에 더한다.
"""
import re
import secrets

from markupsafe import Markup

from app import config
from app.core.php import htmlspecialchars
from app.core.request import base_url, site_base


def asset_url(path):
    """포털 JS/CSS 주소에 파일 수정 시각(?v=)을 붙인다(CLAUDE.md 3.1). PHP 와 같은 파일을 보므로 같은 값이 나온다."""
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
    seconds = int(seconds or 0)
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
