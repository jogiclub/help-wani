"""
파일 위치: backend/app/models/guide_model.py
역할: 조직 가이드(분류, 글, 동의어) 저장/조회, 입력 검사, 매칭 색인 갱신, 외부 이미지 저장, guide.json 내보내기/가져오기,
      공개 화면 데이터 (PHP models/Guide_model.php 대응)

원본은 DB 이고 guide.json 은 주고받는 형식이다(CLAUDE.md 3.15). 모든 조회는 org_id 로 거른다.
화면 저장과 파일 가져오기는 같은 검사(clean_article)를 거치고, 글을 바꾸는 곳은 save_article() / set_status() 만 거친다(색인을 함께 다시 만든다).
서버가 외부 이미지를 받는 곳은 fetch_external_image() 하나다(https, 공인 IPv4, 확인한 IP 로 고정 접속, 리다이렉트마다 다시 검사).
"""
import http.client
import json
import os
import re
import secrets
import shutil
import socket
import ssl
import time
from datetime import date, datetime, timezone
from urllib.parse import quote, urlencode, urljoin
from zoneinfo import ZoneInfo, available_timezones

from app import config, db
from app.core.i18n import lang_text, to_timezone
from app.core.php import PCRE_U_SPACE, php_date, php_empty, php_int, php_json_decode, php_json_encode, php_str, utc_str
from app.core.request import base_url
from app.helpers.app import customer_url, image_file_ext
from app.helpers.php_filter import validate_https_url
from app.helpers.php_url import parse_str, parse_url
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


def org_timezone(org_id):
    """조직 시간대. 올바른 IANA 이름이 아니면 Asia/Seoul.

    PHP 는 모델 객체(요청 하나)에만 기억한다. 여기서 모듈 전역에 기억하면 조직 정보에서 시간대를 바꿔도
    프로세스가 끝날 때까지 예전 값을 쓰므로 매번 읽는다.
    """
    global _tz_names
    if _tz_names is None:
        _tz_names = available_timezones()
    found = db.row('SELECT timezone FROM organizations WHERE id = :id', {'id': int(org_id)})
    tz = str(found.timezone or '') if found else ''
    return tz if tz in _tz_names else 'Asia/Seoul'


_tz_names = None


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


# ================================================================== 저장·검사·색인 (가이드 관리, 6단계)

def _is_array(value):
    return isinstance(value, (dict, list))


def _value(array, key, default=''):
    """PHP value(): 배열이고 isset($array[$key]) 이면 그 값, 아니면 기본값(null 도 기본값)."""
    if isinstance(array, dict):
        found = array.get(key)
        return default if found is None else found
    return default


def _nullable(text):
    return None if text == '' else text


def _trim(text):
    """PHP trim() 기본 글자."""
    return text.strip(' \t\n\r\x00\x0b')


def _ascii_lower(text):
    """PHP 8.2 strtolower(): ASCII 만 바꾼다(유니코드 소문자화는 'K'(U+212A) 를 'k' 로 바꾸는 등 결과가 다르다)."""
    return ''.join(chr(ord(ch) + 32) if 'A' <= ch <= 'Z' else ch for ch in text)


def _ctype_digit(text):
    return text != '' and all('0' <= ch <= '9' for ch in text)


def _values(value):
    """PHP 배열 값 목록(dict 는 값 순서대로)."""
    if isinstance(value, dict):
        return list(value.values())
    return list(value) if isinstance(value, list) else []


def _strtr(text, pairs):
    """PHP strtr($text, array): 긴 키부터, 바꾼 글자는 다시 보지 않는다."""
    keys = sorted((k for k in pairs if k != ''), key=len, reverse=True)
    if not keys:
        return text
    return re.sub('|'.join(re.escape(k) for k in keys), lambda m: pairs[m.group(0)], text)


def count_articles(org_id):
    return int(db.scalar('SELECT COUNT(*) FROM guide_articles WHERE org_id = :org', {'org': int(org_id)}))


def save_category(org_id, category_id, name, sort_order):
    data = {'name': _trim(php_str(name))[:60], 'sort_order': php_int(sort_order)}
    if php_int(category_id) > 0:
        db.update('guide_categories', data, {'org_id': int(org_id), 'id': php_int(category_id)})
        return php_int(category_id)
    data['org_id'] = int(org_id)
    return db.insert('guide_categories', data)


def delete_category(org_id, category_id):
    """분류를 지운다. 그 분류의 글은 지우지 않고 '분류 없음' 으로 남는다(FK SET NULL)."""
    return db.execute('DELETE FROM guide_categories WHERE org_id = :org AND id = :id',
                      {'org': int(org_id), 'id': php_int(category_id)}).rowcount


def _article_update(org_id, article_id, data, mark_published):
    """UPDATE guide_articles. 공개로 바꾸면 처음 공개한 시각(published_at)을 함께 찍는다(이미 찍힌 값은 그대로)."""
    sets, params = [], {'org': int(org_id), 'id': php_int(article_id)}
    if mark_published:
        sets.append('`published_at` = IFNULL(published_at, :now)')
        params['now'] = utc_str()
    for index, (column, value) in enumerate(data.items()):
        sets.append(f'`{column}` = :s{index}')
        params[f's{index}'] = value
    db.execute(f"UPDATE guide_articles SET {', '.join(sets)} WHERE org_id = :org AND id = :id", params)


def save_article(org_id, article_id, clean, agent_id=None):
    """검사를 마친 글(clean_article 결과)을 저장하고 색인을 다시 만든다. 블록에서 빠진 이미지 파일은 지운다. 글 id 를 돌려준다."""
    article_id = php_int(article_id)
    old_images = []

    if article_id > 0:
        old = get_article(org_id, article_id)
        old_images = image_paths(old.blocks) if old else []

    data = {
        'category_id': clean['category_id'],
        'slug': clean['slug'],
        'title': clean['title'],
        'subtitle': clean['subtitle'],
        'keywords': clean['keywords'],
        'blocks': php_json_encode(clean['blocks'], unescaped_unicode=True, unescaped_slashes=True),
        'status': clean['status'],
        'sort_order': clean['sort_order'],
        'is_feature': 0 if php_empty(clean.get('is_feature')) else 1,
        'feature_at': clean.get('feature_at'),
        'feature_label': clean.get('feature_label'),
        'updated_by': None if agent_id is None else int(agent_id),
    }

    if article_id > 0:
        _article_update(org_id, article_id, data, clean['status'] == 'published')
    else:
        data['org_id'] = int(org_id)
        data['published_at'] = utc_str() if clean['status'] == 'published' else None
        article_id = db.insert('guide_articles', data)

    rebuild_index(org_id, article_id)
    new_images = image_paths(clean['blocks'])
    remove_images(org_id, [path for path in old_images if path not in new_images])

    return int(article_id)


def delete_article(org_id, article_id):
    article = get_article(org_id, article_id)
    if article is None:
        return 0

    # 색인은 FK CASCADE 로 함께 지워진다.
    db.execute('DELETE FROM guide_articles WHERE org_id = :org AND id = :id', {'org': int(org_id), 'id': int(article.id)})
    remove_images(org_id, image_paths(article.blocks))
    return 1


def set_status(org_id, article_id, status):
    if status not in ('draft', 'published'):
        return 0
    _article_update(org_id, article_id, {'status': status}, status == 'published')
    rebuild_index(org_id, article_id)
    return 1


def slug_taken(org_id, slug, except_id=0):
    if slug is None or slug == '':
        return False
    return int(db.scalar('SELECT COUNT(*) FROM guide_articles WHERE org_id = :org AND slug = :slug AND id != :id',
                         {'org': int(org_id), 'slug': slug, 'id': php_int(except_id)})) > 0


# ------------------------------------------------------------------ 입력 검사 (화면 저장과 guide.json 가져오기 공통)

_SLUG_RE = re.compile(r'^[a-z0-9]+(?:[-_][a-z0-9]+)*$')
_DATE_RE = re.compile(r'^[0-9]{4}-[0-9]{2}-[0-9]{2}$')


def clean_article(org_id, data_in, warnings=None):
    """글 입력을 검사하고 저장할 값으로 바꾼다. 허용 목록 밖의 값은 버리고, 통과하지 못한 블록은 빼고 warnings 에 사유를 남긴다.

    data_in: title, subtitle, slug, keywords(문자열|배열), status, sort_order, category_id, blocks(목록|JSON 문자열),
             is_feature/feature_date/feature_label 또는 feature: {date, label}
    결과: 저장할 값(dict) 또는 거부 사유(str)
    """
    if warnings is None:
        warnings = []

    title = _trim(php_str(_value(data_in, 'title')))[:100]
    if title == '':
        return '제목을 입력해 주세요.'

    slug = _ascii_lower(_trim(php_str(_value(data_in, 'slug'))))
    if slug != '' and (not _SLUG_RE.match(slug) or len(slug.encode('utf-8')) > 60 or _ctype_digit(slug)):
        return '주소는 영문 소문자, 숫자, 하이픈(-), 밑줄(_)로 60자까지 쓸 수 있고 숫자만으로는 만들 수 없습니다.'

    if slug in RESERVED_SLUGS:
        return '"' + slug + '" 는 공개 가이드의 모음 페이지 주소라 쓸 수 없습니다. 다른 주소를 입력해 주세요.'

    feature = _clean_feature(org_id, data_in)
    if isinstance(feature, str):
        return feature

    status = php_str(_value(data_in, 'status'))
    status = status if status in ('draft', 'published') else 'draft'

    category_id = php_int(_value(data_in, 'category_id'))
    if category_id > 0 and get_category(org_id, category_id) is None:
        category_id = 0

    raw_blocks = _value(data_in, 'blocks', [])
    if isinstance(raw_blocks, str):
        raw_blocks = php_json_decode(raw_blocks)
    raw_blocks = _values(raw_blocks) if _is_array(raw_blocks) else []

    if len(raw_blocks) > MAX_BLOCKS:
        warnings.append('본문 블록은 ' + str(MAX_BLOCKS) + '개까지만 저장합니다.')
        raw_blocks = raw_blocks[:MAX_BLOCKS]

    blocks = []
    for index, block in enumerate(raw_blocks):
        result = _clean_block(org_id, block)
        if isinstance(result, str):
            warnings.append(str(index + 1) + '번째 블록: ' + result)
        elif result is not None:
            blocks.append(result)

    return {
        'title': title,
        'subtitle': _nullable(_trim(php_str(_value(data_in, 'subtitle')))[:200]),
        'slug': None if slug == '' else slug,
        'keywords': _nullable(_clean_keywords(_value(data_in, 'keywords'))),
        'status': status,
        'sort_order': max(-100000, min(100000, php_int(_value(data_in, 'sort_order')))),
        'category_id': category_id if category_id > 0 else None,
        'blocks': blocks,
        'is_feature': feature['is_feature'],
        'feature_at': feature['feature_at'],
        'feature_label': feature['feature_label'],
    }


def _clean_feature(org_id, data_in):
    """새 기능 표시 검사. 화면은 is_feature / feature_date / feature_label, guide.json 은 feature: {date, label}.

    날짜는 조직 시간대의 날짜(YYYY-MM-DD)이며 그날 00:00 을 UTC 로 바꿔 저장한다(CLAUDE.md 3.4). 비우면 처음 공개한 시각을 출시일로 쓴다.
    """
    json_value = _value(data_in, 'feature', None)

    if _is_array(json_value):
        is_feature = True
        day = php_str(_value(json_value, 'date'))
        label = php_str(_value(json_value, 'label'))
    else:
        is_feature = php_str(_value(data_in, 'is_feature')) in ('1', 'true', 'on')
        day = php_str(_value(data_in, 'feature_date'))
        label = php_str(_value(data_in, 'feature_label'))

    if not is_feature:
        return {'is_feature': 0, 'feature_at': None, 'feature_label': None}

    day = _trim(day)
    feature_at = None

    if day != '':
        # PHP: DateTime::createFromFormat('!Y-m-d', ...) 후 format('Y-m-d') 가 같아야 한다(없는 날짜, 자릿수가 다른 값은 거부).
        # 0001~9999 년만 다룬다(PHP 는 0000 년도 받지만 DATETIME 범위 밖이라 쓸 수 없는 값이다).
        try:
            if not _DATE_RE.match(day) or day[-1] == '\n':
                raise ValueError(day)
            local = datetime.combine(date.fromisoformat(day), datetime.min.time(), ZoneInfo(org_timezone(org_id)))
            feature_at = php_date('Y-m-d H:i:s', local.astimezone(timezone.utc))
        except (ValueError, OverflowError):
            return '출시일은 YYYY-MM-DD 형식의 날짜여야 합니다.'

    return {'is_feature': 1, 'feature_at': feature_at, 'feature_label': _nullable(_trim(label)[:30])}


_YOUTUBE_PATH = re.compile(r'^/([A-Za-z0-9_-]{11})$')
_YOUTUBE_SHORTS = re.compile(r'^/(shorts|embed)/([A-Za-z0-9_-]{11})/?$')


def _type_is(value, name):
    """PHP switch 의 느슨한 비교(==). PHP 8 에서 문자열 case 와 같아지는 값은 같은 문자열과 true 뿐이다."""
    return value is True or (isinstance(value, str) and value == name)


def _clean_block(org_id, block):
    """블록 하나를 검사한다. 빈 블록은 None, 거부하면 사유 문자열."""
    if not isinstance(block, dict) or block.get('type') is None:
        return '형식을 알 수 없습니다.'

    caption = _trim(php_str(_value(block, 'caption')))[:200]
    block_type = block['type']

    if _type_is(block_type, 'text'):
        text = _trim(php_str(_value(block, 'text')).replace('\r\n', '\n'))
        if text == '':
            return None
        return {'type': 'text', 'text': text[:MAX_TEXT]}

    if _type_is(block_type, 'image'):
        src = php_str(_value(block, 'src'))
        if src == '':
            return None
        # 이 조직이 올린 파일이나 외부 https 주소만 받는다. 다른 조직 경로, http, data:, javascript: 는 막는다.
        if not is_own_image(org_id, src) and not is_external_image(src):
            return '이 조직에 올린 이미지나 https 주소가 아니어서 뺐습니다(' + src[:80] + ').'
        return {'type': 'image', 'src': src, 'caption': caption}

    if _type_is(block_type, 'video'):
        video_id = php_str(_value(block, 'youtube_id'))
        url = _value(block, 'url')
        if video_id == '' and not php_empty(url):
            video_id = php_str(youtube_id(url))
        if video_id == '' and php_empty(url):
            return None
        if not _YOUTUBE_ID.match(video_id):
            return '유튜브 주소를 알아보지 못했습니다.'
        return {'type': 'video', 'youtube_id': video_id, 'caption': caption}

    return '허용하지 않는 블록 종류입니다(' + php_str(block_type)[:20] + ').'


def youtube_id(url):
    """유튜브 주소에서 영상 id 만 꺼낸다. watch?v=, youtu.be/, shorts/, embed/ 형식만 받는다. 아니면 None."""
    url = _trim(php_str(url))

    if _YOUTUBE_ID.match(url):
        return url

    parts = parse_url(url) or {}
    if (php_empty(parts.get('host')) or parts.get('scheme') is None
            or _ascii_lower(parts['scheme']) not in ('http', 'https')):
        return None

    host = _ascii_lower(re.sub(r'^(www\.|m\.)', '', parts['host'], count=1))
    path = parts.get('path', '')

    match = _YOUTUBE_PATH.match(path)
    if host == 'youtu.be' and match:
        return match.group(1)

    if host != 'youtube.com':
        return None

    if path == '/watch' and parts.get('query') is not None:
        value = parse_str(parts['query']).get('v')
        # PHP 는 v 가 배열이면 preg_match 에서 TypeError(500) 가 난다. 여기서는 받지 않는다.
        return value if isinstance(value, str) and _YOUTUBE_ID.match(value) else None

    match = _YOUTUBE_SHORTS.match(path)
    return match.group(2) if match else None


def _clean_keywords(keywords):
    """쉼표/줄바꿈 구분 키워드(또는 배열)를 정리한다. 중복을 빼고 20개까지."""
    items = _values(keywords) if _is_array(keywords) else re.split(r'[,\n]+', php_str(keywords))
    out = []
    for keyword in items:
        keyword = _trim(re.sub(PCRE_U_SPACE + '+', ' ', php_str(keyword)))[:40]
        if keyword != '' and keyword not in out:
            out.append(keyword)
    return ', '.join(out[:MAX_KEYWORDS])[:500]


# ------------------------------------------------------------------ 이미지 파일

def image_dir(org_id):
    """조직 이미지 폴더(포털 기준 상대 경로)."""
    return 'assets/uploads/guides/' + str(php_int(org_id)) + '/'


def asset_file(rel):
    """상대 경로(assets/...)의 실제 파일 경로. api 컨테이너는 backend/assets 를 ASSETS_DIR(/srv/assets) 로 붙인다."""
    rel = str(rel)
    if not rel.startswith('assets/'):
        raise ValueError(rel)
    return os.path.join(str(config.ASSETS_DIR), rel[len('assets/'):])


def _own_image_re(org_id):
    return re.compile('^' + re.escape(image_dir(org_id)) + r'[A-Za-z0-9_-]+\.(png|jpg|webp)$')


def is_own_image(org_id, src):
    src = php_str(src)
    return bool(_own_image_re(org_id).match(src)) and os.path.isfile(asset_file(src))


def image_paths(blocks):
    out = []
    for block in _values(blocks):
        if (isinstance(block, dict) and block.get('type') is not None and block.get('src') is not None
                and block['type'] == 'image' and block['src'] not in out):
            out.append(block['src'])
    return out


def remove_images(org_id, paths):
    """이미지 파일을 지운다. 다른 글이 같은 파일을 쓰고 있으면 남긴다(가져오기로 같은 경로가 들어올 수 있다)."""
    pattern = _own_image_re(org_id)
    for path in paths:
        if not isinstance(path, str) or not pattern.match(path):
            continue
        in_use = int(db.scalar("SELECT COUNT(*) FROM guide_articles WHERE org_id = :org AND blocks LIKE :p ESCAPE '!'",
                               {'org': int(org_id), 'p': db.like_pattern('"' + path + '"')}))
        target = asset_file(path)
        if in_use == 0 and os.path.isfile(target):
            try:
                os.unlink(target)
            except OSError:
                pass


def cleanup_images(org_id):
    """어떤 글에도 쓰이지 않는 이미지를 지운다. 편집 중에 올리고 아직 저장하지 않은 파일을 지우지 않도록 하루가 지난 것만."""
    folder = asset_file(image_dir(org_id))
    if not os.path.isdir(folder):
        return

    used = set()
    for found in db.query('SELECT blocks FROM guide_articles WHERE org_id = :org', {'org': int(org_id)}):
        blocks = php_json_decode(php_str(found.blocks))
        used.update(image_paths(blocks if _is_array(blocks) else []))

    limit = time.time() - 86400
    for name in os.listdir(folder):
        if not name.endswith(('.png', '.jpg', '.webp')):
            continue
        target = os.path.join(folder, name)
        try:
            if image_dir(org_id) + name not in used and os.path.isfile(target) and os.path.getmtime(target) < limit:
                os.unlink(target)
        except OSError:
            pass


# ------------------------------------------------------------------ 외부 이미지를 조직 폴더로 저장

FETCH_MAX_BYTES = 2097152   # 외부 이미지 한 개의 최대 크기(2MB, 직접 올리는 이미지와 같다)
FETCH_MAX_REDIRECTS = 3     # 리다이렉트를 따라가는 최대 횟수
FETCH_CONNECT_TIMEOUT = 5
FETCH_TIMEOUT = 15


def localize_external_images(org_id, agent_id=None):
    """이 조직 글의 외부 https 이미지를 받아 조직 폴더(image_dir)에 저장하고 블록 주소를 바꾼다.

    같은 주소는 한 번만 받는다. 받지 못한 이미지는 외부 주소 그대로 둔다.
    결과: saved(저장한 이미지 수), articles(바뀐 글 수), failed([[주소, 사유], ...])
    """
    done, failed = {}, []
    saved = changed_articles = 0

    for found in db.query('SELECT id FROM guide_articles WHERE org_id = :org ORDER BY id ASC', {'org': int(org_id)}):
        article = get_article(org_id, found.id)
        changed = False

        for index, block in enumerate(article.blocks):
            if (not isinstance(block, dict) or block.get('type') is None or block.get('src') is None
                    or block['type'] != 'image' or not is_external_image(block['src'])):
                continue

            url = block['src']
            if url not in done:
                result = fetch_external_image(org_id, url)
                if isinstance(result, dict):
                    done[url] = False
                    failed.append([url, result['error']])
                else:
                    done[url] = result
                    saved += 1

            if done[url] is not False:
                article.blocks[index]['src'] = done[url]
                changed = True

        if changed:
            # 블록 주소만 바꾼다. 나머지 값은 저장된 그대로 넘긴다(새 기능 표시도 그대로 둔다).
            save_article(org_id, article.id, {
                'category_id': article.category_id,
                'slug': article.slug,
                'title': article.title,
                'subtitle': article.subtitle,
                'keywords': article.keywords,
                'blocks': article.blocks,
                'status': article.status,
                'sort_order': php_int(article.sort_order),
                'is_feature': php_int(article.is_feature),
                'feature_at': article.feature_at,
                'feature_label': article.feature_label,
            }, agent_id)
            changed_articles += 1

    return {'saved': saved, 'articles': changed_articles, 'failed': failed}


class _PinnedHTTPSConnection(http.client.HTTPSConnection):
    """확인한 IP 로만 접속하는 HTTPS 연결(PHP CURLOPT_RESOLVE 대응). 인증서와 SNI 는 원래 호스트 이름으로 검사한다."""

    def __init__(self, host, port, ip, context):
        super().__init__(host, port, timeout=FETCH_CONNECT_TIMEOUT, context=context)
        self._pinned_ip = ip

    def connect(self):
        sock = socket.create_connection((self._pinned_ip, self.port), FETCH_CONNECT_TIMEOUT)
        try:
            self.sock = self._context.wrap_socket(sock, server_hostname=self.host)
        except Exception:
            sock.close()
            raise


def _https_get(url, host, port, ip):
    """한 번 요청한다(리다이렉트는 따라가지 않는다). (상태 코드, Location, 본문, 너무 큼) 또는 예외."""
    parts = parse_url(url) or {}
    target = parts.get('path') or '/'
    if parts.get('query') is not None:
        target += '?' + parts['query']

    deadline = time.monotonic() + FETCH_TIMEOUT
    conn = _PinnedHTTPSConnection(host, port, ip, ssl.create_default_context())
    try:
        conn.request('GET', target, headers={
            'User-Agent': 'RemoteHelp-Guide/1.0',
            'Accept': 'image/png,image/jpeg,image/webp,image/*;q=0.8',
        })
        conn.sock.settimeout(max(0.1, deadline - time.monotonic()))
        response = conn.getresponse()
        location = response.getheader('Location') or ''
        body = bytearray()
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise TimeoutError('Operation timed out after ' + str(FETCH_TIMEOUT * 1000) + ' milliseconds')
            conn.sock.settimeout(remaining)
            chunk = response.read(65536)
            if not chunk:
                break
            if len(body) + len(chunk) > FETCH_MAX_BYTES:
                return response.status, location, b'', True
            body.extend(chunk)
        return response.status, location, bytes(body), False
    finally:
        conn.close()


def fetch_external_image(org_id, url):
    """외부 이미지 한 개를 받아 조직 폴더에 저장한다. 서버가 대신 요청하므로 내부망으로 향하지 않게 막는다(SSRF).

    https 만, 공인 IPv4 만, 확인한 IP 로 고정해 접속, 리다이렉트는 직접 따라가며 매번 다시 검사한다.
    결과: 저장한 경로(str) 또는 {'error': 사유}
    """
    for _ in range(FETCH_MAX_REDIRECTS + 1):
        if not is_external_image(url):
            return {'error': 'https 주소가 아닙니다.'}

        parts = parse_url(url) or {}
        host = _ascii_lower(php_str(parts.get('host')))
        port = php_int(parts.get('port')) or 443
        ip = public_ipv4(host)

        if ip is None:
            return {'error': '주소를 찾지 못했거나 내부망 주소입니다.'}

        try:
            status, location, body, too_big = _https_get(url, host, port, ip)
        except Exception as error:  # 접속, 인증서, 시간 초과 (PHP 는 curl_error 문구)
            return {'error': '받지 못했습니다(' + (str(error) or type(error).__name__)[:80] + ').'}

        if too_big:
            return {'error': str(FETCH_MAX_BYTES // 1048576) + 'MB 보다 큽니다.'}

        if 300 <= status < 400 and location != '':
            url = urljoin(url, location)
            continue

        if status != 200:
            return {'error': '응답 코드 ' + str(status) + '.'}

        return store_fetched_image(org_id, body)

    return {'error': '리다이렉트가 너무 많습니다.'}


_IPV4_RE = re.compile(r'^(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])(\.(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])){3}$')


def _valid_ipv4(text):
    """_php_filter_validate_ipv4(): 점 4칸 10진수, 앞자리 0 금지, 각 칸 255 이하. 끝 줄바꿈도 받지 않는다."""
    return bool(_IPV4_RE.match(text)) and not text.endswith('\n')


def _public_range(ip):
    """FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE 를 통과하는지(php-src logical_filters.c 의 IPv4 판정)."""
    a, b = (int(x) for x in ip.split('.')[:2])
    if a == 10 or (a == 172 and 16 <= b <= 31) or (a == 192 and b == 168):
        return False
    if a == 0 or a >= 240 or a == 127 or (a == 169 and b == 254):
        return False
    return True


def public_ipv4(host):
    """호스트의 IPv4 주소 중 첫 번째. 하나라도 사설/예약 대역이면 None(섞어 두고 고르는 우회를 막는다)."""
    if host in ('', 'localhost'):
        return None

    if _valid_ipv4(host):
        ips = [host]
    else:
        try:
            ips = socket.gethostbyname_ex(host)[2]   # PHP gethostbynamel()
        except (OSError, UnicodeError, ValueError):
            ips = []

    for ip in ips:
        if not _valid_ipv4(ip) or not _public_range(ip):
            return None

    return ips[0] if ips else None


def store_fetched_image(org_id, body):
    """받은 내용을 올린 이미지와 같은 검사(image_file_ext)를 거쳐 조직 폴더에 저장한다."""
    ext = image_file_ext(body)
    if ext is None:
        return {'error': 'PNG, JPG, WEBP 이미지가 아닙니다.'}

    folder = image_dir(org_id)
    src = folder + secrets.token_hex(10) + '.' + ext
    try:
        os.makedirs(asset_file(folder), mode=0o775, exist_ok=True)
        with open(asset_file(src), 'xb') as handle:
            handle.write(body)
        os.chmod(asset_file(src), 0o664)
    except OSError:
        return {'error': '저장하지 못했습니다. 업로드 폴더 권한을 확인해 주세요.'}
    return src


# ------------------------------------------------------------------ 동의어, 색인

def replace_synonyms(org_id, groups):
    """동의어를 통째로 바꾼다. 묶음 하나는 낱말 2개 이상이어야 한다. 저장한 묶음 수를 돌려준다."""
    db.execute('DELETE FROM guide_synonyms WHERE org_id = :org', {'org': int(org_id)})
    saved = 0

    groups = _values(groups) if _is_array(groups) else ([] if groups is None else [groups])
    for group in groups[:200]:
        group = _values(group) if _is_array(group) else ([] if group is None else [group])
        words = []
        for word in group:
            word = _trim(php_str(word).replace(',', ' '))[:40]
            if word != '' and word not in words:
                words.append(word)
        if len(words) < 2:
            continue
        db.insert('guide_synonyms', {'org_id': int(org_id), 'words': ', '.join(words)[:300]})
        saved += 1

    return saved


def rebuild_index(org_id, article_id):
    """글 하나의 색인을 다시 만든다. 공개 글만 색인한다."""
    db.execute('DELETE FROM guide_terms WHERE article_id = :id', {'id': php_int(article_id)})

    article = get_article(org_id, article_id)
    if article is None or article.status != 'published':
        return 0

    rows = [(str(term), int(weight)) for term, weight in guide_matcher.index_terms(dict(article)).items()]

    # CI3 insert_batch 와 같이 100행씩 넣는다.
    for start in range(0, len(rows), 100):
        chunk = rows[start:start + 100]
        params = {'org': int(org_id), 'aid': php_int(article_id)}
        values = []
        for index, (term, weight) in enumerate(chunk):
            values.append(f'(:org, :t{index}, :aid, :w{index})')
            params[f't{index}'] = term
            params[f'w{index}'] = weight
        db.execute('INSERT INTO guide_terms (org_id, term, article_id, weight) VALUES ' + ', '.join(values), params)

    return len(rows)


def rebuild_org_index(org_id):
    db.execute('DELETE FROM guide_terms WHERE org_id = :org', {'org': int(org_id)})
    count = 0
    for found in db.query('SELECT id FROM guide_articles WHERE org_id = :org', {'org': int(org_id)}):
        count += rebuild_index(org_id, found.id)
    return count


# ------------------------------------------------------------------ 테마, 공개 페이지 구성 저장

def set_theme(org_id, theme):
    if not isinstance(theme, str) or theme not in themes():
        return 0
    db.update('organizations', {'guide_theme': theme}, {'id': int(org_id)})
    return 1


def set_page_options(org_id, data_in):
    """공개 페이지 구성을 저장한다. 허용 목록 밖의 값은 거부한다. 결과: 저장한 값(page_options 형식) 또는 거부 사유."""
    days = php_str(_value(data_in, 'feature_new_days', '30'))
    sort = php_str(_value(data_in, 'video_sort', 'toc'))

    if not _ctype_digit(days) or php_int(days) not in new_days_options():
        return 'NEW 배지 기간을 목록에서 골라 주세요.'

    if sort not in video_sorts():
        return '동영상 정렬을 목록에서 골라 주세요.'

    def flag(key):
        return 1 if php_str(_value(data_in, key)) in ('1', 'true', 'on') else 0

    data = {
        'guide_feature_enabled': flag('feature_enabled'),
        'guide_feature_new_days': php_int(days),
        'guide_feature_banner': flag('feature_banner'),
        'guide_video_enabled': flag('video_enabled'),
        'guide_video_sort': sort,
        'guide_video_filter': flag('video_filter'),
    }
    db.update('organizations', data, {'id': int(org_id)})
    return page_options(data)


def count_features(org_id):
    """새 기능으로 표시된 공개 글 수."""
    return int(db.scalar("SELECT COUNT(*) FROM guide_articles WHERE org_id = :org AND status = 'published' AND is_feature = 1",
                         {'org': int(org_id)}))


# ------------------------------------------------------------------ 샘플

def seed_samples(org, agent_id=None):
    """가이드 관리를 처음 열 때 글이 없으면 샘플(settings/guide_samples.py)을 임시 상태로 만든다. 만든 글 수를 돌려준다.

    조직마다 한 번만 한다. 표시(guide_seeded_at)를 먼저 찍어 동시에 두 번 열어도 한 번만 만들고,
    샘플을 모두 지운 뒤 다시 열어도 되살리지 않는다. 이미 글이 있는 조직은 표시만 찍는다.
    """
    from app.settings import guide_samples

    marked = db.execute('UPDATE organizations SET guide_seeded_at = :now WHERE id = :id AND guide_seeded_at IS NULL',
                        {'now': utc_str(), 'id': int(org['id'])}).rowcount
    if marked == 0 or count_articles(org['id']) > 0:
        return 0

    replace = {
        '{org}': php_str(org['name']),
        '{phone}': '-' if php_empty(org.get('phone')) else php_str(org['phone']),
        '{customer_url}': customer_url(org['org_code']),
    }

    category_ids = {}
    for category in guide_samples.CATEGORIES:
        category_ids[category['key']] = save_category(org['id'], 0, category['name'], category['sort_order'])

    replace_synonyms(org['id'], guide_samples.SYNONYMS)
    created = 0

    for sample in guide_samples.ARTICLES:
        article = dict(sample)
        blocks = []
        for block in sample['blocks']:
            block = dict(block)
            if block['type'] == 'text':
                block['text'] = _strtr(block['text'], replace)
            elif block['type'] == 'image':
                block['src'] = _copy_sample_image(org['id'], block.pop('sample', None))
                if block['src'] is None:
                    continue
            blocks.append(block)

        article['blocks'] = blocks
        article['status'] = 'draft'
        article['category_id'] = category_ids.get(article.get('category'), 0)

        clean = clean_article(org['id'], article)

        # 같은 주소가 있으면 주소 없이 만든다(번호로 접속).
        if isinstance(clean, dict) and slug_taken(org['id'], clean['slug']):
            clean['slug'] = None

        if isinstance(clean, dict):
            save_article(org['id'], 0, clean, agent_id)
            created += 1

    return created


def _copy_sample_image(org_id, name):
    """샘플 이미지(assets/img/guide-samples/)를 조직 이미지 폴더로 복사한다. 실패하면 None."""
    match = re.match(r'^[a-z0-9-]+\.(png|jpg|webp)$', php_str(name))
    if not match or name.endswith('\n'):
        return None

    source = asset_file('assets/img/guide-samples/' + name)
    folder = image_dir(org_id)
    src = folder + secrets.token_hex(10) + '.' + match.group(1)
    try:
        os.makedirs(asset_file(folder), mode=0o775, exist_ok=True)
        if not os.path.isfile(source):
            return None
        shutil.copyfile(source, asset_file(src))
    except OSError:
        return None
    return src


# ------------------------------------------------------------------ guide.json

def export(org):
    """조직의 가이드 전체를 guide.json 구조로 만든다. 분류는 DB id 대신 key 로 잇는다."""
    categories, keys = [], {}
    for category in list_categories(org['id']):
        keys[str(category.id)] = 'c' + str(category.id)
        categories.append({'key': keys[str(category.id)], 'name': category.name, 'sort_order': int(category.sort_order)})

    tz = org_timezone(org['id'])
    articles = []
    for found in db.query('SELECT * FROM guide_articles WHERE org_id = :org ORDER BY id', {'org': int(org['id'])}):
        found = _decode(found)
        feature = None
        if php_int(found.is_feature) == 1:
            # 날짜는 사람이 고치는 형식이라 조직 시간대 날짜로 내보낸다. 출시일을 비운 글은 첫 공개 날짜를 적는다.
            moment = feature_date(found)
            feature = {'date': to_timezone(moment, tz, 'Y-m-d') if moment else '', 'label': php_str(found.feature_label)}

        articles.append({
            'slug': found.slug,
            'category': keys.get(php_str(found.category_id)),
            'title': found.title,
            'subtitle': found.subtitle,
            'keywords': [_trim(k) for k in found.keywords.split(',')] if not php_empty(found.keywords) else [],
            'status': found.status,
            'sort_order': int(found.sort_order),
            'feature': feature,
            'blocks': found.blocks,
            'updated_at': php_date('Y-m-d\\TH:i:s\\Z', datetime.fromisoformat(found.updated_at).replace(tzinfo=timezone.utc)),
        })

    return {
        'format': FORMAT,
        'version': VERSION,
        'exported_at': php_date('Y-m-d\\TH:i:s\\Z'),
        'org_code': org['org_code'],
        'categories': categories,
        'synonyms': synonym_groups(org['id']),
        'articles': articles,
    }


def import_guide(org_id, data, mode, agent_id):
    """guide.json 을 가져온다(PHP Guide_model::import, import 는 파이썬 예약어라 이름만 다르다).

    merge   : slug 가 같은 글은 덮어쓰고 나머지는 새로 만든다. 분류는 이름이 같으면 그대로 쓴다.
    replace : 기존 글, 분류, 동의어를 지우고 파일 내용으로 바꾼다.
    결과: {created, updated, skipped, warnings} 또는 거부 사유(str)
    """
    if not _is_array(data) or _value(data, 'format') != FORMAT:
        return 'RemoteHelp 가이드 파일(guide.json)이 아닙니다.'

    if php_int(_value(data, 'version')) != VERSION:
        return '지원하지 않는 파일 버전입니다(' + str(php_int(_value(data, 'version'))) + ').'

    mode = 'replace' if mode == 'replace' else 'merge'
    articles = _value(data, 'articles', [])
    articles = _values(articles) if _is_array(articles) else []

    if len(articles) > MAX_ARTICLES:
        return '가이드는 ' + str(MAX_ARTICLES) + '건까지 가져올 수 있습니다.'

    if mode == 'merge' and count_articles(org_id) + len(articles) > MAX_ARTICLES * 2:
        return '가이드가 너무 많습니다. 전체 교체로 가져오거나 기존 글을 정리해 주세요.'

    result = {'created': 0, 'updated': 0, 'skipped': [], 'warnings': []}

    try:
        with db.transaction():
            _import_rows(org_id, data, mode, articles, agent_id, result)
    except Exception:
        return '가져오는 중 오류가 나서 아무것도 바꾸지 않았습니다.'

    cleanup_images(org_id)
    return result


def _import_rows(org_id, data, mode, articles, agent_id, result):
    if mode == 'replace':
        # 이미지 파일은 남겨 둔다. 가져오는 파일이 같은 경로를 쓸 수 있고, 남는 파일은 cleanup_images 가 정리한다.
        for table in ('guide_articles', 'guide_categories', 'guide_synonyms'):
            db.execute(f'DELETE FROM {table} WHERE org_id = :org', {'org': int(org_id)})

    # 분류: key -> 이 조직의 분류 id
    category_map, existing = {}, {}
    for category in list_categories(org_id):
        existing[category.name] = int(category.id)

    categories = _value(data, 'categories', [])
    categories = _values(categories) if _is_array(categories) else [categories]
    for category in categories:
        key = php_str(_value(category, 'key'))
        name = _trim(php_str(_value(category, 'name')))[:60]
        if key == '' or name == '':
            continue
        if name not in existing:
            existing[name] = save_category(org_id, 0, name, php_int(_value(category, 'sort_order')))
        category_map[key] = existing[name]

    # 동의어: 병합이면 기존 묶음 뒤에 새 묶음을 붙인다.
    groups = _value(data, 'synonyms', [])
    if _is_array(groups) and len(groups) > 0:
        current = synonym_groups(org_id) if mode == 'merge' else []
        replace_synonyms(org_id, current + _values(groups))

    saved_ids = set()

    for index, item in enumerate(articles):
        if not _is_array(item):
            result['skipped'].append({'slug': '', 'reason': str(index + 1) + '번째 항목 형식 오류'})
            continue

        item = dict(item) if isinstance(item, dict) else {str(i): v for i, v in enumerate(item)}
        item['category_id'] = category_map.get(php_str(_value(item, 'category')), 0)

        warnings = []

        # 모음 페이지 주소(features, videos)는 글 주소로 쓸 수 없으므로 비우고(번호로 접속) 알린다.
        if _ascii_lower(_trim(php_str(_value(item, 'slug')))) in RESERVED_SLUGS:
            warnings.append('주소 "' + php_str(item['slug']) + '" 는 모음 페이지 주소라 비웠습니다(번호로 접속).')
            item['slug'] = ''

        clean = clean_article(org_id, item, warnings)
        label = php_str(_value(item, 'slug'))
        if php_empty(label):
            label = php_str(_value(item, 'title'))[:40]

        if isinstance(clean, str):
            result['skipped'].append({'slug': label, 'reason': clean})
            continue

        for warning in warnings:
            result['warnings'].append(label + ': ' + warning)

        target_id = 0
        if clean['slug'] is not None:
            found = db.row('SELECT id FROM guide_articles WHERE org_id = :org AND slug = :slug LIMIT 1',
                           {'org': int(org_id), 'slug': clean['slug']})
            target_id = int(found.id) if found else 0

            # 같은 파일 안에서 slug 가 겹치면 뒤의 것을 건너뛴다.
            if target_id > 0 and target_id in saved_ids:
                result['skipped'].append({'slug': label, 'reason': '파일 안에서 주소(slug)가 겹칩니다.'})
                continue

        saved = save_article(org_id, target_id, clean, agent_id)
        saved_ids.add(saved)
        result['updated' if target_id > 0 else 'created'] += 1
