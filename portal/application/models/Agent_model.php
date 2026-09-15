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
        ));
    }
}
