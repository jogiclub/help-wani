<?php
/**
 * 파일 위치: application/controllers/api/Relay.php
 * 역할: nginx auth_request 가 호출하는 1회용 뷰어 토큰 검증 API
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Relay extends MY_Controller {

    /**
     * GET /api/relay/auth?token=...&ip=...
     * 성공 200, 실패 403. 본문은 nginx 가 무시하므로 최소한만 출력한다.
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

        $token = (string) $this->input->get('token');
        $ip    = (string) $this->input->get('ip');

        $session = $this->session_model->consume_viewer_token($token, $ip);

        if (empty($session))
        {
            $this->deny('invalid or used token');
            return;
        }

        if ( ! in_array($session->status, array('waiting', 'connected'), TRUE))
        {
            $this->deny('session not connectable: '.$session->status);
            return;
        }

        $this->session_model->set_status($session->id, 'connected');
        $this->log_model->add($session->id, 'viewer_authorized', 'noVNC 토큰 검증 통과', 'agent', $ip);

        $this->output->set_status_header(200)
                     ->set_content_type('text/plain', 'utf-8')
                     ->set_output('ok');
    }

    protected function deny($reason)
    {
        log_message('error', 'relay auth denied: '.$reason);
        $this->output->set_status_header(403)
                     ->set_content_type('text/plain', 'utf-8')
                     ->set_output('denied');
    }
}
