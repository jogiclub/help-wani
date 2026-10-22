"""
파일 위치: backend/app/libraries/guide_matcher.py
역할: 가이드 키워드 매칭 — 문장 정규화, 색인 낱말 생성, 질문으로 가이드 찾기 (PHP libraries/Guide_matcher.php 대응)

외부 API 를 부르지 않는다. 문자열 처리와 guide_terms 색인 조회 한 번으로 끝난다.
색인과 질문에 같은 정규화를 거쳐야 맞으므로 정규화는 이 파일 한 곳에만 둔다(docs/guide-plan.md 5장).

전환 기간에는 PHP 가 만든 색인을 FastAPI 가 읽고 그 반대도 일어나므로 정규화 결과가 PHP 와 한 글자도 달라서는 안 된다.
- \\p{L}\\p{N}, \\p{Hangul} 은 파이썬 unicodedata 와 PCRE 의 판정이 4천여 글자에서 다르고, PCRE 의 Hangul 은 스크립트 확장
  (CJK 구두점 등)까지 포함한다. 그래서 PHP 8.2.34(PCRE 10.40)에서 전체 코드 포인트를 확인해 뽑은 구간 표를 쓴다.
- 웹 컨테이너에 intl 이 없어 PHP 는 Normalizer(NFKC)를 건너뛴다. 여기서도 하지 않는다.
- PHP 배열은 숫자 문자열 키('123')를 정수로 바꾸므로 조회 SQL 에 IN (123) 으로 들어가 숫자 비교가 된다. 같게 정수로 넘긴다.
"""
import bisect
import json
import re
from pathlib import Path

from app import db
from app.settings import guide_match as conf

# 색인 낱말 최대 길이 (guide_terms.term)
TERM_MAX = 40

_UNICODE = json.loads((Path(__file__).resolve().parent.parent / 'core' / 'data' / 'php_pcre_unicode.json')
                      .read_text(encoding='utf-8'))


class _RangeSet:
    def __init__(self, ranges):
        self.starts = [r[0] for r in ranges]
        self.ends = [r[1] for r in ranges]

    def __contains__(self, char):
        cp = ord(char)
        i = bisect.bisect_right(self.starts, cp) - 1
        return i >= 0 and cp <= self.ends[i]


_LETTER_OR_NUMBER = _RangeSet(_UNICODE['letter_or_number'])
_HANGUL = _RangeSet(_UNICODE['hangul'])

# 긴 조사부터 비교해야 '에서' 를 '서' 로 잘못 떼지 않는다(PHP usort 는 8.0 부터 안정 정렬).
_SUFFIXES = sorted(conf.SUFFIXES, key=lambda s: -len(s))
_STOPWORDS = set(conf.STOPWORDS)

# PHP 가 정수 키로 바꾸는 문자열: 앞자리 0 이 없는 10진수(부호는 낱말에 남지 않는다)
_PHP_INT_KEY = re.compile(r'^(0|[1-9][0-9]*)$')
_PHP_INT_MAX = 9223372036854775807


def min_score(usage):
    return int(conf.MIN_SCORE.get(usage, 1))


def _has_hangul(text):
    return any(ch in _HANGUL for ch in text)


def words(text):
    """문장을 낱말 목록으로. 소문자화, 기호 제거, 조사 떼기, 불용어 제거까지 한다."""
    # 글자마다 따로 소문자로 바꾼다. str.lower() 는 낱말 끝 Σ 를 ς 로 바꾸지만 PHP 8.2 mb_strtolower 는 문맥을 보지 않는다.
    text = ''.join(ch.lower() for ch in str(text if text is not None else ''))
    # preg_replace('/[^\p{L}\p{N}]+/u', ' ')
    text = ''.join(ch if ch in _LETTER_OR_NUMBER else ' ' for ch in text)
    out = []

    for word in text.split(' '):
        if word == '':
            continue

        # '안녕하세요' 가 '안녕하' 로 남아 빠져나가지 않도록 조사를 떼기 전과 후를 모두 본다.
        if word in _STOPWORDS:
            continue

        word = _strip_suffix(word)

        if len(word) < 2 or word in _STOPWORDS:
            continue

        out.append(word[:TERM_MAX])

    return out


def _strip_suffix(word):
    """한글 낱말 끝의 조사/어미를 한 번만 뗀다."""
    if not word or word[-1] not in _HANGUL:
        return word

    length = len(word)
    for suffix in _SUFFIXES:
        cut = len(suffix)
        if length - cut >= 2 and word.endswith(suffix):
            return word[:length - cut]

    return word


def grams(word):
    """3글자 이상 한글 낱말을 2글자 조각으로 나눈다. 붙여 쓴 말과 합성어를 잡기 위한 것이다."""
    if not _has_hangul(word) or len(word) < 3:
        return []

    return [word[i:i + 2] for i in range(len(word) - 1)
            if word[i] in _HANGUL and word[i + 1] in _HANGUL]


def _php_key(term):
    """PHP 배열 키로 쓰면 정수가 되는 낱말은 정수로."""
    if _PHP_INT_KEY.match(term) and int(term) <= _PHP_INT_MAX:
        return int(term)
    return term


def index_terms(article):
    """글 하나의 색인 낱말과 가중치. 같은 낱말은 가장 높은 가중치 하나만 남긴다.

    article: title, subtitle, keywords(문자열), blocks(목록). 결과는 {낱말: 가중치}(가중치 큰 순, 같으면 먼저 나온 순).
    """
    body = []
    for block in article.get('blocks') or []:
        if block.get('type') == 'text':
            body.append(str(block.get('text', '')))
        elif block.get('caption'):
            body.append(str(block['caption']))

    sources = {
        'keywords': str(article.get('keywords') or '').replace(',', ' '),
        'title': str(article.get('title') or ''),
        'subtitle': str(article.get('subtitle') or ''),
        'body': ' '.join(body),
    }

    terms = {}

    def keep_max(term, weight):
        key = _php_key(term)
        if key not in terms or terms[key] < weight:
            terms[key] = weight

    for field, text in sources.items():
        weight = conf.WEIGHTS[field]
        for word in words(text):
            keep_max(word, weight['word'])
            if weight['gram'] > 0:
                for gram in grams(word):
                    keep_max(gram, weight['gram'])

    ordered = sorted(terms.items(), key=lambda item: -item[1])
    return dict(ordered[:int(conf.MAX_TERMS_PER_ARTICLE)])


def query_terms(text, synonyms=None):
    """질문을 조회용 낱말 목록으로. 붙여 쓴 형태와 동의어도 함께 넣는다.

    synonyms: 동의어 묶음 [[낱말, 낱말], ...] (정규화 전)
    """
    query_words = words(text)[:int(conf.MAX_QUERY_WORDS)]
    terms = {}

    for index, word in enumerate(query_words):
        terms[_php_key(word)] = True

        for gram in grams(word):
            terms[_php_key(gram)] = True

        # '원격 지원' 처럼 띄어 쓴 질문이 '원격지원' 키워드에 맞도록 이웃 낱말을 붙여 본다.
        if index > 0 and _has_hangul(query_words[index - 1] + word):
            terms[_php_key((query_words[index - 1] + word)[:TERM_MAX])] = True

    for group in synonyms or []:
        normalized = [''.join(words(item)) for item in group]
        normalized = [item for item in normalized if item != '' and item != '0']  # array_filter

        keys = {str(k) for k in terms}
        if any(item in keys for item in normalized):
            for item in normalized:
                terms[_php_key(item)] = True

    return list(terms)


def match(org_id, text, usage='console', limit=3, exclude_ids=None):
    """질문에 맞는 공개 가이드를 점수순으로. [{id, slug, title, subtitle, score, hits}]"""
    from app.models import guide_model  # guide_model 이 이 모듈을 쓰므로 함수 안에서 불러온다

    terms = query_terms(text, guide_model.synonym_groups(org_id))

    if not terms:
        return []

    sql = ('SELECT t.article_id, SUM(t.weight) AS score, COUNT(*) AS hits, a.slug, a.title, a.subtitle '
           'FROM guide_terms t JOIN guide_articles a ON a.id = t.article_id '
           "WHERE t.org_id = :org AND a.org_id = :org AND a.status = 'published' AND t.term IN :terms ")
    params = {'org': int(org_id), 'terms': terms}

    if exclude_ids:
        sql += 'AND t.article_id NOT IN :exclude '
        params['exclude'] = [int(i) for i in exclude_ids]

    sql += f'GROUP BY t.article_id HAVING score >= {min_score(usage)} ORDER BY score DESC, hits DESC LIMIT {int(limit)}'

    return [{
        'id': int(row.article_id),
        'slug': row.slug,
        'title': row.title,
        'subtitle': row.subtitle,
        'score': int(row.score),
        'hits': int(row.hits),
    } for row in db.query(sql, params)]
