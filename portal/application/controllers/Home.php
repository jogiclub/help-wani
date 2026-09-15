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

        $this->render('home/landing', array(
            'locales'       => supported_locales(),
            'page_title'    => '기업용 원격지원 서비스',
            'pricing'       => $pricing,
            // 문의처는 .env 에서 설정한다. 값이 없으면 화면에 노출하지 않는다.
            'contact_email' => env('CONTACT_EMAIL', ''),
            'contact_phone' => env('CONTACT_PHONE', ''),
        ), 'layouts/public');
    }
}
