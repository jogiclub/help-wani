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
        $org = $this->organization_model->get_by_id($this->agent->org_id);

        $this->render('console/queue', array(
            'page_title'   => '원격지원',
            'use_grid'     => TRUE,
            'customer_url' => customer_url($org->org_code),
            // 화면에 다시 채울 검색 조건 원문(목록 조회는 api_session_list 가 허용 목록으로 거른다)
            'filters'      => array(
                'keyword'   => (string) $this->input->get('keyword', TRUE),
                'status'    => (string) $this->input->get('status', TRUE),
                'date_from' => (string) $this->input->get('date_from', TRUE),
                'date_to'   => (string) $this->input->get('date_to', TRUE),
            ),
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
            'relay_ws_url'=> relay_viewer_ws_url(),
        ));
    }

    /**
     * GET /console/history
     * 원격 이력은 원격지원 화면(/console)에 합쳤다. 예전 주소로 오면 검색 조건을 그대로 넘긴다.
     */
    public function history()
    {
        $query = (string) $this->input->server('QUERY_STRING');
        redirect('console'.($query !== '' ? '?'.$query : ''));
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
            'customer_url' => customer_url($org->org_code),
        ));
    }

    /**
     * GET /console/api/session/list
     */
    public function api_session_list()
    {
        $this->session_model->expire_stale();

        $rows = $this->session_model->search_history($this->agent->org_id, $this->list_filters(), 100);
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
                'duration'      => ($row->started_at && $row->ended_at)
                    ? max(0, strtotime($row->ended_at) - strtotime($row->started_at))
                    : 0,
                'survey_score'  => $row->survey_score ? (int) $row->survey_score : NULL,
                'created_at'    => $row->created_at,
                'started_at'    => $row->started_at,
                'ended_at'      => $row->ended_at,
                'is_mine'       => ((int) $row->agent_id === (int) $this->agent->id),
            );
        }

        api_response(TRUE, '', array('sessions' => $out, 'server_time' => date('Y-m-d H:i:s')));
    }

    /**
     * 원격지원 목록 검색 조건. 상태는 허용 목록으로 거르고,
     * 날짜는 조직 시간대의 하루를 UTC 범위로 바꾼다(저장 기준이 UTC, CLAUDE.md 3.4).
     */
    protected function list_filters()
    {
        $statuses = array('active', 'issued', 'verified', 'waiting', 'connected', 'ended', 'expired', 'canceled');
        $status   = (string) $this->input->get('status', TRUE);

        return array(
            'keyword'   => mb_substr(trim((string) $this->input->get('keyword', TRUE)), 0, 50),
            'status'    => in_array($status, $statuses, TRUE) ? $status : '',
            'date_from' => $this->local_date_to_utc($this->input->get('date_from', TRUE), '00:00:00'),
            'date_to'   => $this->local_date_to_utc($this->input->get('date_to', TRUE), '23:59:59'),
        );
    }

    protected function local_date_to_utc($date, $time)
    {
        if ( ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        {
            return '';
        }

        try
        {
            $local = new DateTime($date.' '.$time, new DateTimeZone($this->current_timezone));
        }
        catch (Exception $e)
        {
            return '';
        }

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
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
            'ws_url'     => relay_viewer_ws_url(),
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
        $this->load->model(array('chat_model', 'chat_hours_model', 'macro_model'));
        // chat_followup 은 설정 드로어가 이어 가기 주소 유효 기간(RESUME_TTL)을 보여 주려고 불러 둔다.
        $this->load->library(array('realtime', 'chat_followup'));

        // 위젯 설정 드로어는 조직 관리자에게만 보인다(저장 API 는 Admin 이 권한을 다시 확인한다).
        $is_admin = $this->agent->role === 'admin';
        $org = NULL;

        if ($is_admin)
        {
            $org = $this->organization_model->get_by_id($this->agent->org_id);

            // 설정을 처음 열 때 키가 없으면 바로 발급한다.
            if (empty($org->widget_key))
            {
                $this->organization_model->regenerate_widget_key($org->id);
                $org = $this->organization_model->get_by_id($org->id);
            }
        }

        $own_org = $org ?: $this->organization_model->get_by_id($this->agent->org_id);

        $this->render('console/chat', array(
            'page_title'    => '상담 대화',
            // 매크로 편집 드로어와 변수 값(docs/macro-plan.md)
            'macro_editor'  => $this->macro_model->editor_conf($this->agent),
            'chat_macro'    => array(
                'orgName'         => $own_org->name,
                'visitorFallback' => lang_text('macro.visitor_fallback', array(), $own_org->locale),
            ),
            // 운영 상태(docs/chat-operation-plan.md F3). 목록 폴링이 이어서 갱신한다.
            'availability'  => $this->chat_hours_model->console_view($own_org,
                $this->chat_hours_model->availability($own_org)),
            'my_status'     => $this->agent->chat_status,
            'notify'        => (int) $this->agent->chat_notify === 1,
            // 설정 드로어의 답변 속도(실제 기록) 미리보기. 위젯과 같은 기준(최근 7일, 운영 중 문의, 10건 이상)이다.
            'reply_preview' => $org ? $this->chat_model->median_reply_seconds($org->id,
                Chat_model::REPLY_WINDOW_DAYS, Chat_model::REPLY_MIN_SAMPLES) : NULL,
            'me'            => $this->agent,
            // 설정 드로어의 인사말 버튼과 팔로업 메일 안내(docs/widget-ops-plan.md)
            'greeting_buttons' => $org ? $this->chat_greeting_buttons($org) : array(),
            'mail_ready'    => (string) env('SMTP_HOST', '') !== '',
            'hours'         => $org ? $this->chat_hours_model->get_hours($org->id) : array(),
            'holidays'      => $org ? array_map(array($this->chat_hours_model, 'present_holiday'),
                $this->chat_hours_model->list_holidays($org->id)) : array(),
            'use_grid'      => TRUE,
            'is_admin'      => $is_admin,
            'org'           => $org,
            'snippet'       => $org ? $this->organization_model->widget_snippet($org) : '',
            // ?settings=widget 이면 설정 드로어를 연 채로 시작한다(예전 /admin/widget 주소).
            'open_settings' => $is_admin && $this->input->get('settings') === 'widget',
            'conversations' => $this->chat_model->list_for_org($this->agent->org_id, array('status' => 'open')),
            // 실시간 구독 정보. 비어 있으면 화면이 폴링으로만 동작한다.
            'realtime'      => $this->realtime->client_config(array(
                'role'   => 'agent',
                'org_id' => $this->agent->org_id,
            )),
        ));
    }

    protected function chat_greeting_buttons($org)
    {
        $this->load->library('chat_greeting');

        return $this->chat_greeting->buttons($org);
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
                'visitor_phone'=> $row->visitor_phone,
                'page_url'     => $row->page_url,
                'followup_sent_at' => $row->followup_sent_at,
                'agent_name'   => $row->agent_name,
                'assigned_agent_id' => $row->assigned_agent_id ? (int) $row->assigned_agent_id : NULL,
                'status'       => $row->status,
                'unread'       => (int) $row->unread_for_agent,
                'last_body'    => $row->last_body,
                'last_message_at' => $row->last_message_at,
                'created_at'   => $row->created_at,
                'session_id'   => $row->session_id ? (int) $row->session_id : NULL,
            );
        }

        $this->load->model('chat_hours_model');
        $org = $this->organization_model->get_by_id($this->agent->org_id);

        api_response(TRUE, '', array(
            'conversations' => $out,
            'unread_total'  => $this->chat_model->count_unread($this->agent->org_id),
            'availability'  => $this->chat_hours_model->console_view($org, $this->chat_hours_model->availability($org)),
        ));
    }

    /**
     * POST /console/api/chat/my_status  (status = available | away | off)
     * 상담원 개인 응대 상태. '자리 비움' / '응대 종료' 이면 고객에게 응대 가능 인원으로 세지 않는다(F3).
     */
    public function api_chat_my_status()
    {
        $this->load->model(array('chat_model', 'chat_hours_model'));

        $status = (string) $this->input->post('status', TRUE);

        if ( ! in_array($status, array('available', 'away', 'off'), TRUE))
        {
            api_response(FALSE, '상태 값이 올바르지 않습니다.', array(), 400);
            return;
        }

        $this->chat_model->set_agent_status($this->agent->id, $status);
        $this->chat_model->touch_agent($this->agent->id);

        $org = $this->organization_model->get_by_id($this->agent->org_id);
        $labels = array('available' => '응대 가능', 'away' => '자리 비움', 'off' => '응대 종료');

        api_response(TRUE, '내 상태: '.$labels[$status], array(
            'availability' => $this->chat_hours_model->console_view($org, $this->chat_hours_model->availability($org)),
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
        $out = array_map(array($this->chat_model, 'present'), $messages);

        api_response(TRUE, '', array(
            'conversation' => array(
                'id'         => (int) $conversation->id,
                'status'     => $conversation->status,
                'session_id' => $conversation->session_id ? (int) $conversation->session_id : NULL,
                'assigned_agent_id' => $conversation->assigned_agent_id ? (int) $conversation->assigned_agent_id : NULL,
            ),
            'messages' => $out,
        ));
    }

    /**
     * POST /console/api/chat/send  (mode = '' | join | take, macro_id = 매크로로 채운 글이면 그 id)
     *
     * 다른 상담원이 담당인 대화에 보내면 바로 보내지 않고 409 와 담당자 이름을 돌려준다(F7 중복 응대 방지).
     * 화면이 묻고 다시 보낸다: join 은 담당을 그대로 두고 함께 답하기, take 는 담당을 가져와서 보내기.
     */
    public function api_chat_send()
    {
        $conversation = $this->require_own_conversation($this->input->post('conversation_id'));
        $body = trim((string) $this->input->post('body', TRUE));
        $mode = (string) $this->input->post('mode', TRUE);
        $mode = in_array($mode, array('join', 'take'), TRUE) ? $mode : '';

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

        $owner = $conversation->assigned_agent_id ? (int) $conversation->assigned_agent_id : NULL;

        if ($owner !== NULL && $owner !== (int) $this->agent->id)
        {
            if ($mode === '')
            {
                $other = $this->agent_model->get_by_id($owner);
                api_response(FALSE, '다른 상담원이 응대 중인 대화입니다.', array(
                    'conflict'   => TRUE,
                    'agent_name' => $other ? $other->name : '',
                ), 409);
                return;
            }

            if ($mode === 'take')
            {
                $this->take_conversation($conversation, $owner);
            }
        }
        else
        {
            $this->chat_model->assign($conversation->id, $this->agent->id);
        }

        $message_id = $this->chat_model->add_message($conversation->id, 'agent', $body, $this->agent->id);
        $this->chat_model->mark_read($conversation->id);
        $this->chat_model->touch_agent($this->agent->id);

        $followup = $this->publish_new_messages($conversation, $message_id);

        // 매크로로 채운 글이면 사용 횟수를 올린다(docs/macro-plan.md M2). 쓸 수 없는 매크로면 조용히 넘긴다.
        $macro_id = (int) $this->input->post('macro_id');

        if ($macro_id > 0)
        {
            $this->load->model('macro_model');
            $this->macro_model->record_use($this->agent, $macro_id, $conversation->id, $message_id);
        }

        api_response(TRUE, '', array('followup' => $followup));
    }

    /**
     * POST /console/api/chat/assign  (action = take | release)
     * take: 담당을 가져온다. release: 담당을 비워 대기 중으로 돌린다(담당자 본인이나 조직 관리자만).
     */
    public function api_chat_assign()
    {
        $conversation = $this->require_own_conversation($this->input->post('conversation_id'));
        $action = (string) $this->input->post('action', TRUE);
        $owner = $conversation->assigned_agent_id ? (int) $conversation->assigned_agent_id : NULL;

        if ($action === 'take')
        {
            if ($owner === (int) $this->agent->id)
            {
                api_response(TRUE, '이미 내가 담당입니다.');
                return;
            }

            $this->take_conversation($conversation, $owner);
            api_response(TRUE, '담당을 가져왔습니다.');
            return;
        }

        if ($action === 'release')
        {
            if ($owner !== (int) $this->agent->id && $this->agent->role !== 'admin')
            {
                api_response(FALSE, '담당자 본인이나 조직 관리자만 대기 중으로 돌릴 수 있습니다.', array(), 403);
                return;
            }

            $this->chat_model->set_assignee($conversation->id, NULL);
            $this->log_model->audit($this->agent->org_id, $this->agent->id, 'chat_release',
                'conversation_id='.$conversation->id.' from='.($owner ?: '-'), client_ip());
            $this->publish_assignment($conversation, NULL, $owner);

            api_response(TRUE, '대기 중으로 돌렸습니다.');
            return;
        }

        api_response(FALSE, '요청 값이 올바르지 않습니다.', array(), 400);
    }

    /**
     * POST /console/api/chat/presence  (kind = viewing | typing | leave)
     * 이 대화를 보고 있거나 입력 중임을 다른 상담원에게 알린다. 저장하지 않고 조직 방에만 발행한다(CLAUDE.md 3.11, 3.16).
     */
    public function api_chat_presence()
    {
        $conversation = $this->require_own_conversation($this->input->post('conversation_id'));
        $kind = (string) $this->input->post('kind', TRUE);

        if ( ! in_array($kind, array('viewing', 'typing', 'leave'), TRUE))
        {
            api_response(FALSE, '요청 값이 올바르지 않습니다.', array(), 400);
            return;
        }

        $this->load->library('realtime');
        $this->realtime->publish_org($this->agent->org_id, array(
            'type'            => 'presence',
            'conversation_id' => (int) $conversation->id,
            'agent_id'        => (int) $this->agent->id,
            'agent_name'      => $this->agent->name,
            'kind'            => $kind,
        ));

        api_response(TRUE, '');
    }

    /**
     * POST /console/api/chat/notify  (enabled = 0 | 1)
     * 새 문의 브라우저 알림을 받을지. 브라우저 권한은 화면이 따로 묻는다.
     */
    public function api_chat_notify()
    {
        $this->load->model('chat_model');
        $enabled = $this->input->post('enabled') ? 1 : 0;

        $this->chat_model->set_agent_notify($this->agent->id, $enabled);

        api_response(TRUE, $enabled ? '새 문의 알림을 켰습니다.' : '새 문의 알림을 껐습니다.');
    }

    /**
     * 담당을 로그인 상담원으로 바꾸고 이전 담당자에게 알린다. 다른 사람 것을 가져오면 감사 로그에 남긴다.
     */
    protected function take_conversation($conversation, $previous)
    {
        $this->chat_model->set_assignee($conversation->id, $this->agent->id);

        if ($previous !== NULL)
        {
            $this->log_model->audit($this->agent->org_id, $this->agent->id, 'chat_take',
                'conversation_id='.$conversation->id.' from='.$previous, client_ip());
        }

        $this->publish_assignment($conversation, (int) $this->agent->id, $previous);
    }

    /**
     * 담당이 바뀌었음을 상담원들에게 알린다(조직 방만). 이전 담당자 화면은 이 이벤트로 안내를 띄운다.
     */
    protected function publish_assignment($conversation, $agent_id, $previous)
    {
        $this->load->library('realtime');
        $this->realtime->publish_org($conversation->org_id, array(
            'type'              => 'assignment',
            'conversation_id'   => (int) $conversation->id,
            'assigned_agent_id' => $agent_id,
            'previous_agent_id' => $previous,
            'agent_name'        => $agent_id === NULL ? '' : $this->agent->name,
            'by_agent_id'       => (int) $this->agent->id,
            'by_agent_name'     => $this->agent->name,
        ));
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
        $customer_url = customer_url($org->org_code);

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

    // ------------------------------------------------------------------
    // 상담 중 가이드 추천 (docs/guide-plan.md 6-1)
    // ------------------------------------------------------------------

    /**
     * GET /console/api/guide/suggest/{conversation_id}
     * 방문자의 최근 메시지 3개로 공개 가이드를 찾는다. AI 를 부르지 않는다(Guide_matcher).
     */
    public function api_guide_suggest($conversation_id)
    {
        $conversation = $this->require_own_conversation($conversation_id);
        $this->load->library('guide_matcher');
        $this->load->model('guide_model');

        $sent = array();

        foreach ($this->chat_model->guide_cards($conversation->id) as $card)
        {
            $sent[$card['article_id']] = TRUE;
        }

        $text = $this->chat_model->recent_visitor_text($conversation->id, 3);
        $items = $this->guide_matcher->match($this->agent->org_id, $text, 'console', 3);

        api_response(TRUE, '', array('items' => $this->present_guides($items, $sent)));
    }

    /**
     * GET /console/api/guide/search?q=&conversation_id=
     */
    public function api_guide_search()
    {
        $this->load->library('guide_matcher');
        $this->load->model(array('guide_model', 'chat_model'));

        $q = mb_substr((string) $this->input->get('q'), 0, 200);
        $items = $this->guide_matcher->match($this->agent->org_id, $q, 'search', 10);
        $sent = array();
        $conversation = $this->chat_model->get_conversation($this->input->get('conversation_id'));

        if ($conversation && (int) $conversation->org_id === (int) $this->agent->org_id)
        {
            foreach ($this->chat_model->guide_cards($conversation->id) as $card)
            {
                $sent[$card['article_id']] = TRUE;
            }
        }

        api_response(TRUE, '', array('items' => $this->present_guides($items, $sent)));
    }

    /**
     * POST /console/api/chat/send_guide (conversation_id, article_id)
     * 가이드 카드를 상담원 메시지로 저장하고 발행한다. 본문에도 제목과 주소를 넣어 카드를 못 그리는 화면에서도 읽힌다.
     */
    public function api_chat_send_guide()
    {
        $conversation = $this->require_own_conversation($this->input->post('conversation_id'));
        $this->load->model('guide_model');

        if ($conversation->status !== 'open')
        {
            api_response(FALSE, '이미 종료된 대화입니다.', array(), 409);
            return;
        }

        $article = $this->guide_model->get_article($this->agent->org_id, $this->input->post('article_id'));

        if (empty($article) || $article->status !== 'published')
        {
            api_response(FALSE, '공개된 가이드만 보낼 수 있습니다.', array(), 404);
            return;
        }

        $org  = $this->organization_model->get_by_id($this->agent->org_id);
        $card = $this->guide_model->card($org, $article);

        $this->chat_model->assign($conversation->id, $this->agent->id);
        $message_id = $this->chat_model->add_message($conversation->id, 'agent',
            $card['title']."\n".$card['url'], $this->agent->id, $card);
        $this->chat_model->mark_read($conversation->id);
        $this->chat_model->touch_agent($this->agent->id);

        // 저장한 뒤 반드시 발행한다(CLAUDE.md 3.11). 빠지면 방문자 화면이 폴링 간격만큼 늦는다.
        $followup = $this->publish_new_messages($conversation, $message_id);

        api_response(TRUE, '가이드를 보냈습니다.', array('followup' => $followup));
    }

    protected function present_guides($items, $sent)
    {
        $org = $this->organization_model->get_by_id($this->agent->org_id);
        $out = array();

        foreach ($items as $item)
        {
            $out[] = array(
                'id'       => $item['id'],
                'title'    => $item['title'],
                'subtitle' => $item['subtitle'],
                'score'    => $item['score'],
                'url'      => $this->guide_model->public_url($org, $item),
                'sent'     => isset($sent[$item['id']]),
            );
        }

        return $out;
    }

    /**
     * 방금 저장한 메시지를 구독자(방문자 위젯, 다른 상담원)에게 전달한다.
     * 실패해도 화면은 폴링으로 따라잡으므로 응답을 막지 않는다.
     */
    protected function publish_new_messages($conversation, $from_message_id)
    {
        $this->load->library('realtime');

        $agent_body = NULL;

        foreach ($this->chat_model->list_messages($conversation->id, $from_message_id - 1) as $message)
        {
            $this->realtime->publish_message($conversation->org_id, $conversation->id,
                $this->chat_model->present($message));

            if ($message->sender_type === 'agent')
            {
                $agent_body = $message->body;
            }
        }

        // 고객이 대화창을 떠났으면 상담원 답장을 이메일로 알린다(docs/widget-ops-plan.md 3-4).
        // 상담원 답장을 저장하는 곳은 모두 이 함수를 거치므로 여기서 한 번만 판단한다.
        if ($agent_body === NULL)
        {
            return FALSE;
        }

        $this->load->library('chat_followup');

        return $this->chat_followup->after_agent_message($conversation, $agent_body);
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
            $this->output->_display();
            exit;
        }

        return $conversation;
    }
}
