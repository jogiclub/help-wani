"""
파일 위치: backend/app/models/session_model.py
역할: 원격 세션 생성/조회/상태변경, 코드 검증, 뷰어 토큰 발급 및 소진 (PHP models/Session_model.php 대응)
"""
import hmac
import re
import secrets
import time

from app import db
from app.core.php import strtotime_utc, utc_str
from app.helpers.app import random_digits
from app.models import log_model
from app.settings import (RH_CODE_TTL, RH_HEARTBEAT_TIMEOUT, RH_VERIFY_BLOCK_WINDOW, RH_VERIFY_FAIL_LIMIT,
                          RH_VIEWER_TOKEN_TTL)

TABLE = 'sessions'

# 아직 끝나지 않은 원격 세션 상태
ACTIVE_STATUSES = ('issued', 'verified', 'waiting', 'connected')

_TOKEN_RE = re.compile(r'^[a-f0-9]{64}$')


# ------------------------------------------------------------------ 조회

def get_by_id(session_id):
    return db.first_where(TABLE, {'id': int(session_id)})


def search_history(org_id, filters=None, limit=50, offset=0):
    """원격지원 목록 검색 (원격지원 화면이 3초마다 부른다).

    status 가 'active' 이면 진행 중인 세션만, date_from/date_to 는 UTC 'Y-m-d H:i:s' 로 받는다.
    """
    filters = filters or {}
    where = ['s.org_id = :org']
    params = {'org': int(org_id)}

    if filters.get('keyword'):
        params['kw'] = db.like_pattern(filters['keyword'])
        where.append("(s.customer_pc_name LIKE :kw ESCAPE '!' OR s.code LIKE :kw ESCAPE '!' OR a.name LIKE :kw ESCAPE '!')")

    if filters.get('status'):
        if filters['status'] == 'active':
            where.append('s.status IN :active')
            params['active'] = list(ACTIVE_STATUSES)
        else:
            where.append('s.status = :status')
            params['status'] = filters['status']

    if filters.get('date_from'):
        where.append('s.created_at >= :date_from')
        params['date_from'] = filters['date_from']

    if filters.get('date_to'):
        where.append('s.created_at <= :date_to')
        params['date_to'] = filters['date_to']

    return db.query(
        'SELECT s.*, a.name AS agent_name, sv.score AS survey_score '
        'FROM sessions s '
        'JOIN agents a ON a.id = s.agent_id '
        'LEFT JOIN surveys sv ON sv.session_id = s.id '
        'WHERE ' + ' AND '.join(where) + ' '
        f'ORDER BY s.id DESC LIMIT {int(offset)}, {int(limit)}',
        params)


# ------------------------------------------------------------------ 생성

def create_session(org_id, agent_id):
    """6자리 코드를 발급해 새 세션을 만든다. 6자리 코드는 활성 세션 안에서 중복되지 않는다."""
    expire_stale()

    code = _generate_unique_code()
    data = {
        'org_id': int(org_id),
        'agent_id': int(agent_id),
        'code': code,
        'status': 'issued',
        'expires_at': utc_str(RH_CODE_TTL),
    }
    session_id = db.insert(TABLE, data)

    log_model.add(session_id, 'session_created', '코드 발급', 'agent')

    return {'id': session_id, 'code': code, 'expires_at': data['expires_at']}


def _generate_unique_code():
    for _ in range(50):
        code = random_digits(6)
        exists = int(db.scalar('SELECT COUNT(*) FROM sessions WHERE code = :code AND status IN :active',
                               {'code': code, 'active': list(ACTIVE_STATUSES)}))
        if exists == 0:
            return code

    raise RuntimeError('사용 가능한 코드를 생성하지 못했습니다.')


# ------------------------------------------------------------------ 코드 검증 (런처)

def is_ip_blocked(ip):
    """IP 기준 무차별 대입 차단 여부."""
    fails = int(db.scalar(
        'SELECT COUNT(*) FROM verify_attempts WHERE ip = :ip AND is_success = 0 AND created_at >= :since',
        {'ip': ip, 'since': utc_str(-RH_VERIFY_BLOCK_WINDOW)}))
    return fails >= RH_VERIFY_FAIL_LIMIT


def record_attempt(ip, code, success):
    db.insert('verify_attempts', {'ip': ip, 'code': code, 'is_success': 1 if success else 0})


def verify_code(code, ip, meta=None):
    """코드를 검증하고 즉시 소진(verified)한다. 성공하면 세션 레코드와 launcher_secret, agent_token 을 돌려준다."""
    meta = meta or {}
    expire_stale()

    session = db.row(
        "SELECT * FROM sessions WHERE code = :code AND status = 'issued' AND expires_at > :now ORDER BY id DESC LIMIT 1",
        {'code': code, 'now': utc_str()})

    if session is None:
        return None

    launcher_secret = secrets.token_hex(32)
    agent_token = secrets.token_hex(32)

    # 동시 검증 경쟁을 막기 위해 상태 조건을 포함해 갱신한다.
    changed = db.execute(
        'UPDATE sessions SET status = :status, launcher_secret = :secret, agent_token = :token, customer_ip = :ip, '
        'customer_pc_name = :pc, customer_os = :os, launcher_version = :ver, verified_at = :now '
        "WHERE id = :id AND status = 'issued'",
        {'status': 'verified', 'secret': launcher_secret, 'token': agent_token, 'ip': ip,
         'pc': meta.get('pc_name'), 'os': meta.get('os_version'), 'ver': meta.get('launcher_version'),
         'now': utc_str(), 'id': int(session.id)}).rowcount

    if changed < 1:
        return None

    log_model.add(session.id, 'code_verified', '고객 코드 확인', 'customer', ip)

    return {'session': get_by_id(session.id), 'launcher_secret': launcher_secret, 'agent_token': agent_token}


def get_by_agent_token(token):
    """에이전트 토큰으로 세션을 찾는다. 중계 서버 인증에 쓴다."""
    if not _TOKEN_RE.match(str(token or '')):
        return None
    return db.first_where(TABLE, {'agent_token': token})


def get_by_secret(session_id, secret):
    """launcher_secret 으로 세션을 확인한다."""
    if not secret or secret == '0':
        return None

    session = get_by_id(session_id)

    if session is None or not session.launcher_secret or session.launcher_secret == '0':
        return None

    return session if hmac.compare_digest(session.launcher_secret.encode(), str(secret).encode()) else None


# ------------------------------------------------------------------ 상태 변경

def set_status(session_id, status, extra=None):
    data = {'status': status}
    data.update(extra or {})

    if status == 'connected' and not (extra or {}).get('started_at'):
        current = get_by_id(session_id)
        if current is not None and not current.started_at:
            data['started_at'] = utc_str()

    if status in ('ended', 'expired', 'canceled'):
        data['ended_at'] = utc_str()
        data['launcher_secret'] = None
        data['agent_token'] = None

    return db.update(TABLE, data, {'id': int(session_id)})


def touch_heartbeat(session_id):
    db.update(TABLE, {'last_heartbeat_at': utc_str()}, {'id': int(session_id)})


def expire_stale():
    """만료 코드와 하트비트가 끊긴 세션을 정리한다."""
    now = utc_str()

    # 코드 미사용 만료
    db.execute(
        "UPDATE sessions SET status = 'expired', ended_at = :now, end_reason = 'code_expired' "
        "WHERE status = 'issued' AND expires_at < :now",
        {'now': now})

    # 하트비트 끊김
    db.execute(
        "UPDATE sessions SET status = 'ended', ended_at = :now, end_reason = 'heartbeat_timeout', "
        'launcher_secret = NULL, agent_token = NULL '
        "WHERE status IN ('waiting', 'connected') AND last_heartbeat_at IS NOT NULL AND last_heartbeat_at < :limit",
        {'now': now, 'limit': utc_str(-RH_HEARTBEAT_TIMEOUT)})


# ------------------------------------------------------------------ 뷰어 토큰

def issue_viewer_token(session_id):
    token = secrets.token_hex(32)

    # 같은 세션의 미사용 토큰은 폐기한다.
    db.execute(
        "UPDATE session_tokens SET used_at = :now, used_ip = 'revoked' WHERE session_id = :id AND used_at IS NULL",
        {'now': utc_str(), 'id': int(session_id)})

    db.insert('session_tokens', {
        'session_id': int(session_id),
        'token': token,
        'purpose': 'viewer',
        'expires_at': utc_str(RH_VIEWER_TOKEN_TTL),
    })

    return {'token': token, 'expires_in': RH_VIEWER_TOKEN_TTL}


def consume_viewer_token(token, ip=None):
    """토큰을 1회용으로 소진한다. 유효하면 세션 레코드를 돌려준다."""
    if not _TOKEN_RE.match(str(token or '')):
        return None

    found = db.first_where('session_tokens', {'token': token})

    if found is None or found.used_at is not None:
        return None

    expires = strtotime_utc(found.expires_at)
    if expires is None or expires < int(time.time()):
        return None

    # 동시 요청에서도 한 번만 소진되도록 조건부 갱신
    changed = db.execute(
        'UPDATE session_tokens SET used_at = :now, used_ip = :ip WHERE id = :id AND used_at IS NULL',
        {'now': utc_str(), 'ip': ip, 'id': int(found.id)}).rowcount

    if changed < 1:
        return None

    return get_by_id(found.session_id)


# ------------------------------------------------------------------ 메모

def add_note(session_id, agent_id, content):
    return db.insert('session_notes', {'session_id': int(session_id), 'agent_id': int(agent_id), 'content': content})


def list_notes(session_id):
    return db.query(
        'SELECT n.*, a.name AS agent_name FROM session_notes n JOIN agents a ON a.id = n.agent_id '
        'WHERE n.session_id = :id ORDER BY n.id ASC',
        {'id': int(session_id)})
