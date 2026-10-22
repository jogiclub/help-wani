"""
파일 위치: backend/app/routers/customer.py
역할: 고객 접속 페이지, 팔로업 메일의 상담 대화 이어 가기, 만족도 조사, 런처 다운로드

모두 /{조직코드} 로 시작하는 주소다. 조직 코드 하나만 받는 /{org_code} 는 반드시 맨 마지막에 등록한다(CLAUDE.md 3.8, main.py).
"""
import re
from urllib.parse import quote

from fastapi import APIRouter, Depends, Request

from app import config, db
from app.core.errors import Halt, redirect, show_404
from app.core.i18n import lang_text
from app.core.input import PostData, post_data
from app.core.middleware import use_org_locale
from app.core.php import php_int, php_str
from app.core.request import base_url, client_ip
from app.core.response import api_response
from app.core.security import xss_clean
from app.core.templates import render
from app.models import guide_model, log_model, organization_model, session_model
from app.routers import download

router = APIRouter()      # /{org_code}/... (조직 코드 뒤에 낱말이 있는 주소)
org_router = APIRouter()  # /{org_code} (맨 마지막에 등록)

_RESUME_TOKEN = re.compile(r'^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$')


def _landing(request, org_code, resume=''):
    org = organization_model.get_active_by_code(org_code)
    if org is None:
        raise Halt(show_404())

    # 고객 화면은 해당 조직이 정한 언어와 시간대를 따른다.
    use_org_locale(request, org)

    store_id = config.env('STORE_PRODUCT_ID', '')
    store_id_text = php_str(store_id)

    return render(request, 'customer/landing', {
        'page_title': org.name + ' 원격지원',
        'org': org,
        'tel_digits': re.sub(r'[^0-9+]', '', org.phone or ''),
        'store_id': store_id,
        'store_web_url': 'https://apps.microsoft.com/detail/' + store_id_text,
        'store_app_url': 'ms-windows-store://pdp/?productid=' + store_id_text,
        'protocol_url': 'remotehelp://connect?org=' + quote(org.org_code, safe='-_.~'),
        'download_enabled': bool(config.env('DIRECT_DOWNLOAD_ENABLED', False)),
        'download_url': base_url(org.org_code + '/download'),
        'download_info': download.launcher_info(),
        # 조직이 상담 위젯을 켜 두었으면 이 페이지에서도 그 조직과 상담을 열 수 있게 한다.
        'widget_key': php_str(org.widget_key) if php_int(org.widget_enabled) == 1 else '',
        # 팔로업 메일로 왔으면 위젯이 그 대화를 펼친 채로 시작한다. 토큰 검증은 위젯 init 이 한다.
        'widget_resume': resume,
        # 공개 가이드가 있으면 원격지원 전에 스스로 찾아볼 수 있게 링크를 둔다.
        'guide_count': guide_model.count_published(org.id),
    }, 'layouts/customer')


@router.get('/{org_code}/download')
def launcher(request: Request, org_code: str):
    """GET /{org_code}/download — 직접 다운로드는 코드 서명 인증서가 준비된 뒤에만 연다(docs/code-signing.md)."""
    org = organization_model.get_active_by_code(org_code)
    if org is None:
        raise Halt(show_404())

    if not config.env('DIRECT_DOWNLOAD_ENABLED', False):
        return redirect(org.org_code)

    if not download.launcher_path().is_file():
        use_org_locale(request, org)
        return render(request, 'customer/download_unavailable', {
            'page_title': lang_text('customer.download.unavailable.title'),
            'org': org,
        }, 'layouts/customer')

    log_model.audit(org.id, None, 'launcher_download', 'org=' + org.org_code, client_ip(request))
    return download.launcher_response(org)


@router.api_route('/{org_code}/survey/{session_id:int}', methods=['GET', 'POST'])
def survey(request: Request, org_code: str, session_id: int, post: PostData = Depends(post_data)):
    """GET|POST /{org_code}/survey/{session_id}"""
    org = organization_model.get_active_by_code(org_code)
    found = session_model.get_by_id(session_id)

    if org is None or found is None or int(found.org_id) != int(org.id):
        raise Halt(show_404())

    use_org_locale(request, org)

    existing = db.first_where('surveys', {'session_id': int(found.id)})

    if request.method == 'POST':
        if existing:
            return api_response(False, '이미 참여하신 만족도 조사입니다.', None, 409)

        score = php_int(post.get('score'))
        if score < 1 or score > 5:
            return api_response(False, '별점을 선택해 주세요.', None, 400)

        db.insert('surveys', {
            'session_id': int(found.id),
            'score': score,
            'comment': php_str(xss_clean(post.get('comment')))[:1000],
        })

        log_model.add(found.id, 'survey_submitted', '점수 ' + str(score), 'customer', client_ip(request))

        return api_response(True, lang_text('survey.thanks'), None)

    return render(request, 'customer/survey', {
        'page_title': '원격지원 만족도 조사',
        'org': org,
        # 템플릿의 session 은 로그인 세션이므로 원격 세션은 session_row 로 넘긴다(PHP 뷰의 $session).
        'session_row': found,
        'existing': existing,
    }, 'layouts/customer')


@router.get('/{org_code}/chat/{token}')
def chat(request: Request, org_code: str, token: str):
    """팔로업 메일의 '대화 이어 가기' 주소(docs/widget-ops-plan.md 3-5). 고객 접속 페이지에 상담 위젯을 펼쳐 보인다."""
    return _landing(request, org_code, token if _RESUME_TOKEN.match(token) else '')


@org_router.get('/{org_code}')
def index(request: Request, org_code: str):
    """GET /{org_code} — 고객 접속 안내 페이지."""
    return _landing(request, org_code)
