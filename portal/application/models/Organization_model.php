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
