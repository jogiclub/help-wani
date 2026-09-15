<?php
/**
 * 파일 위치: application/controllers/Admin.php
 * 역할: 조직 관리(상담원 관리, 조직 정보, 통계)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin extends Agent_Controller {

    public function __construct()
    {
        parent::__construct();

        if ($this->agent->role !== 'admin')
        {
            if ($this->input->is_ajax_request())
            {
                api_response(FALSE, '관리자만 접근할 수 있습니다.', array(), 403);
                exit;
            }
            show_error('관리자만 접근할 수 있습니다.', 403);
        }
    }

    public function index()
    {
        $stats = $this->organization_model->statistics($this->agent->org_id, 30);

        $this->render('admin/dashboard', array(
            'page_title' => '조직 관리',
            'org'        => $this->organization_model->get_by_id($this->agent->org_id),
            'stats'      => $stats,
        ));
    }

    public function agents()
    {
        $this->render('admin/agents', array(
            'page_title' => '상담원 관리',
            'use_grid'   => TRUE,
            'agents'     => $this->agent_model->list_by_org($this->agent->org_id),
        ));
    }

    public function organization()
    {
        $this->render('admin/organization', array(
            'page_title' => '조직 정보',
            'org'        => $this->organization_model->get_by_id($this->agent->org_id),
        ));
    }

    /**
     * POST /admin/api/agent_save
     */
    public function api_agent_save()
    {
        $id    = (int) $this->input->post('id');
        $name  = $this->input->post('name', TRUE);
        $email = $this->input->post('email', TRUE);
        $role  = $this->input->post('role', TRUE) === 'admin' ? 'admin' : 'agent';
        $pass  = (string) $this->input->post('password');

        if ($name === '' OR ! filter_var($email, FILTER_VALIDATE_EMAIL))
        {
            api_response(FALSE, '이름과 올바른 이메일을 입력해 주세요.', array(), 400);
            return;
        }

        $dup = $this->agent_model->get_by_email($email);

        if ($dup && (int) $dup->id !== $id)
        {
            api_response(FALSE, '이미 사용 중인 이메일입니다.', array(), 409);
            return;
        }

        if ($id > 0)
        {
            $target = $this->agent_model->get_by_id($id);

            if (empty($target) || (int) $target->org_id !== (int) $this->agent->org_id)
            {
                api_response(FALSE, '대상을 찾을 수 없습니다.', array(), 404);
                return;
            }

            $data = array('name' => $name, 'email' => $email, 'role' => $role,
                          'phone' => $this->input->post('phone', TRUE));

            if ($pass !== '')
            {
                if (strlen($pass) < 10)
                {
                    api_response(FALSE, '비밀번호는 10자 이상이어야 합니다.', array(), 400);
                    return;
                }
                $data['password'] = $pass;
            }

            $this->agent_model->update($id, $data);
            $this->log_model->audit($this->agent->org_id, $this->agent->id, 'agent_update', 'agent_id='.$id, client_ip());
            api_response(TRUE, '상담원 정보를 저장했습니다.', array());
            return;
        }

        if (strlen($pass) < 10)
        {
            api_response(FALSE, '비밀번호는 10자 이상이어야 합니다.', array(), 400);
            return;
        }

        $new_id = $this->agent_model->insert(array(
            'org_id'      => $this->agent->org_id,
            'email'       => $email,
            'password'    => $pass,
            'name'        => $name,
            'phone'       => $this->input->post('phone', TRUE),
            'role'        => $role,
            'is_verified' => 1,
        ));

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'agent_create', 'agent_id='.$new_id, client_ip());
        api_response(TRUE, '상담원을 추가했습니다.', array('id' => $new_id));
    }

    /**
     * POST /admin/api/agent_toggle
     */
    public function api_agent_toggle()
    {
        $id     = (int) $this->input->post('id');
        $target = $this->agent_model->get_by_id($id);

        if (empty($target) || (int) $target->org_id !== (int) $this->agent->org_id)
        {
            api_response(FALSE, '대상을 찾을 수 없습니다.', array(), 404);
            return;
        }

        if ((int) $target->id === (int) $this->agent->id)
        {
            api_response(FALSE, '본인 계정은 비활성화할 수 없습니다.', array(), 400);
            return;
        }

        $next = ((int) $target->is_active === 1) ? 0 : 1;
        $this->agent_model->update($id, array('is_active' => $next));
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'agent_toggle', 'agent_id='.$id.' active='.$next, client_ip());

        api_response(TRUE, $next ? '계정을 활성화했습니다.' : '계정을 비활성화했습니다.', array('is_active' => $next));
    }

    /**
     * POST /admin/api/org_save
     */
    public function api_org_save()
    {
        $name  = $this->input->post('name', TRUE);
        $phone = $this->input->post('phone', TRUE);

        if ($name === '')
        {
            api_response(FALSE, '조직명을 입력해 주세요.', array(), 400);
            return;
        }

        $this->organization_model->update($this->agent->org_id, array(
            'name'  => $name,
            'phone' => $phone,
        ));

        $this->session->set_userdata('org_name', $name);
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'org_update', NULL, client_ip());

        api_response(TRUE, '조직 정보를 저장했습니다.', array());
    }
}
