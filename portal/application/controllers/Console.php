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
            'page_title' => '원격 대기열',
            'use_grid'   => TRUE,
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
            'relay_ws_url'=> env('RELAY_VIEWER_WS_URL', 'wss://localhost:8443/viewer'),
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
            'page_title' => '원격 이력',
            'use_grid'   => TRUE,
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
     * 중계 서버 접속에 필요한 1회용 토큰과 접속 주소를 돌려준다.
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

        $token = $this->session_model->issue_viewer_token($session->id);

        $this->log_model->add($session->id, 'viewer_token_issued', '상담원 '.$this->agent->name, 'agent', client_ip());
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'viewer_token', 'session_id='.$session->id, client_ip());

        api_response(TRUE, '', array(
            'token'      => $token['token'],
            'expires_in' => $token['expires_in'],
            'ws_url'     => env('RELAY_VIEWER_WS_URL', 'wss://localhost:8443/viewer'),
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
     * 뷰어 측 이벤트를 세션 로그로 남긴다.
     */
    public function api_session_log()
    {
        $session_id = (int) $this->input->post('session_id');
        $event      = mb_substr((string) $this->input->post('event', TRUE), 0, 50);
        $detail     = mb_substr((string) $this->input->post('detail', TRUE), 0, 500);

        $session = $this->require_own_session($session_id);

        $allowed = array('viewer_connected', 'viewer_disconnected', 'viewer_auth_failed',
                         'viewer_reconnecting', 'viewer_clipboard', 'viewer_ctrl_alt_del',
                         'viewer_quality_changed', 'viewer_peer_lost');

        if ( ! in_array($event, $allowed, TRUE))
        {
            api_response(FALSE, '허용되지 않는 이벤트입니다.', array(), 400);
            return;
        }

        $this->log_model->add($session->id, $event, $detail, 'agent', client_ip());

        api_response(TRUE, '');
    }

    // ------------------------------------------------------------------
    // 상담 위젯 대화
    // ------------------------------------------------------------------

    /**
     * GET /console/chat
     */
    public function chat()
    {
        $this->load->model('chat_model');
        $this->load->library('realtime');

        $this->render('console/chat', array(
            'page_title'    => '상담 대화',
            'use_grid'      => TRUE,
            'conversations' => $this->chat_model->list_for_org($this->agent->org_id, array('status' => 'open')),
            // 실시간 구독 정보. 비어 있으면 화면이 폴링으로만 동작한다.
            'realtime'      => $this->realtime->client_config(array(
                'role'   => 'agent',
                'org_id' => $this->agent->org_id,
            )),
        ));
    }

    /**
     * GET /console/api/chat/list
     * 목록 폴링. 이 호출이 상담원 접속 상태도 함께 갱신한다.
     */
    public function api_chat_list()
    {
        $this->load->model('chat_model');
        $this->chat_model->touch_agent($this->agent->id);

        $filters = array(
            'status'    => $this->input->get('status', TRUE),
            'keyword'   => $this->input->get('keyword', TRUE),
            'date_from' => $this->input->get('date_from', TRUE),
            'date_to'   => $this->input->get('date_to', TRUE),
        );

        $rows = $this->chat_model->list_for_org($this->agent->org_id, $filters);
        $out  = array();

        foreach ($rows as $row)
        {
            $out[] = array(
                'id'           => (int) $row->id,
                'visitor_name' => $row->visitor_name,
                'visitor_email'=> $row->visitor_email,
                'page_url'     => $row->page_url,
                'agent_name'   => $row->agent_name,
                'status'       => $row->status,
                'unread'       => (int) $row->unread_for_agent,
                'last_body'    => $row->last_body,
                'last_message_at' => $row->last_message_at,
                'created_at'   => $row->created_at,
                'session_id'   => $row->session_id ? (int) $row->session_id : NULL,
            );
        }

        api_response(TRUE, '', array(
            'conversations' => $out,
            'unread_total'  => $this->chat_model->count_unread($this->agent->org_id),
        ));
    }

    /**
     * GET /console/api/chat/messages/{id}?after_id=
     */
    public function api_chat_messages($id)
    {
        $conversation = $this->require_own_conversation($id);
        $after_id = (int) $this->input->get('after_id');

        // 대화를 연 시점에 미확인 표시를 지운다.
        if ($after_id === 0)
        {
            $this->chat_model->mark_read($conversation->id);
        }

        $messages = $this->chat_model->list_messages($conversation->id, $after_id);
        $out = array();

        foreach ($messages as $message)
        {
            $out[] = array(
                'id'         => (int) $message->id,
                'sender'     => $message->sender_type,
                'body'       => $message->body,
                'created_at' => $message->created_at,
            );
        }

        api_response(TRUE, '', array(
            'conversation' => array(
                'id'         => (int) $conversation->id,
                'status'     => $conversation->status,
                'session_id' => $conversation->session_id ? (int) $conversation->session_id : NULL,
            ),
            'messages' => $out,
        ));
    }

    /**
     * POST /console/api/chat/send
     */
    public function api_chat_send()
    {
        $conversation = $this->require_own_conversation($this->input->post('conversation_id'));
        $body = trim((string) $this->input->post('body', TRUE));

        if ($body === '')
        {
            api_response(FALSE, '보낼 내용을 입력해 주세요.', array(), 400);
            return;
        }

        if ($conversation->status !== 'open')
        {
            api_response(FALSE, '이미 종료된 대화입니다.', array(), 409);
            return;
        }

        $this->chat_model->assign($conversation->id, $this->agent->id);
        $message_id = $this->chat_model->add_message($conversation->id, 'agent', $body, $this->agent->id);
        $this->chat_model->mark_read($conversation->id);
        $this->chat_model->touch_agent($this->agent->id);

        $this->publish_new_messages($conversation, $message_id);

        api_response(TRUE, '');
    }

    /**
     * POST /console/api/chat/close
     */
    public function api_chat_close()
    {
        $conversation = $this->require_own_conversation($this->input->post('conversation_id'));

        $this->chat_model->set_status($conversation->id, 'closed');
        $this->log_model->audit($this->agent->org_id, $this->agent->id,
            'chat_close', 'conversation_id='.$conversation->id, client_ip());

        $this->load->library('realtime');
        $this->realtime->publish_conversation($conversation->org_id, $conversation->id,
            array('status' => 'closed'));

        api_response(TRUE, '대화를 종료했습니다.');
    }

    /**
     * POST /console/api/chat/remote
     * 대화 중인 방문자에게 원격지원 코드를 발급하고 안내 메시지를 대화에 남긴다.
     * 코드 발급 자체는 기존 세션 흐름(api_session_create)과 같은 것을 쓴다.
     */
    public function api_chat_remote()
    {
        $conversation = $this->require_own_conversation($this->input->post('conversation_id'));

        if ($conversation->status !== 'open')
        {
            api_response(FALSE, '이미 종료된 대화입니다.', array(), 409);
            return;
        }

        try
        {
            $created = $this->session_model->create_session($this->agent->org_id, $this->agent->id);
        }
        catch (RuntimeException $e)
        {
            api_response(FALSE, $e->getMessage(), array(), 500);
            return;
        }

        $org = $this->organization_model->get_by_id($this->agent->org_id);
        $customer_url = base_url($org->org_code);

        // 안내 문구는 방문자가 읽으므로 조직이 정한 언어로 만든다. (CLAUDE.md 3.2)
        $notice = lang_text('widget.remote_notice', array(
            'code' => $created['code'],
            'url'  => $customer_url,
        ), $org->locale);

        $this->chat_model->assign($conversation->id, $this->agent->id);
        $message_id = $this->chat_model->add_message($conversation->id, 'system', $notice, $this->agent->id);
        $this->chat_model->link_session($conversation->id, $created['id']);

        $this->publish_new_messages($conversation, $message_id);

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'chat_remote_start',
            'conversation_id='.$conversation->id.' session_id='.$created['id'], client_ip());
        $this->log_model->add($created['id'], 'session_create', '상담 대화에서 발급', 'agent', client_ip());

        api_response(TRUE, '코드가 발급되었습니다.', array(
            'session_id'   => $created['id'],
            'code'         => $created['code'],
            'expires_in'   => RH_CODE_TTL,
            'customer_url' => $customer_url,
        ));
    }

    /**
     * 방금 저장한 메시지를 구독자(방문자 위젯, 다른 상담원)에게 전달한다.
     * 실패해도 화면은 폴링으로 따라잡으므로 응답을 막지 않는다.
     */
    protected function publish_new_messages($conversation, $from_message_id)
    {
        $this->load->library('realtime');

        foreach ($this->chat_model->list_messages($conversation->id, $from_message_id - 1) as $message)
        {
            $this->realtime->publish_message($conversation->org_id, $conversation->id, array(
                'id'         => (int) $message->id,
                'sender'     => $message->sender_type,
                'body'       => $message->body,
                'created_at' => $message->created_at,
            ));
        }
    }

    /**
     * 대화가 로그인 상담원의 조직 소속인지 확인한다.
     */
    protected function require_own_conversation($conversation_id)
    {
        $this->load->model('chat_model');

        $conversation = $this->chat_model->get_conversation($conversation_id);

        if (empty($conversation) || (int) $conversation->org_id !== (int) $this->agent->org_id)
        {
            api_response(FALSE, '대화를 찾을 수 없습니다.', array(), 404);
            exit;
        }

        return $conversation;
    }
}
