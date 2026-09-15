<?php
/**
 * 파일 위치: application/controllers/Home.php
 * 역할: 서비스 소개(홍보) 페이지
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Home extends MY_Controller {

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
            'page_title'    => '기업용 원격지원 서비스',
            'pricing'       => $pricing,
            // 문의처는 .env 에서 설정한다. 값이 없으면 화면에 노출하지 않는다.
            'contact_email' => env('CONTACT_EMAIL', ''),
            'contact_phone' => env('CONTACT_PHONE', ''),
        ), 'layouts/public');
    }
}
