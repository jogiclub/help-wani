<?php
/**
 * 파일 위치: application/models/Chat_hours_model.php
 * 역할: 상담 운영 시간·휴일 저장과 운영 상태 판정(docs/chat-operation-plan.md F1~F3)
 *
 * 운영 시간과 휴일은 "조직 시간대의 벽시계" 값(분, 날짜)으로 저장한다. UTC 로 바꾸지 않는다.
 * 판정할 때만 지금 시각을 조직 시간대로 바꿔 비교하고, 밖으로 내보내는 시각은 UTC 로 돌려준다(CLAUDE.md 3.4).
 * 운영 여부는 availability() 한 곳에서만 계산한다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Chat_hours_model extends CI_Model {

    /** 하루에 둘 수 있는 운영 구간 수 */
    const MAX_RANGES_PER_DAY = 3;

    /** 다음 운영 시각을 찾을 때 앞으로 살펴보는 날 수 */
    const LOOKAHEAD_DAYS = 14;

    /** 화면 표시 순서(월요일부터) */
    const WEEK_ORDER = array(1, 2, 3, 4, 5, 6, 0);

    // ------------------------------------------------------------------
    // 운영 시간
    // ------------------------------------------------------------------

    /**
     * @return array weekday(0~6) => [[start_min, end_min], ...] (시작 순)
     */
    public function get_hours($org_id)
    {
        $rows = $this->db->where('org_id', (int) $org_id)
                         ->order_by('weekday', 'ASC')
                         ->order_by('start_min', 'ASC')
                         ->get('chat_hours')
                         ->result();

        $out = array_fill(0, 7, array());

        foreach ($rows as $row)
        {
            $out[(int) $row->weekday][] = array((int) $row->start_min, (int) $row->end_min);
        }

        return $out;
    }

    /**
     * 화면에서 받은 운영 시간을 검사해 정리한다. 허용 목록 검사(CLAUDE.md 3.7).
     *
     * @param mixed $input {"1": [["09:00","18:00"]], ...} (JSON 문자열 또는 배열)
     * @return array|string 정리된 get_hours() 모양, 잘못되면 오류 문구
     */
    public function clean_hours($input)
    {
        if (is_string($input))
        {
            $input = json_decode($input, TRUE);
        }

        if ( ! is_array($input))
        {
            return '운영 시간 형식이 올바르지 않습니다.';
        }

        $out = array_fill(0, 7, array());

        foreach ($input as $weekday => $ranges)
        {
            if ( ! preg_match('/^[0-6]$/', (string) $weekday) || ! is_array($ranges))
            {
                return '운영 시간 형식이 올바르지 않습니다.';
            }

            if (count($ranges) > self::MAX_RANGES_PER_DAY)
            {
                return '하루에 운영 구간은 '.self::MAX_RANGES_PER_DAY.'개까지 둘 수 있습니다.';
            }

            $day = array();

            foreach ($ranges as $range)
            {
                $start = is_array($range) && isset($range[0]) ? $this->parse_time($range[0]) : NULL;
                $end   = is_array($range) && isset($range[1]) ? $this->parse_time($range[1]) : NULL;

                if ($start === NULL || $end === NULL || $start >= $end)
                {
                    return '운영 시간은 시작이 끝보다 빨라야 합니다. 자정을 넘기면 다음 날 00:00 구간으로 나눠 넣어 주세요.';
                }

                $day[] = array($start, $end);
            }

            usort($day, function ($a, $b) { return $a[0] - $b[0]; });

            for ($i = 1; $i < count($day); $i++)
            {
                if ($day[$i][0] < $day[$i - 1][1])
                {
                    return '같은 날의 운영 구간이 서로 겹칩니다.';
                }
            }

            $out[(int) $weekday] = $day;
        }

        return $out;
    }

    public function save_hours($org_id, $hours)
    {
        $this->db->trans_start();
        $this->db->where('org_id', (int) $org_id)->delete('chat_hours');

        foreach ($hours as $weekday => $ranges)
        {
            foreach ($ranges as $range)
            {
                $this->db->insert('chat_hours', array(
                    'org_id'    => (int) $org_id,
                    'weekday'   => (int) $weekday,
                    'start_min' => (int) $range[0],
                    'end_min'   => (int) $range[1],
                ));
            }
        }

        $this->db->trans_complete();

        return $this->db->trans_status();
    }

    /**
     * 'HH:MM' -> 자정부터의 분. 끝 시각으로 24:00 까지 받는다.
     */
    public function parse_time($text)
    {
        if ( ! preg_match('/^(\d{1,2}):(\d{2})$/', trim((string) $text), $m))
        {
            return NULL;
        }

        $minutes = (int) $m[1] * 60 + (int) $m[2];

        return ((int) $m[2] < 60 && $minutes <= 1440) ? $minutes : NULL;
    }

    public function format_time($minutes)
    {
        return sprintf('%02d:%02d', intdiv((int) $minutes, 60), (int) $minutes % 60);
    }

    /**
     * 위젯 머리에 보여 줄 한 줄 요약. 같은 시간인 연속 요일을 묶는다. 예) "월-금 09:00-18:00 · 토 10:00-14:00"
     * 운영 시간을 쓰지 않으면 빈 문자열.
     */
    public function summary($org)
    {
        if ((int) $org->chat_hours_enabled !== 1)
        {
            return '';
        }

        $hours  = $this->get_hours($org->id);
        $groups = array();

        foreach (self::WEEK_ORDER as $weekday)
        {
            $key  = json_encode($hours[$weekday]);
            $last = count($groups) - 1;

            if ($last >= 0 && $groups[$last]['key'] === $key)
            {
                $groups[$last]['days'][] = $weekday;
            }
            else
            {
                $groups[] = array('key' => $key, 'days' => array($weekday), 'ranges' => $hours[$weekday]);
            }
        }

        $parts = array();

        foreach ($groups as $group)
        {
            if (empty($group['ranges']))
            {
                continue;
            }

            $first = lang_text('chat.wd.'.$group['days'][0]);
            $label = $first;

            if (count($group['days']) > 1)
            {
                $label .= (count($group['days']) > 2 ? '-' : '·').lang_text('chat.wd.'.end($group['days']));
            }

            $times = array();

            foreach ($group['ranges'] as $range)
            {
                $times[] = $this->format_time($range[0]).'-'.$this->format_time($range[1]);
            }

            $parts[] = $label.' '.implode(', ', $times);
        }

        return implode(' · ', $parts);
    }

    // ------------------------------------------------------------------
    // 휴일
    // ------------------------------------------------------------------

    public function list_holidays($org_id)
    {
        return $this->db->where('org_id', (int) $org_id)
                        ->order_by('repeat_yearly', 'DESC')
                        ->order_by('start_date', 'ASC')
                        ->get('chat_holidays')
                        ->result();
    }

    /**
     * 화면에 보낼 휴일 모양
     */
    public function present_holiday($row)
    {
        return array(
            'id'            => (int) $row->id,
            'start_date'    => $row->start_date,
            'end_date'      => $row->end_date,
            'name'          => $row->name,
            'mode'          => $row->mode,
            'start'         => $row->start_min === NULL ? NULL : $this->format_time($row->start_min),
            'end'           => $row->end_min === NULL ? NULL : $this->format_time($row->end_min),
            'repeat_yearly' => (int) $row->repeat_yearly === 1,
        );
    }

    /**
     * 휴일 입력 검사. 허용 목록 검사(CLAUDE.md 3.7).
     *
     * @return array|string 저장할 값, 잘못되면 오류 문구
     */
    public function clean_holiday($input)
    {
        $start = (string) (isset($input['start_date']) ? $input['start_date'] : '');
        $end   = (string) (isset($input['end_date']) && $input['end_date'] !== '' ? $input['end_date'] : $start);
        $name  = trim((string) (isset($input['name']) ? $input['name'] : ''));
        $mode  = isset($input['mode']) && $input['mode'] === 'short' ? 'short' : 'closed';

        if ( ! $this->valid_date($start) || ! $this->valid_date($end) || $end < $start)
        {
            return '휴일 날짜를 확인해 주세요.';
        }

        if ((strtotime($end) - strtotime($start)) / 86400 > 60)
        {
            return '휴일 기간은 60일까지 넣을 수 있습니다.';
        }

        if ($name === '')
        {
            return '휴일 이름을 입력해 주세요.';
        }

        $row = array(
            'start_date'    => $start,
            'end_date'      => $end,
            'name'          => mb_substr($name, 0, 60),
            'mode'          => $mode,
            'start_min'     => NULL,
            'end_min'       => NULL,
            'repeat_yearly' => empty($input['repeat_yearly']) ? 0 : 1,
        );

        if ($mode === 'short')
        {
            $row['start_min'] = $this->parse_time(isset($input['start']) ? $input['start'] : '');
            $row['end_min']   = $this->parse_time(isset($input['end']) ? $input['end'] : '');

            if ($row['start_min'] === NULL || $row['end_min'] === NULL || $row['start_min'] >= $row['end_min'])
            {
                return '단축 운영 시간은 시작이 끝보다 빨라야 합니다.';
            }
        }

        return $row;
    }

    public function add_holiday($org_id, $row)
    {
        $row['org_id'] = (int) $org_id;
        $this->db->insert('chat_holidays', $row);

        return (int) $this->db->insert_id();
    }

    public function delete_holiday($org_id, $id)
    {
        $this->db->where('org_id', (int) $org_id)->where('id', (int) $id)->delete('chat_holidays');

        return $this->db->affected_rows();
    }

    /**
     * 조직 국가의 공휴일 후보(config/holidays.php). 오늘 이후만, 이미 넣은 날짜는 표시한다.
     */
    public function holiday_candidates($org)
    {
        $this->config->load('holidays', TRUE);
        $all  = $this->config->item('holidays', 'holidays');
        $list = isset($all[$org->country]) ? $all[$org->country] : array();

        $today = $this->local_now($org)->format('Y-m-d');
        $added = array();

        foreach ($this->list_holidays($org->id) as $row)
        {
            if ((int) $row->repeat_yearly === 0)
            {
                $added[$row->start_date] = TRUE;
            }
        }

        $out = array();

        foreach ($list as $date => $name)
        {
            if ($date >= $today)
            {
                $out[] = array('date' => $date, 'name' => $name, 'added' => isset($added[$date]));
            }
        }

        return $out;
    }

    protected function valid_date($text)
    {
        if ( ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m))
        {
            return FALSE;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    // ------------------------------------------------------------------
    // 판정
    // ------------------------------------------------------------------

    /**
     * 지금 고객이 볼 운영 상태. 위젯, 오프라인 메일, 자동 응답, 콘솔 배지가 모두 이것을 쓴다.
     *
     * @return array {
     *   state: 'open' | 'away' | 'closed',
     *   reason: '' | 'hours' | 'holiday' | 'manual' | 'agents',
     *   holiday: 오늘 휴일 이름 또는 NULL,
     *   next_open_at: 다음 운영 시작(UTC 'Y-m-d H:i:s') 또는 NULL,
     *   manual: 'auto' | 'open' | 'closed', manual_until: UTC 또는 NULL,
     *   hours_enabled: bool, online: state === 'open' (예전 위젯 호환)
     * }
     */
    public function availability($org)
    {
        $now      = time();
        $enabled  = (int) $org->chat_hours_enabled === 1;
        $schedule = $enabled ? $this->schedule($org) : NULL;
        $auto     = $enabled ? $this->open_at($schedule['intervals'], $now) !== NULL : TRUE;
        $manual   = $this->effective_manual($org, $now);

        $operating = $manual === 'open' ? TRUE : ($manual === 'closed' ? FALSE : $auto);

        $out = array(
            'state'         => 'closed',
            'reason'        => '',
            'holiday'       => NULL,
            'next_open_at'  => NULL,
            'manual'        => $manual,
            'manual_until'  => $manual === 'auto' ? NULL : $org->chat_manual_until,
            'hours_enabled' => $enabled,
            'online'        => FALSE,
        );

        if ($operating)
        {
            $this->load->model('chat_model');
            $out['state']  = $this->chat_model->has_available_agent($org->id) ? 'open' : 'away';
            $out['reason'] = $out['state'] === 'away' ? 'agents' : '';
            $out['online'] = $out['state'] === 'open';

            return $out;
        }

        if ($manual === 'closed')
        {
            $out['reason'] = 'manual';
        }
        else
        {
            $out['holiday'] = $schedule['today_holiday'];
            $out['reason']  = $schedule['today_holiday'] !== NULL ? 'holiday' : 'hours';
        }

        if ($enabled)
        {
            $next = $this->next_start($schedule['intervals'], $now);

            // 수동 종료 중이면 복귀 시각 전에 시작하는 구간은 열리지 않는다.
            if ($manual === 'closed' && ! empty($org->chat_manual_until))
            {
                $next = $this->next_start($schedule['intervals'], max($now, $this->utc_ts($org->chat_manual_until) - 1));
            }

            $out['next_open_at'] = $next === NULL ? NULL : gmdate('Y-m-d H:i:s', $next);
        }

        return $out;
    }

    /**
     * 조직 운영 상태를 수동으로 바꾼다. 자동 판정이 같은 값이 되는 때 '자동' 으로 돌아가도록 복귀 시각을 함께 둔다.
     *
     * @return array availability() 결과
     */
    public function set_manual($org, $state)
    {
        if ( ! in_array($state, array('auto', 'open', 'closed'), TRUE))
        {
            $state = 'auto';
        }

        $now     = time();
        $enabled = (int) $org->chat_hours_enabled === 1;
        $until   = NULL;

        if ($state !== 'auto')
        {
            if ( ! $enabled)
            {
                // 운영 시간을 쓰지 않으면 늘 운영 중이므로 '지금 운영' 은 자동과 같다. '지금 종료' 는 직접 풀 때까지 둔다.
                $state = $state === 'open' ? 'auto' : 'closed';
            }
            else
            {
                $intervals = $this->schedule($org)['intervals'];
                $current   = $this->open_at($intervals, $now);

                if ($state === 'open' && $current !== NULL || $state === 'closed' && $current === NULL)
                {
                    // 이미 자동 판정과 같다.
                    $state = 'auto';
                }
                elseif ($state === 'open')
                {
                    $next  = $this->next_start($intervals, $now);
                    $until = $next === NULL ? NULL : gmdate('Y-m-d H:i:s', $next);
                }
                else
                {
                    $until = gmdate('Y-m-d H:i:s', $current[1]);
                }
            }
        }

        $this->db->where('id', (int) $org->id)->update('organizations', array(
            'chat_manual_state' => $state,
            'chat_manual_until' => $until,
        ));

        $org->chat_manual_state = $state;
        $org->chat_manual_until = $until;

        return $this->availability($org);
    }

    /**
     * 상담원 콘솔 상단의 운영 상태 표시용. 시각은 조직 시간대 문구로 바꿔 보낸다(CLAUDE.md 3.4).
     */
    public function console_view($org, $availability)
    {
        return array(
            'state'         => $availability['state'],
            'reason'        => $availability['reason'],
            'holiday'       => $availability['holiday'],
            'manual'        => $availability['manual'],
            'manual_until'  => $this->format_moment($availability['manual_until'], $org),
            'next_open'     => $this->format_moment($availability['next_open_at'], $org),
            'hours_enabled' => $availability['hours_enabled'],
        );
    }

    /**
     * UTC 시각을 조직 시간대로 바꿔 "오늘 13:00", "내일 09:00", "10월 12일(월) 09:00" 처럼 만든다.
     * 문구는 지금 언어 사전을 쓴다(위젯은 조직 언어, 콘솔은 상담원 언어).
     */
    public function format_moment($utc, $org)
    {
        if (empty($utc))
        {
            return '';
        }

        $tz    = $this->org_timezone($org);
        $local = (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone($tz);
        $today = new DateTime('now', $tz);
        $time  = $local->format('H:i');
        $days  = (int) (new DateTime($today->format('Y-m-d'), $tz))->diff(new DateTime($local->format('Y-m-d'), $tz))->format('%r%a');

        if ($days === 0)
        {
            return lang_text('chat.today', array('time' => $time));
        }

        if ($days === 1)
        {
            return lang_text('chat.tomorrow', array('time' => $time));
        }

        return lang_text('chat.date', array(
            'm'    => $local->format('n'),
            'd'    => $local->format('j'),
            'w'    => lang_text('chat.wd.'.$local->format('w')),
            'time' => $time,
        ));
    }

    // ------------------------------------------------------------------
    // 내부
    // ------------------------------------------------------------------

    /**
     * 복귀 시각이 지난 수동 상태는 '자동' 으로 되돌린다(다음 판정부터 다시 계산하지 않도록 저장까지 한다).
     */
    protected function effective_manual($org, $now)
    {
        $manual = in_array($org->chat_manual_state, array('open', 'closed'), TRUE) ? $org->chat_manual_state : 'auto';

        if ($manual !== 'auto' && ! empty($org->chat_manual_until) && $this->utc_ts($org->chat_manual_until) <= $now)
        {
            $this->db->where('id', (int) $org->id)
                     ->where('chat_manual_state', $manual)
                     ->update('organizations', array('chat_manual_state' => 'auto', 'chat_manual_until' => NULL));

            $org->chat_manual_state = 'auto';
            $org->chat_manual_until = NULL;

            return 'auto';
        }

        return $manual;
    }

    /**
     * 어제부터 LOOKAHEAD_DAYS 뒤까지의 운영 구간을 절대 시각(초)으로 펼치고, 맞닿은 구간은 합친다.
     * 자정에 나뉜 구간(22:00-24:00 + 다음 날 00:00-02:00)도 하나로 이어진다.
     *
     * @return array {intervals: [[start_ts, end_ts], ...], today_holiday: 이름 또는 NULL}
     */
    protected function schedule($org)
    {
        $tz       = $this->org_timezone($org);
        $hours    = $this->get_hours($org->id);
        $holidays = $this->list_holidays($org->id);
        $today    = new DateTimeImmutable('today', $tz);
        $raw      = array();
        $today_holiday = NULL;

        for ($offset = -1; $offset <= self::LOOKAHEAD_DAYS; $offset++)
        {
            $date = $today->modify(($offset >= 0 ? '+' : '').$offset.' day');
            $day  = $this->day_ranges($date, $hours, $holidays);

            if ($offset === 0)
            {
                $today_holiday = $day['holiday'];
            }

            foreach ($day['ranges'] as $range)
            {
                $raw[] = array($this->at_minute($date, $range[0]), $this->at_minute($date, $range[1]));
            }
        }

        usort($raw, function ($a, $b) { return $a[0] - $b[0]; });

        $merged = array();

        foreach ($raw as $interval)
        {
            $last = count($merged) - 1;

            if ($last >= 0 && $interval[0] <= $merged[$last][1])
            {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);
            }
            else
            {
                $merged[] = $interval;
            }
        }

        return array('intervals' => $merged, 'today_holiday' => $today_holiday);
    }

    /**
     * 그날의 운영 구간. 휴일이 요일 운영 시간보다 우선한다.
     */
    protected function day_ranges(DateTimeImmutable $date, $hours, $holidays)
    {
        $ymd = $date->format('Y-m-d');
        $md  = $date->format('m-d');

        foreach ($holidays as $holiday)
        {
            if ((int) $holiday->repeat_yearly === 1)
            {
                $start = substr($holiday->start_date, 5);
                $end   = substr($holiday->end_date, 5);
                // 연말에서 연초로 넘어가는 기간(12-30 ~ 01-02)도 받는다.
                $hit = $start <= $end ? ($md >= $start && $md <= $end) : ($md >= $start || $md <= $end);
            }
            else
            {
                $hit = $ymd >= $holiday->start_date && $ymd <= $holiday->end_date;
            }

            if ($hit)
            {
                $ranges = $holiday->mode === 'short' && $holiday->start_min !== NULL
                    ? array(array((int) $holiday->start_min, (int) $holiday->end_min))
                    : array();

                return array('ranges' => $ranges, 'holiday' => $holiday->name);
            }
        }

        return array('ranges' => $hours[(int) $date->format('w')], 'holiday' => NULL);
    }

    /**
     * 그날 벽시계 기준 분을 절대 시각으로. 서머타임이 있는 날도 벽시계 시각을 따른다.
     */
    protected function at_minute(DateTimeImmutable $date, $minutes)
    {
        if ($minutes >= 1440)
        {
            return $date->modify('+1 day')->setTime(0, 0)->getTimestamp();
        }

        return $date->setTime(intdiv($minutes, 60), $minutes % 60)->getTimestamp();
    }

    /**
     * 지금이 들어 있는 구간, 없으면 NULL
     */
    protected function open_at($intervals, $ts)
    {
        foreach ($intervals as $interval)
        {
            if ($interval[0] <= $ts && $ts < $interval[1])
            {
                return $interval;
            }
        }

        return NULL;
    }

    /**
     * $ts 뒤에 시작하는 첫 구간의 시작 시각, 없으면 NULL
     */
    protected function next_start($intervals, $ts)
    {
        foreach ($intervals as $interval)
        {
            if ($interval[0] > $ts)
            {
                return $interval[0];
            }
        }

        return NULL;
    }

    protected function local_now($org)
    {
        return new DateTime('now', $this->org_timezone($org));
    }

    protected function org_timezone($org)
    {
        try
        {
            return new DateTimeZone($org->timezone ?: 'Asia/Seoul');
        }
        catch (Exception $e)
        {
            return new DateTimeZone('Asia/Seoul');
        }
    }

    protected function utc_ts($utc)
    {
        return (new DateTime($utc, new DateTimeZone('UTC')))->getTimestamp();
    }
}
