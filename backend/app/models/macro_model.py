"""
파일 위치: backend/app/models/macro_model.py
역할: 상담 대화 매크로(자주 쓰는 답변) 저장/조회, 입력 검사, 권한 판정, 즐겨찾기, 사용 기록, 추천 색인, CSV 가져오기/내보내기
      (PHP models/Macro_model.php 대응)

macros 테이블은 이 모델만 고친다(save / delete / import). 저장할 때 검사(clean_macro), 이름 중복(owner_key),
추천 색인(macro_terms)을 함께 처리한다(CLAUDE.md 3.17, 검사가 잡습니다).
범위: owner_agent_id 가 NULL 이면 조직 공용(scope 'org'), 값이 있으면 그 상담원의 개인 매크로(scope 'personal').
"""
import re

from app import db
from app.core.php import PCRE_U_SPACE, number_format, php_int, php_str, utc_str
from app.helpers.php_csv import fgetcsv_all, fputcsv
from app.libraries import guide_matcher
from app.settings import macro as conf

# CSV 머리글. 한글 머리글도 받는다.
CSV_COLUMNS = ('name', 'content', 'keywords')

_HEADER_ALIASES = {
    'name': 'name', '이름': 'name', '매크로 이름': 'name',
    'content': 'content', '내용': 'content', '매크로 내용': 'content',
    'keywords': 'keywords', '키워드': 'keywords',
}


class _Rollback(Exception):
    """import() 의 트랜잭션을 되돌리고 오류를 돌려주려고 쓴다."""

    def __init__(self, message):
        super().__init__(message)
        self.message = message


def editor_conf(agent):
    """편집 드로어(templates/console/_macro_edit.html)와 입력창 / 목록이 쓰는 화면 설정."""
    return {
        'isAdmin': agent['role'] == 'admin',
        'nameMax': int(conf.NAME_MAX),
        'bodyMax': int(conf.BODY_MAX),
        'keywordsMax': int(conf.KEYWORDS_MAX),
        'maxFavorites': int(conf.MAX_FAVORITES),
        'variables': dict(conf.VARIABLES),
    }


# ------------------------------------------------------------------ 조회와 권한

def list_for_agent(agent):
    """상담원이 쓸 수 있는 매크로 전체(공용 + 본인 개인)와 본인 즐겨찾기 여부. 개인 → 공용, 최근 사용 순."""
    rows = db.query(
        'SELECT m.id, m.owner_agent_id, m.name, m.body, m.keywords, m.use_count, m.last_used_at, '
        'm.created_at, m.updated_at, a.name AS created_by_name, f.sort_order AS fav_order '
        'FROM macros m LEFT JOIN agents a ON a.id = m.created_by '
        'LEFT JOIN macro_favorites f ON f.macro_id = m.id AND f.agent_id = :agent '
        'WHERE m.org_id = :org AND (m.owner_agent_id IS NULL OR m.owner_agent_id = :agent) '
        'ORDER BY (m.owner_agent_id IS NULL) ASC, m.last_used_at IS NULL ASC, m.last_used_at DESC, m.name ASC',
        {'agent': int(agent['id']), 'org': int(agent['org_id'])})
    return [present(r, agent) for r in rows]


def present(found, agent):
    scope = 'org' if found['owner_agent_id'] is None else 'personal'
    fav = found.get('fav_order')
    return {
        'id': int(found['id']),
        'scope': scope,
        'name': found['name'],
        'body': found['body'],
        'keywords': php_str(found['keywords']),
        'use_count': php_int(found['use_count']),
        'last_used_at': found['last_used_at'],
        'created_at': found['created_at'],
        'updated_at': found['updated_at'],
        'created_by_name': php_str(found['created_by_name']) if found.get('created_by_name') is not None else '',
        'favorite': fav is not None,
        'fav_order': php_int(fav) if fav is not None else None,
        'can_edit': can_edit_scope(agent, scope),
    }


def get_usable(agent, macro_id):
    """상담원이 쓸 수 있는(보이는) 매크로 하나. 다른 조직이나 다른 사람의 개인 매크로면 None."""
    found = db.first_where('macros', {'id': php_int(macro_id), 'org_id': int(agent['org_id'])})
    if found is None or (found.owner_agent_id is not None and int(found.owner_agent_id) != int(agent['id'])):
        return None
    return found


def get_editable(agent, macro_id):
    """상담원이 고칠 수 있는 매크로 하나. 공용은 조직 관리자만, 개인은 본인만."""
    found = get_usable(agent, macro_id)
    if found is None or not can_edit_scope(agent, 'org' if found.owner_agent_id is None else 'personal'):
        return None
    return found


def can_edit_scope(agent, scope):
    return scope == 'personal' or (scope == 'org' and agent['role'] == 'admin')


def scope_value(value):
    """범위 값 허용 목록 검사. 틀리면 None."""
    return value if value in ('org', 'personal') else None


def _owner_for(agent, scope):
    return None if scope == 'org' else int(agent['id'])


def _count_in_scope(agent, scope):
    if scope == 'org':
        return int(db.scalar('SELECT COUNT(*) FROM macros WHERE org_id = :org AND owner_agent_id IS NULL',
                             {'org': int(agent['org_id'])}))
    return int(db.scalar('SELECT COUNT(*) FROM macros WHERE org_id = :org AND owner_agent_id = :owner',
                         {'org': int(agent['org_id']), 'owner': int(agent['id'])}))


def _scope_limit(scope):
    return int(conf.MAX_ORG if scope == 'org' else conf.MAX_PERSONAL)


def _find_by_name(agent, scope, name):
    """같은 범위의 같은 이름. owner_key 는 공용이면 0, 개인이면 상담원 id(모델이 채운다)."""
    return db.first_where('macros', {'org_id': int(agent['org_id']), 'owner_key': int(_owner_for(agent, scope) or 0),
                                     'name': name})


# ------------------------------------------------------------------ 입력 검사

def _php_trim(text):
    return text.strip(' \t\n\r\x00\x0b')


def _one_line(value):
    value = re.sub(r'[\x00-\x1f\x7f]+', ' ', php_str(value))
    return _php_trim(re.sub(PCRE_U_SPACE + '+', ' ', value))


def _multi_line(value):
    value = php_str(value).replace('\r\n', '\n').replace('\r', '\n')
    # 줄바꿈과 탭만 남기고 제어 문자를 지운다.
    value = re.sub(r'[\x00-\x08\x0b-\x1f\x7f]', '', value)
    return _php_trim(value)


def clean_macro(data_in):
    """화면 저장과 파일 가져오기가 함께 쓰는 검사. {'ok': 정리한 값} 또는 {'error': (필드, 문구)}."""
    name = _one_line(data_in.get('name', ''))
    body = _multi_line(data_in.get('body', ''))
    keywords = _one_line(data_in.get('keywords', ''))

    if name == '':
        return {'error': ('name', '이름을 입력해 주세요.')}
    if len(name) > conf.NAME_MAX:
        return {'error': ('name', f'이름은 {conf.NAME_MAX}자까지입니다.')}
    if body == '':
        return {'error': ('body', '내용을 입력해 주세요.')}
    if len(body) > conf.BODY_MAX:
        return {'error': ('body', '내용은 ' + number_format(conf.BODY_MAX) + '자까지입니다.')}

    # 키워드는 쉼표로 나눠 빈 칸과 중복을 지운다.
    parts = []
    for word in keywords.split(','):
        word = _php_trim(word)
        if word != '' and word not in parts:
            parts.append(word)
    keywords = ', '.join(parts)

    if len(keywords) > conf.KEYWORDS_MAX:
        return {'error': ('keywords', f'키워드는 {conf.KEYWORDS_MAX}자까지입니다.')}

    return {'ok': {'name': name, 'body': body, 'keywords': None if keywords == '' else keywords}}


def unknown_variables(body):
    """내용에 들어 있는 변수 중 모르는 것. 저장은 막지 않고 경고만 한다."""
    out = []
    for name in re.findall(r'\{([a-z_]+)\}', php_str(body)):
        if name not in conf.VARIABLES and name not in out:
            out.append(name)
    return out


# ------------------------------------------------------------------ 저장

def save(agent, scope, data_in, macro_id=0):
    """만들기/고치기. macro_id 가 있으면 고치기이며 범위는 바꾸지 않는다. 저장한 id(int) 또는 오류 문구(str)."""
    clean = clean_macro(data_in)
    if 'error' in clean:
        return clean['error'][1]

    data = dict(clean['ok'])

    if macro_id > 0:
        found = get_editable(agent, macro_id)
        if found is None:
            return '매크로를 찾을 수 없거나 고칠 권한이 없습니다.'
        scope = 'org' if found.owner_agent_id is None else 'personal'
    elif not can_edit_scope(agent, scope):
        return '공용 매크로는 조직 관리자만 만들 수 있습니다.'

    same = _find_by_name(agent, scope, data['name'])
    if same is not None and int(same.id) != int(macro_id):
        return '같은 이름의 매크로가 이미 있습니다.'

    if macro_id > 0:
        data['updated_by'] = int(agent['id'])
        db.update('macros', data, {'id': int(macro_id)})
    else:
        if _count_in_scope(agent, scope) >= _scope_limit(scope):
            return ('공용' if scope == 'org' else '개인') + ' 매크로는 ' + number_format(_scope_limit(scope)) + '개까지 만들 수 있습니다.'
        owner = _owner_for(agent, scope)
        data.update(org_id=int(agent['org_id']), owner_agent_id=owner, owner_key=int(owner or 0),
                    created_by=int(agent['id']), updated_by=int(agent['id']))
        macro_id = db.insert('macros', data)

    _reindex(int(agent['org_id']), macro_id, data['name'], data['keywords'], data['body'])
    return int(macro_id)


def delete(agent, ids):
    """고칠 수 있는 것만 지운다. 즐겨찾기, 색인은 외래 키로 함께 지워진다. 지운 매크로 [{id, scope, name}]."""
    deleted = []
    seen = []
    for value in ids:
        macro_id = php_int(value)
        if macro_id in seen:
            continue
        seen.append(macro_id)
        found = get_editable(agent, macro_id)
        if found is not None:
            db.execute('DELETE FROM macros WHERE id = :id', {'id': int(found.id)})
            deleted.append({'id': int(found.id), 'scope': 'org' if found.owner_agent_id is None else 'personal',
                            'name': found.name})
    return deleted


def copy_to_personal(agent, macro_id):
    """쓸 수 있는 매크로를 내 개인 매크로로 복사한다. 이름이 겹치면 뒤에 (복사본) 을 붙인다."""
    found = get_usable(agent, macro_id)
    if found is None:
        return '매크로를 찾을 수 없습니다.'

    name = found.name
    suffix = ' (복사본)'
    n = 1
    while _find_by_name(agent, 'personal', name):
        tail = suffix if n == 1 else f' (복사본 {n})'
        name = found.name[:conf.NAME_MAX - len(tail)] + tail
        if n > 50:
            return '복사본 이름을 정하지 못했습니다. 기존 개인 매크로 이름을 정리해 주세요.'
        n += 1

    return save(agent, 'personal', {'name': name, 'body': found.body, 'keywords': found.keywords})


# ------------------------------------------------------------------ 즐겨찾기

def set_favorite(agent, macro_id, on):
    """True 또는 오류 문구."""
    found = get_usable(agent, macro_id)
    if found is None:
        return '매크로를 찾을 수 없습니다.'

    key = {'agent_id': int(agent['id']), 'macro_id': int(found.id)}

    if not on:
        db.execute('DELETE FROM macro_favorites WHERE agent_id = :agent_id AND macro_id = :macro_id', key)
        return True

    if db.first_where('macro_favorites', key):
        return True

    count = int(db.scalar('SELECT COUNT(*) FROM macro_favorites WHERE agent_id = :a', {'a': int(agent['id'])}))
    if count >= conf.MAX_FAVORITES:
        return f'즐겨찾기는 {conf.MAX_FAVORITES}개까지입니다.'

    top = db.scalar('SELECT MAX(sort_order) FROM macro_favorites WHERE agent_id = :a', {'a': int(agent['id'])})
    db.insert('macro_favorites', dict(key, sort_order=php_int(top) + 1 if top is not None else 0))
    return True


def set_favorite_order(agent, ids):
    """즐겨찾기 순서를 화면에서 보낸 차례대로 바꾼다."""
    order = 0
    seen = []
    for value in ids:
        macro_id = php_int(value)
        if macro_id in seen:
            continue
        seen.append(macro_id)
        db.execute('UPDATE macro_favorites SET sort_order = :o WHERE agent_id = :a AND macro_id = :m',
                   {'o': order, 'a': int(agent['id']), 'm': macro_id})
        order += 1


# ------------------------------------------------------------------ 사용 기록

def record_use(agent, macro_id, conversation_id, message_id):
    """매크로로 채운 글을 보냈을 때 부른다. 쓸 수 없는 매크로면 조용히 넘긴다(전송은 이미 끝났다)."""
    found = get_usable(agent, macro_id)
    if found is None:
        return False

    db.execute('UPDATE macros SET use_count = use_count + 1, last_used_at = :now WHERE id = :id',
               {'now': utc_str(), 'id': int(found.id)})
    db.insert('macro_uses', {'org_id': int(agent['org_id']), 'macro_id': int(found.id), 'agent_id': int(agent['id']),
                             'conversation_id': int(conversation_id), 'message_id': int(message_id)})
    return True


def usage_stats(org_id, from_utc, limit=10):
    """기간 안에 많이 쓴 공용 매크로(조직 관리 통계). 개인 매크로는 이름을 보이지 않고 합계만 낸다."""
    top = db.query(
        'SELECT m.id, m.name, COUNT(*) AS uses FROM macro_uses u JOIN macros m ON m.id = u.macro_id '
        'WHERE u.org_id = :org AND u.created_at >= :from AND m.owner_agent_id IS NULL '
        f'GROUP BY m.id, m.name ORDER BY uses DESC, m.name ASC LIMIT {int(limit)}',
        {'org': int(org_id), 'from': from_utc})
    sums = db.row(
        'SELECT COUNT(*) AS total, SUM(CASE WHEN m.owner_agent_id IS NOT NULL THEN 1 ELSE 0 END) AS personal '
        'FROM macro_uses u LEFT JOIN macros m ON m.id = u.macro_id WHERE u.org_id = :org AND u.created_at >= :from',
        {'org': int(org_id), 'from': from_utc})
    return {
        'top': [{'id': int(r.id), 'name': r.name, 'uses': int(r.uses)} for r in top],
        'personal_uses': php_int(sums.personal),
        'total_uses': php_int(sums.total),
    }


# ------------------------------------------------------------------ 추천 (AI 없이 키워드 매칭, 낱말 처리는 guide_matcher 와 같다)

def _reindex(org_id, macro_id, name, keywords, body):
    sources = {
        'name': php_str(name),
        'keywords': php_str(keywords).replace(',', ' '),
        'body': php_str(body),
    }
    terms = {}

    def keep_max(term, weight):
        key = guide_matcher._php_key(term)
        if key not in terms or terms[key] < weight:
            terms[key] = weight

    for field, text in sources.items():
        weight = conf.WEIGHTS[field]
        for word in guide_matcher.words(text):
            keep_max(word, weight['word'])
            if weight['gram'] > 0:
                for gram in guide_matcher.grams(word):
                    keep_max(gram, weight['gram'])

    ordered = sorted(terms.items(), key=lambda item: -item[1])[:int(conf.MAX_TERMS)]

    db.execute('DELETE FROM macro_terms WHERE macro_id = :id', {'id': int(macro_id)})
    for term, weight in ordered:
        db.insert('macro_terms', {'org_id': int(org_id), 'term': str(term), 'macro_id': int(macro_id), 'weight': int(weight)})


def suggest(agent, text):
    """고객 문장에 맞는 매크로 id 를 점수순으로. 공용과 본인 개인만 후보. 동의어는 가이드 동의어 묶음을 함께 쓴다."""
    from app.models import guide_model

    terms = guide_matcher.query_terms(text, guide_model.synonym_groups(agent['org_id']))
    if not terms:
        return []

    rows = db.query(
        'SELECT t.macro_id, SUM(t.weight) AS score FROM macro_terms t JOIN macros m ON m.id = t.macro_id '
        'WHERE t.org_id = :org AND m.org_id = :org AND (m.owner_agent_id IS NULL OR m.owner_agent_id = :agent) '
        f'AND t.term IN :terms GROUP BY t.macro_id HAVING score >= {int(conf.SUGGEST_MIN_SCORE)} '
        f'ORDER BY score DESC LIMIT {int(conf.SUGGEST_LIMIT)}',
        {'org': int(agent['org_id']), 'agent': int(agent['id']), 'terms': terms})
    return [{'id': int(r.macro_id), 'score': int(r.score)} for r in rows]


# ------------------------------------------------------------------ CSV 가져오기/내보내기

def _decode_csv(content):
    """BOM 이 있으면 떼고, UTF-8 이 아니면 CP949(엑셀 저장)로 읽는다. PHP mb_convert_encoding 처럼 못 읽는 바이트는 '?'."""
    if content.startswith(b'\xef\xbb\xbf'):
        return content[3:].decode('utf-8', 'replace')
    try:
        return content.decode('utf-8')
    except UnicodeDecodeError:
        return content.decode('cp949', 'php_question')


def _register_codec_error():
    import codecs
    codecs.register_error('php_question', lambda error: ('?', error.end))


_register_codec_error()


def _from_cell(value):
    value = php_str(value)
    return value[1:] if re.match(r"^'[=+\-@\t\r]", value) else value


def parse_csv(content):
    """CSV 내용(bytes)을 행 목록으로. {'rows': [{row, name, content, keywords}]} 또는 {'error': 문구}."""
    rows_raw = fgetcsv_all(_decode_csv(content))

    if not rows_raw:
        return {'error': '빈 파일입니다.'}

    header = rows_raw[0]
    index = {}
    for i, title in enumerate(header):
        title = _php_trim(php_str(title)).lower()
        if title in _HEADER_ALIASES and _HEADER_ALIASES[title] not in index:
            index[_HEADER_ALIASES[title]] = i

    if 'name' not in index or 'content' not in index:
        return {'error': '첫 줄 머리글에 name, content 열이 있어야 합니다. [양식 받기] 의 파일을 써 주세요.'}

    rows = []
    line = 1
    for cells in rows_raw[1:]:
        line += 1
        # 완전히 빈 줄은 건너뛴다.
        if not any(_php_trim(php_str(v)) != '' for v in cells):
            continue

        def get(key):
            i = index.get(key)
            return _from_cell(cells[i]) if i is not None and i < len(cells) and cells[i] is not None else ''

        rows.append({'row': line, 'name': get('name'), 'content': get('content'), 'keywords': get('keywords')})

        if len(rows) > conf.IMPORT_MAX_ROWS:
            return {'error': '한 파일에 ' + number_format(conf.IMPORT_MAX_ROWS) + '행까지 올릴 수 있습니다.'}

    return {'rows': rows}


def import_rows(agent, scope, rows, mode):
    """행을 모두 검사하고, 오류가 하나도 없을 때만 한 트랜잭션으로 저장한다(전부 또는 전무).

    mode: skip(같은 이름은 그대로 둠) | overwrite(같은 이름은 내용을 바꿈)
    결과: {'errors': [...]} 또는 {'created': n, 'updated': n, 'skipped': n}
    """
    errors, plan, seen = [], [], {}

    for found in rows:
        clean = clean_macro({'name': found['name'], 'body': found['content'], 'keywords': found['keywords']})
        if 'error' in clean:
            field = 'content' if clean['error'][0] == 'body' else clean['error'][0]
            errors.append({'row': found['row'], 'field': field, 'message': clean['error'][1]})
            continue

        data = clean['ok']
        if data['name'] in seen:
            errors.append({'row': found['row'], 'field': 'name',
                           'message': f"같은 파일의 {seen[data['name']]}행과 이름이 같습니다."})
            continue

        seen[data['name']] = found['row']
        plan.append({'data': data, 'existing': _find_by_name(agent, scope, data['name'])})

    if errors:
        return {'errors': errors}

    new = sum(1 for p in plan if p['existing'] is None)
    current = _count_in_scope(agent, scope)
    if current + new > _scope_limit(scope):
        return {'errors': [{'row': 0, 'field': '',
                            'message': ('공용' if scope == 'org' else '개인') + ' 매크로는 ' + number_format(_scope_limit(scope))
                            + '개까지입니다. 지금 ' + number_format(current) + f'개에 새로 {new}개를 더할 수 없습니다.'}]}

    result = {'created': 0, 'updated': 0, 'skipped': 0}
    try:
        with db.transaction():
            for item in plan:
                if item['existing'] is not None and mode != 'overwrite':
                    result['skipped'] += 1
                    continue
                saved = save(agent, scope, item['data'], int(item['existing'].id) if item['existing'] is not None else 0)
                if isinstance(saved, str):
                    # 검사를 미리 했으므로 여기로 오면 동시에 다른 사람이 같은 이름을 만든 경우다.
                    raise _Rollback(saved)
                result['updated' if item['existing'] is not None else 'created'] += 1
    except _Rollback as rollback:
        return {'errors': [{'row': 0, 'field': '', 'message': rollback.message}]}

    return result


def _to_cell(value):
    """엑셀이 = + - @ 로 시작하는 칸을 수식으로 실행하지 않도록 앞에 ' 를 붙인다. 가져올 때 _from_cell 이 뗀다."""
    value = php_str(value)
    return "'" + value if re.match(r'^[=+\-@\t\r]', value) else value


def to_csv(macros=None):
    """CSV 내용(UTF-8 BOM, 엑셀에서 한글이 깨지지 않게). macros 가 없으면 양식(예시 한 줄)을 만든다."""
    out = [fputcsv(CSV_COLUMNS)]
    if macros is None:
        macros = [{
            'name': '환불 규정 안내',
            'body': '{visitor_name}님, 환불은 결제일로부터 7일 이내에 신청하실 수 있습니다.\n자세한 내용은 안내 페이지를 확인해 주세요.',
            'keywords': '환불, 취소, 반품',
        }]
    for m in macros:
        out.append(fputcsv([_to_cell(m['name']), _to_cell(m['body']), _to_cell(php_str(m['keywords']))]))
    return '﻿' + ''.join(out)


def export_rows(agent, scope):
    """범위 하나의 매크로를 내보내기용으로 읽는다. 개인은 본인 것만."""
    if scope == 'org':
        return db.query('SELECT name, body, keywords FROM macros WHERE org_id = :org AND owner_agent_id IS NULL ORDER BY name ASC',
                        {'org': int(agent['org_id'])})
    return db.query('SELECT name, body, keywords FROM macros WHERE org_id = :org AND owner_agent_id = :owner ORDER BY name ASC',
                    {'org': int(agent['org_id']), 'owner': int(agent['id'])})
