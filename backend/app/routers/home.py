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
    """소개 페이지에서 쓰는 Material Symbols 아이콘.

    여기에 적은 이름으로 폰트 서브셋을 요청하므로, 아이콘을 추가하면 반드시 이 목록에도 넣어야 화면에 나온다(CLAUDE.md 3.3).
    """
    return {
        'value': {
            1: 'cleaning_services',   # 설치 부담 없음
            2: 'web',                 # 브라우저로 제어
            3: 'vpn_lock',            # 443 포트 하나
            4: 'language',            # 다양한 언어 지원
            5: 'schedule',            # 시간대 지원
            6: 'how_to_reg',          # 동의 없이는 시작 안 함
        },
        'how': {
            1: 'link',                # 전용 페이지 접속
            2: 'download',            # 앱 실행
            3: 'dialpad',             # 코드 입력 후 허용
        },
        'flow': {
            1: 'confirmation_number',  # 코드 발급
            2: 'keyboard',             # 코드 입력
            3: 'check_circle',         # 허용 후 연결
        },
        'features': {
            1: 'screen_share', 2: 'dashboard', 3: 'storefront', 4: 'content_paste', 5: 'edit_note',
            6: 'sentiment_satisfied', 7: 'tune', 8: 'devices', 9: 'translate',
        },
        'security': {
            1: 'lock',                # 전 구간 암호화
            2: 'key',                 # 1회용 코드와 토큰
            3: 'touch_app',           # 명시적 동의
            4: 'visibility',          # 상시 표시와 즉시 종료
            5: 'delete_forever',      # 잔류 없음
            6: 'receipt_long',        # 기록
        },
        # 채팅 상담 + 원격지원 섹션
        'dual': {
            1: 'forum',               # 웹사이트 채팅 상담
            2: 'screen_share',        # 필요할 때 원격지원
            3: 'history',             # 기록은 한곳에
        },
        'misc': {
            'brand': 'support_agent', 'phishing': 'gpp_maybe', 'check': 'check_circle', 'arrow': 'arrow_forward',
            'expand': 'expand_more', 'mail': 'mail', 'call': 'call', 'language': 'language',
        },
    }


def flatten_icons(icons):
    """폰트 서브셋 요청에 쓸 아이콘 이름 목록(중복 제거, 정렬)."""
    return sorted({name for group in icons.values() for name in group.values()})


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
        'icon_names': flatten_icons(icons),
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
