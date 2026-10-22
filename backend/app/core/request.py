"""
파일 위치: backend/app/core/request.py
역할: 지금 처리 중인 요청 문맥 (base_url, uri_string, AJAX 판별, 고객 IP) — CI3 의 전역 함수 대응

CI3 는 base_url() 처럼 어디서든 요청 정보를 읽는다. 옮긴 코드를 그대로 쓰도록 요청을 ContextVar 에 담는다.
RequestContextMiddleware 가 요청마다 채운다(app/core/middleware.py).
"""
import ipaddress
import re
from contextvars import ContextVar

from app import config

current_request = ContextVar('rh_request', default=None)

# Cloudflare Tunnel(cloudflared) 고정 주소. 이 주소에서 온 요청의 CF-Connecting-IP 만 믿는다(CLAUDE.md 3.12).
# docker-compose.yml 의 cloudflared ipv4_address, apache-vhost.conf 의 RemoteIPInternalProxy 와 같아야 한다(검사가 잡습니다).
TRUSTED_PROXY_IP = '10.250.88.5'

_HOST_RE = re.compile(r'^[A-Za-z0-9.\-]+(:[0-9]{1,5})?$')


def get_request():
    request = current_request.get()
    if request is None:
        raise RuntimeError('요청 문맥 밖에서 호출했습니다')
    return request


def site_base():
    """포털 기준 주소(끝에 '/'). PHP config.php 의 base_url 결정과 같다.

    APP_BASE_URL 이 있으면 그것, 없으면 요청 Host(호스트[:포트] 형식만 허용)와
    X-Forwarded-Proto(Cloudflare Tunnel 이 https 를 알림)로 만든다.
    """
    configured = config.env('APP_BASE_URL', '')
    if configured:
        return configured if configured.endswith('/') else configured + '/'

    request = get_request()
    host = request.headers.get('host', 'localhost')
    if not _HOST_RE.match(host):
        host = 'localhost'

    https = request.url.scheme == 'https' or request.headers.get('x-forwarded-proto') == 'https'
    return ('https://' if https else 'http://') + host + '/'


def base_url(path=''):
    """CI3 base_url() 대응."""
    return site_base() + str(path or '').lstrip('/')


def uri_string():
    """CI3 uri_string() 대응. 앞뒤 '/' 없는 경로."""
    return get_request().url.path.strip('/')


def is_ajax(request=None):
    """CI3 input->is_ajax_request() 대응. jQuery 가 X-Requested-With 를 붙인다."""
    request = request or get_request()
    return request.headers.get('x-requested-with', '').lower() == 'xmlhttprequest'


def client_ip(request=None):
    """고객 IP. cloudflared 고정 주소에서 온 요청만 CF-Connecting-IP 를 믿는다. X-Forwarded-For 는 쓰지 않는다."""
    request = request or get_request()
    peer = request.client.host if request.client else '0.0.0.0'

    if peer == TRUSTED_PROXY_IP:
        forwarded = request.headers.get('cf-connecting-ip', '').strip()
        try:
            return str(ipaddress.ip_address(forwarded))
        except ValueError:
            pass

    return peer
