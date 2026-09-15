<?php
/**
 * 파일 위치: application/models/Session_model.php
 * 역할: 원격 세션 생성/조회/상태변경, 코드 검증, 뷰어 토큰 발급 및 소진
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Session_model extends CI_Model {

    protected $table = 'sessions';

    /** 아직 살아 있는 것으로 간주하는 상태 */
    protected $active_statuses = array('issued', 'verified', 'waiting', 'connected');

    public function __construct()
    {
        parent::__construct();
        $this->load->model('log_model');
    }

    // ------------------------------------------------------------------
    // 조회
    // ------------------------------------------------------------------

    public function get_by_id($id)
    {
        return $this->db->get_where($this->table, array('id' => (int) $id))->row();
    }

    /**
     * 대기열 목록 (오늘 생성된 세션 + 진행 중인 세션)
     */
    public function list_queue($org_id, $limit = 50)
    {
        return $this->db->query("
            SELECT s.*, a.name AS agent_name
            FROM sessions s
            JOIN agents a ON a.id = s.agent_id
            WHERE s.org_id = ?
              AND (s.status IN ('issued','verified','waiting','connected')
                   OR s.created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR))
            ORDER BY s.id DESC
            LIMIT ?
        ", array((int) $org_id, (int) $limit))->result();
    }

    /**
     * 상담 이력 검색
     */
    public function search_history($org_id, $filters = array(), $limit = 50, $offset = 0)
    {
        $this->db->select('s.*, a.name AS agent_name, sv.score AS survey_score')
                 ->from('sessions s')
                 ->join('agents a', 'a.id = s.agent_id')
                 ->join('surveys sv', 'sv.session_id = s.id', 'left')
                 ->where('s.org_id', (int) $org_id);

        if ( ! empty($filters['keyword']))
        {
            $kw = $filters['keyword'];
            $this->db->group_start()
                     ->like('s.customer_pc_name', $kw)
                     ->or_like('s.code', $kw)
                     ->or_like('a.name', $kw)
                     ->group_end();
        }

        if ( ! empty($filters['status']))
        {
            $this->db->where('s.status', $filters['status']);
        }

        if ( ! empty($filters['date_from']))
        {
            $this->db->where('s.created_at >=', $filters['date_from'].' 00:00:00');
        }

        if ( ! empty($filters['date_to']))
        {
            $this->db->where('s.created_at <=', $filters['date_to'].' 23:59:59');
        }

        return $this->db->order_by('s.id', 'DESC')
                        ->limit((int) $limit, (int) $offset)
                        ->get()
                        ->result();
    }

    // ------------------------------------------------------------------
    // 생성
    // ------------------------------------------------------------------

    /**
     * 6자리 코드와 리피터 ID 를 발급해 새 세션을 만든다.
     * 반환값에는 평문 VNC 비밀번호가 포함되므로 저장하지 않는다.
     */
    public function create_session($org_id, $agent_id)
    {
        $this->expire_stale();

        $code        = $this->generate_unique_code();
        $repeater_id = $this->generate_unique_repeater_id();
        $password    = random_vnc_password();

        $data = array(
            'org_id'           => (int) $org_id,
            'agent_id'         => (int) $agent_id,
            'code'             => $code,
            'repeater_id'      => $repeater_id,
            'vnc_password_enc' => $this->encryption->encrypt($password),
            'status'           => 'issued',
            'expires_at'       => date('Y-m-d H:i:s', time() + RH_CODE_TTL),
        );

        $this->db->insert($this->table, $data);
        $id = (int) $this->db->insert_id();

        $this->log_model->add($id, 'session_created', '코드 발급', 'agent');

        return array(
            'id'          => $id,
            'code'        => $code,
            'repeater_id' => $repeater_id,
            'expires_at'  => $data['expires_at'],
        );
    }

    /**
     * 활성 세션 중 중복되지 않는 6자리 코드를 만든다.
     */
    protected function generate_unique_code()
    {
        for ($i = 0; $i < 50; $i++)
        {
            $code = random_digits(6);

            $exists = $this->db->from($this->table)
                               ->where('code', $code)
                               ->where_in('status', $this->active_statuses)
                               ->count_all_results();

            if ($exists === 0)
            {
                return $code;
            }
        }

        throw new RuntimeException('사용 가능한 코드를 생성하지 못했습니다.');
    }

    /**
     * 리피터 ID 는 양의 정수여야 하며(uvncrepeater 사양), 활성 세션 내에서 유일해야 한다.
     */
    protected function generate_unique_repeater_id()
    {
        for ($i = 0; $i < 50; $i++)
        {
            $rid = random_int(100000000, 999999999);

            $exists = $this->db->from($this->table)
                               ->where('repeater_id', $rid)
                               ->where_in('status', $this->active_statuses)
                               ->count_all_results();

            if ($exists === 0)
            {
                return $rid;
            }
        }

        throw new RuntimeException('사용 가능한 리피터 ID 를 생성하지 못했습니다.');
    }

    // ------------------------------------------------------------------
    // 코드 검증 (런처)
    // ------------------------------------------------------------------

    /**
     * IP 기준 무차별 대입 차단 여부
     */
    public function is_ip_blocked($ip)
    {
        $fails = $this->db->from('verify_attempts')
                          ->where('ip', $ip)
                          ->where('is_success', 0)
                          ->where('created_at >=', date('Y-m-d H:i:s', time() - RH_VERIFY_BLOCK_WINDOW))
                          ->count_all_results();

        return $fails >= RH_VERIFY_FAIL_LIMIT;
    }

    public function record_attempt($ip, $code, $success)
    {
        $this->db->insert('verify_attempts', array(
            'ip'         => $ip,
            'code'       => $code,
            'is_success' => $success ? 1 : 0,
        ));
    }

    /**
     * 코드를 검증하고 즉시 소진(verified)한다.
     * 성공 시 세션 레코드와 평문 비밀번호, launcher_secret 을 반환한다.
     */
    public function verify_code($code, $ip, $meta = array())
    {
        $this->expire_stale();

        $session = $this->db->from($this->table)
                            ->where('code', $code)
                            ->where('status', 'issued')
                            ->where('expires_at >', date('Y-m-d H:i:s'))
                            ->order_by('id', 'DESC')
                            ->get()
                            ->row();

        if (empty($session))
        {
            return NULL;
        }

        $launcher_secret = bin2hex(random_bytes(32));

        // 동시 검증 경쟁을 막기 위해 상태 조건을 포함해 갱신한다.
        $this->db->where('id', $session->id)
                 ->where('status', 'issued')
                 ->update($this->table, array(
                     'status'           => 'verified',
                     'launcher_secret'  => $launcher_secret,
                     'customer_ip'      => $ip,
                     'customer_pc_name' => isset($meta['pc_name']) ? $meta['pc_name'] : NULL,
                     'customer_os'      => isset($meta['os_version']) ? $meta['os_version'] : NULL,
                     'launcher_version' => isset($meta['launcher_version']) ? $meta['launcher_version'] : NULL,
                     'verified_at'      => date('Y-m-d H:i:s'),
                 ));

        if ($this->db->affected_rows() < 1)
        {
            return NULL;
        }

        $this->log_model->add($session->id, 'code_verified', '고객 코드 확인', 'customer', $ip);

        $session = $this->get_by_id($session->id);

        return array(
            'session'         => $session,
            'password'        => $this->decrypt_password($session),
            'launcher_secret' => $launcher_secret,
        );
    }

    public function decrypt_password($session)
    {
        $plain = $this->encryption->decrypt($session->vnc_password_enc);
        return $plain === FALSE ? NULL : $plain;
    }

    /**
     * launcher_secret 으로 세션을 확인한다.
     */
    public function get_by_secret($session_id, $secret)
    {
        if (empty($secret))
        {
            return NULL;
        }

        $session = $this->get_by_id($session_id);

        if (empty($session) || empty($session->launcher_secret))
        {
            return NULL;
        }

        return hash_equals($session->launcher_secret, (string) $secret) ? $session : NULL;
    }

    // ------------------------------------------------------------------
    // 상태 변경
    // ------------------------------------------------------------------

    public function set_status($session_id, $status, $extra = array())
    {
        $data = array_merge(array('status' => $status), $extra);

        if ($status === 'connected' && empty($extra['started_at']))
        {
            $current = $this->get_by_id($session_id);
            if ($current && empty($current->started_at))
            {
                $data['started_at'] = date('Y-m-d H:i:s');
            }
        }

        if (in_array($status, array('ended', 'expired', 'canceled'), TRUE))
        {
            $data['ended_at'] = date('Y-m-d H:i:s');
            $data['launcher_secret'] = NULL;
        }

        $this->db->where('id', (int) $session_id)->update($this->table, $data);
        return $this->db->affected_rows();
    }

    public function touch_heartbeat($session_id)
    {
        $this->db->where('id', (int) $session_id)
                 ->update($this->table, array('last_heartbeat_at' => date('Y-m-d H:i:s')));
    }

    /**
     * 만료 코드와 하트비트가 끊긴 세션을 정리한다.
     */
    public function expire_stale()
    {
        // 코드 미사용 만료
        $this->db->where('status', 'issued')
                 ->where('expires_at <', date('Y-m-d H:i:s'))
                 ->update($this->table, array(
                     'status'     => 'expired',
                     'ended_at'   => date('Y-m-d H:i:s'),
                     'end_reason' => 'code_expired',
                 ));

        // 하트비트 끊김
        $this->db->where_in('status', array('waiting', 'connected'))
                 ->where('last_heartbeat_at IS NOT NULL', NULL, FALSE)
                 ->where('last_heartbeat_at <', date('Y-m-d H:i:s', time() - RH_HEARTBEAT_TIMEOUT))
                 ->update($this->table, array(
                     'status'          => 'ended',
                     'ended_at'        => date('Y-m-d H:i:s'),
                     'end_reason'      => 'heartbeat_timeout',
                     'launcher_secret' => NULL,
                 ));
    }

    // ------------------------------------------------------------------
    // 뷰어 토큰
    // ------------------------------------------------------------------

    public function issue_viewer_token($session_id)
    {
        $token = bin2hex(random_bytes(32));

        // 같은 세션의 미사용 토큰은 폐기한다.
        $this->db->where('session_id', (int) $session_id)
                 ->where('used_at IS NULL', NULL, FALSE)
                 ->update('session_tokens', array('used_at' => date('Y-m-d H:i:s'), 'used_ip' => 'revoked'));

        $this->db->insert('session_tokens', array(
            'session_id' => (int) $session_id,
            'token'      => $token,
            'purpose'    => 'viewer',
            'expires_at' => date('Y-m-d H:i:s', time() + RH_VIEWER_TOKEN_TTL),
        ));

        return array(
            'token'      => $token,
            'expires_in' => RH_VIEWER_TOKEN_TTL,
        );
    }

    /**
     * 토큰을 1회용으로 소진한다. 유효하면 세션 레코드를 반환한다.
     */
    public function consume_viewer_token($token, $ip = NULL)
    {
        if ( ! preg_match('/^[a-f0-9]{64}$/', (string) $token))
        {
            return NULL;
        }

        $row = $this->db->get_where('session_tokens', array('token' => $token))->row();

        if (empty($row) || $row->used_at !== NULL)
        {
            return NULL;
        }

        if (strtotime($row->expires_at) < time())
        {
            return NULL;
        }

        // 동시 요청에서도 한 번만 소진되도록 조건부 갱신
        $this->db->where('id', $row->id)
                 ->where('used_at IS NULL', NULL, FALSE)
                 ->update('session_tokens', array(
                     'used_at' => date('Y-m-d H:i:s'),
                     'used_ip' => $ip,
                 ));

        if ($this->db->affected_rows() < 1)
        {
            return NULL;
        }

        return $this->get_by_id($row->session_id);
    }

    // ------------------------------------------------------------------
    // 메모
    // ------------------------------------------------------------------

    public function add_note($session_id, $agent_id, $content)
    {
        $this->db->insert('session_notes', array(
            'session_id' => (int) $session_id,
            'agent_id'   => (int) $agent_id,
            'content'    => $content,
        ));
        return (int) $this->db->insert_id();
    }

    public function list_notes($session_id)
    {
        return $this->db->select('n.*, a.name AS agent_name')
                        ->from('session_notes n')
                        ->join('agents a', 'a.id = n.agent_id')
                        ->where('n.session_id', (int) $session_id)
                        ->order_by('n.id', 'ASC')
                        ->get()
                        ->result();
    }
}
