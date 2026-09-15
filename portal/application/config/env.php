<?php
/**
 * 파일 위치: application/config/env.php
 * 역할: 환경변수(.env / docker environment)를 읽는 공통 함수 정의
 */
defined('BASEPATH') OR exit('No direct script access allowed');

if ( ! function_exists('env'))
{
    /**
     * 환경변수를 읽는다. 값이 없으면 기본값을 돌려준다.
     */
    function env($key, $default = NULL)
    {
        $value = getenv($key);

        if ($value === FALSE OR $value === '')
        {
            return $default;
        }

        switch (strtolower($value))
        {
            case 'true':  return TRUE;
            case 'false': return FALSE;
            case 'null':  return NULL;
        }

        return $value;
    }
}
