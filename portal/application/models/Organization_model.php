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
}
