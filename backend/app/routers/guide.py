"""
파일 위치: backend/app/routers/guide.py
역할: 고객이 보는 공개 가이드 — 목록, 검색, 상세, 새 기능 모음, 동영상 모음

/{조직코드}/guide 아래 경로다. 고객 화면이므로 조직이 정한 언어를 쓴다(CLAUDE.md 3.2). 공개(published) 글만 보인다.
화면 모양은 조직이 고른 테마(organizations.guide_theme)를 따른다. 모음 페이지는 조직이 켤 때만 열린다(docs/guide-collections-plan.md).
모음 주소(features, videos)는 글 주소({slug})보다 먼저 등록한다(CLAUDE.md 3.15). 이 라우터는 조직 코드 라우터보다 먼저 등록한다.
"""
from urllib.parse import quote

from fastapi import APIRouter, Depends, Request

from app.core.errors import Halt, show_404
from app.core.i18n import lang_text
from app.core.input import PostData, query_data
from app.core.middleware import use_org_locale
from app.core.php import php_empty, php_int, php_str
from app.core.templates import render
from app.models import guide_model, organization_model

router = APIRouter()


def _require_org(request, org_code):
    org = organization_model.get_active_by_code(org_code)
    if org is None:
        raise Halt(show_404())
    use_org_locale(request, org)
    return org


def _is_org_admin(request, org):
    return (request.session.get('agent_role') == 'admin'
            and php_int(request.session.get('org_id')) == int(org.id))


def _preview_theme(request, org, query):
    """?theme= 로 다른 테마를 미리 본다. 그 조직 관리자로 로그인한 경우에만 받는다(가이드 관리 > 테마 미리보기)."""
    theme = php_str(query.get('theme'))
    if theme == '' or theme not in guide_model.themes():
        return None
    return theme if _is_org_admin(request, org) else None


def _require_collection(request, org, option):
    """모음 페이지는 조직 옵션을 켰을 때만 연다. 꺼져 있어도 그 조직 관리자 세션이면 미리 볼 수 있다."""
    if not guide_model.page_options(org)[option] and not _is_org_admin(request, org):
        raise Halt(show_404())


def _view_helpers(base, article):
    """PHP views/guide/page.php 의 $link, $is_current 클로저."""
    def link(item):
        target = item.get('slug')
        if php_empty(target):
            target = item.get('id')
        return base + '/' + quote(php_str(target), safe='-_.~')

    def is_current(item):
        return article is not None and int(article.id) == php_int(item.get('id'))

    return {'link': link, 'is_current': is_current}


def _render_page(request, org, article, q, title, query, view='list'):
    data = guide_model.page_data(org, article, q, _preview_theme(request, org, query), view, {
        'page': php_int(query.get('page')),
        'category': php_int(query.get('category')),
    })
    data.update(_view_helpers(data['base'], article))
    data['guide_feature_date'] = guide_model.feature_date
    data['page_title'] = title
    data['preview'] = False
    # 조직이 상담 위젯을 켜 두었으면 가이드를 읽다가 바로 상담을 열 수 있게 한다.
    data['widget_key'] = php_str(org.widget_key) if php_int(org.widget_enabled) == 1 else ''

    return render(request, 'guide/page', data, 'layouts/guide')


@router.get('/{org_code}/guide')
def index(request: Request, org_code: str, query: PostData = Depends(query_data)):
    """GET /{org_code}/guide?q="""
    org = _require_org(request, org_code)
    q = php_str(query.get('q'))[:100].strip(' \t\n\r\x00\x0b')
    return _render_page(request, org, None, q, lang_text('guide.page_title', {'org': org.name}), query)


@router.get('/{org_code}/guide/features')
def features(request: Request, org_code: str, query: PostData = Depends(query_data)):
    """GET /{org_code}/guide/features?page="""
    org = _require_org(request, org_code)
    _require_collection(request, org, 'feature_enabled')
    return _render_page(request, org, None, '', lang_text('guide.features_title') + ' - ' + org.name, query, 'features')


@router.get('/{org_code}/guide/videos')
def videos(request: Request, org_code: str, query: PostData = Depends(query_data)):
    """GET /{org_code}/guide/videos?page=&category="""
    org = _require_org(request, org_code)
    _require_collection(request, org, 'video_enabled')
    return _render_page(request, org, None, '', lang_text('guide.videos_title') + ' - ' + org.name, query, 'videos')


@router.get('/{org_code}/guide/{slug}')
def article(request: Request, org_code: str, slug: str, query: PostData = Depends(query_data)):
    """GET /{org_code}/guide/{slug 또는 번호}"""
    org = _require_org(request, org_code)
    found = guide_model.get_published(org.id, slug)

    if found is None:
        raise Halt(show_404())

    # 같은 브라우저에서 다시 열면 조회수를 올리지 않는다.
    seen = request.session.get('guide_seen')
    seen = list(seen) if isinstance(seen, list) else []

    if int(found.id) not in seen:
        guide_model.increment_view(found.id)
        seen.append(int(found.id))
        request.session['guide_seen'] = seen[-200:]

    return _render_page(request, org, found, '', found.title, query)
