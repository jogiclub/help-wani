<?php
/**
 * 파일 위치: application/models/Organization_model.php
 * 역할: 조직 정보 조회 및 등록
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Organization_model extends CI_Model {

    protected $table = 'organizations';

    public function get_by_id($id)
    {
        return $this->db->get_where($this->table, array('id' => (int) $id))->row();
    }

    public function get_by_code($org_code)
    {
        return $this->db->get_where($this->table, array('org_code' => $org_code))->row();
    }

    public function get_active_by_code($org_code)
    {
        return $this->db->get_where($this->table, array(
            'org_code' => $org_code,
            'status'   => 'active',
        ))->row();
    }

    public function insert($data)
    {
        $this->db->insert($this->table, $data);
        return (int) $this->db->insert_id();
    }

    public function update($id, $data)
    {
        $this->db->where('id', (int) $id)->update($this->table, $data);
        return $this->db->affected_rows();
    }

    // ------------------------------------------------------------------
    // 가입 승인
    // ------------------------------------------------------------------

    /**
     * 상태별 조직 목록. 대표 관리자 정보와 상담원 수를 함께 가져온다.
     */
    public function list_for_review($status = NULL, $limit = 200)
    {
        $this->db->select('o.*, '
                . '(SELECT COUNT(*) FROM agents WHERE org_id = o.id) AS agent_count, '
                . '(SELECT COUNT(*) FROM sessions WHERE org_id = o.id) AS session_count')
                 ->from($this->table.' o');

        if ( ! empty($status))
        {
            $this->db->where('o.status', $status);
        }

        $rows = $this->db->order_by('FIELD(o.status, \'pending\', \'active\', \'suspended\', \'rejected\')', '', FALSE)
                         ->order_by('o.created_at', 'DESC')
                         ->limit((int) $limit)
                         ->get()
                         ->result();

        // 대표 관리자(가장 먼저 등록된 admin)를 붙인다.
        foreach ($rows as $row)
        {
            $row->owner = $this->db->select('name, email, phone, created_at')
                                   ->from('agents')
                                   ->where('org_id', $row->id)
                                   ->where('role', 'admin')
                                   ->order_by('id', 'ASC')
                                   ->limit(1)
                                   ->get()
                                   ->row();
        }

        return $rows;
    }

    public function count_by_status()
    {
        $rows = $this->db->select('status, COUNT(*) AS cnt')
                         ->from($this->table)
                         ->group_by('status')
                         ->get()
                         ->result();

        $out = array('pending' => 0, 'active' => 0, 'rejected' => 0, 'suspended' => 0);

        foreach ($rows as $row)
        {
            $out[$row->status] = (int) $row->cnt;
        }

        return $out;
    }

    /**
     * 가입 승인. 소속 상담원 계정도 함께 사용 가능 상태로 만든다.
     */
    public function approve($org_id, $operator_id)
    {
        $this->db->where('id', (int) $org_id)->update($this->table, array(
            'status'        => 'active',
            'approved_at'   => date('Y-m-d H:i:s'),
            'approved_by'   => (int) $operator_id,
            'status_reason' => NULL,
        ));

        $this->db->where('org_id', (int) $org_id)->update('agents', array('is_verified' => 1));

        return $this->db->affected_rows();
    }

    /**
     * 반려 또는 이용 중지. 사유를 남긴다.
     */
    public function set_status($org_id, $status, $operator_id, $reason = NULL)
    {
        $allowed = array('pending', 'active', 'rejected', 'suspended');

        if ( ! in_array($status, $allowed, TRUE))
        {
            return 0;
        }

        $data = array(
            'status'        => $status,
            'status_reason' => $reason === NULL ? NULL : mb_substr($reason, 0, 300),
        );

        if ($status === 'active')
        {
            $data['approved_at'] = date('Y-m-d H:i:s');
            $data['approved_by'] = (int) $operator_id;
        }

        $this->db->where('id', (int) $org_id)->update($this->table, $data);

        return $this->db->affected_rows();
    }

    /**
     * 조직 통계 (일별 건수, 평균 시간, 만족도)
     */
    public function statistics($org_id, $days = 30)
    {
        $org_id = (int) $org_id;
        $days   = (int) $days;

        $daily = $this->db->query("
            SELECT DATE(created_at) AS day, COUNT(*) AS cnt
            FROM sessions
            WHERE org_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY day
        ", array($org_id, $days))->result();

        $summary = $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(status = 'ended') AS ended,
                AVG(CASE WHEN started_at IS NOT NULL AND ended_at IS NOT NULL
                         THEN TIMESTAMPDIFF(SECOND, started_at, ended_at) END) AS avg_seconds
            FROM sessions
            WHERE org_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ", array($org_id, $days))->row();

        $survey = $this->db->query("
            SELECT COUNT(*) AS cnt, AVG(sv.score) AS avg_score
            FROM surveys sv
            JOIN sessions s ON s.id = sv.session_id
            WHERE s.org_id = ? AND sv.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ", array($org_id, $days))->row();

        return array(
            'daily'   => $daily,
            'summary' => $summary,
            'survey'  => $survey,
        );
    }

    // ------------------------------------------------------------------
    // 상담 위젯
    // ------------------------------------------------------------------

    /**
     * 위젯 공개 키로 조직을 찾는다. 승인되고 위젯을 켠 조직만 돌려준다.
     */
    public function get_by_widget_key($widget_key)
    {
        if ( ! preg_match('/^[a-f0-9]{32}$/', (string) $widget_key))
        {
            return NULL;
        }

        return $this->db->get_where($this->table, array(
            'widget_key'     => $widget_key,
            'status'         => 'active',
            'widget_enabled' => 1,
        ))->row();
    }

    /**
     * 위젯 키를 새로 발급한다. 기존 스니펫은 즉시 무효가 된다.
     */
    public function regenerate_widget_key($org_id)
    {
        $key = bin2hex(random_bytes(16));

        $this->db->where('id', (int) $org_id)->update($this->table, array('widget_key' => $key));

        return $key;
    }

    /**
     * 고객사 사이트에 붙여 넣는 위젯 스니펫 한 줄
     * 관리자가 localhost 나 내부 IP 로 열어도 공개 주소가 들어가도록 customer_url() 을 쓴다(CLAUDE.md 3.13).
     */
    public function widget_snippet($org)
    {
        return '<script src="'.customer_url('widget.js').'" data-key="'.$org->widget_key.'" defer></script>';
    }

    /**
     * 요청 Origin 이 조직이 허용한 도메인인지 확인한다.
     * 허용 목록이 비어 있으면 개발 편의를 위해 모두 허용한다.
     * 포털 자기 주소(고객 접속 페이지 /{조직코드} 에 뜨는 위젯)는 허용 목록과 관계없이 통과한다.
     */
    public function widget_origin_allowed($org, $origin)
    {
        $allowed = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) $org->widget_domains)));

        if (empty($allowed))
        {
            return TRUE;
        }

        $host = parse_url((string) $origin, PHP_URL_HOST);

        if (empty($host))
        {
            return FALSE;
        }

        foreach (array(base_url(), customer_url()) as $own)
        {
            if (strcasecmp($host, (string) parse_url($own, PHP_URL_HOST)) === 0)
            {
                return TRUE;
            }
        }

        foreach ($allowed as $rule)
        {
            $rule = strtolower(preg_replace('#^https?://#', '', $rule));
            $rule = rtrim($rule, '/');

            if ($rule === '')
            {
                continue;
            }

            // "example.com" 은 하위 도메인까지 허용한다.
            if (strcasecmp($host, $rule) === 0 || substr($host, -(strlen($rule) + 1)) === '.'.$rule)
            {
                return TRUE;
            }
        }

        return FALSE;
    }
}
