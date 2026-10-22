<?php
/**
 * 파일 위치: application/libraries/Realtime.php
 * 역할: 상담 실시간 메시지 서버(realtime-msg)용 구독 토큰 발급/검증과 이벤트 발행
 *
 * 토큰은 서명만으로 검증할 수 있게 만든다(DB 를 쓰지 않는다).
 *   payload(base64url) . HMAC-SHA256(payload)
 * 서명 키는 포털만 가지며, realtime-msg 는 /api/realtime/auth 로 물어본다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Realtime {

    /** 토큰 유효 시간(초) */
    const TOKEN_TTL = 7200;

    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * 실시간 기능을 쓸 수 있는 상태인지 확인한다.
     * 서명 키가 없으면 토큰을 만들 수 없으므로 폴링으로만 동작한다.
     */
    public function is_enabled()
    {
        return $this->token_secret() !== '';
    }

    /**
     * 화면에 넘길 접속 정보. 쓸 수 없으면 빈 배열을 돌려준다.
     */
    public function client_config($claims)
    {
        if ( ! $this->is_enabled())
        {
            return array();
        }

        return array(
            'url'   => $this->ws_url(),
            'token' => $this->issue_token($claims),
        );
    }

    /**
     * 구독 토큰을 만든다.
     *
     * @param array $claims role(visitor|agent), org_id, conversation_id
     */
    public function issue_token($claims)
    {
        $payload = array(
            'role'   => isset($claims['role']) ? $claims['role'] : '',
            'org'    => isset($claims['org_id']) ? (int) $claims['org_id'] : 0,
            'conv'   => isset($claims['conversation_id']) ? (int) $claims['conversation_id'] : 0,
            'exp'    => time() + self::TOKEN_TTL,
        );

        $body = $this->base64url(json_encode($payload));

        return $body.'.'.$this->base64url(hash_hmac('sha256', $body, $this->token_secret(), TRUE));
    }

    /**
     * 토큰을 검증해 구독할 방 목록을 돌려준다. 실패하면 NULL 이다.
     */
    public function verify_token($token)
    {
        if ( ! $this->is_enabled() || strpos((string) $token, '.') === FALSE)
        {
            return NULL;
        }

        list($body, $signature) = explode('.', $token, 2);

        $expected = $this->base64url(hash_hmac('sha256', $body, $this->token_secret(), TRUE));

        if ( ! hash_equals($expected, $signature))
        {
            return NULL;
        }

        $payload = json_decode($this->base64url_decode($body), TRUE);

        if ( ! is_array($payload) || empty($payload['exp']) || $payload['exp'] < time())
        {
            return NULL;
        }

        $rooms = array();

        // 상담원은 조직 전체 알림을, 방문자는 자기 대화만 받는다.
        if ($payload['role'] === 'agent' && ! empty($payload['org']))
        {
            $rooms[] = 'org:'.(int) $payload['org'];
        }
        elseif ($payload['role'] === 'visitor' && ! empty($payload['conv']))
        {
            $rooms[] = 'conv:'.(int) $payload['conv'];
        }

        if (empty($rooms))
        {
            return NULL;
        }

        return array(
            'role'            => $payload['role'],
            'org_id'          => (int) $payload['org'],
            'conversation_id' => (int) $payload['conv'],
            'rooms'           => $rooms,
        );
    }

    /**
     * 대화에 생긴 일을 구독자에게 알린다.
     * 서버가 없거나 실패해도 예외를 던지지 않는다. 화면은 폴링으로 따라잡는다.
     */
    public function publish_message($org_id, $conversation_id, $message)
    {
        return $this->publish(
            array('org:'.(int) $org_id, 'conv:'.(int) $conversation_id),
            array(
                'type'            => 'message',
                'conversation_id' => (int) $conversation_id,
                'message'         => $message,
            ));
    }

    /**
     * 대화 상태가 바뀌었음을 알린다(종료, 원격 세션 연결 등).
     */
    public function publish_conversation($org_id, $conversation_id, $data = array())
    {
        return $this->publish(
            array('org:'.(int) $org_id, 'conv:'.(int) $conversation_id),
            array_merge(array(
                'type'            => 'conversation',
                'conversation_id' => (int) $conversation_id,
            ), $data));
    }

    /**
     * 상담원끼리만 알면 되는 일(담당 변경, 보는 중/입력 중)을 조직 방에만 보낸다.
     * 방문자 방(conv:)에 보내지 않으므로 상담원 이름이 고객 화면으로 새지 않는다.
     */
    public function publish_org($org_id, $event)
    {
        return $this->publish(array('org:'.(int) $org_id), $event);
    }

    // ------------------------------------------------------------------
    // 내부
    // ------------------------------------------------------------------

    protected function publish($rooms, $event)
    {
        $url = (string) env('REALTIME_PUBLISH_URL', '');

        if ($url === '')
        {
            return FALSE;
        }

        $payload = json_encode(array('rooms' => $rooms, 'event' => $event), JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => TRUE,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => TRUE,
            // 실시간 전달이 늦어도 화면 응답을 붙잡지 않는다.
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'X-Realtime-Secret: '.env('REALTIME_SHARED_SECRET', ''),
            ),
        ));

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === FALSE || $status !== 200)
        {
            log_message('error', '실시간 발행 실패: '.($error ?: 'HTTP '.$status));
            return FALSE;
        }

        return TRUE;
    }

    /**
     * 브라우저가 접속할 주소. 비어 있으면 지금 접속한 호스트의 REALTIME_PUBLIC_PORT 를 쓴다.
     * (base_url 과 같은 이유로 고정하지 않는다)
     * https 로 접속했으면(Cloudflare Tunnel) 다른 포트로 붙을 수 없으므로 포털 경유 /realtime-ws 를 쓴다.
     */
    protected function ws_url()
    {
        $configured = (string) env('REALTIME_WS_URL', '');

        if ($configured !== '')
        {
            return $configured;
        }

        $base = base_url();
        $host = parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);

        if (parse_url($base, PHP_URL_SCHEME) === 'https')
        {
            return 'wss://'.$host.($port ? ':'.$port : '').'/realtime-ws';
        }

        return 'ws://'.$host.':'.env('REALTIME_PUBLIC_PORT', '8091').'/ws';
    }

    protected function token_secret()
    {
        return (string) env('REALTIME_TOKEN_SECRET', '');
    }

    protected function base64url($binary)
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    protected function base64url_decode($text)
    {
        return base64_decode(strtr($text, '-_', '+/'));
    }
}
