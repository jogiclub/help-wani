<?php
/**
 * 파일 위치: application/controllers/Console.php
 * 역할: 상담원 콘솔 화면과 콘솔용 AJAX API
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Console extends Agent_Controller {

    // ------------------------------------------------------------------
    // 화면
    // ------------------------------------------------------------------

    public function index()
    {
        $this->render('console/queue', array(
            'page_title' => '상담 대기열',
        ));
    }

    public function session($id)
    {
        $session = $this->session_model->get_by_id($id);

        if (empty($session) || (int) $session->org_id !== (int) $this->agent->org_id)
        {
            show_404();
            return;
        }

        $this->render('console/viewer', array(
            'page_title'  => '원격 화면',
            'session'     => $session,
            'notes'       => $this->session_model->list_notes($session->id),
            'relay_ws_url'=> env('RELAY_WS_URL', 'wss://localhost:8443/ws'),
        ));
    }

    public function history()
    {
        $filters = array(
            'keyword'   => $this->input->get('keyword', TRUE),
            'status'    => $this->input->get('status', TRUE),
            'date_from' => $this->input->get('date_from', TRUE),
            'date_to'   => $this->input->get('date_to', TRUE),
        );

        $this->render('console/history', array(
            'page_title' => '상담 이력',
            'filters'    => $filters,
            'sessions'   => $this->session_model->search_history($this->agent->org_id, $filters, 100),
        ));
    }

    // ------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------

    /**
     * POST /console/api/session/create
     */
    public function api_session_create()
    {
        try
        {
            $created = $this->session_model->create_session($this->agent->org_id, $this->agent->id);
        }
        catch (RuntimeException $e)
        {
            api_response(FALSE, $e->getMessage(), array(), 500);
            return;
        }

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'session_create', 'session_id='.$created['id'], client_ip());

        $org = $this->organization_model->get_by_id($this->agent->org_id);

        api_response(TRUE, '코드가 발급되었습니다.', array(
            'session_id'   => $created['id'],
            'code'         => $created['code'],
            'expires_at'   => $created['expires_at'],
            'expires_in'   => RH_CODE_TTL,
            'customer_url' => base_url($org->org_code),
        ));
    }

    /**
     * GET /console/api/session/list
     */
    public function api_session_list()
    {
        $this->session_model->expire_stale();

        $rows = $this->session_model->list_queue($this->agent->org_id);
        $now  = time();
        $out  = array();

        foreach ($rows as $row)
        {
            $out[] = array(
                'id'            => (int) $row->id,
                'code'          => $row->code,
                'status'        => $row->status,
                'status_label'  => status_label($row->status),
                'agent_name'    => $row->agent_name,
                'pc_name'       => $row->customer_pc_name,
                'customer_ip'   => $row->customer_ip,
                'remain_sec'    => max(0, strtotime($row->expires_at) - $now),
                'created_at'    => $row->created_at,
                'started_at'    => $row->started_at,
                'ended_at'      => $row->ended_at,
                'is_mine'       => ((int) $row->agent_id === (int) $this->agent->id),
            );
        }

        api_response(TRUE, '', array('sessions' => $out, 'server_time' => date('Y-m-d H:i:s')));
    }

    /**
     * GET /console/api/session/detail/{id}
     */
    public function api_session_detail($id)
    {
        $session = $this->require_own_session($id);

        api_response(TRUE, '', array(
            'id'           => (int) $session->id,
            'status'       => $session->status,
            'status_label' => status_label($session->status),
            'pc_name'      => $session->customer_pc_name,
            'customer_ip'  => $session->customer_ip,
            'customer_os'  => $session->customer_os,
            'started_at'   => $session->started_at,
            'ended_at'     => $session->ended_at,
            'end_reason'   => $session->end_reason,
        ));
    }

    /**
     * POST /console/api/session/viewer-token
     * noVNC 접속에 필요한 1회용 토큰과 접속 정보를 돌려준다.
     */
    public function api_viewer_token()
    {
        $session_id = (int) $this->input->post('session_id');
        $session    = $this->require_own_session($session_id);

        if ( ! in_array($session->status, array('waiting', 'connected'), TRUE))
        {
            api_response(FALSE, '고객이 아직 연결되지 않았습니다.', array('status' => $session->status), 409);
            return;
        }

        $token    = $this->session_model->issue_viewer_token($session->id);
        $password = $this->session_model->decrypt_password($session);

        if ($password === NULL)
        {
            api_response(FALSE, '세션 비밀번호를 복호화하지 못했습니다.', array(), 500);
            return;
        }

        $this->log_model->add($session->id, 'viewer_token_issued', '상담원 '.$this->agent->name, 'agent', client_ip());
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'viewer_token', 'session_id='.$session->id, client_ip());

        api_response(TRUE, '', array(
            'token'        => $token['token'],
            'expires_in'   => $token['expires_in'],
            'repeater_id'  => (string) $session->repeater_id,
            'vnc_password' => $password,
            'ws_url'       => env('RELAY_WS_URL', 'wss://localhost:8443/ws'),
        ));
    }

    /**
     * POST /console/api/session/end
     */
    public function api_session_end()
    {
        $session_id = (int) $this->input->post('session_id');
        $session    = $this->require_own_session($session_id);

        if (in_array($session->status, array('ended', 'expired', 'canceled'), TRUE))
        {
            api_response(TRUE, '이미 종료된 세션입니다.', array('status' => $session->status));
            return;
        }

        $status = ($session->status === 'issued') ? 'canceled' : 'ended';

        $this->session_model->set_status($session->id, $status, array(
            'end_reason' => 'agent_ended',
        ));

        $this->log_model->add($session->id, 'agent_ended', '상담원 '.$this->agent->name.' 종료', 'agent', client_ip());
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'session_end', 'session_id='.$session->id, client_ip());

        api_response(TRUE, '세션을 종료했습니다.', array('status' => $status));
    }

    /**
     * POST /console/api/session/note
     */
    public function api_session_note()
    {
        $session_id = (int) $this->input->post('session_id');
        $content    = trim((string) $this->input->post('content', TRUE));

        $session = $this->require_own_session($session_id);

        if ($content === '')
        {
            api_response(FALSE, '메모 내용을 입력해 주세요.', array(), 400);
            return;
        }

        $this->session_model->add_note($session->id, $this->agent->id, $content);

        api_response(TRUE, '메모를 저장했습니다.', array(
            'notes' => $this->session_model->list_notes($session->id),
        ));
    }

    /**
     * POST /console/api/session/log
     * 뷰어(noVNC) 측 이벤트를 세션 로그로 남긴다.
     */
    public function api_session_log()
    {
        $session_id = (int) $this->input->post('session_id');
        $event      = mb_substr((string) $this->input->post('event', TRUE), 0, 50);
        $detail     = mb_substr((string) $this->input->post('detail', TRUE), 0, 500);

        $session = $this->require_own_session($session_id);

        $allowed = array('viewer_connected', 'viewer_disconnected', 'viewer_auth_failed',
                         'viewer_reconnecting', 'viewer_clipboard', 'viewer_ctrl_alt_del');

        if ( ! in_array($event, $allowed, TRUE))
        {
            api_response(FALSE, '허용되지 않는 이벤트입니다.', array(), 400);
            return;
        }

        $this->log_model->add($session->id, $event, $detail, 'agent', client_ip());

        api_response(TRUE, '');
    }
}
