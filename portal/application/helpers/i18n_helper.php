<?php
/**
 * 파일 위치: application/helpers/i18n_helper.php
 * 역할: 서버 측 번역과 타임존 변환 (JS 와 같은 사전 파일을 쓴다)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

if ( ! function_exists('supported_locales'))
{
    /**
     * 지원 언어 목록
     */
    function supported_locales()
    {
        return array(
            'ko' => array('name' => '한국어',  'intl' => 'ko-KR'),
            'en' => array('name' => 'English', 'intl' => 'en-US'),
            'ja' => array('name' => '日本語',  'intl' => 'ja-JP'),
        );
    }
}

if ( ! function_exists('supported_countries'))
{
    /**
     * 가입 화면에서 고르는 국가. 국가를 고르면 언어와 타임존 기본값이 함께 정해진다.
     */
    function supported_countries()
    {
        return array(
            'KR' => array('name' => '대한민국',      'locale' => 'ko', 'timezone' => 'Asia/Seoul'),
            'JP' => array('name' => '일본',          'locale' => 'ja', 'timezone' => 'Asia/Tokyo'),
            'US' => array('name' => '미국',          'locale' => 'en', 'timezone' => 'America/New_York'),
            'SG' => array('name' => '싱가포르',      'locale' => 'en', 'timezone' => 'Asia/Singapore'),
            'VN' => array('name' => '베트남',        'locale' => 'en', 'timezone' => 'Asia/Ho_Chi_Minh'),
            'ID' => array('name' => '인도네시아',    'locale' => 'en', 'timezone' => 'Asia/Jakarta'),
            'AU' => array('name' => '호주',          'locale' => 'en', 'timezone' => 'Australia/Sydney'),
            'GB' => array('name' => '영국',          'locale' => 'en', 'timezone' => 'Europe/London'),
            'DE' => array('name' => '독일',          'locale' => 'en', 'timezone' => 'Europe/Berlin'),
        );
    }
}

if ( ! function_exists('supported_timezones'))
{
    /**
     * 선택 가능한 타임존. 값은 IANA 이름이다.
     */
    function supported_timezones()
    {
        return array(
            'Asia/Seoul'          => '서울 (UTC+9)',
            'Asia/Tokyo'          => '도쿄 (UTC+9)',
            'Asia/Shanghai'       => '상하이 (UTC+8)',
            'Asia/Singapore'      => '싱가포르 (UTC+8)',
            'Asia/Jakarta'        => '자카르타 (UTC+7)',
            'Asia/Ho_Chi_Minh'    => '호치민 (UTC+7)',
            'Asia/Kolkata'        => '콜카타 (UTC+5:30)',
            'Asia/Dubai'          => '두바이 (UTC+4)',
            'Europe/London'       => '런던 (UTC+0/+1)',
            'Europe/Berlin'       => '베를린 (UTC+1/+2)',
            'America/New_York'    => '뉴욕 (UTC-5/-4)',
            'America/Chicago'     => '시카고 (UTC-6/-5)',
            'America/Los_Angeles' => '로스앤젤레스 (UTC-8/-7)',
            'Australia/Sydney'    => '시드니 (UTC+10/+11)',
            'UTC'                 => 'UTC',
        );
    }
}

if ( ! function_exists('load_messages'))
{
    /**
     * 사전 파일을 읽는다. 없는 언어는 한국어로 되돌린다.
     */
    function load_messages($locale)
    {
        static $cache = array();

        if (isset($cache[$locale]))
        {
            return $cache[$locale];
        }

        $path = FCPATH.'assets/lang/'.preg_replace('/[^a-z_-]/', '', $locale).'.json';

        if ( ! is_file($path))
        {
            $path = FCPATH.'assets/lang/ko.json';
        }

        $decoded = json_decode(file_get_contents($path), TRUE);
        $cache[$locale] = is_array($decoded) ? $decoded : array();

        return $cache[$locale];
    }
}

if ( ! function_exists('lang_text'))
{
    /**
     * 번역 문구. 사전에 없으면 키를 그대로 돌려준다.
     */
    function lang_text($key, $params = array(), $locale = NULL)
    {
        $CI =& get_instance();
        $locale = $locale ?: ($CI->current_locale ?? 'ko');
        $messages = load_messages($locale);
        $text = isset($messages[$key]) ? $messages[$key] : $key;

        foreach ($params as $name => $value)
        {
            $text = str_replace('{'.$name.'}', $value, $text);
        }

        return $text;
    }
}

if ( ! function_exists('to_timezone'))
{
    /**
     * UTC 로 저장된 값을 조직 타임존 문자열로 바꾼다.
     */
    function to_timezone($utc_datetime, $timezone = 'Asia/Seoul', $format = 'Y-m-d H:i')
    {
        if (empty($utc_datetime))
        {
            return '-';
        }

        try
        {
            $date = new DateTime($utc_datetime, new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone($timezone));
            return $date->format($format);
        }
        catch (Exception $e)
        {
            return (string) $utc_datetime;
        }
    }
}

if ( ! function_exists('timezone_offset_label'))
{
    /**
     * 'Asia/Seoul' -> 'UTC+09:00' 형태의 현재 오프셋
     */
    function timezone_offset_label($timezone)
    {
        try
        {
            $tz = new DateTimeZone($timezone);
            $offset = $tz->getOffset(new DateTime('now', new DateTimeZone('UTC')));
            $sign = $offset < 0 ? '-' : '+';
            $offset = abs($offset);

            return sprintf('UTC%s%02d:%02d', $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
        }
        catch (Exception $e)
        {
            return 'UTC';
        }
    }
}
