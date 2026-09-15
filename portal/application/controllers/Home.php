<?php
/**
 * 파일 위치: application/controllers/Home.php
 * 역할: 서비스 소개(홍보) 페이지
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Home extends MY_Controller {

    /** 언어 선택을 기억하는 쿠키 이름 */
    const LOCALE_COOKIE = 'rh_locale';

    /**
     * 소개 페이지에서 쓰는 Material Symbols 아이콘.
     *
     * 여기에 적은 이름으로 폰트 서브셋을 요청하므로, 아이콘을 추가하면
     * 반드시 이 목록에도 넣어야 화면에 나온다. (전체 폰트 315KB -> 서브셋 약 5KB)
     * 아이콘 이름은 https://fonts.google.com/icons 에서 고른다.
     */
    protected function landing_icons()
    {
        return array(
            'value' => array(
                1 => 'cleaning_services',   // 설치 부담 없음
                2 => 'web',                 // 브라우저로 제어
                3 => 'vpn_lock',            // 443 포트 하나
                4 => 'language',            // 다양한 언어 지원
                5 => 'schedule',            // 시간대 지원
                6 => 'how_to_reg',          // 동의 없이는 시작 안 함
            ),
            'how' => array(
                1 => 'link',                // 전용 페이지 접속
                2 => 'download',            // 앱 실행
                3 => 'dialpad',             // 코드 입력 후 허용
            ),
            'flow' => array(
                1 => 'confirmation_number', // 코드 발급
                2 => 'keyboard',            // 코드 입력
                3 => 'check_circle',        // 허용 후 연결
            ),
            'features' => array(
                1 => 'screen_share',
                2 => 'dashboard',
                3 => 'storefront',
                4 => 'content_paste',
                5 => 'edit_note',
                6 => 'sentiment_satisfied',
                7 => 'tune',
                8 => 'devices',
                9 => 'translate',
            ),
            'security' => array(
                1 => 'lock',                // 전 구간 암호화
                2 => 'key',                 // 1회용 코드와 토큰
                3 => 'touch_app',           // 명시적 동의
                4 => 'visibility',          // 상시 표시와 즉시 종료
                5 => 'delete_forever',      // 잔류 없음
                6 => 'receipt_long',        // 기록
            ),
            'misc' => array(
                'phishing'  => 'gpp_maybe',
                'check'     => 'check_circle',
                'arrow'     => 'arrow_forward',
                'expand'    => 'expand_more',
                'mail'      => 'mail',
                'call'      => 'call',
                'language'  => 'language',
            ),
        );
    }

    /**
     * 폰트 서브셋 요청에 쓸 아이콘 이름 목록(중복 제거)
     */
    protected function flatten_icons($icons)
    {
        $names = array();

        array_walk_recursive($icons, function ($name) use (&$names) {
            $names[] = $name;
        });

        $names = array_unique($names);
        sort($names);

        return $names;
    }

    /**
     * GET /lang/{locale}
     * 방문자가 고른 언어를 1년간 기억하고 원래 보던 페이지로 돌아간다.
     */
    public function set_locale($locale = '')
    {
        $locales = supported_locales();

        if (isset($locales[$locale]))
        {
            $this->input->set_cookie(array(
                'name'   => self::LOCALE_COOKIE,
                'value'  => $locale,
                'expire' => 31536000,
                'path'   => '/',
                'httponly' => FALSE,
                'samesite' => 'Lax',
            ));
        }

        // 열린 화면으로 되돌린다. 외부 주소로는 보내지 않는다.
        $back = (string) $this->input->server('HTTP_REFERER');

        if ($back === '' || strpos($back, base_url()) !== 0)
        {
            $back = base_url();
        }

        redirect($back);
    }

    /**
     * GET /
     */
    public function index()
    {
        // 요금은 여기 한 곳에서만 관리한다. 값이 바뀌면 이 배열만 고치면 된다.
        $pricing = array(
            'accounts' => 5,
            'months'   => 12,
            'price'    => 220000,
        );

        $pricing['per_account_year']  = (int) round($pricing['price'] / $pricing['accounts']);
        $pricing['per_account_month'] = (int) round($pricing['price'] / $pricing['accounts'] / $pricing['months']);

        $icons = $this->landing_icons();

        $this->render('home/landing', array(
            'locales'       => supported_locales(),
            'icons'         => $icons,
            'icon_names'    => $this->flatten_icons($icons),
            'page_title'    => '기업용 원격지원 서비스',
            'pricing'       => $pricing,
            // 문의처는 .env 에서 설정한다. 값이 없으면 화면에 노출하지 않는다.
            'contact_email' => env('CONTACT_EMAIL', ''),
            'contact_phone' => env('CONTACT_PHONE', ''),
        ), 'layouts/public');
    }
}
