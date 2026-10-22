<?php
/**
 * 파일 위치: application/controllers/Customer.php
 * 역할: 고객 접속 페이지, 팔로업 메일의 상담 대화 이어 가기, 만족도 조사
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Customer extends MY_Controller {

    /**
     * GET /{org_code}
     */
    public function index($org_code = '', $resume = '')
    {
        $org = $this->organization_model->get_active_by_code($org_code);

        if (empty($org))
        {
            show_404();
            return;
        }

        // 고객 화면은 해당 조직이 정한 언어와 시간대를 따른다.
        $this->use_org_locale($org);

        $store_id = env('STORE_PRODUCT_ID', '');
        $this->load->model('guide_model');

        $this->load->helper('download');
        require_once APPPATH.'controllers/Download.php';

        $this->render('customer/landing', array(
            'page_title'      => $org->name.' 원격지원',
            'org'             => $org,
            'store_id'        => $store_id,
            'store_web_url'   => 'https://apps.microsoft.com/detail/'.$store_id,
            'store_app_url'   => 'ms-windows-store://pdp/?productid='.$store_id,
            'protocol_url'    => 'remotehelp://connect?org='.rawurlencode($org->org_code),
            'download_enabled'=> (bool) env('DIRECT_DOWNLOAD_ENABLED', FALSE),
            'download_url'    => base_url($org->org_code.'/download'),
            'download_info'   => Download::launcher_info(),
            // 조직이 상담 위젯을 켜 두었으면 이 페이지에서도 그 조직과 상담을 열 수 있게 한다.
            'widget_key'      => ((int) $org->widget_enabled === 1) ? (string) $org->widget_key : '',
            // 팔로업 메일로 왔으면 위젯이 그 대화를 펼친 채로 시작한다. 토큰 검증은 위젯 init 이 한다.
            'widget_resume'   => $resume,
            // 공개 가이드가 있으면 원격지원 전에 스스로 찾아볼 수 있게 링크를 둔다.
            'guide_count'     => $this->guide_model->count_published($org->id),
        ), 'layouts/customer');
    }

    /**
     * GET /{org_code}/chat/{token}
     * 팔로업 메일의 '대화 이어 가기' 주소(docs/widget-ops-plan.md 3-5). 고객 접속 페이지에 상담 위젯을 펼쳐 보인다.
     * 다른 기기에서 열어도 이어지도록 고객사 사이트가 아니라 이 페이지로 온다.
     */
    public function chat($org_code = '', $token = '')
    {
        $token = preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', (string) $token) ? $token : '';

        $this->index($org_code, $token);
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

        $this->use_org_locale($org);

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

            api_response(TRUE, lang_text('survey.thanks'), array());
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
