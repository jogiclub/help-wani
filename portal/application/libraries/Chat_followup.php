<?php
/**
 * 파일 위치: application/libraries/Chat_followup.php
 * 역할: 팔로업 알림 — 고객이 대화창을 떠난 뒤 상담원이 답하면 고객 이메일로 알리고, 대화를 이어 갈 주소(서명 토큰)를 만든다
 *       (docs/widget-ops-plan.md 3-4 ~ 3-6)
 *
 * 예약 작업(cron)이 없으므로 상담원 답장을 저장하는 요청 안에서 바로 판단한다(Console::publish_new_messages).
 * 이어 가기 주소는 customer_url() 로 만든다. 상담원이 내부 주소로 콘솔을 열어도 고객에게는 공개 주소가 간다(CLAUDE.md 3.13).
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Chat_followup {

    /** 이어 가기 주소 유효 기간(초) */
    const RESUME_TTL = 1209600;

    /** 메일에 넣는 답장 앞부분 길이 */
    const EXCERPT_MAX = 200;

    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * 상담원 답장을 저장한 뒤 부른다. 보냈으면 TRUE.
     * 메일이 실패해도 예외를 던지지 않는다. 답장 저장과 발행은 이미 끝났다.
     */
    public function after_agent_message($conversation, $message_body)
    {
        $this->CI->load->model(array('chat_model', 'visitor_model'));
        $this->CI->load->library('mailer');

        $org = $this->CI->organization_model->get_by_id($conversation->org_id);
        $visitor = $this->CI->visitor_model->get_by_id($conversation->visitor_id);

        if (empty($org) || empty($visitor) || (int) $org->chat_followup_email !== 1)
        {
            return FALSE;
        }

        if ( ! filter_var((string) $visitor->email, FILTER_VALIDATE_EMAIL)
            || ! $this->CI->mailer->is_configured() || $this->secret() === '')
        {
            return FALSE;
        }

        $previous = $this->CI->chat_model->claim_followup($conversation->id);

        if ($previous === FALSE)
        {
            return FALSE;
        }

        $body = trim((string) $message_body);

        if (mb_strlen($body) > self::EXCERPT_MAX)
        {
            $body = mb_substr($body, 0, self::EXCERPT_MAX).'...';
        }

        // 고객이 읽는 메일이므로 조직 언어로 쓴다(CLAUDE.md 3.2).
        $params = array(
            'org'  => $org->name,
            'name' => trim((string) $visitor->name) !== '' ? $visitor->name : lang_text('macro.visitor_fallback', array(), $org->locale),
            'body' => $body,
            'url'  => $this->resume_url($org, $conversation),
            'days' => (int) (self::RESUME_TTL / 86400),
        );

        $sent = $this->CI->mailer->send($visitor->email,
            lang_text('widget.followup.subject', $params, $org->locale),
            lang_text('widget.followup.body', $params, $org->locale),
            array('from_name' => $org->name, 'reply_to' => (string) $org->widget_offline_email));

        if ( ! $sent)
        {
            $this->CI->chat_model->release_followup($conversation->id, $previous);
            return FALSE;
        }

        return TRUE;
    }

    /**
     * 대화를 이어 갈 주소. {customer_url}/chat/{token}
     */
    public function resume_url($org, $conversation)
    {
        return customer_url($org->org_code.'/chat/'.$this->issue($conversation));
    }

    /**
     * 대화와 방문자를 담은 서명 토큰. visitor_key 자체는 메일에 넣지 않는다.
     */
    public function issue($conversation)
    {
        $body = $this->base64url(json_encode(array(
            'c' => (int) $conversation->id,
            'v' => (int) $conversation->visitor_id,
            'e' => time() + self::RESUME_TTL,
        )));

        return $body.'.'.$this->base64url(hash_hmac('sha256', $body, $this->secret(), TRUE));
    }

    /**
     * 토큰을 확인해 array('conversation_id', 'visitor_id') 를 돌려준다. 틀리거나 만료면 NULL.
     */
    public function verify($token)
    {
        $token = (string) $token;

        if ($this->secret() === '' || ! preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token) || strlen($token) > 300)
        {
            return NULL;
        }

        list($body, $signature) = explode('.', $token, 2);
        $expected = $this->base64url(hash_hmac('sha256', $body, $this->secret(), TRUE));

        if ( ! hash_equals($expected, $signature))
        {
            return NULL;
        }

        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/')), TRUE);

        if ( ! is_array($payload) || empty($payload['c']) || empty($payload['v']) || empty($payload['e']) || $payload['e'] < time())
        {
            return NULL;
        }

        return array('conversation_id' => (int) $payload['c'], 'visitor_id' => (int) $payload['v']);
    }

    /**
     * 서명 키. CHAT_RESUME_SECRET 이 없으면 실시간 토큰 키에서 용도를 나눠 만든다(같은 키를 그대로 쓰지 않는다).
     * 둘 다 없으면 빈 값이고, 그러면 이어 가기 주소를 만들 수 없어 팔로업 메일도 보내지 않는다.
     */
    protected function secret()
    {
        $secret = (string) env('CHAT_RESUME_SECRET', '');

        if ($secret !== '')
        {
            return $secret;
        }

        $base = (string) env('REALTIME_TOKEN_SECRET', '');

        return $base === '' ? '' : hash_hmac('sha256', 'chat-resume', $base);
    }

    protected function base64url($binary)
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
