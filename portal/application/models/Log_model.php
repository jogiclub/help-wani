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
