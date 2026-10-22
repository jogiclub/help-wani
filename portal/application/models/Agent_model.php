<?php
/**
 * 파일 위치: application/models/Agent_model.php
 * 역할: 상담원 계정 조회, 등록, 인증
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Agent_model extends CI_Model {

    protected $table = 'agents';

    public function get_by_id($id)
    {
        return $this->db->get_where($this->table, array('id' => (int) $id))->row();
    }

    public function get_by_email($email)
    {
        return $this->db->get_where($this->table, array('email' => $email))->row();
    }

    public function list_by_org($org_id)
    {
        return $this->db
            ->where('org_id', (int) $org_id)
            ->order_by('id', 'ASC')
            ->get($this->table)
            ->result();
    }

    /**
     * 조직의 대표 관리자 이메일. 위젯 오프라인 알림의 기본 수신자로 쓴다.
     */
    public function admin_email($org_id)
    {
        $row = $this->db->select('email')
                        ->from('agents')
                        ->where('org_id', (int) $org_id)
                        ->where('role', 'admin')
                        ->where('is_active', 1)
                        ->order_by('id', 'ASC')
                        ->limit(1)
                        ->get()
                        ->row();

        return empty($row) ? NULL : $row->email;
    }

    public function insert($data)
    {
        if (isset($data['password']))
        {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        $this->db->insert($this->table, $data);
        return (int) $this->db->insert_id();
    }

    public function update($id, $data)
    {
        if (isset($data['password']))
        {
            $data['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            unset($data['password']);
        }
        $this->db->where('id', (int) $id)->update($this->table, $data);
        return $this->db->affected_rows();
    }

    /**
     * 이메일/비밀번호 검증. 성공 시 상담원 레코드를 반환한다.
     */
    public function authenticate($email, $password)
    {
        $agent = $this->get_by_email($email);

        if (empty($agent) || (int) $agent->is_active !== 1)
        {
            return NULL;
        }

        if ( ! password_verify($password, $agent->password_hash))
        {
            return NULL;
        }

        return $agent;
    }

    public function touch_login($id)
    {
        $this->db->where('id', (int) $id)->update($this->table, array(
            'last_login_at' => date('Y-m-d H:i:s'),
            // 로그인하면 응대 가능으로 시작한다. 어제 '응대 종료' 로 두고 퇴근한 사람이 오늘 안 보이는 일을 막는다.
            'chat_status'   => 'available',
        ));
    }
}
