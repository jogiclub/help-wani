<?php
/**
 * 파일 위치: application/core/MY_Controller.php
 * 역할: 공통 컨트롤러 기반 클래스 (로그인 확인, 레이아웃 렌더링)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Controller extends CI_Controller {

    /** 현재 화면 언어 (ko / en / ja) */
    public $current_locale = 'ko';

    /** 현재 화면 타임존 (IANA) */
    public $current_timezone = 'Asia/Seoul';

    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('organization_model', 'agent_model', 'session_model', 'log_model'));

        // 로그인한 사용자는 소속 조직 설정을 따른다. 고객 화면은 컨트롤러가 직접 지정한다.
        if ($this->session->userdata('locale'))
        {
            $this->current_locale = $this->session->userdata('locale');
            $this->current_timezone = $this->session->userdata('timezone');
        }

        // 방문자가 직접 고른 언어가 있으면 그것을 우선한다.
        $chosen = (string) $this->input->cookie('rh_locale', TRUE);
        $locales = supported_locales();

        if (isset($locales[$chosen]))
        {
            $this->current_locale = $chosen;
        }
    }

    /**
     * 조직 설정에 맞춰 언어와 타임존을 바꾼다. (고객 화면에서 사용)
     */
    protected function use_org_locale($org)
    {
        if ( ! empty($org))
        {
            $this->current_locale = $org->locale ?: 'ko';
            $this->current_timezone = $org->timezone ?: 'Asia/Seoul';
        }
    }

    /**
     * 레이아웃과 함께 뷰를 출력한다.
     */
    protected function render($view, $data = array(), $layout = 'layouts/main')
    {
        $locales = supported_locales();
        $intl = isset($locales[$this->current_locale]) ? $locales[$this->current_locale]['intl'] : 'ko-KR';

        $data['content_view'] = $view;
        $data['app_name'] = env('APP_NAME', 'RemoteHelp');
        $data['i18n'] = array(
            'locale'   => $this->current_locale,
            'intl'     => $intl,
            'timezone' => $this->current_timezone,
            'messages' => load_messages($this->current_locale),
        );

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

/**
 * 플랫폼 운영자만 접근 가능한 컨트롤러 (조직 가입 승인 등)
 *
 * 조직 관리자(role=admin)는 자기 조직만 다루고, 플랫폼 운영자(is_super=1)는 전체 조직을 다룬다.
 */
class Operator_Controller extends Agent_Controller {

    public function __construct()
    {
        parent::__construct();

        if ((int) $this->agent->is_super !== 1)
        {
            $this->log_model->audit($this->agent->org_id, $this->agent->id,
                'operator_access_denied', uri_string(), client_ip());

            if ($this->input->is_ajax_request())
            {
                api_response(FALSE, '플랫폼 운영자만 접근할 수 있습니다.', array(), 403);
                exit;
            }

            show_error('플랫폼 운영자만 접근할 수 있습니다.', 403);
        }
    }
}
