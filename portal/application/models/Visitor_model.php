<?php
/**
 * 파일 위치: application/models/Visitor_model.php
 * 역할: 상담 위젯 방문자 식별과 정보 갱신, 연락처 카드 입력 검사와 저장(docs/widget-ops-plan.md 3장)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Visitor_model extends CI_Model {

    protected $table = 'visitors';

    /** 연락처 카드에서 받을 수 있는 항목 */
    const CONTACT_FIELDS = array('name', 'email', 'phone');

    public function get_by_id($id)
    {
        return $this->db->get_where($this->table, array('id' => (int) $id))->row();
    }

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

    // ------------------------------------------------------------------
    // 연락처 카드
    // ------------------------------------------------------------------

    /**
     * 조직이 묻는 항목. 순서는 카드에 그리는 순서다.
     */
    public function asked_fields($org)
    {
        $out = array();

        foreach (self::CONTACT_FIELDS as $field)
        {
            if ((int) $org->{'chat_ask_'.$field} === 1)
            {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * 묻는 항목 중 비어 있는 것. '연락처를 남긴 고객에게만' 이면 이것이 비어야 문의를 받는다.
     */
    public function missing_fields($org, $visitor)
    {
        return array_values(array_filter($this->asked_fields($org), function ($field) use ($visitor) {
            return trim((string) $visitor->{$field}) === '';
        }));
    }

    /**
     * 연락처 카드 입력을 검사한다. 조직이 묻지 않는 항목은 버린다.
     * 필수 모드면 묻는 항목을 모두, 아니면 하나 이상 받아야 한다. 오류 문구는 고객이 읽으므로 사전 키로 돌려준다.
     *
     * @param array $input name, email, phone_country(KR 등), phone
     * @return array array('ok' => 저장할 값) | array('error' => 사전 키, 'field' => 항목)
     */
    public function clean_contact($org, $input, $required)
    {
        $asked = $this->asked_fields($org);
        $data = array();

        if (in_array('name', $asked, TRUE))
        {
            $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) (isset($input['name']) ? $input['name'] : '')));

            if ($name !== '')
            {
                $data['name'] = mb_substr($name, 0, 60);
            }
        }

        if (in_array('email', $asked, TRUE))
        {
            $email = trim((string) (isset($input['email']) ? $input['email'] : ''));

            if ($email !== '')
            {
                if (mb_strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL))
                {
                    return array('error' => 'widget.contact_bad_email', 'field' => 'email');
                }

                $data['email'] = $email;
            }
        }

        if (in_array('phone', $asked, TRUE))
        {
            $raw = trim((string) (isset($input['phone']) ? $input['phone'] : ''));

            if ($raw !== '')
            {
                $phone = $this->normalize_phone(isset($input['phone_country']) ? $input['phone_country'] : '', $raw);

                if ($phone === NULL)
                {
                    return array('error' => 'widget.contact_bad_phone', 'field' => 'phone');
                }

                $data['phone'] = $phone;
            }
        }

        foreach ($asked as $field)
        {
            if ($required && ! isset($data[$field]))
            {
                return array('error' => 'widget.contact_need_all', 'field' => $field);
            }
        }

        if (empty($data))
        {
            return array('error' => 'widget.contact_need_one', 'field' => isset($asked[0]) ? $asked[0] : '');
        }

        return array('ok' => $data);
    }

    /**
     * 휴대폰 번호를 E.164(+821012345678)로 바꾼다. 국가번호는 허용 목록(country_dial_codes)만 받는다.
     * 번호를 +로 시작해 적었으면 그 국가번호가 목록에 있어야 한다. 국내 번호 앞의 0 은 뗀다.
     */
    public function normalize_phone($country, $raw)
    {
        $codes = country_dial_codes();
        $digits = preg_replace('/[\s\-().]/', '', (string) $raw);

        if ( ! preg_match('/^\+?[0-9]+$/', $digits))
        {
            return NULL;
        }

        if ($digits[0] === '+')
        {
            $digits = substr($digits, 1);
            $dial = NULL;

            // 국가번호는 1~3자리. 긴 것부터 맞춰 본다.
            foreach (array(3, 2, 1) as $len)
            {
                if (in_array(substr($digits, 0, $len), $codes, TRUE))
                {
                    $dial = substr($digits, 0, $len);
                    break;
                }
            }

            if ($dial === NULL)
            {
                return NULL;
            }

            $national = substr($digits, strlen($dial));
        }
        else
        {
            if ( ! isset($codes[$country]))
            {
                return NULL;
            }

            $dial = $codes[$country];
            $national = ltrim($digits, '0');
        }

        $total = strlen($dial) + strlen($national);

        if (strlen($national) < 6 || $total > 15)
        {
            return NULL;
        }

        return '+'.$dial.$national;
    }

    /**
     * 연락처를 저장하고 수집 안내를 본 시각을 남긴다.
     */
    public function save_contact($visitor_id, $data)
    {
        $data = array_intersect_key($data, array_flip(self::CONTACT_FIELDS));
        $data['contact_consent_at'] = date('Y-m-d H:i:s');

        $this->db->where('id', (int) $visitor_id)->update($this->table, $data);
    }
}
