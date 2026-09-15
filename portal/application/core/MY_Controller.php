<?php
/**
 * 파일 위치: application/core/MY_Controller.php
 * 역할: 공통 컨트롤러 기반 클래스 (로그인 확인, 레이아웃 렌더링)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Controller extends CI_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('organization_model', 'agent_model', 'session_model', 'log_model'));
    }

    /**
     * 레이아웃과 함께 뷰를 출력한다.
     */
    protected function render($view, $data = array(), $layout = 'layouts/main')
    {
        $data['content_view'] = $view;
        $data['app_name'] = env('APP_NAME', 'RemoteHelp');
        $this->load->view($layout, $data);
    }
}

/**
 * 로그인한 상담원만 접근 가능한 컨트롤러
 */
class Agent_Controller extends MY_Controller {

    protected $agent = NULL;

    public function __construct()
    {
        parent::__construct();

        $agent_id = $this->session->userdata('agent_id');

        if (empty($agent_id))
        {
            if ($this->input->is_ajax_request())
            {
                api_response(FALSE, '로그인이 필요합니다.', array('redirect' => base_url('login')), 401);
                exit;
            }
            redirect('login');
        }

        $this->agent = $this->agent_model->get_by_id($agent_id);

        if (empty($this->agent) || (int) $this->agent->is_active !== 1)
        {
            $this->session->sess_destroy();
            redirect('login');
        }
    }

    /**
     * 세션이 로그인 상담원의 조직 소속인지 확인한다.
     */
    protected function require_own_session($session_id)
    {
        $session = $this->session_model->get_by_id($session_id);

        if (empty($session) || (int) $session->org_id !== (int) $this->agent->org_id)
        {
            api_response(FALSE, '세션을 찾을 수 없습니다.', array(), 404);
            exit;
        }

        return $session;
    }
}
