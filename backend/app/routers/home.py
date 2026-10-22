"""
파일 위치: backend/app/routers/home.py
역할: 서비스 소개(홍보) 페이지와 방문자 언어 선택
"""
from fastapi import APIRouter, Request

from app import config
from app.core.errors import redirect
from app.core.i18n import supported_locales
from app.core.php import php_int, php_round, php_str
from app.core.request import base_url
from app.core.templates import render
from app.models import organization_model

router = APIRouter()

# 언어 선택을 기억하는 쿠키 이름
LOCALE_COOKIE = 'rh_locale'


def landing_icons():
    """소개 페이지에서 쓰는 Bootstrap Icons 이름(bi-*). 앞의 'bi-' 는 템플릿이 붙인다.

    아이콘 글꼴(assets/vendor/bootstrap-icons/1.13.2)에 있는 이름만 쓴다. 없는 이름이면 빈칸이 되므로
    check-conventions 가 글꼴 CSS 와 대조한다(CLAUDE.md 3.3).
    """
    return {
        'value': {
            1: 'magic',               # 설치 부담 없음
            2: 'window',              # 브라우저로 제어
            3: 'shield-lock',         # 443 포트 하나
            4: 'globe2',              # 다양한 언어 지원
            5: 'clock',               # 시간대 지원
            6: 'person-check',        # 동의 없이는 시작 안 함
        },
        'how': {
            1: 'link-45deg',          # 전용 페이지 접속
            2: 'download',            # 앱 실행
            3: 'grid-3x3-gap',        # 코드 입력 후 허용
        },
        'flow': {
            1: 'ticket-perforated',   # 코드 발급
            2: 'keyboard',            # 코드 입력
            3: 'check-circle',        # 허용 후 연결
        },
        'features': {
            1: 'display', 2: 'speedometer2', 3: 'shop', 4: 'clipboard', 5: 'pencil-square',
            6: 'emoji-smile', 7: 'sliders', 8: 'laptop', 9: 'translate',
        },
        'security': {
            1: 'lock',                # 전 구간 암호화
            2: 'key',                 # 1회용 코드와 토큰
            3: 'hand-index',          # 명시적 동의
            4: 'eye',                 # 상시 표시와 즉시 종료
            5: 'trash3',              # 잔류 없음
            6: 'journal-text',        # 기록
        },
        # 채팅 상담 + 원격지원 섹션
        'dual': {
            1: 'chat-dots',           # 웹사이트 채팅 상담
            2: 'display',             # 필요할 때 원격지원
            3: 'clock-history',       # 기록은 한곳에
        },
        'misc': {
            'brand': 'headset', 'phishing': 'shield-exclamation', 'check': 'check-circle-fill', 'arrow': 'arrow-right',
            'expand': 'chevron-down', 'mail': 'envelope', 'call': 'telephone', 'language': 'globe2',
        },
    }


@router.get('/lang/{locale}')
def set_locale(request: Request, locale: str):
    """방문자가 고른 언어를 1년간 기억하고 원래 보던 페이지로 돌아간다. 외부 주소로는 보내지 않는다."""
    back = request.headers.get('referer', '')
    if back == '' or not back.startswith(base_url()):
        back = base_url()

    response = redirect(back)
    if locale in supported_locales():
        response.set_cookie(LOCALE_COOKIE, locale, max_age=31536000, expires=31536000, path='/', httponly=False, samesite='lax')
    return response


@router.get('/')
@router.get('/home')
def index(request: Request):
    # 요금은 여기 한 곳에서만 관리한다(CLAUDE.md 6장).
    pricing = {'accounts': 5, 'months': 12, 'price': 220000}
    pricing['per_account_year'] = int(php_round(pricing['price'] / pricing['accounts']))
    pricing['per_account_month'] = int(php_round(pricing['price'] / pricing['accounts'] / pricing['months']))

    icons = landing_icons()

    # 소개 페이지의 상담 버블은 우리 쪽 조직(기본 system)이 응대한다. 그 조직이 위젯을 꺼 두면 버블을 띄우지 않는다.
    landing_org = organization_model.get_active_by_code(config.env('LANDING_WIDGET_ORG', 'system'))
    widget_key = landing_org.widget_key if (landing_org and php_int(landing_org.widget_enabled) == 1) else ''

    demo_org = php_str(config.env('DEMO_ORG', 'demo'))
    has_exe = (config.ASSETS_DIR / 'downloads' / 'RemoteHelp.exe').is_file()

    return render(request, 'home/landing', {
        'locales': supported_locales(),
        'icons': icons,
        'page_title': '기업용 원격지원 서비스',
        'pricing': pricing,
        'widget_key': widget_key,
        # 데모 조직의 고객 접속 페이지 / 실행 파일 다운로드 주소 (파일이 있고 켜져 있을 때만)
        'demo_url': base_url(demo_org),
        'demo_download': base_url(demo_org + '/download') if (config.env('DIRECT_DOWNLOAD_ENABLED', False) and has_exe) else '',
        # 문의처는 .env 에서 설정한다. 값이 없으면 화면에 노출하지 않는다.
        'contact_email': config.env('CONTACT_EMAIL', ''),
        'contact_phone': config.env('CONTACT_PHONE', ''),
    }, 'layouts/public')
