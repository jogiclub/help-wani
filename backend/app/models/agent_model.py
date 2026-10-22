"""
파일 위치: backend/app/models/agent_model.py
역할: 상담원 계정 조회, 등록, 인증
"""
from app import db
from app.core.password import password_hash, password_verify
from app.core.php import utc_now_str

TABLE = 'agents'


def get_by_id(agent_id):
    return db.first_where(TABLE, {'id': int(agent_id)})


def get_by_email(email):
    return db.first_where(TABLE, {'email': email})


def list_by_org(org_id):
    return db.query('SELECT * FROM agents WHERE org_id = :org ORDER BY id ASC', {'org': int(org_id)})


def admin_email(org_id):
    """조직의 대표 관리자 이메일. 위젯 오프라인 알림의 기본 수신자로 쓴다."""
    found = db.row(
        "SELECT email FROM agents WHERE org_id = :org AND role = 'admin' AND is_active = 1 ORDER BY id ASC LIMIT 1",
        {'org': int(org_id)})
    return None if found is None else found.email


def _with_hash(data):
    data = dict(data)
    if 'password' in data:
        data['password_hash'] = password_hash(data.pop('password'))
    return data


def insert(data):
    return db.insert(TABLE, _with_hash(data))


def update(agent_id, data):
    return db.update(TABLE, _with_hash(data), {'id': int(agent_id)})


def authenticate(email, password):
    """이메일/비밀번호 검증. 성공 시 상담원 레코드를 돌려준다."""
    agent = get_by_email(email)

    if agent is None or int(agent.is_active) != 1:
        return None

    if not password_verify(password, agent.password_hash):
        return None

    return agent


def touch_login(agent_id):
    db.update(TABLE, {
        'last_login_at': utc_now_str(),
        # 로그인하면 응대 가능으로 시작한다. 어제 '응대 종료' 로 두고 퇴근한 사람이 오늘 안 보이는 일을 막는다.
        'chat_status': 'available',
    }, {'id': int(agent_id)})
