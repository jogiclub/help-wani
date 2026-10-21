<?php
/**
 * 파일 위치: application/models/Chat_model.php
 * 역할: 상담 위젯 대화와 메시지 저장/조회, 상담원 접속 여부 판별
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Chat_model extends CI_Model {

    /** 이 시간(초) 안에 콘솔 활동이 있으면 접속 중으로 본다 */
    const ONLINE_WINDOW_SECONDS = 60;

    /** 방문자 한 명이 1분에 보낼 수 있는 메시지 수 */
    const RATE_LIMIT_PER_MINUTE = 20;

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
        $this->db->select('c.*, v.name AS visitor_name, v.email AS visitor_email, v.page_url, '
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

    public function assign($conversation_id, $agent_id)
    {
        $this->db->where('id', (int) $conversation_id)
                 ->where('assigned_agent_id IS NULL', NULL, FALSE)
                 ->update('conversations', array('assigned_agent_id' => (int) $agent_id));

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

        if ($sender_type === 'visitor')
        {
            $this->db->set('unread_for_agent', 'unread_for_agent + 1', FALSE);
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
    // 상담원 접속 여부
    // ------------------------------------------------------------------

    /**
     * 콘솔이 살아 있음을 알린다. 오프라인 이메일 발송 판단에 쓰인다.
     */
    public function touch_agent($agent_id)
    {
        $this->db->where('id', (int) $agent_id)
                 ->update('agents', array('last_seen_at' => date('Y-m-d H:i:s')));
    }

    /**
     * 조직에 접속 중인 상담원이 한 명이라도 있는지 확인한다.
     */
    public function has_online_agent($org_id)
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS cnt
            FROM agents
            WHERE org_id = ?
              AND is_active = 1
              AND last_seen_at IS NOT NULL
              AND last_seen_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
        ", array((int) $org_id, self::ONLINE_WINDOW_SECONDS))->row();

        return (int) $row->cnt > 0;
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
