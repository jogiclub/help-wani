<?php
/**
 * 파일 위치: application/controllers/Widget.php
 * 역할: 고객사 사이트에 삽입되는 상담 위젯의 공개 API (스니펫 배포, 메시지 송수신)
 *
 * 이 컨트롤러만 로그인 없이 외부 도메인에서 호출된다.
 * 운영 시간, 휴일, 부재중 자동 응답은 docs/chat-operation-plan.md 1차(F1~F5), 답변 속도와 전화 버튼은 2차(F6, F8).
 * 인사말, 연락처 카드, 팔로업 메일의 대화 이어 가기는 docs/widget-ops-plan.md 2~3장.
 * 보호 수단은 위젯 키, Origin 허용 목록, 방문자 토큰 세 가지다. (CLAUDE.md 3.9)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Widget extends MY_Controller {

    /** 대화창을 처음 열 때 가져오는 최근 메시지 수 */
    const HISTORY_LIMIT = 50;

    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('visitor_model', 'chat_model', 'chat_hours_model'));
        $this->load->library(array('realtime', 'chat_greeting'));
    }

    // ------------------------------------------------------------------
    // 스니펫
    // ------------------------------------------------------------------

    /**
     * GET /widget.js
     *
     * 고객사 사이트가 <script src="https://.../widget.js" data-key="..."> 로 불러간다.
     * 포털 자산을 함께 내려보내지 않는다. 위젯은 혼자 돈다.
     */
    public function script()
    {
        $path = FCPATH.'assets/js/widget.js';

        if ( ! is_file($path))
        {
            show_404();
            return;
        }

        $this->output
            ->set_content_type('application/javascript', 'utf-8')
            ->set_header('Cache-Control: public, max-age=300')
            ->set_header('Access-Control-Allow-Origin: *')
            ->set_output(file_get_contents($path));
    }

    // ------------------------------------------------------------------
    // 대화 API
    // ------------------------------------------------------------------

    /**
     * POST /api/widget/init
     * 위젯을 열 때 설정, 문구, 최근 대화를 한 번에 내려준다.
     */
    public function init()
    {
        $org = $this->authorize_org();

        if (empty($org))
        {
            return;
        }

        $visitor = $this->visitor_model->ensure($org->id, $this->resume_visitor_key($org), array(
            'ip'         => client_ip(),
            'user_agent' => $this->input->user_agent(),
            'page_url'   => json_input('page_url'),
        ));

        $conversation = $this->chat_model->ensure_conversation($org->id, $visitor->id);
        $messages = $this->chat_model->list_messages($conversation->id, 0, self::HISTORY_LIMIT);
        $availability = $this->chat_hours_model->availability($org);
        $talking = $this->chat_model->has_visitor_message($conversation->id);

        // 운영 시간 외 '숨기기' 여도 이미 대화 중인 방문자에게는 보인다(답변을 못 보게 되면 안 된다).
        if ($availability['state'] === 'closed' && $org->chat_offhours_mode === 'hide' && ! $talking)
        {
            api_response(TRUE, '', array('hidden' => TRUE));
            return;
        }

        api_response(TRUE, '', array(
            'visitor_key' => $visitor->visitor_key,
            'visitor'     => array('name' => $visitor->name, 'email' => $visitor->email, 'phone' => $visitor->phone),
            // 인사말은 대화를 시작하기 전에만 화면에 그린다. 시작하면 첫 메시지 앞에 저장된 것이 보인다.
            'greeting'    => $talking ? NULL : $this->chat_greeting->render($org, $visitor),
            'contact'     => $this->contact_state($org, $visitor, $conversation, $talking),
            'config'      => array(
                'color'    => $org->widget_color,
                'position' => $org->widget_position,
                'org_name' => $org->name,
                'locale'   => $this->current_locale,
                'intl'     => $this->current_intl(),
                'timezone' => $this->current_timezone,
                'online'   => $availability['online'],
                'hours'    => $this->chat_hours_model->summary($org),
            ),
            'availability' => $this->present_availability($org, $availability, $talking),
            'texts'       => $this->widget_texts(),
            // 실시간 구독 정보. 비어 있으면 위젯이 폴링으로만 동작한다.
            'realtime'    => $this->realtime->client_config(array(
                'role'            => 'visitor',
                'org_id'          => $org->id,
                'conversation_id' => $conversation->id,
            )),
            'messages'    => $this->present_messages($messages),
        ));
    }

    /**
     * POST /api/widget/send
     */
    public function send()
    {
        $org = $this->authorize_org();

        if (empty($org))
        {
            return;
        }

        $visitor = $this->visitor_model->get_by_key($org->id, json_input('visitor_key'));

        if (empty($visitor))
        {
            api_response(FALSE, lang_text('widget.error'), array('reset' => TRUE), 401);
            return;
        }

        $body = trim((string) json_input('body', ''));

        if ($body === '')
        {
            api_response(FALSE, lang_text('widget.error'), array(), 400);
            return;
        }

        // 예전 위젯(위쪽 이름/이메일 칸)이 캐시에 남아 있을 수 있어 그대로 받는다. 지금 위젯은 /contact 로 보낸다.
        if (json_input('name') !== NULL || json_input('email') !== NULL)
        {
            $this->visitor_model->update_profile($visitor->id, json_input('name'), json_input('email'));
            $visitor = $this->visitor_model->get_by_id($visitor->id);
        }

        $conversation = $this->chat_model->ensure_conversation($org->id, $visitor->id);
        $availability = $this->chat_hours_model->availability($org);
        $talking = $this->chat_model->has_visitor_message($conversation->id);

        // '안내만' / '숨기기' 는 운영 시간 외에 새 문의를 받지 않는다. 화면만 막지 않고 서버에서도 막는다.
        if ( ! $this->accepting($org, $availability, $talking))
        {
            api_response(FALSE, lang_text('widget.closed_reject'), array(), 409);
            return;
        }

        // '연락처를 남긴 고객에게만' 이면 연락처 없이 새 문의를 시작할 수 없다. 이미 대화 중이면 막지 않는다.
        if ( ! $talking && $this->contact_blocking($org, $visitor))
        {
            api_response(FALSE, lang_text('widget.contact_required'), array(
                'contact' => $this->contact_state($org, $visitor, $conversation, FALSE),
            ), 409);
            return;
        }

        if ($this->chat_model->is_flooding($conversation->id))
        {
            api_response(FALSE, lang_text('widget.flood'), array(), 429);
            return;
        }

        // 첫 메시지면 그 앞에 방문자가 본 인사말을 남긴다. 상담원도 어떤 안내를 보고 말을 걸었는지 알 수 있다.
        $greeting = $talking ? NULL : $this->chat_greeting->render($org, $visitor);
        $first_id = NULL;

        if ($greeting !== NULL)
        {
            $first_id = $this->chat_model->add_message($conversation->id, 'system', $greeting['body'], NULL,
                array('type' => 'greeting', 'buttons' => $greeting['buttons']));
        }

        $message_id = $this->chat_model->add_message($conversation->id, 'visitor', $body);
        $first_id = $first_id ?: $message_id;
        $this->chat_model->touch_visitor_seen($conversation->id);
        $this->chat_model->mark_received($conversation->id, $availability['state'] !== 'closed');
        $this->suggest_guide($org, $conversation, $body);
        $this->auto_reply($org, $conversation, $availability);

        $messages = $this->present_messages(
            $this->chat_model->list_messages($conversation->id, $first_id - 1)
        );

        // 상담원 콘솔에 바로 띄운다. 실패해도 폴링이 따라잡는다.
        foreach ($messages as $message)
        {
            $this->realtime->publish_message($org->id, $conversation->id, $message);
        }

        $this->notify_if_offline($org, $conversation, $visitor, $body, $availability);

        api_response(TRUE, '', array(
            'messages'     => $messages,
            'availability' => $this->present_availability($org, $availability, TRUE),
            'contact'      => $this->contact_state($org, $visitor, $this->chat_model->get_conversation($conversation->id), TRUE),
        ));
    }

    /**
     * POST /api/widget/contact  (name, email, phone_country, phone | skip=1)
     * 연락처 카드 제출. 묻지 않는 항목은 버리고, 저장하면 이 대화에서 다시 묻지 않는다.
     */
    public function contact()
    {
        $org = $this->authorize_org();

        if (empty($org))
        {
            return;
        }

        $visitor = $this->visitor_model->get_by_key($org->id, json_input('visitor_key'));

        if (empty($visitor))
        {
            api_response(FALSE, lang_text('widget.error'), array('reset' => TRUE), 401);
            return;
        }

        $conversation = $this->chat_model->ensure_conversation($org->id, $visitor->id);
        $talking = $this->chat_model->has_visitor_message($conversation->id);
        $required = (int) $org->chat_contact_required === 1 && ! $talking;

        if (json_input('skip'))
        {
            if ($required && $this->contact_blocking($org, $visitor))
            {
                api_response(FALSE, lang_text('widget.contact_required'), array(), 409);
                return;
            }

            $this->chat_model->mark_contact_asked($conversation->id);
        }
        else
        {
            $result = $this->visitor_model->clean_contact($org, array(
                'name'          => json_input('name'),
                'email'         => json_input('email'),
                'phone_country' => json_input('phone_country'),
                'phone'         => json_input('phone'),
            ), $required);

            if (isset($result['error']))
            {
                api_response(FALSE, lang_text($result['error']), array('field' => $result['field']), 400);
                return;
            }

            $this->visitor_model->save_contact($visitor->id, $result['ok']);
            $this->chat_model->mark_contact_asked($conversation->id);
        }

        $this->chat_model->touch_visitor_seen($conversation->id);
        $visitor = $this->visitor_model->get_by_id($visitor->id);

        api_response(TRUE, json_input('skip') ? '' : lang_text('widget.contact_saved'), array(
            'visitor' => array('name' => $visitor->name, 'email' => $visitor->email, 'phone' => $visitor->phone),
            'contact' => $this->contact_state($org, $visitor, $this->chat_model->get_conversation($conversation->id), $talking),
        ));
    }

    /**
     * GET /api/widget/poll?key=&visitor_key=&after_id=
     * 대화창이 열려 있는 동안에만 호출된다.
     */
    public function poll()
    {
        $org = $this->authorize_org('get');

        if (empty($org))
        {
            return;
        }

        $visitor = $this->visitor_model->get_by_key($org->id, $this->input->get('visitor_key'));

        if (empty($visitor))
        {
            api_response(FALSE, '', array('reset' => TRUE), 401);
            return;
        }

        $conversation = $this->chat_model->ensure_conversation($org->id, $visitor->id);
        $after_id = (int) $this->input->get('after_id');
        $availability = $this->chat_hours_model->availability($org);

        // 위젯은 대화창을 펼치고 탭이 보일 때만 폴링한다. 이 시각으로 자리 비움을 판단한다(팔로업 메일).
        $this->chat_model->touch_visitor_seen($conversation->id);

        api_response(TRUE, '', array(
            'online'       => $availability['online'],
            'availability' => $this->present_availability($org, $availability,
                $this->chat_model->has_visitor_message($conversation->id)),
            'messages'     => $this->present_messages(
                $this->chat_model->list_messages($conversation->id, $after_id)
            ),
        ));
    }

    // ------------------------------------------------------------------
    // 내부
    // ------------------------------------------------------------------

    /**
     * 위젯 키와 Origin 을 확인하고 조직을 돌려준다.
     * 실패하면 응답을 직접 내보내고 NULL 을 돌려준다.
     */
    protected function authorize_org($method = 'post')
    {
        $key = $method === 'get' ? $this->input->get('key') : json_input('key');
        $org = $this->organization_model->get_by_widget_key($key);

        if (empty($org))
        {
            // 키가 틀렸을 때 조직 존재 여부를 알려주지 않는다.
            api_response(FALSE, '', array(), 404);
            return NULL;
        }

        $origin = (string) $this->input->get_request_header('Origin', TRUE);

        if ( ! $this->organization_model->widget_origin_allowed($org, $origin))
        {
            api_response(FALSE, '', array(), 403);
            return NULL;
        }

        if ($origin !== '')
        {
            $this->output
                ->set_header('Access-Control-Allow-Origin: '.$origin)
                ->set_header('Vary: Origin');
        }

        // 고객이 보는 화면이므로 조직이 정한 언어를 쓴다. (CLAUDE.md 3.2)
        $this->use_org_locale($org);

        return $org;
    }

    /**
     * 조직이 켜 두었으면 방문자 질문에 맞는 가이드를 시스템 카드로 하나 붙인다(docs/guide-plan.md 6-2).
     * 고객에게 바로 보이므로 기준 점수가 높고, 같은 글은 한 번만, 대화당 3번까지만 보낸다.
     * 저장만 하고 발행은 send() 가 방금 넣은 메시지들과 함께 한다.
     */
    protected function suggest_guide($org, $conversation, $body)
    {
        if ((int) $org->guide_autosuggest !== 1)
        {
            return;
        }

        $sent = array();
        $auto = 0;

        foreach ($this->chat_model->guide_cards($conversation->id) as $card)
        {
            $sent[] = $card['article_id'];
            $auto += $card['sender'] === 'system' ? 1 : 0;
        }

        if ($auto >= 3)
        {
            return;
        }

        $this->load->library('guide_matcher');
        $this->load->model('guide_model');

        $items = $this->guide_matcher->match($org->id, $body, 'widget', 1, $sent);

        if (empty($items))
        {
            return;
        }

        $card = $this->guide_model->card($org, $items[0]);
        $this->chat_model->add_message($conversation->id, 'system',
            lang_text('widget.guide_suggest')."\n".$card['title']."\n".$card['url'], NULL, $card);
    }

    /**
     * 운영 시간 외이거나 응대 가능한 상담원이 없으면 방문자에게 자동 응답을 남긴다(F4).
     * 같은 상황에서는 한 번만 보낸다(Chat_model::claim_auto_reply). 발행은 send() 가 함께 한다(CLAUDE.md 3.11).
     */
    protected function auto_reply($org, $conversation, $availability)
    {
        if ($availability['state'] === 'open')
        {
            return;
        }

        if ($availability['state'] === 'away')
        {
            $kind = 'away';
            $custom = $org->chat_msg_away;
        }
        elseif ($availability['reason'] === 'holiday')
        {
            $kind = 'holiday';
            $custom = $org->chat_msg_holiday;
        }
        else
        {
            $kind = 'closed';
            $custom = $org->chat_msg_closed;
        }

        if ( ! $this->chat_model->claim_auto_reply($conversation->id, $kind))
        {
            return;
        }

        $next = $this->chat_hours_model->format_moment($availability['next_open_at'], $org);
        $params = array(
            'next_open' => $next !== '' ? $next : lang_text('chat.next_open_unknown'),
            'holiday'   => (string) $availability['holiday'],
        );

        if (trim((string) $custom) !== '')
        {
            $body = strtr($custom, array('{next_open}' => $params['next_open'], '{holiday}' => $params['holiday']));
        }
        else
        {
            // 다음 운영 시각을 모르면(앞으로 2주 안에 운영일이 없음) 시각 없는 문구를 쓴다.
            $key = 'widget.auto.'.$kind.($kind !== 'away' && $next === '' ? '_nonext' : '');
            $body = lang_text($key, $params);
        }

        $this->chat_model->add_message($conversation->id, 'system', $body, NULL, array('type' => 'auto_reply', 'kind' => $kind));
    }

    /**
     * 팔로업 메일의 이어 가기 주소로 왔으면(resume 토큰) 그 대화의 방문자 키를 쓴다.
     * 토큰이 틀렸거나 다른 조직 것이면 평소처럼 브라우저에 저장된 키를 쓴다.
     */
    protected function resume_visitor_key($org)
    {
        $token = (string) json_input('resume', '');

        if ($token !== '')
        {
            $this->load->library('chat_followup');
            $claims = $this->chat_followup->verify($token);
            $conversation = $claims ? $this->chat_model->get_conversation($claims['conversation_id']) : NULL;

            if ( ! empty($conversation) && (int) $conversation->org_id === (int) $org->id
                && (int) $conversation->visitor_id === $claims['visitor_id'])
            {
                $visitor = $this->visitor_model->get_by_id($claims['visitor_id']);

                if ( ! empty($visitor))
                {
                    return $visitor->visitor_key;
                }
            }
        }

        return json_input('visitor_key');
    }

    /**
     * 연락처 카드 상태. 위젯은 이 값대로만 그린다.
     *   fields   조직이 묻는 항목(name/email/phone)
     *   required 연락처를 내야 대화를 시작할 수 있음(이미 대화 중이면 FALSE)
     *   ask      지금 카드를 띄울지 — 필수인데 비었거나, 대화를 시작했고 아직 묻지 않았는데 빈 항목이 있을 때
     */
    protected function contact_state($org, $visitor, $conversation, $talking)
    {
        $fields = $this->visitor_model->asked_fields($org);

        if (empty($fields))
        {
            return array('fields' => array(), 'required' => FALSE, 'ask' => FALSE);
        }

        $missing = $this->visitor_model->missing_fields($org, $visitor);
        $required = ! $talking && (int) $org->chat_contact_required === 1 && ! empty($missing);
        $ask = $required || ($talking && ! empty($missing) && empty($conversation->contact_asked_at));
        $notice = trim((string) $org->chat_contact_notice);

        $countries = array();

        foreach (country_dial_codes() as $code => $dial)
        {
            $countries[] = array('code' => $code, 'dial' => '+'.$dial);
        }

        return array(
            'fields'    => $fields,
            'required'  => $required,
            'ask'       => $ask,
            'notice'    => $notice !== '' ? $notice : lang_text('widget.contact_notice'),
            'countries' => $countries,
            'country'   => isset(country_dial_codes()[$org->country]) ? $org->country : 'KR',
        );
    }

    /**
     * '연락처를 남긴 고객에게만' 인데 묻는 항목이 비어 있으면 TRUE.
     */
    protected function contact_blocking($org, $visitor)
    {
        return (int) $org->chat_contact_required === 1
            && ! empty($this->visitor_model->asked_fields($org))
            && ! empty($this->visitor_model->missing_fields($org, $visitor));
    }

    /**
     * 운영 시간 외 '안내만' / '숨기기' 에서는 새 문의를 받지 않는다. 이미 대화 중인 방문자는 계속 쓴다.
     */
    protected function accepting($org, $availability, $talking)
    {
        return $availability['state'] !== 'closed' || $org->chat_offhours_mode === 'accept' || $talking;
    }

    /**
     * 위젯에 보낼 운영 상태. 다음 운영 시각은 조직 시간대로 바꾼 문구로 보낸다(CLAUDE.md 3.4).
     * 답변 속도는 상담 가능할 때만, 전화 번호는 조직이 켰고 (운영 시간에만이면) 운영 중일 때만 보낸다.
     */
    protected function present_availability($org, $availability, $talking)
    {
        $reply = $availability['state'] === 'open' ? $this->chat_model->reply_minutes($org) : NULL;
        $phone = (int) $org->chat_phone_button === 1 && trim((string) $org->phone) !== ''
            && ((int) $org->chat_phone_hours_only !== 1 || $availability['state'] !== 'closed');

        return array(
            'state'     => $availability['state'],
            'reason'    => $availability['reason'],
            'holiday'   => $availability['holiday'],
            'next_open' => $this->chat_hours_model->format_moment($availability['next_open_at'], $org),
            'accepting' => $this->accepting($org, $availability, $talking),
            'reply'     => $reply === NULL ? '' : lang_text('widget.reply.'.$reply),
            'phone'     => $phone ? trim($org->phone) : '',
        );
    }

    /**
     * 응대할 수 있는 상담원이 없으면(운영 시간 외 포함) 조직에 메일로 알린다.
     * 같은 대화에서 두 번 보내지 않으며, 메일이 실패해도 메시지 저장은 유지한다.
     */
    protected function notify_if_offline($org, $conversation, $visitor, $body, $availability)
    {
        if ($availability['state'] === 'open')
        {
            return;
        }

        $this->load->library('mailer');

        if ( ! $this->mailer->is_configured())
        {
            return;
        }

        if ( ! $this->chat_model->claim_offline_notice($conversation->id))
        {
            return;
        }

        $to = $org->widget_offline_email;

        if (empty($to))
        {
            $to = $this->agent_model->admin_email($org->id);
        }

        if (empty($to))
        {
            return;
        }

        $params = array(
            'org'     => $org->name,
            'name'    => $visitor->name ?: '-',
            'email'   => $visitor->email ?: '-',
            'page'    => $visitor->page_url ?: '-',
            'body'    => $body,
            'console' => base_url('console/chat'),
        );

        $this->mailer->send($to,
            lang_text('widget.mail.subject', $params, $org->locale),
            lang_text('widget.mail.body', $params, $org->locale));
    }

    /**
     * 위젯이 쓰는 문구. 위젯은 사전 파일을 직접 읽지 않는다.
     */
    protected function widget_texts()
    {
        $keys = array('title', 'subtitle', 'open', 'close', 'placeholder', 'send', 'name',
                      'email', 'online', 'offline', 'agent', 'system',
                      'error', 'flood', 'empty', 'guide_suggest', 'guide_open',
                      'status_open', 'status_away', 'status_closed', 'hours_label', 'next_open',
                      'closed_info', 'phone_label', 'phone_copy', 'phone_copied',
                      'phone', 'contact_title', 'contact_submit', 'contact_skip', 'contact_saved',
                      'contact_locked', 'contact_required');

        $out = array();

        foreach ($keys as $key)
        {
            $out[$key] = lang_text('widget.'.$key);
        }

        return $out;
    }

    /**
     * 화면에 필요한 값만 남긴다. 시각은 UTC 그대로 보내고 위젯이 변환한다. (CLAUDE.md 3.4)
     */
    protected function present_messages($messages)
    {
        return array_map(array($this->chat_model, 'present'), $messages);
    }

    protected function current_intl()
    {
        $locales = supported_locales();

        return isset($locales[$this->current_locale]) ? $locales[$this->current_locale]['intl'] : 'ko-KR';
    }
}
