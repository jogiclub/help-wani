"""
파일 위치: backend/app/models/guide_model.py
역할: 조직 가이드(분류, 글, 동의어) 조회와 공개 화면 데이터 (PHP models/Guide_model.php 대응)

원본은 DB 이고 guide.json 은 주고받는 형식이다(CLAUDE.md 3.15). 모든 조회는 org_id 로 거른다.
3단계에서는 공개 화면(/{조직코드}/guide)에 필요한 함수만 옮겼다. 저장·검사·색인·가져오기는 가이드 관리를 옮길 때(6단계)
PHP 와 같은 이름(save_article, set_status, clean_article ...)으로 여기에 더한다. 글을 바꾸는 곳은 그 함수들만 거친다.
"""
import json
import re
from urllib.parse import quote, urlencode
from zoneinfo import available_timezones

from app import db
from app.core.i18n import lang_text, to_timezone
from app.core.php import php_empty, php_int, utc_str
from app.core.request import base_url
from app.helpers.app import customer_url
from app.helpers.php_filter import validate_https_url
from app.libraries import guide_matcher

# guide.json 형식 이름과 버전
FORMAT = 'remotehelp-guide'
VERSION = 1

MAX_ARTICLES = 500
MAX_BLOCKS = 50
MAX_TEXT = 5000
MAX_KEYWORDS = 20

# 공개 가이드의 모음 페이지 주소(/{조직코드}/guide/{낱말}). 글 주소(slug)로 쓸 수 없다.
RESERVED_SLUGS = ('features', 'videos')

# 새 기능 모음 한 쪽 개수, 동영상 모음 한 쪽 개수, [더 보기] 최대 쪽
FEATURE_PAGE = 20
VIDEO_PAGE = 12
MAX_PAGE = 50

_YOUTUBE_ID = re.compile(r'^[A-Za-z0-9_-]{11}$')
_ACCENT = re.compile(r'^#[0-9a-fA-F]{6}$')


def _or(value, fallback):
    """PHP $value ?: $fallback 대응."""
    return fallback if php_empty(value) else value


# ------------------------------------------------------------------ 분류

def list_categories(org_id):
    return db.query('SELECT * FROM guide_categories WHERE org_id = :org ORDER BY sort_order ASC, id ASC',
                    {'org': int(org_id)})


def get_category(org_id, category_id):
    return db.first_where('guide_categories', {'org_id': int(org_id), 'id': php_int(category_id)})


# ------------------------------------------------------------------ 글

def list_articles(org_id, published_only=False):
    """관리 화면·공개 목차 목록. 본문 블록은 빼고 내려준다. 분류 없는 글은 맨 뒤."""
    sql = ('SELECT a.id, a.category_id, a.slug, a.title, a.subtitle, a.keywords, a.status, '
           'a.is_feature, a.feature_at, a.feature_label, a.published_at, '
           'a.sort_order, a.view_count, a.updated_at, a.created_at, '
           'c.name AS category_name, c.sort_order AS category_sort '
           'FROM guide_articles a LEFT JOIN guide_categories c ON c.id = a.category_id '
           'WHERE a.org_id = :org ')
    if published_only:
        sql += "AND a.status = 'published' "
    sql += 'ORDER BY a.category_id IS NULL ASC, c.sort_order ASC, a.category_id ASC, a.sort_order ASC, a.id ASC'
    return db.query(sql, {'org': int(org_id)})


def count_published(org_id):
    return int(db.scalar("SELECT COUNT(*) FROM guide_articles WHERE org_id = :org AND status = 'published'",
                         {'org': int(org_id)}))


def _decode(found):
    """blocks JSON 을 목록으로. PHP json_decode(..., TRUE) 가 배열이 아니면 빈 배열."""
    if found is not None:
        try:
            blocks = json.loads(found.blocks or '')
        except (TypeError, ValueError):
            blocks = None
        if isinstance(blocks, dict):
            blocks = list(blocks.values())
        found['blocks'] = blocks if isinstance(blocks, list) else []
    return found


def get_article(org_id, article_id):
    return _decode(db.first_where('guide_articles', {'org_id': int(org_id), 'id': php_int(article_id)}))


def get_published(org_id, slug_or_id):
    """공개 주소의 slug 또는 숫자 id 로 공개 글을 찾는다."""
    slug_or_id = str(slug_or_id)
    if re.fullmatch(r'[0-9]+', slug_or_id):  # PHP ctype_digit
        where = {'org_id': int(org_id), 'status': 'published', 'id': php_int(slug_or_id)}
    else:
        where = {'org_id': int(org_id), 'status': 'published', 'slug': slug_or_id}
    return _decode(db.first_where('guide_articles', where))


def public_url(org, article):
    """고객에게 보내는 가이드 주소. 상담원이 localhost 로 콘솔을 열어도 공개 주소가 나가야 한다(CLAUDE.md 3.13)."""
    target = _or(article.get('slug'), article.get('id'))
    return customer_url(org['org_code'] + '/guide/' + quote(str(target), safe='-_.~'))


def card(org, article):
    """상담 메시지에 붙이는 가이드 카드 정보(messages.meta)."""
    return {
        'type': 'guide',
        'article_id': php_int(article.get('id')),
        'title': article.get('title'),
        'subtitle': str(article.get('subtitle') or ''),
        'url': public_url(org, article),
    }


def increment_view(article_id):
    # 조회수만 올리고 수정 시각은 그대로 둔다.
    db.execute('UPDATE guide_articles SET view_count = view_count + 1, updated_at = updated_at WHERE id = :id',
               {'id': int(article_id)})


_timezones = {}


def org_timezone(org_id):
    """조직 시간대. 올바른 IANA 이름이 아니면 Asia/Seoul."""
    org_id = int(org_id)
    if org_id not in _timezones:
        found = db.row('SELECT timezone FROM organizations WHERE id = :id', {'id': org_id})
        tz = str(found.timezone or '') if found else ''
        _timezones[org_id] = tz if tz in available_timezones() else 'Asia/Seoul'
    return _timezones[org_id]


def feature_date(article):
    """새 기능 출시일(UTC). 지정한 출시일이 없으면 처음 공개한 시각."""
    if not php_empty(article.get('feature_at')):
        return article['feature_at']
    return None if php_empty(article.get('published_at')) else article['published_at']


def is_external_image(src):
    """외부 이미지 주소로 받을 수 있는지. https 만 받는다(https 화면에 http 이미지는 브라우저가 막는다)."""
    src = str(src or '')
    if len(src.encode('utf-8')) > 1000 or re.match(r'^https://[^\s"\'<>]+$', src, re.IGNORECASE) is None:
        return False
    return validate_https_url(src)


def synonym_groups(org_id):
    """동의어 묶음 [[낱말, 낱말], ...]."""
    out = []
    for found in db.query('SELECT words FROM guide_synonyms WHERE org_id = :org ORDER BY id', {'org': int(org_id)}):
        items = [w.strip(' \t\n\r\x00\x0b') for w in str(found.words).split(',')]
        items = [w for w in items if len(w) > 0]
        if len(items) >= 2:
            out.append(items)
    return out


# ------------------------------------------------------------------ 테마, 공개 페이지 구성

def themes():
    """공개 가이드 테마. 키는 organizations.guide_theme 값이자 templates/guide/themes/{키}.html 이름. 순서가 관리 화면 번호."""
    return {
        'card': {'name': '기본 카드형', 'desc': '가운데 한 줄로 분류와 가이드를 보여줍니다. 가이드가 적을 때 알맞습니다.'},
        'docs': {'name': '문서형 (왼쪽 목차)', 'desc': '왼쪽에 번호 목차를 두고 오른쪽에 본문을 읽습니다. 사용 설명서처럼 글이 많을 때 알맞습니다.'},
        'portal': {'name': '헬프센터형', 'desc': '큰 검색창과 분류 카드로 시작합니다. 고객이 먼저 검색하도록 이끌 때 알맞습니다.'},
        'faq': {'name': 'FAQ 아코디언형', 'desc': '질문을 누르면 그 자리에서 답이 펼쳐집니다. 짧은 자주 묻는 질문에 알맞습니다.'},
        'dark': {'name': '다크 미니멀형', 'desc': '어두운 배경에 큰 글씨로 읽습니다. 기술 서비스나 야간 사용이 많을 때 알맞습니다.'},
    }


def theme_of(org):
    """조직 테마. 허용 목록 밖이면 기본(card)."""
    theme = str(org['guide_theme']) if org.get('guide_theme') is not None else 'card'
    return theme if theme in themes() else 'card'


def new_days_options():
    """NEW 배지 기간(일). 0 은 끔."""
    return [0, 7, 14, 30, 60, 90]


def video_sorts():
    return {'toc': '가이드 목차 순', 'updated': '최근 수정순'}


def page_options(org):
    """조직의 공개 페이지 구성. 옵션 목록과 기본값은 여기 한 곳에 둔다."""
    days = php_int(org['guide_feature_new_days']) if org.get('guide_feature_new_days') is not None else 30
    sort = str(org['guide_video_sort']) if org.get('guide_video_sort') is not None else 'toc'

    return {
        'feature_enabled': not php_empty(org.get('guide_feature_enabled')),
        'feature_new_days': days if days in new_days_options() else 30,
        'feature_banner': not php_empty(org.get('guide_feature_banner')),
        'video_enabled': not php_empty(org.get('guide_video_enabled')),
        'video_sort': sort if sort in video_sorts() else 'toc',
        'video_filter': not php_empty(org.get('guide_video_filter')),
    }


def public_features(org_id, limit):
    """새 기능으로 표시된 공개 글. 출시일(없으면 첫 공개 시각) 최신순. 각 행에 feature_date(UTC) 를 붙인다."""
    rows = db.query(
        'SELECT id, slug, title, subtitle, category_id, feature_at, feature_label, published_at, updated_at '
        "FROM guide_articles WHERE org_id = :org AND status = 'published' AND is_feature = 1 "
        f'ORDER BY COALESCE(feature_at, published_at, updated_at) DESC, id DESC LIMIT {int(limit)}',
        {'org': int(org_id)})
    for found in rows:
        found['feature_date'] = _or(feature_date(found), found.updated_at)
    return rows


def new_feature_ids(org):
    """NEW 배지를 붙일 글 id 집합. 새 기능 모음이 꺼져 있거나 기간이 0 이면 빈 집합."""
    options = page_options(org)
    if not options['feature_enabled'] or options['feature_new_days'] == 0:
        return set()

    since = utc_str(-options['feature_new_days'] * 86400)
    rows = db.query(
        "SELECT id FROM guide_articles WHERE org_id = :org AND status = 'published' AND is_feature = 1 "
        'AND COALESCE(feature_at, published_at) >= :since',
        {'org': int(org['id']), 'since': since})
    return {int(found.id) for found in rows}


def public_videos(org_id, sort='toc'):
    """공개 글의 유튜브 동영상을 모은다. 따로 저장하지 않고 본문 블록에서 그때그때 꺼낸다.

    같은 영상이 여러 글에 있으면 처음 나온 것 하나만 두고, 그 영상이 있는 글을 articles 에 함께 담는다.
    """
    sql = ('SELECT a.id, a.slug, a.title, a.category_id, a.blocks, c.name AS category_name '
           'FROM guide_articles a LEFT JOIN guide_categories c ON c.id = a.category_id '
           "WHERE a.org_id = :org AND a.status = 'published' "
           "AND JSON_SEARCH(a.blocks, 'one', 'video', NULL, '$[*].type') IS NOT NULL ")
    if sort == 'updated':
        sql += 'ORDER BY a.updated_at DESC, a.id DESC'
    else:
        # list_articles() 와 같은 목차 순서
        sql += 'ORDER BY a.category_id IS NULL ASC, c.sort_order ASC, a.category_id ASC, a.sort_order ASC, a.id ASC'

    videos = {}
    for found in db.query(sql, {'org': int(org_id)}):
        found = _decode(found)
        article = {'id': int(found.id), 'slug': found.slug, 'title': found.title}

        for block in found.blocks:
            if (not isinstance(block, dict) or block.get('type') != 'video' or block.get('youtube_id') is None
                    or not _YOUTUBE_ID.match(str(block['youtube_id']))):
                continue

            video_id = block['youtube_id']
            if video_id in videos:
                if article not in videos[video_id]['articles']:
                    videos[video_id]['articles'].append(article)
                continue

            videos[video_id] = {
                'youtube_id': video_id,
                'caption': str(block['caption']) if block.get('caption') is not None else '',
                'category_id': php_int(found.category_id) if not php_empty(found.category_id) else 0,
                'category_name': _or(found.category_name, lang_text('guide.uncategorized')),
                'articles': [article],
            }

    return list(videos.values())


def public_nav(org_id):
    """공개 목차. 분류별 묶음과, 이전/다음 글을 찾기 위한 한 줄 목록."""
    groups, flat = {}, []
    for article in list_articles(org_id, True):
        key = php_int(article.category_id) if not php_empty(article.category_id) else 0
        if key not in groups:
            groups[key] = {
                'id': key,
                'name': _or(article.category_name, lang_text('guide.uncategorized')),
                'articles': [],
            }
        groups[key]['articles'].append(article)
        flat.append(article)
    return {'groups': list(groups.values()), 'flat': flat}


def title_contains(org_id, q):
    rows = db.query(
        "SELECT id, slug, title, subtitle FROM guide_articles WHERE org_id = :org AND status = 'published' "
        "AND (title LIKE :q ESCAPE '!' OR subtitle LIKE :q ESCAPE '!') LIMIT 20",
        {'org': int(org_id), 'q': db.like_pattern(q)})
    return [{'id': int(r.id), 'slug': r.slug, 'title': r.title, 'subtitle': r.subtitle, 'score': 0, 'hits': 0}
            for r in rows]


def page_data(org, article=None, q='', theme=None, view='list', params=None):
    """공개 화면(목록/검색/상세) 공통 데이터. 공개 화면과 관리자 미리보기가 같은 값을 쓴다.

    테마 템플릿(templates/guide/themes/*.html)은 이 값만 보고 그린다.
    view: list | features | videos (모음 페이지는 Guide 경로가 옵션을 확인한 뒤 넘긴다)
    """
    params = params or {}
    nav = public_nav(org['id'])
    results = None
    view = view if view in ('features', 'videos') else 'list'

    if article is None and q != '':
        results = guide_matcher.match(org['id'], q, 'search', 20)
        # 낱말이 하나도 안 맞으면 제목/부제목에 그대로 들어 있는지 한 번 더 본다(1글자 질문 등).
        if not results:
            results = title_contains(org['id'], q)

    # 상세: 분류 이름과 목차상 이전/다음 글
    prev = nxt = None
    category = ''
    if article is not None:
        flat = nav['flat']
        for index, item in enumerate(flat):
            if int(item.id) == int(article.id):
                prev = flat[index - 1] if index > 0 else None
                nxt = flat[index + 1] if index + 1 < len(flat) else None
                category = _or(item.category_name, '')

        if category == '' and not php_empty(article.category_id):
            found = get_category(org['id'], article.category_id)
            category = found.name if found else ''

    theme = theme or theme_of(org)

    # FAQ 테마는 목록에서 답을 그 자리에서 펼치므로 본문까지 읽는다.
    bodies = {}
    if theme == 'faq' and article is None and results is None:
        for found in db.query("SELECT id, blocks, updated_at FROM guide_articles WHERE org_id = :org AND status = 'published'",
                              {'org': int(org['id'])}):
            bodies[int(found.id)] = _decode(found)

    # 테마 강조색은 상담 위젯 색을 따른다. 허용 형식이 아니면 기본 파랑.
    accent = org['widget_color'] if _ACCENT.match(str(org.get('widget_color') or '')) else '#2563eb'

    base = base_url(org['org_code'] + '/guide')
    options = page_options(org)
    collection = _collection_data(org, view, options, base, params)

    # 첫 화면(검색 전 목록)의 최근 새 기능 띠
    strip = []
    if view == 'list' and article is None and results is None and options['feature_enabled'] and options['feature_banner']:
        strip = public_features(org['id'], 3)

    # 탭은 켜 둔 모음이 있을 때만 보인다. 관리자 미리보기로 연 모음은 꺼져 있어도 탭에 넣는다.
    tabs = []
    if options['feature_enabled'] or options['video_enabled'] or view != 'list':
        tabs.append({'key': 'list', 'label': lang_text('guide.tab_all'), 'url': base})
        if options['feature_enabled'] or view == 'features':
            tabs.append({'key': 'features', 'label': lang_text('guide.tab_features'), 'url': base + '/features'})
        if options['video_enabled'] or view == 'videos':
            tabs.append({'key': 'videos', 'label': lang_text('guide.tab_videos'), 'url': base + '/videos'})

    data = {
        'view': view,
        'options': options,
        'tabs': tabs,
        'strip': strip,
        'new_ids': new_feature_ids(org),
        'timezone': org_timezone(org['id']),
        'org': org,
        'theme': theme,
        'bodies': bodies,
        'accent': accent,
        'base': base,
        'groups': nav['groups'],
        'total': len(nav['flat']),
        'q': q,
        'results': results,
        'article': article,
        'category': category,
        'prev': prev,
        'next': nxt,
    }
    # PHP $collection + array(...): 같은 키가 있으면 왼쪽(collection)이 이긴다.
    data.update(collection)
    return data


def _collection_data(org, view, options, base, params):
    """모음 페이지 데이터. [더 보기] 는 page 쪽까지 이어서 보여준다(?page=2 면 처음부터 2쪽 분량)."""
    page = max(1, min(MAX_PAGE, php_int(params['page']))) if 'page' in params else 1
    data = {'page': page, 'more_url': '', 'features': [], 'videos': [],
            'video_total': 0, 'video_categories': {}, 'video_category': 0}

    if view == 'features':
        limit = page * FEATURE_PAGE
        rows = public_features(org['id'], limit + 1)
        tz = org_timezone(org['id'])

        if len(rows) > limit:
            rows = rows[:limit]
            data['more_url'] = base + '/features?page=' + str(page + 1)

        # 조직 시간대 기준 월별 묶음
        months = {}
        for found in rows:
            month = to_timezone(found.feature_date, tz, 'Y-m')
            if month not in months:
                year, _, mon = month.partition('-')
                months[month] = {
                    'label': lang_text('guide.month_label', {'year': year, 'month': php_int(mon)}),
                    'items': [],
                }
            months[month]['items'].append(found)
        data['features'] = list(months.values())

    elif view == 'videos':
        videos = public_videos(org['id'], options['video_sort'])

        # 분류 거르기: 동영상이 있는 분류만 고를 수 있다(허용 목록).
        if options['video_filter']:
            for video in videos:
                data['video_categories'][video['category_id']] = video['category_name']

            category = php_int(params['category']) if 'category' in params else 0
            if category in data['video_categories'] and len(data['video_categories']) > 1:
                data['video_category'] = category
                videos = [v for v in videos if v['category_id'] == category]

        limit = page * VIDEO_PAGE
        data['video_total'] = len(videos)
        data['videos'] = videos[:limit]

        if len(videos) > limit:
            query = {'page': page + 1}
            if data['video_category'] > 0:
                query['category'] = data['video_category']
            data['more_url'] = base + '/videos?' + urlencode(query)

    return data
