<?php
/**
 * 파일 위치: application/controllers/api/Relay.php
 * 역할: nginx auth_request 가 호출하는 1회용 뷰어 토큰 검증 API
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Relay extends MY_Controller {

    /**
     * GET /api/relay/auth?role=agent|viewer&token=...&ip=...
     *
     * 중계 서버(Node)가 웹소켓 접속을 받을 때마다 호출한다.
     * 성공하면 { result: true, data: { session_id, org_id } } 를 돌려준다.
     */
    public function auth()
    {
        $shared = (string) env('RELAY_AUTH_SHARED_SECRET', '');
        $sent   = (string) $this->input->get_request_header('X-Relay-Secret', TRUE);

        if ($shared !== '' && ! hash_equals($shared, $sent))
        {
            $this->deny('relay secret mismatch');
            return;
        }

        $role  = (string) $this->input->get('role');
        $token = (string) $this->input->get('token');
        $ip    = (string) $this->input->get('ip');

        if ($role === 'agent')
        {
            $session = $this->session_model->get_by_agent_token($token);
            $event   = 'agent_connected';
            $detail  = '고객 PC 에이전트 접속';
            $actor   = 'customer';
        }
        elseif ($role === 'viewer')
        {
            // 뷰어 토큰은 1회용이므로 여기서 소진한다.
            $session = $this->session_model->consume_viewer_token($token, $ip);
            $event   = 'viewer_connected';
            $detail  = '상담원 뷰어 접속';
            $actor   = 'agent';
        }
        else
        {
            $this->deny('unknown role: '.$role);
            return;
        }

        if (empty($session))
        {
            $this->deny('invalid or used token (role='.$role.')');
            return;
        }

        if ( ! in_array($session->status, array('verified', 'waiting', 'connected'), TRUE))
        {
            $this->deny('session not connectable: '.$session->status);
            return;
        }

        // 에이전트 접속은 대기 상태, 뷰어까지 붙으면 원격 중으로 본다.
        $next = ($role === 'viewer') ? 'connected' : 'waiting';

        if ($session->status !== 'connected')
        {
            $this->session_model->set_status($session->id, $next);
        }

        $this->session_model->touch_heartbeat($session->id);
        $this->log_model->add($session->id, $event, $detail, $actor, $ip);

        api_response(TRUE, '', array(
            'session_id' => (int) $session->id,
            'org_id'     => (int) $session->org_id,
            'role'       => $role,
        ));
    }

    protected function deny($reason)
    {
        log_message('error', 'relay auth denied: '.$reason);
        api_response(FALSE, '접속 인증에 실패했습니다.', array(), 403);
    }
}
