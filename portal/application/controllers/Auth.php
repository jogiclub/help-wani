<?php
/**
 * 파일 위치: application/controllers/Auth.php
 * 역할: 상담원 로그인/로그아웃, 조직 가입 신청
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends MY_Controller {

    public function login()
    {
        if ($this->session->userdata('agent_id'))
        {
            redirect('console');
        }

        if ($this->input->method() === 'post')
        {
            $this->form_validation->set_rules('email', '이메일', 'required|valid_email');
            $this->form_validation->set_rules('password', '비밀번호', 'required');

            if ($this->form_validation->run() === FALSE)
            {
                api_response(FALSE, validation_errors(' ', ' '), array(), 400);
                return;
            }

            $email    = $this->input->post('email', TRUE);
            $password = (string) $this->input->post('password');

            $agent = $this->agent_model->authenticate($email, $password);

            if (empty($agent))
            {
                $this->log_model->audit(NULL, NULL, 'login_failed', $email, client_ip());
                api_response(FALSE, '이메일 또는 비밀번호가 올바르지 않습니다.', array(), 401);
                return;
            }

            $org = $this->organization_model->get_by_id($agent->org_id);

            if (empty($org))
            {
                api_response(FALSE, '조직 정보를 찾을 수 없습니다.', array(), 403);
                return;
            }

            if ($org->status !== 'active')
            {
                api_response(FALSE, $this->status_message($org), array('status' => $org->status), 403);
                return;
            }

            $this->session->sess_regenerate(TRUE);
            $this->session->set_userdata(array(
                'agent_id'   => (int) $agent->id,
                'agent_name' => $agent->name,
                'agent_role' => $agent->role,
                'is_super'   => ((int) $agent->is_super === 1),
                'org_id'     => (int) $agent->org_id,
                'org_name'   => $org->name,
                'org_code'   => $org->org_code,
                'locale'     => $org->locale ?: 'ko',
                'timezone'   => $org->timezone ?: 'Asia/Seoul',
            ));

            $this->agent_model->touch_login($agent->id);
            $this->log_model->audit($agent->org_id, $agent->id, 'login', NULL, client_ip());

            api_response(TRUE, '로그인되었습니다.', array('redirect' => base_url('console')));
            return;
        }

        $this->render('auth/login', array('page_title' => '상담원 로그인'), 'layouts/blank');
    }

    /**
     * 조직 상태에 따른 로그인 실패 안내 문구
     */
    protected function status_message($org)
    {
        switch ($org->status)
        {
            case 'pending':
                return '가입 신청이 검토 중입니다. 운영자 승인 후 이용하실 수 있습니다.';

            case 'rejected':
                return '가입 신청이 반려되었습니다.'
                    .($org->status_reason ? ' 사유: '.$org->status_reason : '')
                    .' 문의는 운영자에게 해 주세요.';

            case 'suspended':
                return '이용이 중지된 조직입니다.'
                    .($org->status_reason ? ' 사유: '.$org->status_reason : '')
                    .' 문의는 운영자에게 해 주세요.';

            default:
                return '지금은 로그인할 수 없습니다. 운영자에게 문의해 주세요.';
        }
    }

    public function logout()
    {
        $agent_id = $this->session->userdata('agent_id');
        $org_id   = $this->session->userdata('org_id');

        if ($agent_id)
        {
            $this->log_model->audit($org_id, $agent_id, 'logout', NULL, client_ip());
        }

        $this->session->sess_destroy();
        redirect('login');
    }

    /**
     * 조직 가입 신청. 관리자 승인(status=active) 후 로그인 가능.
     */
    public function signup()
    {
        if ($this->input->method() === 'post')
        {
            $this->form_validation->set_rules('org_code', '조직 주소', 'required|alpha_dash|min_length[3]|max_length[32]');
            $this->form_validation->set_rules('org_name', '조직명', 'required|max_length[100]');
            $this->form_validation->set_rules('biz_no', '사업자등록번호', 'required|max_length[20]');
            $this->form_validation->set_rules('name', '담당자명', 'required|max_length[60]');
            $this->form_validation->set_rules('email', '이메일', 'required|valid_email|max_length[190]');
            $this->form_validation->set_rules('password', '비밀번호', 'required|min_length[10]');
            $this->form_validation->set_rules('password_confirm', '비밀번호 확인', 'required|matches[password]');

            if ($this->form_validation->run() === FALSE)
            {
                api_response(FALSE, validation_errors(' ', ' '), array(), 400);
                return;
            }

            $org_code = strtolower($this->input->post('org_code', TRUE));
            $email    = $this->input->post('email', TRUE);

            if ($this->organization_model->get_by_code($org_code))
            {
                api_response(FALSE, '이미 사용 중인 조직 주소입니다.', array(), 409);
                return;
            }

            if ($this->agent_model->get_by_email($email))
            {
                api_response(FALSE, '이미 가입된 이메일입니다.', array(), 409);
                return;
            }

            // 국가/언어/시간대는 허용 목록 안의 값만 받는다.
            $countries = supported_countries();
            $locales   = supported_locales();
            $timezones = supported_timezones();

            $country  = (string) $this->input->post('country', TRUE);
            $locale   = (string) $this->input->post('locale', TRUE);
            $timezone = (string) $this->input->post('timezone', TRUE);

            $country  = isset($countries[$country]) ? $country : 'KR';
            $locale   = isset($locales[$locale]) ? $locale : $countries[$country]['locale'];
            $timezone = isset($timezones[$timezone]) ? $timezone : $countries[$country]['timezone'];

            $org_id = $this->organization_model->insert(array(
                'org_code' => $org_code,
                'name'     => $this->input->post('org_name', TRUE),
                'biz_no'   => $this->input->post('biz_no', TRUE),
                'phone'    => $this->input->post('phone', TRUE),
                'country'  => $country,
                'locale'   => $locale,
                'timezone' => $timezone,
                'status'   => 'pending',
            ));

            $agent_id = $this->agent_model->insert(array(
                'org_id'   => $org_id,
                'email'    => $email,
                'password' => (string) $this->input->post('password'),
                'name'     => $this->input->post('name', TRUE),
                'phone'    => $this->input->post('phone', TRUE),
                'role'     => 'admin',
            ));

            $this->log_model->audit($org_id, $agent_id, 'org_signup', $org_code, client_ip());

            api_response(TRUE,
                '가입 신청이 접수되었습니다. 운영자 승인 후 로그인할 수 있습니다. '
                .'승인 여부는 로그인 화면에서 확인하실 수 있습니다.',
                array('redirect' => base_url('login')));
            return;
        }

        $this->render('auth/signup', array(
            'page_title' => '조직 가입 신청',
            'countries'  => supported_countries(),
            'locales'    => supported_locales(),
            'timezones'  => supported_timezones(),
        ), 'layouts/blank');
    }
}
