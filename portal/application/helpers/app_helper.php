<?php
/**
 * 파일 위치: application/helpers/app_helper.php
 * 역할: 공통 응답 형식과 유틸리티 함수
 */
defined('BASEPATH') OR exit('No direct script access allowed');

if ( ! function_exists('api_response'))
{
    /**
     * 공통 JSON 응답 { result, message, data }
     */
    function api_response($result, $message = '', $data = array(), $http_status = 200)
    {
        $CI =& get_instance();
        $CI->output
            ->set_status_header($http_status)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode(array(
                'result'  => (bool) $result,
                'message' => (string) $message,
                // 빈 배열은 [] 로 나가 런처(C#)가 객체로 읽지 못하므로 항상 {} 로 보낸다.
                'data'    => ($data === NULL || $data === array()) ? new stdClass() : $data,
            ), JSON_UNESCAPED_UNICODE));
    }
}

if ( ! function_exists('json_input'))
{
    /**
     * JSON 본문과 폼 전송을 모두 허용하는 입력 파서
     */
    function json_input($key = NULL, $default = NULL)
    {
        static $parsed = NULL;

        if ($parsed === NULL)
        {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw, TRUE);
            $parsed = is_array($decoded) ? $decoded : $_POST;
        }

        if ($key === NULL)
        {
            return $parsed;
        }

        return isset($parsed[$key]) ? $parsed[$key] : $default;
    }
}

if ( ! function_exists('random_digits'))
{
    /**
     * 암호학적으로 안전한 숫자 문자열 생성
     */
    function random_digits($length)
    {
        $out = '';
        for ($i = 0; $i < $length; $i++)
        {
            $out .= (string) random_int(0, 9);
        }
        return $out;
    }
}

if ( ! function_exists('relay_viewer_ws_url'))
{
    /**
     * 상담원 뷰어가 붙을 relay 웹소켓 주소.
     * RELAY_VIEWER_WS_URL=auto 이면 접속한 포털 주소 기준 ws(s)://<호스트>/relay-viewer (포털 경유)를 쓴다.
     * http(8099) 와 https(Cloudflare Tunnel) 어느 쪽으로 들어와도 같은 출처로 붙게 하려는 것이다.
     */
    function relay_viewer_ws_url()
    {
        $configured = (string) env('RELAY_VIEWER_WS_URL', 'wss://localhost:8443/viewer');

        if ($configured !== 'auto')
        {
            return $configured;
        }

        $base   = base_url();
        $scheme = parse_url($base, PHP_URL_SCHEME) === 'https' ? 'wss' : 'ws';
        $port   = parse_url($base, PHP_URL_PORT);

        return $scheme.'://'.parse_url($base, PHP_URL_HOST).($port ? ':'.$port : '').'/relay-viewer';
    }
}

if ( ! function_exists('customer_url'))
{
    /**
     * 고객에게 전달하는 주소(접속 페이지, 만족도 조사).
     * 상담원이 localhost 나 내부 IP 로 콘솔을 열어도 고객에게는 공개 주소가 나가도록
     * .env 의 PUBLIC_BASE_URL 을 우선 쓰고, 비어 있으면 접속한 주소(base_url)를 쓴다.
     */
    function customer_url($path = '')
    {
        $public = trim((string) env('PUBLIC_BASE_URL', ''));

        if ($public === '')
        {
            return base_url($path);
        }

        return rtrim($public, '/').'/'.ltrim((string) $path, '/');
    }
}

if ( ! function_exists('check_uploaded_image'))
{
    /**
     * 올린 이미지 파일을 검사한다. 형식은 확장자가 아니라 파일 내용으로 판별하고 SVG 는 받지 않는다.
     * 조직 로고와 가이드 이미지가 같은 검사를 쓴다.
     *
     * @param array|null $file      $_FILES 항목
     * @param int        $max_bytes 최대 크기
     * @return string|array 확장자(png|jpg|webp), 또는 array('error' => 사유)
     */
    function check_uploaded_image($file, $max_bytes)
    {
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name']))
        {
            return array('error' => '이미지 파일을 선택해 주세요.');
        }

        if ($file['size'] > $max_bytes)
        {
            return array('error' => '이미지는 '.round($max_bytes / 1048576, 1).'MB 이하로 올려 주세요.');
        }

        $allowed = array('image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp');
        $finfo   = new finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($file['tmp_name']);

        if ( ! isset($allowed[$mime]) || @getimagesize($file['tmp_name']) === FALSE)
        {
            return array('error' => 'PNG, JPG, WEBP 이미지만 올릴 수 있습니다.');
        }

        return $allowed[$mime];
    }
}

if ( ! function_exists('guide_image_url'))
{
    /**
     * 가이드 이미지 블록의 화면 주소. 외부 https 주소는 그대로, 이 조직에 올린 파일은 포털 주소로 만든다.
     * 출력할 때는 html_escape() 를 함께 쓴다.
     */
    function guide_image_url($src)
    {
        return preg_match('#^https://#i', (string) $src) ? (string) $src : base_url($src);
    }
}

if ( ! function_exists('guide_text_html'))
{
    /**
     * 가이드 글 블록을 화면용 HTML 로 바꾼다. 먼저 전부 이스케이프하고 https 주소만 링크로 만든다.
     * 줄바꿈은 CSS(whitespace-pre-line)로 살린다. 관리자가 HTML 을 넣어도 글자로만 보인다.
     */
    function guide_text_html($text)
    {
        // 원문에서 주소를 먼저 나누고 조각마다 이스케이프한다. 이스케이프한 뒤에 찾으면 &quot; 같은 것이 주소에 섞인다.
        $parts = preg_split('#(https?://[^\s<>"\'`]+)#u', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html  = '';

        foreach ($parts as $index => $part)
        {
            if ($index % 2 === 0)
            {
                $html .= html_escape($part);
                continue;
            }

            // 문장 끝의 마침표, 괄호는 주소에서 뺀다.
            $url  = rtrim($part, '.,);:!?');
            $tail = substr($part, strlen($url));

            $html .= '<a class="text-brand-600 underline" href="'.html_escape($url).'" target="_blank" rel="noopener">'
                   . html_escape($url).'</a>'.html_escape($tail);
        }

        return $html;
    }
}

if ( ! function_exists('client_ip'))
{
    function client_ip()
    {
        $CI =& get_instance();
        return $CI->input->ip_address();
    }
}

if ( ! function_exists('format_duration'))
{
    /**
     * 초를 "12분 34초" 형태로 변환
     */
    function format_duration($seconds)
    {
        $seconds = (int) $seconds;
        if ($seconds <= 0)
        {
            return '-';
        }
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        return $m > 0 ? $m.'분 '.$s.'초' : $s.'초';
    }
}

if ( ! function_exists('status_label'))
{
    function status_label($status)
    {
        $map = array(
            'issued'    => '코드 발급',
            'verified'  => '고객 코드 확인',
            'waiting'   => '고객 연결 대기',
            'connected' => '원격 중',
            'ended'     => '종료',
            'expired'   => '만료',
            'canceled'  => '취소',
        );
        return isset($map[$status]) ? $map[$status] : $status;
    }
}
