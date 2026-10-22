"""
파일 위치: backend/app/models/chat_hours_model.py
역할: 상담 운영 시간·휴일 저장과 운영 상태 판정 (PHP models/Chat_hours_model.php 대응, docs/chat-operation-plan.md F1~F3)

운영 시간과 휴일은 "조직 시간대의 벽시계" 값(분, 날짜)으로 저장한다. UTC 로 바꾸지 않는다(CLAUDE.md 3.4 의 유일한 예외).
판정할 때만 지금 시각을 조직 시간대로 바꿔 비교하고, 밖으로 내보내는 시각은 UTC 로 돌려준다.
운영 여부는 availability() 한 곳에서만 계산한다(CLAUDE.md 3.16).

서머타임: PHP DateTime::setTime() 은 없는 시각(봄)을 앞으로 밀고, 겹치는 시각(가을)은 앞의 것을 고른다.
zoneinfo 의 fold=0 이 같은 결과를 낸다(PHP 와 비교해 확인, 계획 문서 4단계 결과).
"""
import calendar
import json
import re
import time
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

from app import db
from app.core.i18n import lang_text
from app.core.php import php_date, php_empty, php_int, php_str, strtotime_utc

# 하루에 둘 수 있는 운영 구간 수
MAX_RANGES_PER_DAY = 3

# 다음 운영 시각을 찾을 때 앞으로 살펴보는 날 수
LOOKAHEAD_DAYS = 14

# 화면 표시 순서(월요일부터)
WEEK_ORDER = (1, 2, 3, 4, 5, 6, 0)


# ------------------------------------------------------------------ 운영 시간

def get_hours(org_id):
    """{weekday(0~6): [[start_min, end_min], ...]} (시작 순)"""
    out = {weekday: [] for weekday in range(7)}
    for found in db.query('SELECT weekday, start_min, end_min FROM chat_hours WHERE org_id = :org ORDER BY weekday ASC, start_min ASC',
                          {'org': int(org_id)}):
        out[int(found.weekday)].append([int(found.start_min), int(found.end_min)])
    return out


def clean_hours(value):
    """화면에서 받은 운영 시간을 검사해 정리한다(허용 목록 검사, CLAUDE.md 3.7). 잘못되면 오류 문구(str)."""
    if isinstance(value, str):
        try:
            value = json.loads(value)
        except ValueError:
            value = None

    if isinstance(value, list):
        value = {str(i): v for i, v in enumerate(value)}
    if not isinstance(value, dict):
        return '운영 시간 형식이 올바르지 않습니다.'

    out = {weekday: [] for weekday in range(7)}

    for weekday, ranges in value.items():
        if isinstance(ranges, dict):
            ranges = list(ranges.values())
        if not re.match(r'^[0-6]$', php_str(weekday)) or not isinstance(ranges, list):
            return '운영 시간 형식이 올바르지 않습니다.'

        if len(ranges) > MAX_RANGES_PER_DAY:
            return f'하루에 운영 구간은 {MAX_RANGES_PER_DAY}개까지 둘 수 있습니다.'

        day = []
        for item in ranges:
            if isinstance(item, dict):
                item = [item.get('0'), item.get('1')]
            start = parse_time(item[0]) if isinstance(item, list) and len(item) > 0 and item[0] is not None else None
            end = parse_time(item[1]) if isinstance(item, list) and len(item) > 1 and item[1] is not None else None

            if start is None or end is None or start >= end:
                return '운영 시간은 시작이 끝보다 빨라야 합니다. 자정을 넘기면 다음 날 00:00 구간으로 나눠 넣어 주세요.'

            day.append([start, end])

        day.sort(key=lambda r: r[0])
        for i in range(1, len(day)):
            if day[i][0] < day[i - 1][1]:
                return '같은 날의 운영 구간이 서로 겹칩니다.'

        out[php_int(weekday)] = day

    return out


def save_hours(org_id, hours):
    with db.transaction():
        db.execute('DELETE FROM chat_hours WHERE org_id = :org', {'org': int(org_id)})
        for weekday, ranges in hours.items():
            for start, end in ranges:
                db.insert('chat_hours', {'org_id': int(org_id), 'weekday': int(weekday),
                                         'start_min': int(start), 'end_min': int(end)})
    return True


def parse_time(text):
    """'HH:MM' -> 자정부터의 분. 끝 시각으로 24:00 까지 받는다."""
    match = re.match(r'^([0-9]{1,2}):([0-9]{2})$', php_str(text).strip(' \t\n\r\x00\x0b'))
    if not match:
        return None
    minutes = int(match.group(1)) * 60 + int(match.group(2))
    return minutes if int(match.group(2)) < 60 and minutes <= 1440 else None


def format_time(minutes):
    minutes = int(minutes)
    return '%02d:%02d' % (minutes // 60, minutes % 60)


def summary(org):
    """위젯 머리의 한 줄 요약. 같은 시간인 연속 요일을 묶는다. 예) "월-금 09:00-18:00 · 토 10:00-14:00" """
    if php_int(org.get('chat_hours_enabled')) != 1:
        return ''

    hours = get_hours(org['id'])
    groups = []
    for weekday in WEEK_ORDER:
        if groups and groups[-1]['ranges'] == hours[weekday]:
            groups[-1]['days'].append(weekday)
        else:
            groups.append({'days': [weekday], 'ranges': hours[weekday]})

    parts = []
    for group in groups:
        if not group['ranges']:
            continue
        label = lang_text('chat.wd.' + str(group['days'][0]))
        if len(group['days']) > 1:
            label += ('-' if len(group['days']) > 2 else '·') + lang_text('chat.wd.' + str(group['days'][-1]))
        times = [format_time(r[0]) + '-' + format_time(r[1]) for r in group['ranges']]
        parts.append(label + ' ' + ', '.join(times))

    return ' · '.join(parts)


# ------------------------------------------------------------------ 휴일

def list_holidays(org_id):
    return db.query('SELECT * FROM chat_holidays WHERE org_id = :org ORDER BY repeat_yearly DESC, start_date ASC',
                    {'org': int(org_id)})


def present_holiday(found):
    """화면에 보낼 휴일 모양."""
    return {
        'id': int(found.id),
        'start_date': found.start_date,
        'end_date': found.end_date,
        'name': found.name,
        'mode': found.mode,
        'start': None if found.start_min is None else format_time(found.start_min),
        'end': None if found.end_min is None else format_time(found.end_min),
        'repeat_yearly': php_int(found.repeat_yearly) == 1,
    }


def holiday_candidates(org):
    """조직 국가의 공휴일 후보(settings/holidays.py). 오늘(조직 시간대) 이후만, 이미 넣은 날(매년 반복 제외)은 added."""
    from app.settings.holidays import HOLIDAYS

    candidates = HOLIDAYS.get(php_str(org.get('country')), {})
    today = datetime.now(_org_timezone(org)).strftime('%Y-%m-%d')
    added = {found.start_date for found in list_holidays(org['id']) if php_int(found.repeat_yearly) == 0}

    return [{'date': day, 'name': name, 'added': day in added} for day, name in candidates.items() if day >= today]


def _valid_date(text):
    match = re.match(r'^([0-9]{4})-([0-9]{2})-([0-9]{2})$', text)
    if not match:
        return False
    year, month, day = (int(g) for g in match.groups())
    return 1 <= month <= 12 and 1 <= year <= 32767 and 1 <= day <= calendar.monthrange(year, month)[1]


def clean_holiday(data_in):
    """휴일 입력 검사(허용 목록 검사, CLAUDE.md 3.7). 잘못되면 오류 문구(str)."""
    start = php_str(data_in.get('start_date', ''))
    end = php_str(data_in['end_date']) if data_in.get('end_date') not in (None, '') else start
    name = php_str(data_in.get('name', '')).strip(' \t\n\r\x00\x0b')
    mode = 'short' if data_in.get('mode') == 'short' else 'closed'

    if not _valid_date(start) or not _valid_date(end) or end < start:
        return '휴일 날짜를 확인해 주세요.'

    if (date.fromisoformat(end) - date.fromisoformat(start)).days > 60:
        return '휴일 기간은 60일까지 넣을 수 있습니다.'

    if name == '':
        return '휴일 이름을 입력해 주세요.'

    found = {
        'start_date': start, 'end_date': end, 'name': name[:60], 'mode': mode,
        'start_min': None, 'end_min': None, 'repeat_yearly': 0 if php_empty(data_in.get('repeat_yearly')) else 1,
    }

    if mode == 'short':
        found['start_min'] = parse_time(data_in.get('start', ''))
        found['end_min'] = parse_time(data_in.get('end', ''))
        if found['start_min'] is None or found['end_min'] is None or found['start_min'] >= found['end_min']:
            return '단축 운영 시간은 시작이 끝보다 빨라야 합니다.'

    return found


def add_holiday(org_id, found):
    found = dict(found)
    found['org_id'] = int(org_id)
    return db.insert('chat_holidays', found)


def delete_holiday(org_id, holiday_id):
    return db.execute('DELETE FROM chat_holidays WHERE org_id = :org AND id = :id',
                      {'org': int(org_id), 'id': php_int(holiday_id)}).rowcount


# ------------------------------------------------------------------ 판정

def availability(org):
    """지금 고객이 볼 운영 상태. 위젯, 오프라인 메일, 자동 응답, 콘솔 배지가 모두 이것을 쓴다.

    {state: open|away|closed, reason: ''|hours|holiday|manual|agents, holiday, next_open_at(UTC),
     manual: auto|open|closed, manual_until, hours_enabled, online}
    """
    from app.models import chat_model

    now = int(time.time())
    enabled = php_int(org.get('chat_hours_enabled')) == 1
    schedule = _schedule(org) if enabled else None
    auto = _open_at(schedule['intervals'], now) is not None if enabled else True
    manual = _effective_manual(org, now)

    operating = True if manual == 'open' else (False if manual == 'closed' else auto)

    out = {
        'state': 'closed',
        'reason': '',
        'holiday': None,
        'next_open_at': None,
        'manual': manual,
        'manual_until': None if manual == 'auto' else org.get('chat_manual_until'),
        'hours_enabled': enabled,
        'online': False,
    }

    if operating:
        out['state'] = 'open' if chat_model.has_available_agent(org['id']) else 'away'
        out['reason'] = 'agents' if out['state'] == 'away' else ''
        out['online'] = out['state'] == 'open'
        return out

    if manual == 'closed':
        out['reason'] = 'manual'
    else:
        out['holiday'] = schedule['today_holiday']
        out['reason'] = 'holiday' if schedule['today_holiday'] is not None else 'hours'

    if enabled:
        nxt = _next_start(schedule['intervals'], now)
        # 수동 종료 중이면 복귀 시각 전에 시작하는 구간은 열리지 않는다.
        if manual == 'closed' and not php_empty(org.get('chat_manual_until')):
            nxt = _next_start(schedule['intervals'], max(now, _utc_ts(org['chat_manual_until']) - 1))
        out['next_open_at'] = None if nxt is None else _gmdate(nxt)

    return out


def set_manual(org, state):
    """조직 운영 상태를 수동으로 바꾼다. 자동 판정이 같은 값이 되는 때 '자동' 으로 돌아가도록 복귀 시각을 함께 둔다."""
    if state not in ('auto', 'open', 'closed'):
        state = 'auto'

    now = int(time.time())
    enabled = php_int(org.get('chat_hours_enabled')) == 1
    until = None

    if state != 'auto':
        if not enabled:
            # 운영 시간을 쓰지 않으면 늘 운영 중이므로 '지금 운영' 은 자동과 같다. '지금 종료' 는 직접 풀 때까지 둔다.
            state = 'auto' if state == 'open' else 'closed'
        else:
            intervals = _schedule(org)['intervals']
            current = _open_at(intervals, now)
            if (state == 'open' and current is not None) or (state == 'closed' and current is None):
                state = 'auto'  # 이미 자동 판정과 같다.
            elif state == 'open':
                nxt = _next_start(intervals, now)
                until = None if nxt is None else _gmdate(nxt)
            else:
                until = _gmdate(current[1])

    db.update('organizations', {'chat_manual_state': state, 'chat_manual_until': until}, {'id': int(org['id'])})
    org['chat_manual_state'] = state
    org['chat_manual_until'] = until

    return availability(org)


def console_view(org, avail):
    """상담원 콘솔 상단의 운영 상태 표시용. 시각은 조직 시간대 문구로 바꿔 보낸다(CLAUDE.md 3.4)."""
    return {
        'state': avail['state'],
        'reason': avail['reason'],
        'holiday': avail['holiday'],
        'manual': avail['manual'],
        'manual_until': format_moment(avail['manual_until'], org),
        'next_open': format_moment(avail['next_open_at'], org),
        'hours_enabled': avail['hours_enabled'],
    }


def format_moment(utc, org):
    """UTC 시각을 조직 시간대로 바꿔 "오늘 13:00", "내일 09:00", "10월 12일(월) 09:00" 처럼. 문구는 지금 언어 사전."""
    if php_empty(utc):
        return ''

    tz = _org_timezone(org)
    local = datetime.fromisoformat(str(utc)).replace(tzinfo=timezone.utc).astimezone(tz)
    today = datetime.now(tz)
    hm = php_date('H:i', local)
    days = (local.date() - today.date()).days

    if days == 0:
        return lang_text('chat.today', {'time': hm})
    if days == 1:
        return lang_text('chat.tomorrow', {'time': hm})

    return lang_text('chat.date', {
        'm': php_date('n', local),
        'd': php_date('j', local),
        'w': lang_text('chat.wd.' + php_date('w', local)),
        'time': hm,
    })


# ------------------------------------------------------------------ 내부

def _effective_manual(org, now):
    """복귀 시각이 지난 수동 상태는 '자동' 으로 되돌린다(다음 판정부터 다시 계산하지 않도록 저장까지 한다)."""
    manual = org.get('chat_manual_state') if org.get('chat_manual_state') in ('open', 'closed') else 'auto'

    if manual != 'auto' and not php_empty(org.get('chat_manual_until')) and _utc_ts(org['chat_manual_until']) <= now:
        db.execute("UPDATE organizations SET chat_manual_state = 'auto', chat_manual_until = NULL "
                   'WHERE id = :id AND chat_manual_state = :manual', {'id': int(org['id']), 'manual': manual})
        org['chat_manual_state'] = 'auto'
        org['chat_manual_until'] = None
        return 'auto'

    return manual


def _schedule(org):
    """어제부터 LOOKAHEAD_DAYS 뒤까지의 운영 구간을 절대 시각(초)으로 펼치고, 맞닿은 구간은 합친다.

    자정에 나뉜 구간(22:00-24:00 + 다음 날 00:00-02:00)도 하나로 이어진다.
    """
    tz = _org_timezone(org)
    hours = get_hours(org['id'])
    holidays = list_holidays(org['id'])
    today = datetime.now(tz).date()
    raw = []
    today_holiday = None

    for offset in range(-1, LOOKAHEAD_DAYS + 1):
        day_date = today + timedelta(days=offset)
        day = _day_ranges(day_date, hours, holidays)
        if offset == 0:
            today_holiday = day['holiday']
        for start, end in day['ranges']:
            raw.append([_at_minute(day_date, start, tz), _at_minute(day_date, end, tz)])

    raw.sort(key=lambda r: r[0])
    merged = []
    for interval in raw:
        if merged and interval[0] <= merged[-1][1]:
            merged[-1][1] = max(merged[-1][1], interval[1])
        else:
            merged.append(interval)

    return {'intervals': merged, 'today_holiday': today_holiday}


def _day_ranges(day_date, hours, holidays):
    """그날의 운영 구간. 휴일이 요일 운영 시간보다 우선한다."""
    ymd = day_date.isoformat()
    md = ymd[5:]

    for holiday in holidays:
        if php_int(holiday.repeat_yearly) == 1:
            start, end = str(holiday.start_date)[5:], str(holiday.end_date)[5:]
            # 연말에서 연초로 넘어가는 기간(12-30 ~ 01-02)도 받는다.
            hit = (start <= md <= end) if start <= end else (md >= start or md <= end)
        else:
            hit = str(holiday.start_date) <= ymd <= str(holiday.end_date)

        if hit:
            ranges = ([[int(holiday.start_min), int(holiday.end_min)]]
                      if holiday.mode == 'short' and holiday.start_min is not None else [])
            return {'ranges': ranges, 'holiday': holiday.name}

    # PHP format('w'): 일요일 0 ~ 토요일 6
    return {'ranges': hours[(day_date.weekday() + 1) % 7], 'holiday': None}


def _at_minute(day_date, minutes, tz):
    """그날 벽시계 기준 분을 절대 시각으로. 서머타임이 있는 날도 벽시계 시각을 따른다."""
    if minutes >= 1440:
        nxt = day_date + timedelta(days=1)
        return int(datetime(nxt.year, nxt.month, nxt.day, 0, 0, tzinfo=tz).timestamp())
    return int(datetime(day_date.year, day_date.month, day_date.day, minutes // 60, minutes % 60, tzinfo=tz).timestamp())


def _open_at(intervals, ts):
    """지금이 들어 있는 구간, 없으면 None."""
    for interval in intervals:
        if interval[0] <= ts < interval[1]:
            return interval
    return None


def _next_start(intervals, ts):
    """ts 뒤에 시작하는 첫 구간의 시작 시각, 없으면 None."""
    for interval in intervals:
        if interval[0] > ts:
            return interval[0]
    return None


def _org_timezone(org):
    try:
        return ZoneInfo(org.get('timezone') or 'Asia/Seoul')
    except (KeyError, ValueError):
        return ZoneInfo('Asia/Seoul')


def _utc_ts(utc):
    return strtotime_utc(utc)


def _gmdate(ts):
    return php_date('Y-m-d H:i:s', datetime.fromtimestamp(ts, timezone.utc))
