<?php
/**
 * 파일 위치: application/controllers/api/Launcher.php
 * 역할: 고객 런처용 API (코드 검증, 상태 보고, 하트비트, 버전 확인)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Launcher extends MY_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->output->set_content_type('application/json', 'utf-8');
    }

    /**
     * POST /api/launcher/verify
     * 입력: code, pc_name, os_version, launcher_version
     */
    public function verify()
    {
        $ip   = client_ip();
        $code = preg_replace('/[^0-9]/', '', (string) json_input('code', ''));

        if (strlen($code) !== 6)
        {
            api_response(FALSE, '6자리 숫자 코드를 입력해 주세요.', array(), 400);
            return;
        }

        if ($this->session_model->is_ip_blocked($ip))
        {
            api_response(FALSE, '입력 실패가 많아 잠시 차단되었습니다. 10분 후에 다시 시도해 주세요.', array(), 429);
            return;
        }

        $meta = array(
            'pc_name'          => mb_substr((string) json_input('pc_name', ''), 0, 100),
            'os_version'       => mb_substr((string) json_input('os_version', ''), 0, 100),
            'launcher_version' => mb_substr((string) json_input('launcher_version', ''), 0, 20),
        );

        $verified = $this->session_model->verify_code($code, $ip, $meta);

        if (empty($verified))
        {
            $this->session_model->record_attempt($ip, $code, FALSE);
            api_response(FALSE, '코드가 올바르지 않거나 유효시간이 지났습니다.', array(), 404);
            return;
        }

        $this->session_model->record_attempt($ip, $code, TRUE);

        $session = $verified['session'];
        $org     = $this->organization_model->get_by_id($session->org_id);

        api_response(TRUE, '', array(
            'session_id'      => (int) $session->id,
            'repeater_id'     => (string) $session->repeater_id,
            'vnc_password'    => $verified['password'],
            'relay_host'      => env('RELAY_HOST', 'localhost'),
            'relay_port'      => (int) env('RELAY_TLS_PORT', 5501),
            'org_name'        => $org ? $org->name : '',
            'org_logo_url'    => ($org && $org->logo_path) ? base_url($org->logo_path) : '',
            'launcher_secret' => $verified['launcher_secret'],
            'survey_url'      => $org ? base_url($org->org_code.'/survey/'.$session->id) : '',
            'heartbeat_sec'   => 30,
        ));
    }

    /**
     * POST /api/launcher/status
     * 입력: session_id, launcher_secret, status(waiting/connected/ended), reason
     */
    public function status()
    {
        $session_id = (int) json_input('session_id', 0);
        $secret     = (string) json_input('launcher_secret', '');
        $status     = (string) json_input('status', '');
        $reason     = mb_substr((string) json_input('reason', ''), 0, 50);

        $allowed = array('waiting', 'connected', 'ended');

        if ( ! in_array($status, $allowed, TRUE))
        {
            api_response(FALSE, '허용되지 않는 상태값입니다.', array(), 400);
            return;
        }

        $session = $this->session_model->get_by_secret($session_id, $secret);

        if (empty($session))
        {
            api_response(FALSE, '세션 인증에 실패했습니다.', array(), 401);
            return;
        }

        if (in_array($session->status, array('ended', 'expired', 'canceled'), TRUE))
        {
            api_response(TRUE, '이미 종료된 세션입니다.', array('status' => $session->status));
            return;
        }

        $extra = array();

        if ($status === 'ended')
        {
            $extra['end_reason'] = $reason !== '' ? $reason : 'customer_ended';
        }

        $this->session_model->set_status($session->id, $status, $extra);
        $this->session_model->touch_heartbeat($session->id);
        $this->log_model->add($session->id, 'launcher_'.$status, $reason, 'customer', client_ip());

        api_response(TRUE, '', array('status' => $status));
    }

    /**
     * GET /api/launcher/heartbeat?session_id=&launcher_secret=
     * 상담원이 종료했으면 terminate: true 를 돌려준다.
     */
    public function heartbeat()
    {
        $session_id = (int) $this->input->get('session_id');
        $secret     = (string) $this->input->get('launcher_secret');

        $session = $this->session_model->get_by_secret($session_id, $secret);

        if (empty($session))
        {
            // 인증 실패도 런처는 종료해야 한다.
            api_response(FALSE, '세션 인증에 실패했습니다.', array('terminate' => TRUE), 401);
            return;
        }

        $this->session_model->touch_heartbeat($session->id);

        $terminate = in_array($session->status, array('ended', 'expired', 'canceled'), TRUE);

        api_response(TRUE, '', array(
            'terminate'  => $terminate,
            'status'     => $session->status,
            'end_reason' => $session->end_reason,
        ));
    }

    /**
     * GET /api/launcher/version
     */
    public function version()
    {
        api_response(TRUE, '', array(
            'min_version'   => env('LAUNCHER_MIN_VERSION', '1.0.0'),
            'store_product_id' => env('STORE_PRODUCT_ID', ''),
            'store_url'     => 'https://apps.microsoft.com/detail/'.env('STORE_PRODUCT_ID', ''),
        ));
    }
}
