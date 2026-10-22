<?php
/**
 * 파일 위치: application/models/Chat_model.php
 * 역할: 상담 위젯 대화와 메시지 저장/조회, 상담원 응대 가능 여부 판별, 부재중 자동 응답 중복 방지,
 *       담당 가져오기/비우기, 첫 응답 시간 기록과 답변 속도 계산(docs/chat-operation-plan.md F6, F7),
 *       방문자 시청 시각·연락처 카드·답장 알림 메일 차례 판단(docs/widget-ops-plan.md 3장)
 *
 * 고객에게 보일 운영 상태(운영 시간, 휴일, 수동 전환)는 Chat_hours_model::availability() 가 정한다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Chat_model extends CI_Model {

    /** 이 시간(초) 안에 콘솔 활동이 있으면 접속 중으로 본다 */
    const ONLINE_WINDOW_SECONDS = 60;

    /** 방문자 한 명이 1분에 보낼 수 있는 메시지 수 */
    const RATE_LIMIT_PER_MINUTE = 20;

    /** 답변 속도(실제 기록): 최근 며칠, 표본 최소 수, 다시 계산하는 간격(초) */
    const REPLY_WINDOW_DAYS = 7;
    const REPLY_MIN_SAMPLES = 10;
    const REPLY_CACHE_SECONDS = 3600;

    /** 위젯에 보여 줄 답변 속도 구간(분). 문구 키는 widget.reply.{구간} */
    const REPLY_BUCKETS = array(5, 10, 30, 60, 180, 1440);

    /**
     * 방문자가 대화창을 이만큼 보지 않았으면 자리를 비운 것으로 본다(팔로업 메일, docs/widget-ops-plan.md 3-4).
     * 위젯은 창을 펼치고 탭이 보일 때만 폴링하며, 실시간 연결 중에는 30초 간격이다. 그보다 넉넉히 잡는다.
     */
    const VISITOR_AWAY_SECONDS = 75;

    // ------------------------------------------------------------------
    // 대화
    // ------------------------------------------------------------------

    public function get_conversation($id)
    {
        return $this->db->get_where('conversations', array('id' => (int) $id))->row();
    }

    /**
     * 방문자의 열린 대화를 찾고, 없으면 만든다.
     */
    public function ensure_conversation($org_id, $visitor_id)
    {
        $row = $this->db->get_where('conversations', array(
            'org_id'     => (int) $org_id,
            'visitor_id' => (int) $visitor_id,
            'status'     => 'open',
        ))->row();

        if ( ! empty($row))
        {
            return $row;
        }

        $this->db->insert('conversations', array(
            'org_id'     => (int) $org_id,
            'visitor_id' => (int) $visitor_id,
            'status'     => 'open',
        ));

        return $this->get_conversation($this->db->insert_id());
    }

    /**
     * 상담원 콘솔의 대화 목록. 방문자 정보와 마지막 메시지를 붙인다.
     */
    public function list_for_org($org_id, $filters = array(), $limit = 100)
    {
        $this->db->select('c.*, v.name AS visitor_name, v.email AS visitor_email, v.phone AS visitor_phone, v.page_url, '
                        . 'a.name AS agent_name, '
                        . '(SELECT body FROM messages WHERE conversation_id = c.id ORDER BY id DESC LIMIT 1) AS last_body')
                 ->from('conversations c')
                 ->join('visitors v', 'v.id = c.visitor_id')
                 ->join('agents a', 'a.id = c.assigned_agent_id', 'left')
                 ->where('c.org_id', (int) $org_id);

        if ( ! empty($filters['status']) && in_array($filters['status'], array('open', 'closed'), TRUE))
        {
            $this->db->where('c.status', $filters['status']);
        }

        if ( ! empty($filters['keyword']))
        {
            $kw = $filters['keyword'];
            $this->db->group_start()
                     ->like('v.name', $kw)
                     ->or_like('v.email', $kw)
                     ->or_like('v.phone', $kw)
                     ->group_end();
        }

        if ( ! empty($filters['date_from']))
        {
            $this->db->where('c.created_at >=', $filters['date_from'].' 00:00:00');
        }

        if ( ! empty($filters['date_to']))
        {
            $this->db->where('c.created_at <=', $filters['date_to'].' 23:59:59');
        }

        return $this->db->order_by('c.last_message_at IS NULL', 'ASC', FALSE)
                        ->order_by('c.last_message_at', 'DESC')
                        ->order_by('c.id', 'DESC')
                        ->limit((int) $limit)
                        ->get()
                        ->result();
    }

    /**
     * 대화 상태 변경. 허용 목록 밖의 값은 무시한다.
     */
    public function set_status($conversation_id, $status)
    {
        if ( ! in_array($status, array('open', 'closed'), TRUE))
        {
            return 0;
        }

        $this->db->where('id', (int) $conversation_id)->update('conversations', array(
            'status'    => $status,
            'closed_at' => $status === 'closed' ? date('Y-m-d H:i:s') : NULL,
        ));

        return $this->db->affected_rows();
    }

    /**
     * 담당이 없을 때만 담당을 정한다(먼저 답한 상담원).
     */
    public function assign($conversation_id, $agent_id)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->where('assigned_agent_id IS NULL', NULL, FALSE)
                 ->update('conversations', array('assigned_agent_id' => (int) $agent_id));

        return $this->db->affected_rows();
    }

    /**
     * 담당을 바꾼다(가져오기). $agent_id 가 NULL 이면 담당을 비워 대기 중으로 돌린다.
     */
    public function set_assignee($conversation_id, $agent_id)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->update('conversations', array('assigned_agent_id' => $agent_id === NULL ? NULL : (int) $agent_id));

        return $this->db->affected_rows();
    }

    public function link_session($conversation_id, $session_id)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->update('conversations', array('session_id' => (int) $session_id));
    }

    public function mark_read($conversation_id)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->update('conversations', array('unread_for_agent' => 0));

        $this->db->where('conversation_id', (int) $conversation_id)
                 ->where('sender_type', 'visitor')
                 ->where('read_at IS NULL', NULL, FALSE)
                 ->update('messages', array('read_at' => date('Y-m-d H:i:s')));
    }

    // ------------------------------------------------------------------
    // 메시지
    // ------------------------------------------------------------------

    /**
     * 메시지를 넣고 대화의 마지막 시각을 갱신한다.
     *
     * @param array|null $meta 카드 정보. 가이드 카드는 {type:'guide', article_id, title, subtitle, url}
     */
    public function add_message($conversation_id, $sender_type, $body, $agent_id = NULL, $meta = NULL)
    {
        if ( ! in_array($sender_type, array('visitor', 'agent', 'system'), TRUE))
        {
            return 0;
        }

        $this->db->insert('messages', array(
            'conversation_id' => (int) $conversation_id,
            'sender_type'     => $sender_type,
            'agent_id'        => $agent_id === NULL ? NULL : (int) $agent_id,
            'body'            => mb_substr((string) $body, 0, 4000),
            'meta'            => $meta === NULL ? NULL : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        $message_id = (int) $this->db->insert_id();

        $this->db->set('last_message_at', date('Y-m-d H:i:s'));

        // 답변 속도 계산용: 방문자 첫 메시지 시각과 그 뒤 상담원 첫 답변 시각(F6)
        if ($sender_type === 'visitor')
        {
            $this->db->set('unread_for_agent', 'unread_for_agent + 1', FALSE);
            $this->db->set('first_visitor_at', 'COALESCE(first_visitor_at, NOW())', FALSE);
        }
        elseif ($sender_type === 'agent')
        {
            $this->db->set('first_reply_at', 'IF(first_reply_at IS NULL AND first_visitor_at IS NOT NULL, NOW(), first_reply_at)', FALSE);
        }

        $this->db->where('id', (int) $conversation_id)->update('conversations');

        return $message_id;
    }

    /**
     * 커서 이후의 메시지. 같은 초에 들어온 메시지를 빠뜨리지 않도록 id 기준으로 읽는다.
     */
    public function list_messages($conversation_id, $after_id = 0, $limit = 100)
    {
        return $this->db->where('conversation_id', (int) $conversation_id)
                        ->where('id >', (int) $after_id)
                        ->order_by('id', 'ASC')
                        ->limit((int) $limit)
                        ->get('messages')
                        ->result();
    }

    /**
     * 화면으로 보낼 메시지 모양. 시각은 UTC 그대로 보내고 화면이 변환한다(CLAUDE.md 3.4).
     * 콘솔, 위젯, 실시간 발행이 모두 이 모양을 쓴다.
     */
    public function present($message)
    {
        $meta = empty($message->meta) ? NULL : json_decode($message->meta, TRUE);

        return array(
            'id'         => (int) $message->id,
            'sender'     => $message->sender_type,
            'body'       => $message->body,
            'meta'       => is_array($meta) ? $meta : NULL,
            'created_at' => $message->created_at,
        );
    }

    /**
     * 이 대화에서 이미 보낸 가이드 카드. 같은 글을 거듭 추천하지 않기 위해 쓴다.
     *
     * @return array [{article_id, sender}]
     */
    public function guide_cards($conversation_id)
    {
        $rows = $this->db->query("
            SELECT sender_type, CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.article_id')) AS UNSIGNED) AS article_id
            FROM messages
            WHERE conversation_id = ?
              AND meta IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.type')) = 'guide'
        ", array((int) $conversation_id))->result();

        $out = array();

        foreach ($rows as $row)
        {
            $out[] = array('article_id' => (int) $row->article_id, 'sender' => $row->sender_type);
        }

        return $out;
    }

    /**
     * 최근 방문자 메시지 본문. 상담원 콘솔 추천에 쓴다(마지막 한 줄만 보면 "네 그거요" 같은 말에 결과가 없다).
     */
    public function recent_visitor_text($conversation_id, $count = 3)
    {
        $rows = $this->db->select('body')
                         ->where('conversation_id', (int) $conversation_id)
                         ->where('sender_type', 'visitor')
                         ->order_by('id', 'DESC')
                         ->limit((int) $count)
                         ->get('messages')
                         ->result();

        return implode("\n", array_reverse(array_map(function ($row) { return $row->body; }, $rows)));
    }

    /**
     * 도배 차단. 최근 1분 동안 이 대화에 방문자가 보낸 메시지 수를 센다.
     */
    public function is_flooding($conversation_id)
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM messages
            WHERE conversation_id = ?
              AND sender_type = 'visitor'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ", array((int) $conversation_id))->row();

        return (int) $row->cnt >= self::RATE_LIMIT_PER_MINUTE;
    }

    // ------------------------------------------------------------------
    // 상담원 응대 여부
    // ------------------------------------------------------------------

    /**
     * 콘솔이 살아 있음을 알린다. 운영 상태(응대 가능 상담원) 판단에 쓰인다.
     */
    public function touch_agent($agent_id)
    {
        $this->db->where('id', (int) $agent_id)
                 ->update('agents', array('last_seen_at' => date('Y-m-d H:i:s')));
    }

    /**
     * 조직에 응대 가능한 상담원이 한 명이라도 있는지 확인한다.
     * 콘솔이 열려 있어도(최근 접속) 본인이 '자리 비움' 이나 '응대 종료' 로 두었으면 세지 않는다.
     */
    public function has_available_agent($org_id)
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM agents
            WHERE org_id = ?
              AND is_active = 1
              AND chat_status = 'available'
              AND last_seen_at IS NOT NULL
              AND last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ", array((int) $org_id, self::ONLINE_WINDOW_SECONDS))->row();

        return (int) $row->cnt > 0;
    }

    /**
     * 상담원 개인 응대 상태. 허용 목록 밖의 값은 무시한다.
     */
    public function set_agent_status($agent_id, $status)
    {
        if ( ! in_array($status, array('available', 'away', 'off'), TRUE))
        {
            return 0;
        }

        $this->db->where('id', (int) $agent_id)->update('agents', array('chat_status' => $status));

        return $this->db->affected_rows();
    }

    /**
     * 새 문의 브라우저 알림 수신 여부(상담원 개인 설정)
     */
    public function set_agent_notify($agent_id, $enabled)
    {
        $this->db->where('id', (int) $agent_id)->update('agents', array('chat_notify' => $enabled ? 1 : 0));
    }

    /**
     * 부재중 자동 응답을 보내도 되면 시각과 종류를 찍고 TRUE 를 돌려준다.
     * 방문자가 연달아 써도 한 번만 보내고, 다음 경우에만 다시 보낸다.
     *   - 처음이거나 종류가 바뀜(자리 비움 -> 운영 시간 외 등)
     *   - 그 뒤에 상담원이 답했음(새 문의로 본다)
     *   - 12시간이 지남(어제 남긴 대화에 오늘 밤 다시 쓴 경우)
     */
    public function claim_auto_reply($conversation_id, $kind)
    {
        $this->db->query("
            UPDATE conversations c
            SET c.auto_reply_at = NOW(), c.auto_reply_kind = ?
            WHERE c.id = ?
              AND (c.auto_reply_at IS NULL
                   OR c.auto_reply_kind <> ?
                   OR c.auto_reply_at < DATE_SUB(NOW(), INTERVAL 12 HOUR)
                   OR EXISTS (SELECT 1 FROM messages m
                              WHERE m.conversation_id = c.id
                                AND m.sender_type = 'agent'
                                AND m.created_at > c.auto_reply_at))
        ", array((string) $kind, (int) $conversation_id, (string) $kind));

        return $this->db->affected_rows() > 0;
    }

    /**
     * 첫 문의가 운영 중에 들어왔는지 한 번만 적는다. 답변 속도는 운영 중에 들어온 문의만으로 계산한다
     * (밤사이 들어온 문의를 넣으면 숫자가 부풀어 고객에게 잘못된 약속이 된다).
     */
    public function mark_received($conversation_id, $in_hours)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->where('received_in_hours IS NULL', NULL, FALSE)
                 ->update('conversations', array('received_in_hours' => $in_hours ? 1 : 0));
    }

    // ------------------------------------------------------------------
    // 답변 속도
    // ------------------------------------------------------------------

    /**
     * 위젯에 보여 줄 답변 속도(분 구간). 보여 주지 않으면 NULL.
     * 실제 기록은 한 시간마다 다시 계산해 organizations 에 담아 둔다(요청마다 계산하지 않는다).
     */
    public function reply_minutes($org)
    {
        if ($org->chat_reply_mode === 'manual')
        {
            return $org->chat_reply_minutes ? $this->reply_bucket((int) $org->chat_reply_minutes) : NULL;
        }

        if ($org->chat_reply_mode !== 'auto')
        {
            return NULL;
        }

        $stale = empty($org->chat_reply_calc_at)
            || strtotime($org->chat_reply_calc_at.' UTC') < time() - self::REPLY_CACHE_SECONDS;

        if ($stale)
        {
            $median = $this->median_reply_seconds($org->id, self::REPLY_WINDOW_DAYS, self::REPLY_MIN_SAMPLES);
            $org->chat_reply_auto_min = $median === NULL ? NULL : max(1, (int) ceil($median / 60));
            $org->chat_reply_calc_at = gmdate('Y-m-d H:i:s');

            $this->db->where('id', (int) $org->id)->update('organizations', array(
                'chat_reply_auto_min' => $org->chat_reply_auto_min,
                'chat_reply_calc_at'  => $org->chat_reply_calc_at,
            ));
        }

        return $org->chat_reply_auto_min ? $this->reply_bucket((int) $org->chat_reply_auto_min) : NULL;
    }

    /**
     * 3분 17초 같은 숫자는 약속처럼 보이므로 구간으로 올려 보여 준다.
     */
    public function reply_bucket($minutes)
    {
        foreach (self::REPLY_BUCKETS as $bucket)
        {
            if ($minutes <= $bucket)
            {
                return $bucket;
            }
        }

        return self::REPLY_BUCKETS[count(self::REPLY_BUCKETS) - 1];
    }

    /**
     * 운영 중에 들어온 문의의 첫 응답 시간 중앙값(초). 표본이 모자라면 NULL.
     */
    public function median_reply_seconds($org_id, $days, $min_samples = 1)
    {
        $rows = $this->db->query("
            SELECT TIMESTAMPDIFF(SECOND, first_visitor_at, first_reply_at) AS sec
            FROM conversations
            WHERE org_id = ?
              AND first_visitor_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND first_reply_at IS NOT NULL
              AND received_in_hours = 1
            ORDER BY sec
            LIMIT 5000
        ", array((int) $org_id, (int) $days))->result();

        $count = count($rows);

        if ($count < max(1, (int) $min_samples))
        {
            return NULL;
        }

        $middle = intdiv($count, 2);

        return $count % 2 ? (int) $rows[$middle]->sec : (int) (($rows[$middle - 1]->sec + $rows[$middle]->sec) / 2);
    }

    /**
     * 조직 관리 통계(/admin)의 상담 대화 숫자. 방문자가 글을 남긴 대화만 센다.
     */
    public function reply_stats($org_id, $days = 30)
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS total,
                   SUM(first_reply_at IS NOT NULL) AS answered,
                   SUM(received_in_hours = 0) AS offhours
            FROM conversations
            WHERE org_id = ? AND first_visitor_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ", array((int) $org_id, (int) $days))->row();

        return array(
            'total'          => (int) $row->total,
            'answered'       => (int) $row->answered,
            'offhours'       => (int) $row->offhours,
            'median_seconds' => $this->median_reply_seconds($org_id, $days),
        );
    }

    /**
     * 방문자가 이 대화에 쓴 메시지가 있는지. 운영 시간 외 '안내만' / '숨기기' 에서도
     * 이미 대화 중인 방문자는 계속 쓸 수 있게 하는 데 쓴다.
     */
    public function has_visitor_message($conversation_id)
    {
        return $this->db->where('conversation_id', (int) $conversation_id)
                        ->where('sender_type', 'visitor')
                        ->limit(1)
                        ->count_all_results('messages') > 0;
    }

    /**
     * 아직 오프라인 알림을 보내지 않은 대화면 발송 시각을 찍고 TRUE 를 돌려준다.
     * 같은 대화에서 두 번 보내지 않기 위한 것이다.
     */
    public function claim_offline_notice($conversation_id)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->where('offline_notified_at IS NULL', NULL, FALSE)
                 ->update('conversations', array('offline_notified_at' => date('Y-m-d H:i:s')));

        return $this->db->affected_rows() > 0;
    }

    // ------------------------------------------------------------------
    // 연락처 카드와 팔로업 메일 (docs/widget-ops-plan.md 3장)
    // ------------------------------------------------------------------

    /**
     * 방문자가 지금 대화창을 보고 있다. 위젯 폴링(창이 펼쳐지고 탭이 보일 때만 돈다)과 전송이 부른다.
     */
    public function touch_visitor_seen($conversation_id)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->update('conversations', array('visitor_seen_at' => date('Y-m-d H:i:s')));
    }

    /**
     * 연락처 카드를 냈거나 건너뛰었다. 이 대화에서 다시 묻지 않는다.
     */
    public function mark_contact_asked($conversation_id)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->where('contact_asked_at IS NULL', NULL, FALSE)
                 ->update('conversations', array('contact_asked_at' => date('Y-m-d H:i:s')));
    }

    /**
     * 답장 알림 메일을 보낼 차례면 시각을 찍고 이전 값(없으면 '')을 돌려준다. 아니면 FALSE.
     *
     * - 방문자가 VISITOR_AWAY_SECONDS 동안 대화창을 보지 않았어야 한다.
     * - 이미 보냈다면, 그 뒤 방문자가 대화창을 다시 봤어야 한다(읽지 않은 사람에게 거듭 보내지 않는다).
     * 조건과 기록을 UPDATE 한 번으로 해서 두 상담원이 동시에 답해도 한 통만 나간다.
     */
    public function claim_followup($conversation_id)
    {
        $conversation = $this->get_conversation($conversation_id);

        if (empty($conversation))
        {
            return FALSE;
        }

        $now = date('Y-m-d H:i:s');
        $away_before = date('Y-m-d H:i:s', time() - self::VISITOR_AWAY_SECONDS);

        $this->db->where('id', (int) $conversation_id)
                 ->where('(visitor_seen_at IS NULL OR visitor_seen_at < '.$this->db->escape($away_before).')', NULL, FALSE)
                 ->where('(followup_sent_at IS NULL OR (visitor_seen_at IS NOT NULL AND visitor_seen_at > followup_sent_at))', NULL, FALSE)
                 ->update('conversations', array('followup_sent_at' => $now));

        if ($this->db->affected_rows() === 0)
        {
            return FALSE;
        }

        return (string) $conversation->followup_sent_at;
    }

    /**
     * 메일을 보내지 못했으면 기록을 되돌려 다음 답장 때 다시 시도하게 한다.
     */
    public function release_followup($conversation_id, $previous)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->update('conversations', array('followup_sent_at' => $previous === '' ? NULL : $previous));
    }

    /**
     * 콘솔 배지에 쓰는 미확인 대화 수
     */
    public function count_unread($org_id)
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM conversations
            WHERE org_id = ? AND status = 'open' AND unread_for_agent > 0
        ", array((int) $org_id))->row();

        return (int) $row->cnt;
    }
}
