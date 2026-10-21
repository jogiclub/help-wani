<?php
/**
 * 파일 위치: application/controllers/Widget.php
 * 역할: 고객사 사이트에 삽입되는 상담 위젯의 공개 API (스니펫 배포, 메시지 송수신)
 *
 * 이 컨트롤러만 로그인 없이 외부 도메인에서 호출된다.
 * 보호 수단은 위젯 키, Origin 허용 목록, 방문자 토큰 세 가지다. (CLAUDE.md 3.9)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Widget extends MY_Controller {

    /** 대화창을 처음 열 때 가져오는 최근 메시지 수 */
    const HISTORY_LIMIT = 50;

    public function __construct()
    {
        parent::__construct();
        $this->load->model(array('visitor_model', 'chat_model'));
        $this->load->library('realtime');
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

        $visitor = $this->visitor_model->ensure($org->id, json_input('visitor_key'), array(
            'ip'         => client_ip(),
            'user_agent' => $this->input->user_agent(),
            'page_url'   => json_input('page_url'),
        ));

        $conversation = $this->chat_model->ensure_conversation($org->id, $visitor->id);
        $messages = $this->chat_model->list_messages($conversation->id, 0, self::HISTORY_LIMIT);

        api_response(TRUE, '', array(
            'visitor_key' => $visitor->visitor_key,
            'visitor'     => array('name' => $visitor->name, 'email' => $visitor->email),
            'config'      => array(
                'color'    => $org->widget_color,
                'position' => $org->widget_position,
                'org_name' => $org->name,
                'locale'   => $this->current_locale,
                'intl'     => $this->current_intl(),
                'timezone' => $this->current_timezone,
                'online'   => $this->chat_model->has_online_agent($org->id),
            ),
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

        $this->visitor_model->update_profile($visitor->id, json_input('name'), json_input('email'));

        $conversation = $this->chat_model->ensure_conversation($org->id, $visitor->id);

        if ($this->chat_model->is_flooding($conversation->id))
        {
            api_response(FALSE, lang_text('widget.flood'), array(), 429);
            return;
        }

        $message_id = $this->chat_model->add_message($conversation->id, 'visitor', $body);
        $this->suggest_guide($org, $conversation, $body);

        $messages = $this->present_messages(
            $this->chat_model->list_messages($conversation->id, $message_id - 1)
        );

        // 상담원 콘솔에 바로 띄운다. 실패해도 폴링이 따라잡는다.
        foreach ($messages as $message)
        {
            $this->realtime->publish_message($org->id, $conversation->id, $message);
        }

        $this->notify_if_offline($org, $conversation, $visitor, $body);

        api_response(TRUE, '', array('messages' => $messages));
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

        api_response(TRUE, '', array(
            'online'   => $this->chat_model->has_online_agent($org->id),
            'messages' => $this->present_messages(
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
     * 상담원이 모두 자리에 없으면 조직에 메일로 알린다.
     * 같은 대화에서 두 번 보내지 않으며, 메일이 실패해도 메시지 저장은 유지한다.
     */
    protected function notify_if_offline($org, $conversation, $visitor, $body)
    {
        if ($this->chat_model->has_online_agent($org->id))
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
                      'email', 'profile_hint', 'online', 'offline', 'agent', 'system',
                      'error', 'flood', 'empty', 'guide_suggest', 'guide_open');

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
