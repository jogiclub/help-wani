<?php
/**
 * 파일 위치: application/models/Log_model.php
 * 역할: 세션 이벤트 로그와 관리 행위 감사 로그 기록
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Log_model extends CI_Model {

    public function add($session_id, $event, $detail = NULL, $actor = 'system', $ip = NULL)
    {
        $this->db->insert('session_logs', array(
            'session_id' => (int) $session_id,
            'event'      => $event,
            'detail'     => $detail === NULL ? NULL : mb_substr($detail, 0, 500),
            'actor'      => $actor,
            'ip'         => $ip,
        ));
    }

    public function list_by_session($session_id)
    {
        return $this->db->where('session_id', (int) $session_id)
                        ->order_by('id', 'ASC')
                        ->get('session_logs')
                        ->result();
    }

    /**
     * 전체 조직의 감사 로그. 조직명과 수행자 이름을 붙여 돌려준다.
     */
    public function list_audit($limit = 500)
    {
        return $this->db->select('l.*, o.name AS org_name, o.org_code, a.name AS agent_name, a.email AS agent_email')
                        ->from('audit_logs l')
                        ->join('organizations o', 'o.id = l.org_id', 'left')
                        ->join('agents a', 'a.id = l.agent_id', 'left')
                        ->order_by('l.id', 'DESC')
                        ->limit((int) $limit)
                        ->get()
                        ->result();
    }

    public function audit($org_id, $agent_id, $action, $detail = NULL, $ip = NULL)
    {
        $this->db->insert('audit_logs', array(
            'org_id'   => $org_id === NULL ? NULL : (int) $org_id,
            'agent_id' => $agent_id === NULL ? NULL : (int) $agent_id,
            'action'   => $action,
            'detail'   => $detail === NULL ? NULL : mb_substr($detail, 0, 500),
            'ip'       => $ip,
        ));
    }
}
