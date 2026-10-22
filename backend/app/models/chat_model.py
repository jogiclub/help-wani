"""
파일 위치: backend/app/models/chat_model.py
역할: 상담 위젯 대화와 메시지 저장/조회, 상담원 응대 가능 여부, 부재중 자동 응답 중복 방지, 담당 가져오기/비우기,
      첫 응답 시간 기록과 답변 속도 계산, 방문자 시청 시각·연락처 카드·답장 알림 메일 차례 판단 (PHP models/Chat_model.php 대응)

고객에게 보일 운영 상태(운영 시간, 휴일, 수동 전환)는 chat_hours_model.availability() 가 정한다(CLAUDE.md 3.16).
has_available_agent() 는 availability() 만 부른다(검사가 잡습니다).
"""
import json
import math
import time

from app import db
from app.core.php import php_empty, php_int, php_json_encode, php_str, strtotime_utc, utc_str

# 이 시간(초) 안에 콘솔 활동이 있으면 접속 중으로 본다
ONLINE_WINDOW_SECONDS = 60

# 방문자 한 명이 1분에 보낼 수 있는 메시지 수
RATE_LIMIT_PER_MINUTE = 20

# 답변 속도(실제 기록): 최근 며칠, 표본 최소 수, 다시 계산하는 간격(초)
REPLY_WINDOW_DAYS = 7
REPLY_MIN_SAMPLES = 10
REPLY_CACHE_SECONDS = 3600

# 위젯에 보여 줄 답변 속도 구간(분). 문구 키는 widget.reply.{구간}
REPLY_BUCKETS = (5, 10, 30, 60, 180, 1440)

# 방문자가 대화창을 이만큼 보지 않았으면 자리를 비운 것으로 본다(팔로업 메일, docs/widget-ops-plan.md 3-4).
VISITOR_AWAY_SECONDS = 75


# ------------------------------------------------------------------ 대화

def get_conversation(conversation_id):
    return db.first_where('conversations', {'id': php_int(conversation_id)})


def ensure_conversation(org_id, visitor_id):
    """방문자의 열린 대화를 찾고, 없으면 만든다."""
    found = db.first_where('conversations', {'org_id': int(org_id), 'visitor_id': int(visitor_id), 'status': 'open'})
    if found is not None:
        return found
    new_id = db.insert('conversations', {'org_id': int(org_id), 'visitor_id': int(visitor_id), 'status': 'open'})
    return get_conversation(new_id)


def list_for_org(org_id, filters=None, limit=100):
    """상담원 콘솔의 대화 목록. 방문자 정보와 마지막 메시지를 붙인다."""
    filters = filters or {}
    where = ['c.org_id = :org']
    params = {'org': int(org_id)}

    if not php_empty(filters.get('status')) and filters['status'] in ('open', 'closed'):
        where.append('c.status = :status')
        params['status'] = filters['status']

    if not php_empty(filters.get('keyword')):
        params['kw'] = db.like_pattern(filters['keyword'])
        where.append("(v.name LIKE :kw ESCAPE '!' OR v.email LIKE :kw ESCAPE '!' OR v.phone LIKE :kw ESCAPE '!')")

    if not php_empty(filters.get('date_from')):
        where.append('c.created_at >= :date_from')
        params['date_from'] = str(filters['date_from']) + ' 00:00:00'

    if not php_empty(filters.get('date_to')):
        where.append('c.created_at <= :date_to')
        params['date_to'] = str(filters['date_to']) + ' 23:59:59'

    return db.query(
        'SELECT c.*, v.name AS visitor_name, v.email AS visitor_email, v.phone AS visitor_phone, v.page_url, '
        'a.name AS agent_name, '
        '(SELECT body FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_body '
        'FROM conversations c JOIN visitors v ON v.id = c.visitor_id LEFT JOIN agents a ON a.id = c.assigned_agent_id '
        'WHERE ' + ' AND '.join(where) + ' '
        f'ORDER BY c.last_message_at IS NULL ASC, c.last_message_at DESC, c.id DESC LIMIT {int(limit)}',
        params)


def set_status(conversation_id, status):
    """대화 상태 변경. 허용 목록 밖의 값은 무시한다."""
    if status not in ('open', 'closed'):
        return 0
    return db.update('conversations', {
        'status': status,
        'closed_at': utc_str() if status == 'closed' else None,
    }, {'id': int(conversation_id)})


def assign(conversation_id, agent_id):
    """담당이 없을 때만 담당을 정한다(먼저 답한 상담원)."""
    return db.execute('UPDATE conversations SET assigned_agent_id = :agent WHERE id = :id AND assigned_agent_id IS NULL',
                      {'agent': int(agent_id), 'id': int(conversation_id)}).rowcount


def set_assignee(conversation_id, agent_id):
    """담당을 바꾼다(가져오기). agent_id 가 None 이면 담당을 비워 대기 중으로 돌린다."""
    return db.update('conversations', {'assigned_agent_id': None if agent_id is None else int(agent_id)},
                     {'id': int(conversation_id)})


def link_session(conversation_id, session_id):
    db.update('conversations', {'session_id': int(session_id)}, {'id': int(conversation_id)})


def mark_read(conversation_id):
    db.update('conversations', {'unread_for_agent': 0}, {'id': int(conversation_id)})
    db.execute("UPDATE messages SET read_at = :now WHERE conversation_id = :id AND sender_type = 'visitor' AND read_at IS NULL",
               {'now': utc_str(), 'id': int(conversation_id)})


# ------------------------------------------------------------------ 메시지

def add_message(conversation_id, sender_type, body, agent_id=None, meta=None):
    """메시지를 넣고 대화의 마지막 시각을 갱신한다.

    meta: 카드 정보. 가이드 카드는 {type:'guide', article_id, title, subtitle, url}
    """
    if sender_type not in ('visitor', 'agent', 'system'):
        return 0

    message_id = db.insert('messages', {
        'conversation_id': int(conversation_id),
        'sender_type': sender_type,
        'agent_id': None if agent_id is None else int(agent_id),
        'body': php_str(body)[:4000],
        # PHP json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        'meta': None if meta is None else php_json_encode(meta, unescaped_unicode=True, unescaped_slashes=True),
    })

    sets = ['last_message_at = :now']
    # 답변 속도 계산용: 방문자 첫 메시지 시각과 그 뒤 상담원 첫 답변 시각(F6)
    if sender_type == 'visitor':
        sets += ['unread_for_agent = unread_for_agent + 1', 'first_visitor_at = COALESCE(first_visitor_at, NOW())']
    elif sender_type == 'agent':
        sets.append('first_reply_at = IF(first_reply_at IS NULL AND first_visitor_at IS NOT NULL, NOW(), first_reply_at)')

    db.execute('UPDATE conversations SET ' + ', '.join(sets) + ' WHERE id = :id', {'now': utc_str(), 'id': int(conversation_id)})

    return message_id


def list_messages(conversation_id, after_id=0, limit=100):
    """커서 이후의 메시지. 같은 초에 들어온 메시지를 빠뜨리지 않도록 id 기준으로 읽는다."""
    return db.query(f'SELECT * FROM messages WHERE conversation_id = :id AND id > :after ORDER BY id ASC LIMIT {int(limit)}',
                    {'id': int(conversation_id), 'after': php_int(after_id)})


def present(message):
    """화면으로 보낼 메시지 모양. 시각은 UTC 그대로 보내고 화면이 변환한다(CLAUDE.md 3.4). 콘솔, 위젯, 실시간 발행이 함께 쓴다."""
    meta = None
    if not php_empty(message.get('meta')):
        try:
            meta = json.loads(message['meta'])
        except ValueError:
            meta = None

    return {
        'id': int(message['id']),
        'sender': message['sender_type'],
        'body': message['body'],
        'meta': meta if isinstance(meta, (dict, list)) else None,
        'created_at': message['created_at'],
    }


def guide_cards(conversation_id):
    """이 대화에서 이미 보낸 가이드 카드. 같은 글을 거듭 추천하지 않기 위해 쓴다. [{article_id, sender}]"""
    rows = db.query(
        "SELECT sender_type, CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.article_id')) AS UNSIGNED) AS article_id "
        'FROM messages WHERE conversation_id = :id AND meta IS NOT NULL '
        "AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type')) = 'guide'",
        {'id': int(conversation_id)})
    return [{'article_id': php_int(r.article_id), 'sender': r.sender_type} for r in rows]


def recent_visitor_text(conversation_id, count=3):
    """최근 방문자 메시지 본문. 상담원 콘솔 추천에 쓴다."""
    rows = db.query(f"SELECT body FROM messages WHERE conversation_id = :id AND sender_type = 'visitor' ORDER BY id DESC LIMIT {int(count)}",
                    {'id': int(conversation_id)})
    return '\n'.join(r.body for r in reversed(rows))


def is_flooding(conversation_id):
    """도배 차단. 최근 1분 동안 이 대화에 방문자가 보낸 메시지 수를 센다."""
    count = db.scalar("SELECT COUNT(*) FROM messages WHERE conversation_id = :id AND sender_type = 'visitor' "
                      'AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)', {'id': int(conversation_id)})
    return int(count) >= RATE_LIMIT_PER_MINUTE


# ------------------------------------------------------------------ 상담원 응대 여부

def touch_agent(agent_id):
    """콘솔이 살아 있음을 알린다. 운영 상태(응대 가능 상담원) 판단에 쓰인다."""
    db.update('agents', {'last_seen_at': utc_str()}, {'id': int(agent_id)})


def has_available_agent(org_id):
    """조직에 응대 가능한 상담원이 있는지. 콘솔이 열려 있어도 본인이 자리 비움/응대 종료면 세지 않는다.

    컨트롤러(경로)에서 바로 부르지 않는다. chat_hours_model.availability() 가 부른다(CLAUDE.md 3.16).
    """
    count = db.scalar("SELECT COUNT(*) FROM agents WHERE org_id = :org AND is_active = 1 AND chat_status = 'available' "
                      'AND last_seen_at IS NOT NULL AND last_seen_at >= DATE_SUB(NOW(), INTERVAL :sec SECOND)',
                      {'org': int(org_id), 'sec': ONLINE_WINDOW_SECONDS})
    return int(count) > 0


def set_agent_status(agent_id, status):
    """상담원 개인 응대 상태. 허용 목록 밖의 값은 무시한다."""
    if status not in ('available', 'away', 'off'):
        return 0
    return db.update('agents', {'chat_status': status}, {'id': int(agent_id)})


def set_agent_notify(agent_id, enabled):
    """새 문의 브라우저 알림 수신 여부(상담원 개인 설정)."""
    db.update('agents', {'chat_notify': 1 if enabled else 0}, {'id': int(agent_id)})


def claim_auto_reply(conversation_id, kind):
    """부재중 자동 응답을 보내도 되면 시각과 종류를 찍고 True.

    한 번만 보내고, 처음이거나 종류가 바뀜 / 그 뒤 상담원이 답함 / 12시간이 지남 일 때만 다시 보낸다.
    """
    return db.execute(
        'UPDATE conversations c SET c.auto_reply_at = NOW(), c.auto_reply_kind = :kind '
        'WHERE c.id = :id AND (c.auto_reply_at IS NULL OR c.auto_reply_kind <> :kind '
        'OR c.auto_reply_at < DATE_SUB(NOW(), INTERVAL 12 HOUR) '
        "OR EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.sender_type = 'agent' "
        'AND m.created_at > c.auto_reply_at))',
        {'kind': php_str(kind), 'id': int(conversation_id)}).rowcount > 0


def mark_received(conversation_id, in_hours):
    """첫 문의가 운영 중에 들어왔는지 한 번만 적는다. 답변 속도는 운영 중에 들어온 문의만으로 계산한다."""
    db.execute('UPDATE conversations SET received_in_hours = :v WHERE id = :id AND received_in_hours IS NULL',
               {'v': 1 if in_hours else 0, 'id': int(conversation_id)})


# ------------------------------------------------------------------ 답변 속도

def reply_minutes(org):
    """위젯에 보여 줄 답변 속도(분 구간). 보여 주지 않으면 None. 실제 기록은 한 시간마다 다시 계산해 organizations 에 둔다."""
    if org['chat_reply_mode'] == 'manual':
        return reply_bucket(php_int(org['chat_reply_minutes'])) if not php_empty(org.get('chat_reply_minutes')) else None

    if org['chat_reply_mode'] != 'auto':
        return None

    calc_at = strtotime_utc(org['chat_reply_calc_at']) if not php_empty(org.get('chat_reply_calc_at')) else None
    stale = calc_at is None or calc_at < time.time() - REPLY_CACHE_SECONDS

    if stale:
        median = median_reply_seconds(org['id'], REPLY_WINDOW_DAYS, REPLY_MIN_SAMPLES)
        org['chat_reply_auto_min'] = None if median is None else max(1, int(math.ceil(median / 60)))
        org['chat_reply_calc_at'] = utc_str()
        db.update('organizations', {
            'chat_reply_auto_min': org['chat_reply_auto_min'],
            'chat_reply_calc_at': org['chat_reply_calc_at'],
        }, {'id': int(org['id'])})

    return reply_bucket(php_int(org['chat_reply_auto_min'])) if not php_empty(org.get('chat_reply_auto_min')) else None


def reply_bucket(minutes):
    """3분 17초 같은 숫자는 약속처럼 보이므로 구간으로 올려 보여 준다."""
    for bucket in REPLY_BUCKETS:
        if minutes <= bucket:
            return bucket
    return REPLY_BUCKETS[-1]


def median_reply_seconds(org_id, days, min_samples=1):
    """운영 중에 들어온 문의의 첫 응답 시간 중앙값(초). 표본이 모자라면 None."""
    rows = db.query(
        'SELECT TIMESTAMPDIFF(SECOND, first_visitor_at, first_reply_at) AS sec FROM conversations '
        'WHERE org_id = :org AND first_visitor_at >= DATE_SUB(NOW(), INTERVAL :days DAY) '
        'AND first_reply_at IS NOT NULL AND received_in_hours = 1 ORDER BY sec LIMIT 5000',
        {'org': int(org_id), 'days': int(days)})
    count = len(rows)
    if count < max(1, int(min_samples)):
        return None
    middle = count // 2
    if count % 2:
        return php_int(rows[middle].sec)
    return int((php_int(rows[middle - 1].sec) + php_int(rows[middle].sec)) / 2)


def reply_stats(org_id, days=30):
    """조직 관리 통계(/admin)의 상담 대화 숫자. 방문자가 글을 남긴 대화만 센다."""
    found = db.row(
        'SELECT COUNT(*) AS total, SUM(first_reply_at IS NOT NULL) AS answered, SUM(received_in_hours = 0) AS offhours '
        'FROM conversations WHERE org_id = :org AND first_visitor_at >= DATE_SUB(NOW(), INTERVAL :days DAY)',
        {'org': int(org_id), 'days': int(days)})
    return {
        'total': php_int(found.total),
        'answered': php_int(found.answered),
        'offhours': php_int(found.offhours),
        'median_seconds': median_reply_seconds(org_id, days),
    }


def has_visitor_message(conversation_id):
    """방문자가 이 대화에 쓴 메시지가 있는지(운영 시간 외에도 이미 대화 중인 방문자는 계속 쓰게 하는 데 쓴다)."""
    return db.row("SELECT 1 AS x FROM messages WHERE conversation_id = :id AND sender_type = 'visitor' LIMIT 1",
                  {'id': int(conversation_id)}) is not None


def claim_offline_notice(conversation_id):
    """아직 오프라인 알림을 보내지 않은 대화면 발송 시각을 찍고 True."""
    return db.execute('UPDATE conversations SET offline_notified_at = :now WHERE id = :id AND offline_notified_at IS NULL',
                      {'now': utc_str(), 'id': int(conversation_id)}).rowcount > 0


# ------------------------------------------------------------------ 연락처 카드와 팔로업 메일

def touch_visitor_seen(conversation_id):
    """방문자가 지금 대화창을 보고 있다. 위젯 폴링과 전송·연락처 제출만 부른다(init 은 부르지 않음, CLAUDE.md 3.18)."""
    db.update('conversations', {'visitor_seen_at': utc_str()}, {'id': int(conversation_id)})


def mark_contact_asked(conversation_id):
    """연락처 카드를 냈거나 건너뛰었다. 이 대화에서 다시 묻지 않는다."""
    db.execute('UPDATE conversations SET contact_asked_at = :now WHERE id = :id AND contact_asked_at IS NULL',
               {'now': utc_str(), 'id': int(conversation_id)})


def claim_followup(conversation_id):
    """답장 알림 메일을 보낼 차례면 시각을 찍고 이전 값(없으면 '')을, 아니면 False 를 돌려준다.

    조건과 기록을 UPDATE 한 번으로 해서 두 상담원이 동시에 답해도 한 통만 나간다.
    """
    conversation = get_conversation(conversation_id)
    if conversation is None:
        return False

    changed = db.execute(
        'UPDATE conversations SET followup_sent_at = :now WHERE id = :id '
        'AND (visitor_seen_at IS NULL OR visitor_seen_at < :away_before) '
        'AND (followup_sent_at IS NULL OR (visitor_seen_at IS NOT NULL AND visitor_seen_at > followup_sent_at))',
        {'now': utc_str(), 'away_before': utc_str(-VISITOR_AWAY_SECONDS), 'id': int(conversation_id)}).rowcount

    if changed == 0:
        return False

    return php_str(conversation.followup_sent_at)


def release_followup(conversation_id, previous):
    """메일을 보내지 못했으면 기록을 되돌려 다음 답장 때 다시 시도하게 한다."""
    db.update('conversations', {'followup_sent_at': None if previous == '' else previous}, {'id': int(conversation_id)})


def count_unread(org_id):
    """콘솔 배지에 쓰는 미확인 대화 수."""
    return int(db.scalar("SELECT COUNT(*) FROM conversations WHERE org_id = :org AND status = 'open' AND unread_for_agent > 0",
                         {'org': int(org_id)}))
