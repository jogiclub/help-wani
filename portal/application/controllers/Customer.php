<?php
/**
 * 파일 위치: application/controllers/Customer.php
 * 역할: 고객 접속 페이지와 만족도 조사
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Customer extends MY_Controller {

    /**
     * GET /{org_code}
     */
    public function index($org_code = '')
    {
        $org = $this->organization_model->get_active_by_code($org_code);

        if (empty($org))
        {
            show_404();
            return;
        }

        $store_id = env('STORE_PRODUCT_ID', '');

        $this->load->helper('download');
        require_once APPPATH.'controllers/Download.php';

        $this->render('customer/landing', array(
            'page_title'      => $org->name.' 원격지원',
            'org'             => $org,
            'store_id'        => $store_id,
            'store_web_url'   => 'https://apps.microsoft.com/detail/'.$store_id,
            'store_app_url'   => 'ms-windows-store://pdp/?productid='.$store_id,
            'protocol_url'    => 'remotehelp://connect?org='.rawurlencode($org->org_code),
            'download_url'    => base_url($org->org_code.'/download'),
            'download_info'   => Download::launcher_info(),
        ), 'layouts/customer');
    }

    /**
     * GET|POST /{org_code}/survey/{session_id}
     */
    public function survey($org_code = '', $session_id = 0)
    {
        $org     = $this->organization_model->get_active_by_code($org_code);
        $session = $this->session_model->get_by_id($session_id);

        if (empty($org) || empty($session) || (int) $session->org_id !== (int) $org->id)
        {
            show_404();
            return;
        }

        $existing = $this->db->get_where('surveys', array('session_id' => $session->id))->row();

        if ($this->input->method() === 'post')
        {
            if ($existing)
            {
                api_response(FALSE, '이미 참여하신 만족도 조사입니다.', array(), 409);
                return;
            }

            $score = (int) $this->input->post('score');

            if ($score < 1 OR $score > 5)
            {
                api_response(FALSE, '별점을 선택해 주세요.', array(), 400);
                return;
            }

            $this->db->insert('surveys', array(
                'session_id' => $session->id,
                'score'      => $score,
                'comment'    => mb_substr((string) $this->input->post('comment', TRUE), 0, 1000),
            ));

            $this->log_model->add($session->id, 'survey_submitted', '점수 '.$score, 'customer', client_ip());

            api_response(TRUE, '소중한 의견 감사합니다.', array());
            return;
        }

        $this->render('customer/survey', array(
            'page_title' => '원격지원 만족도 조사',
            'org'        => $org,
            'session'    => $session,
            'existing'   => $existing,
        ), 'layouts/customer');
    }
}
