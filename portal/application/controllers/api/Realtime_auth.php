<?php
/**
 * 파일 위치: application/controllers/api/Realtime_auth.php
 * 역할: 실시간 메시지 서버(realtime-msg)가 구독 토큰을 검증할 때 호출하는 API
 *
 * 클래스 이름은 라이브러리 Realtime 과 겹치지 않아야 한다.
 * 같은 이름이면 CI3 가 라이브러리를 읽지 못한다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Realtime_auth extends MY_Controller {

    /**
     * GET /api/realtime/auth?token=...&ip=...
     *
     * 성공하면 { result: true, data: { role, org_id, conversation_id, rooms } } 를 돌려준다.
     */
    public function auth()
    {
        $shared = (string) env('REALTIME_SHARED_SECRET', '');
        $sent   = (string) $this->input->get_request_header('X-Realtime-Secret', TRUE);

        if ($shared !== '' && ! hash_equals($shared, $sent))
        {
            $this->deny('realtime secret mismatch');
            return;
        }

        $this->load->library('realtime');

        $claims = $this->realtime->verify_token((string) $this->input->get('token'));

        if (empty($claims))
        {
            $this->deny('invalid or expired token');
            return;
        }

        api_response(TRUE, '', $claims);
    }

    protected function deny($reason)
    {
        log_message('error', 'realtime auth denied: '.$reason);
        api_response(FALSE, '접속 인증에 실패했습니다.', array(), 403);
    }
}
