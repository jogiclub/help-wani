"""
파일 위치: backend/app/routers/admin_guide.py
역할: 조직 관리 > 가이드 관리 — 목록, 편집, 미리보기, 이미지 업로드, 외부 이미지 저장, 분류/동의어, guide.json 내려받기/올리기,
      매칭 시험, 공개 페이지 테마와 구성(새 기능 모음, 동영상 모음) (PHP controllers/Admin_guide.php 대응)

조직 관리자만 쓴다(Depends(require_admin)). 상담원은 콘솔에서 검색하고 보내기만 한다(docs/guide-plan.md).
글을 바꾸는 곳은 guide_model.save_article() / set_status() 만 거친다(CLAUDE.md 3.15). CI3 처럼 GET/POST 를 모두 받는다.
"""
import os
import re
import secrets

from fastapi import APIRouter, Depends, Request
from fastapi.responses import Response
from starlette.concurrency import run_in_threadpool

from app.core.deps import require_admin
from app.core.errors import Halt, show_404
from app.core.i18n import to_timezone
from app.core.input import PostData, post_data, query_data
from app.core.middleware import use_org_locale
from app.core.php import php_date, php_empty, php_int, php_json_decode, php_json_encode, php_str
from app.core.request import base_url, client_ip
from app.core.response import api_response
from app.core.templates import render
from app.helpers.app import check_uploaded_image, customer_url
from app.libraries import guide_matcher
from app.models import guide_model, log_model, organization_model
from app.routers.guide import _view_helpers

router = APIRouter(prefix='/admin/guide')

_METHODS = ['GET', 'POST']

IMAGE_MAX_BYTES = 2097152    # 가이드 이미지 최대 크기(2MB)
IMPORT_MAX_BYTES = 2097152   # guide.json 최대 크기(2MB)


def _feature_day(article, tz):
    """출시일(없으면 첫 공개 시각)을 조직 시간대 날짜로(CLAUDE.md 3.4)."""
    moment = guide_model.feature_date(article)
    return to_timezone(moment, tz, 'Y-m-d') if moment else ''


# ------------------------------------------------------------------ 화면

@router.api_route('', methods=_METHODS)
def index(request: Request, agent=Depends(require_admin)):
    org = organization_model.get_by_id(agent.org_id)

    # 처음 열었고 글이 없으면 샘플을 만든다(조직마다 한 번, 모두 임시 상태).
    seeded = guide_model.seed_samples(org, agent.id)
    if seeded > 0:
        log_model.audit(org.id, agent.id, 'guide_seed', 'articles=' + str(seeded), client_ip(request))

    rows = []
    for article in guide_model.list_articles(org.id):
        rows.append({
            'id': int(article.id),
            'category_name': article.category_name,
            'title': article.title,
            'subtitle': article.subtitle,
            'keywords': article.keywords,
            'status': article.status,
            'is_feature': php_int(article.is_feature) == 1,
            'feature_label': php_str(article.feature_label),
            'feature_date': _feature_day(article, org.timezone),
            'view_count': php_int(article.view_count),
            'updated_at': article.updated_at,
            'public_url': guide_model.public_url(org, article),
        })

    synonyms = [', '.join(group) for group in guide_model.synonym_groups(org.id)]
    categories = guide_model.list_categories(org.id)

    return render(request, 'admin/guide', {
        'page_title': '가이드 관리',
        'seeded': seeded,
        'use_grid': True,
        'use_dropzone': True,
        'org': org,
        'rows': rows,
        'categories': categories,
        # PHP 뷰(views/admin/guide.php)가 만들던 분류 목록
        'category_rows': [{'id': int(c.id), 'name': c.name, 'sort_order': php_int(c.sort_order)} for c in categories],
        'synonyms': '\n'.join(synonyms),
        'guide_url': customer_url(org.org_code + '/guide'),
        'themes': guide_model.themes(),
        'theme': guide_model.theme_of(org),
        'page_options': guide_model.page_options(org),
        'new_days': guide_model.new_days_options(),
        'video_sorts': guide_model.video_sorts(),
        'feature_count': guide_model.count_features(org.id),
        'video_count': len(guide_model.public_videos(org.id)),
        # 미리보기는 지금 연 주소로 연다(관리자 로그인 세션이 있어야 ?theme= 를 받는다).
        'preview_base': base_url(org.org_code + '/guide'),
    })


def _edit(request, agent, article_id):
    org = organization_model.get_by_id(agent.org_id)
    article = None

    if article_id > 0:
        article = guide_model.get_article(org.id, article_id)
        if article is None:
            raise Halt(show_404())

    return render(request, 'admin/guide_edit', {
        'page_title': '가이드 편집' if article else '새 가이드',
        'use_dropzone': True,
        'org': org,
        'article': article,
        'categories': guide_model.list_categories(org.id),
        'guide_base': customer_url(org.org_code + '/guide/'),
        # 새 기능 출시일 입력값(조직 시간대 날짜). 지정하지 않은 글은 비워 두고 첫 공개 날짜를 안내한다.
        'feature_date': to_timezone(article.feature_at, org.timezone, 'Y-m-d') if article and not php_empty(article.feature_at) else '',
        'published_on': to_timezone(article.published_at, org.timezone, 'Y-m-d') if article and not php_empty(article.published_at) else '',
        'timezone': org.timezone,
        'public_url': guide_model.public_url(org, article) if article else '',
    })


@router.api_route('/new', methods=_METHODS)
def new(request: Request, agent=Depends(require_admin)):
    return _edit(request, agent, 0)


@router.api_route('/edit/{article_id:int}', methods=_METHODS)
def edit(request: Request, article_id: int, agent=Depends(require_admin)):
    return _edit(request, agent, article_id)


@router.api_route('/preview/{article_id:int}', methods=_METHODS)
def preview(request: Request, article_id: int, agent=Depends(require_admin)):
    """임시 글도 고객 화면과 같은 모양으로 본다. 고객 화면이므로 조직 언어를 쓴다."""
    org = organization_model.get_by_id(agent.org_id)
    article = guide_model.get_article(org.id, article_id)

    if article is None:
        raise Halt(show_404())

    use_org_locale(request, org)

    data = guide_model.page_data(org, article)
    data.update(_view_helpers(data['base'], article))
    data['guide_feature_date'] = guide_model.feature_date
    data['page_title'] = article.title
    data['preview'] = True

    return render(request, 'guide/page', data, 'layouts/guide')


@router.api_route('/export', methods=_METHODS)
def export(request: Request, agent=Depends(require_admin)):
    """조직 가이드 전체를 guide.json 으로 내려준다. 서버에 파일을 남기지 않는다."""
    org = organization_model.get_by_id(agent.org_id)
    data = guide_model.export(org)

    log_model.audit(org.id, agent.id, 'guide_export', 'articles=' + str(len(data['articles'])), client_ip(request))

    name = 'guide-' + re.sub(r'[^A-Za-z0-9_-]', '', php_str(org.org_code)) + '-' + php_date('Ymd') + '.json'
    body = php_json_encode(data, unescaped_unicode=True, unescaped_slashes=True, pretty=True)

    return Response(body.encode('utf-8'), media_type='application/json; charset=utf-8', headers={
        'Content-Disposition': f'attachment; filename="{name}"',
        'Cache-Control': 'no-store',
    })


# ------------------------------------------------------------------ 글

@router.api_route('/api/save', methods=_METHODS)
def api_save(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """blocks 는 JSON 문자열로 받는다. 원문을 받고 출력할 때 이스케이프한다(저장 단계에서 내용을 바꾸지 않는다)."""
    org_id = int(agent.org_id)
    article_id = php_int(post.get('id'))

    if article_id > 0 and guide_model.get_article(org_id, article_id) is None:
        return api_response(False, '가이드를 찾을 수 없습니다.', None, 404)

    if article_id == 0 and guide_model.count_articles(org_id) >= guide_model.MAX_ARTICLES:
        return api_response(False, f'가이드는 조직당 {guide_model.MAX_ARTICLES}건까지 만들 수 있습니다.', None, 400)

    warnings = []
    clean = guide_model.clean_article(org_id, {
        'title': post.get('title'),
        'subtitle': post.get('subtitle'),
        'slug': post.get('slug'),
        'keywords': post.get('keywords'),
        'status': post.get('status'),
        'sort_order': post.get('sort_order'),
        'category_id': post.get('category_id'),
        'blocks': php_str(post.get('blocks')),
        'is_feature': post.get('is_feature'),
        'feature_date': post.get('feature_date'),
        'feature_label': post.get('feature_label'),
    }, warnings)

    if isinstance(clean, str):
        return api_response(False, clean, None, 400)

    if guide_model.slug_taken(org_id, clean['slug'], article_id):
        return api_response(False, '이미 쓰는 주소입니다. 다른 주소를 입력해 주세요.', None, 409)

    saved_id = guide_model.save_article(org_id, article_id, clean, agent.id)
    log_model.audit(org_id, agent.id, 'guide_update' if article_id > 0 else 'guide_create',
                    'article_id=' + str(saved_id) + ' status=' + clean['status'], client_ip(request))

    org = organization_model.get_by_id(org_id)
    article = guide_model.get_article(org_id, saved_id)

    return api_response(True, '가이드를 저장했습니다.' if not warnings else '가이드를 저장했습니다. 일부 블록은 빠졌습니다.', {
        'id': saved_id,
        'warnings': warnings,
        'public_url': guide_model.public_url(org, article),
        'edit_url': base_url('admin/guide/edit/' + str(saved_id)),
    })


@router.api_route('/api/delete', methods=_METHODS)
def api_delete(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    article_id = php_int(post.get('id'))

    if not guide_model.delete_article(agent.org_id, article_id):
        return api_response(False, '가이드를 찾을 수 없습니다.', None, 404)

    log_model.audit(agent.org_id, agent.id, 'guide_delete', 'article_id=' + str(article_id), client_ip(request))
    return api_response(True, '가이드를 삭제했습니다.')


@router.api_route('/api/status', methods=_METHODS)
def api_status(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    article_id = php_int(post.get('id'))
    status = php_str(post.xss('status'))

    if status not in ('draft', 'published') or guide_model.get_article(agent.org_id, article_id) is None:
        return api_response(False, '바꿀 수 없는 상태입니다.', None, 400)

    guide_model.set_status(agent.org_id, article_id, status)
    log_model.audit(agent.org_id, agent.id, 'guide_status', f'article_id={article_id} status={status}', client_ip(request))

    return api_response(True, '공개했습니다.' if status == 'published' else '임시 저장으로 바꿨습니다.', {'status': status})


@router.api_route('/api/image', methods=_METHODS)
async def api_image(agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """multipart(필드명 image). 올린 파일은 글을 저장할 때 블록에 들어가고, 저장하지 않고 남은 파일은 하루 뒤 정리된다."""
    ext, content = await check_uploaded_image(post.files.get('image'), IMAGE_MAX_BYTES)

    if isinstance(ext, dict):
        return api_response(False, ext['error'], None, 400)

    folder = guide_model.image_dir(agent.org_id)
    src = folder + secrets.token_hex(10) + '.' + ext

    try:
        os.makedirs(guide_model.portal_file(folder), mode=0o775, exist_ok=True)
        with open(guide_model.portal_file(src), 'xb') as handle:
            handle.write(content)
    except OSError:
        return api_response(False, '이미지를 저장하지 못했습니다. 업로드 폴더 권한을 확인해 주세요.', None, 500)

    await run_in_threadpool(guide_model.cleanup_images, agent.org_id)

    return api_response(True, '이미지를 올렸습니다.', {'src': src, 'url': base_url(src)})


@router.api_route('/api/localize_images', methods=_METHODS)
def api_localize_images(request: Request, agent=Depends(require_admin)):
    """글의 외부 https 이미지를 받아 조직 폴더에 저장하고 블록 주소를 바꾼다. 받지 못한 이미지는 외부 주소 그대로 둔다.

    이미지가 많으면 오래 걸린다(한 장에 최대 15초). 동기 함수라 스레드 풀에서 돈다.
    """
    org_id = agent.org_id
    result = guide_model.localize_external_images(org_id, agent.id)

    log_model.audit(org_id, agent.id, 'guide_localize_images',
                    'saved=' + str(result['saved']) + ' articles=' + str(result['articles'])
                    + ' failed=' + str(len(result['failed'])), client_ip(request))

    failed = [{'url': item[0], 'reason': item[1]} for item in result['failed'][:20]]

    if result['saved'] == 0 and not result['failed']:
        message = '외부 이미지가 없습니다.'
    else:
        message = ('외부 이미지 ' + str(result['saved']) + '개를 저장했습니다(가이드 ' + str(result['articles']) + '건).'
                   + ('' if not result['failed'] else ' ' + str(len(result['failed'])) + '개는 받지 못해 외부 주소로 남겼습니다.'))

    return api_response(True, message, {
        'saved': result['saved'],
        'articles': result['articles'],
        'failed_count': len(result['failed']),
        'failed': failed,
    })


# ------------------------------------------------------------------ 분류, 동의어, 색인

@router.api_route('/api/category_save', methods=_METHODS)
def api_category_save(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    category_id = php_int(post.get('id'))
    name = php_str(post.get('name')).strip(' \t\n\r\x00\x0b')

    if name == '':
        return api_response(False, '분류 이름을 입력해 주세요.', None, 400)

    if category_id > 0 and guide_model.get_category(agent.org_id, category_id) is None:
        return api_response(False, '분류를 찾을 수 없습니다.', None, 404)

    saved = guide_model.save_category(agent.org_id, category_id, name, post.get('sort_order'))
    log_model.audit(agent.org_id, agent.id, 'guide_category_save', 'category_id=' + str(saved), client_ip(request))

    return api_response(True, '분류를 저장했습니다.', {'id': saved})


@router.api_route('/api/category_delete', methods=_METHODS)
def api_category_delete(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    category_id = php_int(post.get('id'))

    if not guide_model.delete_category(agent.org_id, category_id):
        return api_response(False, '분류를 찾을 수 없습니다.', None, 404)

    log_model.audit(agent.org_id, agent.id, 'guide_category_delete', 'category_id=' + str(category_id), client_ip(request))
    return api_response(True, '분류를 삭제했습니다. 그 분류의 가이드는 분류 없음으로 남습니다.')


@router.api_route('/api/synonyms_save', methods=_METHODS)
def api_synonyms_save(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """한 줄에 한 묶음, 쉼표로 낱말을 나눈다."""
    groups = [line.split(',') for line in re.split(r'\r\n|\r|\n', php_str(post.get('synonyms')))]

    saved = guide_model.replace_synonyms(agent.org_id, groups)
    log_model.audit(agent.org_id, agent.id, 'guide_synonyms_save', 'groups=' + str(saved), client_ip(request))

    return api_response(True, '동의어 ' + str(saved) + '묶음을 저장했습니다.', {'count': saved})


@router.api_route('/api/theme_save', methods=_METHODS)
def api_theme_save(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """공개 가이드 화면 테마. 허용 목록(guide_model.themes) 밖의 값은 받지 않는다."""
    theme = php_str(post.xss('theme'))

    if not guide_model.set_theme(agent.org_id, theme):
        return api_response(False, '고를 수 없는 테마입니다.', None, 400)

    log_model.audit(agent.org_id, agent.id, 'guide_theme', 'theme=' + theme, client_ip(request))

    return api_response(True, '공개 페이지 테마를 "' + guide_model.themes()[theme]['name'] + '" 으로 바꿨습니다.', {'theme': theme})


@router.api_route('/api/page_options_save', methods=_METHODS)
def api_page_options_save(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """공개 페이지 구성(새 기능 모음, 동영상 모음). 값은 guide_model.set_page_options 가 허용 목록으로 검사한다."""
    saved = guide_model.set_page_options(agent.org_id, {
        key: post.get(key) for key in
        ('feature_enabled', 'feature_new_days', 'feature_banner', 'video_enabled', 'video_sort', 'video_filter')
    })

    if isinstance(saved, str):
        return api_response(False, saved, None, 400)

    detail = ' '.join(key + '=' + (str(int(value)) if isinstance(value, bool) else str(value)) for key, value in saved.items())
    log_model.audit(agent.org_id, agent.id, 'guide_page_options', detail, client_ip(request))

    return api_response(True, '공개 페이지 구성을 저장했습니다.', {'options': saved})


@router.api_route('/api/reindex', methods=_METHODS)
def api_reindex(request: Request, agent=Depends(require_admin)):
    """매칭 설정(settings/guide_match.py)을 바꾼 뒤 색인을 한꺼번에 다시 만든다."""
    terms = guide_model.rebuild_org_index(agent.org_id)
    log_model.audit(agent.org_id, agent.id, 'guide_reindex', 'terms=' + str(terms), client_ip(request))

    return api_response(True, '색인을 다시 만들었습니다. (낱말 ' + str(terms) + '개)', {'terms': terms})


@router.api_route('/api/match_test', methods=_METHODS)
def api_match_test(agent=Depends(require_admin), query: PostData = Depends(query_data)):
    """?q= 문장을 넣으면 어떤 낱말로 바뀌고 어떤 글이 몇 점으로 나오는지 보여준다. 키워드를 다듬을 때 쓴다."""
    q = php_str(query.get('q'))[:500]
    org_id = int(agent.org_id)

    return api_response(True, '', {
        'terms': guide_matcher.query_terms(q, guide_model.synonym_groups(org_id)),
        'results': guide_matcher.match(org_id, q, 'search', 10),
        'min': {
            'console': guide_matcher.min_score('console'),
            'widget': guide_matcher.min_score('widget'),
        },
    })


# ------------------------------------------------------------------ guide.json 올리기

@router.api_route('/api/import', methods=_METHODS)
async def api_import(request: Request, agent=Depends(require_admin), post: PostData = Depends(post_data)):
    """multipart(필드명 guide), mode = merge | replace"""
    upload = post.files.get('guide')

    if upload is None or isinstance(upload, dict) or not upload.filename:
        return api_response(False, 'guide.json 파일을 선택해 주세요.', None, 400)

    content = await upload.read()
    if len(content) > IMPORT_MAX_BYTES:
        return api_response(False, '파일은 2MB 이하로 올려 주세요.', None, 400)

    data = php_json_decode(content)
    if not isinstance(data, (dict, list)):
        return api_response(False, 'JSON 형식이 아닙니다. 내려받은 guide.json 을 그대로 올려 주세요.', None, 400)

    mode = 'replace' if post.get('mode') == 'replace' else 'merge'
    result = await run_in_threadpool(guide_model.import_guide, agent.org_id, data, mode, agent.id)

    if isinstance(result, str):
        return api_response(False, result, None, 400)

    await run_in_threadpool(
        log_model.audit, agent.org_id, agent.id, 'guide_import',
        'mode=' + mode + ' created=' + str(result['created']) + ' updated=' + str(result['updated'])
        + ' skipped=' + str(len(result['skipped'])), client_ip(request))

    return api_response(True, '가져왔습니다. 새 글 ' + str(result['created']) + '건, 바뀐 글 ' + str(result['updated'])
                        + '건, 건너뜀 ' + str(len(result['skipped'])) + '건', result)
