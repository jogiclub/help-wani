<?php
/**
 * 파일 위치: application/models/Visitor_model.php
 * 역할: 상담 위젯 방문자 식별과 정보 갱신
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Visitor_model extends CI_Model {

    protected $table = 'visitors';

    /**
     * 방문자 키로 조회한다. 조직이 다르면 남의 대화이므로 돌려주지 않는다.
     */
    public function get_by_key($org_id, $visitor_key)
    {
        if ( ! preg_match('/^[a-f0-9]{64}$/', (string) $visitor_key))
        {
            return NULL;
        }

        return $this->db->get_where($this->table, array(
            'org_id'      => (int) $org_id,
            'visitor_key' => $visitor_key,
        ))->row();
    }

    /**
     * 방문자를 찾거나 새로 만든다. 키가 없거나 위조면 새 키를 발급한다.
     */
    public function ensure($org_id, $visitor_key, $meta = array())
    {
        $visitor = $this->get_by_key($org_id, $visitor_key);

        $info = array(
            'ip'         => isset($meta['ip']) ? $meta['ip'] : NULL,
            'user_agent' => isset($meta['user_agent']) ? mb_substr((string) $meta['user_agent'], 0, 255) : NULL,
            'page_url'   => isset($meta['page_url']) ? mb_substr((string) $meta['page_url'], 0, 500) : NULL,
        );

        if ( ! empty($visitor))
        {
            $this->db->where('id', $visitor->id)->update($this->table, $info);
            return $this->db->get_where($this->table, array('id' => $visitor->id))->row();
        }

        $info['org_id']      = (int) $org_id;
        $info['visitor_key'] = bin2hex(random_bytes(32));

        $this->db->insert($this->table, $info);

        return $this->db->get_where($this->table, array('id' => (int) $this->db->insert_id()))->row();
    }

    /**
     * 방문자가 남긴 이름과 이메일을 저장한다.
     */
    public function update_profile($visitor_id, $name, $email)
    {
        $data = array();

        if ($name !== NULL && trim($name) !== '')
        {
            $data['name'] = mb_substr(trim($name), 0, 60);
        }

        if ($email !== NULL && filter_var($email, FILTER_VALIDATE_EMAIL))
        {
            $data['email'] = mb_substr($email, 0, 190);
        }

        if (empty($data))
        {
            return 0;
        }

        $this->db->where('id', (int) $visitor_id)->update($this->table, $data);

        return $this->db->affected_rows();
    }
}
