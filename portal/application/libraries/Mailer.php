<?php
/**
 * 파일 위치: application/libraries/Mailer.php
 * 역할: SMTP 메일 발송 (상담 위젯 오프라인 알림 등)
 *
 * 접속 정보는 .env 에서 읽는다. SMTP_HOST 가 비어 있으면 발송을 건너뛴다.
 * 개발 환경에는 메일 서버가 없으므로 이 경우 조용히 통과시킨다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Mailer {

    protected $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * 설정이 있어야 보낼 수 있다.
     */
    public function is_configured()
    {
        return env('SMTP_HOST', '') !== '';
    }

    /**
     * 메일 한 통을 보낸다. 실패해도 예외를 던지지 않고 FALSE 를 돌려준다.
     * 호출한 쪽의 본래 작업(메시지 저장)이 메일 때문에 실패하면 안 된다.
     */
    public function send($to, $subject, $body)
    {
        if ( ! $this->is_configured() || ! filter_var($to, FILTER_VALIDATE_EMAIL))
        {
            return FALSE;
        }

        $this->CI->load->library('email', array(
            'protocol'    => 'smtp',
            'smtp_host'   => env('SMTP_HOST', ''),
            'smtp_port'   => (int) env('SMTP_PORT', 587),
            'smtp_user'   => env('SMTP_USER', ''),
            'smtp_pass'   => env('SMTP_PASS', ''),
            'smtp_crypto' => env('SMTP_CRYPTO', 'tls'),
            'smtp_timeout'=> 10,
            'mailtype'    => 'text',
            'charset'     => 'utf-8',
            'newline'     => "\r\n",
            'crlf'        => "\r\n",
        ));

        $this->CI->email->clear();
        $this->CI->email->from(env('SMTP_FROM', 'no-reply@localhost'), env('APP_NAME', 'RemoteHelp'));
        $this->CI->email->to($to);
        $this->CI->email->subject($subject);
        $this->CI->email->message($body);

        if ($this->CI->email->send(FALSE))
        {
            return TRUE;
        }

        log_message('error', '메일 발송 실패: '.$this->CI->email->print_debugger(array('headers')));

        return FALSE;
    }
}
