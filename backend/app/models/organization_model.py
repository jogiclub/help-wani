"""
파일 위치: backend/app/models/organization_model.py
역할: 조직 조회와 설정 (PHP models/Organization_model.php 대응)

지금까지 옮긴 화면에 필요한 함수만 있다. 나머지 함수는 화면을 옮길 때 PHP 와 같은 이름으로 더한다.
"""
import re
import secrets
from urllib.parse import urlsplit

from app import db
from app.core.php import php_str, utc_str
from app.core.request import base_url
from app.helpers.app import customer_url

TABLE = 'organizations'


def get_by_id(org_id):
    return db.first_where(TABLE, {'id': int(org_id)})


def get_by_code(org_code):
    return db.first_where(TABLE, {'org_code': org_code})


def get_active_by_code(org_code):
    return db.first_where(TABLE, {'org_code': org_code, 'status': 'active'})


def insert(data):
    return db.insert(TABLE, data)


def update(org_id, data):
    return db.update(TABLE, data, {'id': int(org_id)})


# ------------------------------------------------------------------ 플랫폼 운영자 (조직 가입 관리)

STATUSES = ('pending', 'active', 'rejected', 'suspended')


def list_for_review(status=None, limit=200):
    """운영자 조직 목록. 상담원·원격 세션 수와 대표 관리자(가장 먼저 등록된 admin, owner)를 붙인다."""
    sql = ('SELECT o.*, (SELECT COUNT(*) FROM agents WHERE org_id = o.id) AS agent_count, '
           '(SELECT COUNT(*) FROM sessions WHERE org_id = o.id) AS session_count FROM organizations o ')
    params = {}
    if status:
        sql += 'WHERE o.status = :status '
        params['status'] = status
    sql += f"ORDER BY FIELD(o.status, 'pending', 'active', 'suspended', 'rejected'), o.created_at DESC LIMIT {int(limit)}"

    rows = db.query(sql, params)
    for found in rows:
        found['owner'] = db.row(
            "SELECT name, email, phone, created_at FROM agents WHERE org_id = :org AND role = 'admin' ORDER BY id ASC LIMIT 1",
            {'org': int(found.id)})
    return rows


def count_by_status():
    out = {status: 0 for status in STATUSES}
    for found in db.query('SELECT status, COUNT(*) AS cnt FROM organizations GROUP BY status'):
        out[found.status] = int(found.cnt)
    return out


def approve(org_id, operator_id):
    """조직을 승인(또는 다시 활성화)하고 소속 상담원을 인증된 것으로 둔다."""
    db.update(TABLE, {'status': 'active', 'approved_at': utc_str(), 'approved_by': int(operator_id),
                      'status_reason': None}, {'id': int(org_id)})
    return db.update('agents', {'is_verified': 1}, {'org_id': int(org_id)})


def set_status(org_id, status, operator_id, reason=None):
    if status not in STATUSES:
        return 0
    data = {'status': status, 'status_reason': None if reason is None else str(reason)[:300]}
    if status == 'active':
        data['approved_at'] = utc_str()
        data['approved_by'] = int(operator_id)
    return db.update(TABLE, data, {'id': int(org_id)})


def statistics(org_id, days=30):
    """최근 days 일 원격지원 통계(일별 건수, 합계·정상 종료·평균 원격 시간, 만족도). DB 시간대가 UTC 라 날짜도 UTC 기준이다."""
    params = {'org': int(org_id), 'days': int(days)}

    daily = db.query(
        'SELECT DATE(created_at) AS day, COUNT(*) AS cnt FROM sessions '
        'WHERE org_id = :org AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY) '
        'GROUP BY DATE(created_at) ORDER BY day', params)

    summary = db.row(
        "SELECT COUNT(*) AS total, SUM(status = 'ended') AS ended, "
        'AVG(CASE WHEN started_at IS NOT NULL AND ended_at IS NOT NULL '
        'THEN TIMESTAMPDIFF(SECOND, started_at, ended_at) END) AS avg_seconds '
        'FROM sessions WHERE org_id = :org AND created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)', params)

    survey = db.row(
        'SELECT COUNT(*) AS cnt, AVG(sv.score) AS avg_score FROM surveys sv '
        'JOIN sessions s ON s.id = sv.session_id '
        'WHERE s.org_id = :org AND sv.created_at >= DATE_SUB(NOW(), INTERVAL :days DAY)', params)

    return {'daily': daily, 'summary': summary, 'survey': survey}


# ------------------------------------------------------------------ 상담 위젯

def get_by_widget_key(widget_key):
    if not re.match(r'^[a-f0-9]{32}$', php_str(widget_key)):
        return None
    return db.first_where(TABLE, {'widget_key': widget_key, 'status': 'active', 'widget_enabled': 1})


def regenerate_widget_key(org_id):
    """위젯 키를 새로 발급한다. 기존 스니펫은 즉시 무효가 된다."""
    key = secrets.token_hex(16)
    db.update(TABLE, {'widget_key': key}, {'id': int(org_id)})
    return key


def widget_snippet(org):
    """고객사 사이트에 붙여 넣는 위젯 스니펫 한 줄. 공개 주소가 들어가도록 customer_url() 을 쓴다(CLAUDE.md 3.13)."""
    return '<script src="' + customer_url('widget.js') + '" data-key="' + php_str(org['widget_key']) + '" defer></script>'


def _php_url_host(url):
    """PHP parse_url($url, PHP_URL_HOST) 대응(대소문자를 그대로 둔다). 없으면 None."""
    try:
        netloc = urlsplit(php_str(url)).netloc
    except ValueError:
        return None
    host = netloc.rsplit('@', 1)[-1]
    if host.startswith('['):
        host = host[:host.find(']') + 1] if ']' in host else host
    elif ':' in host:
        host = host[:host.rfind(':')]
    return host or None


def widget_origin_allowed(org, origin):
    """요청 Origin 이 조직이 허용한 도메인인지. 허용 목록이 비어 있으면 모두 허용(개발 편의).

    포털 자기 주소(고객 접속 페이지 /{조직코드} 에 뜨는 위젯)는 허용 목록과 관계없이 통과한다.
    """
    allowed = [r.strip(' \t\n\r\x00\x0b') for r in re.split(r'[\r\n,]+', php_str(org.get('widget_domains')))]
    allowed = [r for r in allowed if r not in ('', '0')]

    if not allowed:
        return True

    host = _php_url_host(origin)
    if not host or host == '0':
        return False

    for own in (base_url(), customer_url()):
        own_host = _php_url_host(own) or ''
        if host.lower() == own_host.lower():
            return True

    for rule in allowed:
        rule = re.sub(r'^https?://', '', rule).lower().rstrip('/')
        if rule == '':
            continue
        # "example.com" 은 하위 도메인까지 허용한다.
        if host.lower() == rule or host.endswith('.' + rule):
            return True

    return False
