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
                'data'    => $data === NULL ? new stdClass() : $data,
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

if ( ! function_exists('random_vnc_password'))
{
    /**
     * VNC 인증(DES)은 8바이트 고정이므로 정확히 8자를 생성한다.
     * 혼동하기 쉬운 문자(0/O, 1/l/I)는 제외한다.
     */
    function random_vnc_password()
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < RH_VNC_PASSWORD_LEN; $i++)
        {
            $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
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
