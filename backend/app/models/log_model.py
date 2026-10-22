"""
파일 위치: backend/app/models/log_model.py
역할: 세션 이벤트 로그와 관리 행위 감사 로그 기록
"""
from app import db


def _cut(detail):
    """PHP mb_substr($detail, 0, 500) 대응(글자 단위)."""
    return None if detail is None else str(detail)[:500]


def add(session_id, event, detail=None, actor='system', ip=None):
    db.insert('session_logs', {
        'session_id': int(session_id),
        'event': event,
        'detail': _cut(detail),
        'actor': actor,
        'ip': ip,
    })


def list_by_session(session_id):
    return db.query('SELECT * FROM session_logs WHERE session_id = :id ORDER BY id ASC', {'id': int(session_id)})


def list_audit(limit=500):
    """전체 조직의 감사 로그. 조직명과 수행자 이름을 붙여 돌려준다."""
    return db.query(
        'SELECT l.*, o.name AS org_name, o.org_code, a.name AS agent_name, a.email AS agent_email '
        'FROM audit_logs l '
        'LEFT JOIN organizations o ON o.id = l.org_id '
        'LEFT JOIN agents a ON a.id = l.agent_id '
        'ORDER BY l.id DESC LIMIT ' + str(int(limit)))


def audit(org_id, agent_id, action, detail=None, ip=None):
    db.insert('audit_logs', {
        'org_id': None if org_id is None else int(org_id),
        'agent_id': None if agent_id is None else int(agent_id),
        'action': action,
        'detail': _cut(detail),
        'ip': ip,
    })
