<?php
/**
 * 파일 위치: application/controllers/Operator.php
 * 역할: 플랫폼 운영자 콘솔 (조직 가입 승인, 반려, 이용 중지)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Operator extends Operator_Controller {

    /**
     * 운영자 화면은 조직 소속 사용자 메뉴와 분리된 레이아웃을 쓴다.
     */
    protected function render($view, $data = array(), $layout = 'layouts/operator')
    {
        return parent::render($view, $data, $layout);
    }

    public function index()
    {
        $status = $this->input->get('status', TRUE);
        $allowed = array('pending', 'active', 'rejected', 'suspended');

        if ( ! in_array($status, $allowed, TRUE))
        {
            $status = NULL;
        }

        $this->render('operator/organizations', array(
            'page_title' => '조직 가입 관리',
            'use_grid'   => TRUE,
            'countries'  => supported_countries(),
            'locales'    => supported_locales(),
            'timezones'  => supported_timezones(),
            'filter'     => $status,
            'counts'     => $this->organization_model->count_by_status(),
            'orgs'       => $this->organization_model->list_for_review($status),
        ));
    }

    /**
     * GET /operator/audit
     * 전체 조직의 관리 행위 감사 로그
     */
    public function audit()
    {
        $this->render('operator/audit', array(
            'page_title' => '감사 로그',
            'use_grid'   => TRUE,
            'logs'       => $this->log_model->list_audit(1000),
        ));
    }

    /**
     * POST /operator/api/org_save
     * 조직 기본 정보 수정 (운영자 전용)
     */
    public function api_org_save()
    {
        $org = $this->require_org((int) $this->input->post('org_id'));

        $name     = trim((string) $this->input->post('name', TRUE));
        $org_code = strtolower(trim((string) $this->input->post('org_code', TRUE)));
        $biz_no   = trim((string) $this->input->post('biz_no', TRUE));
        $phone    = trim((string) $this->input->post('phone', TRUE));
        $plan     = trim((string) $this->input->post('plan', TRUE));

        if ($name === '')
        {
            api_response(FALSE, '조직명을 입력해 주세요.', array(), 400);
            return;
        }

        if ( ! preg_match('/^[a-z0-9_-]{3,32}$/', $org_code))
        {
            api_response(FALSE, '조직 주소는 영문 소문자, 숫자, -, _ 조합 3~32자여야 합니다.', array(), 400);
            return;
        }

        // 주소를 바꾸면 기존 안내 링크가 끊기므로 중복 검사를 확실히 한다.
        $duplicate = $this->organization_model->get_by_code($org_code);

        if ($duplicate && (int) $duplicate->id !== (int) $org->id)
        {
            api_response(FALSE, '이미 사용 중인 조직 주소입니다.', array(), 409);
            return;
        }

        // 국가/언어/시간대는 허용 목록 안의 값만 받는다.
        $countries = supported_countries();
        $locales   = supported_locales();
        $timezones = supported_timezones();

        $country  = (string) $this->input->post('country', TRUE);
        $locale   = (string) $this->input->post('locale', TRUE);
        $timezone = (string) $this->input->post('timezone', TRUE);

        $country  = isset($countries[$country]) ? $country : $org->country;
        $locale   = isset($locales[$locale]) ? $locale : $org->locale;
        $timezone = isset($timezones[$timezone]) ? $timezone : $org->timezone;

        $changes = array();

        foreach (array('name' => $name, 'org_code' => $org_code, 'biz_no' => $biz_no,
                       'phone' => $phone, 'plan' => $plan, 'country' => $country,
                       'locale' => $locale, 'timezone' => $timezone) as $field => $value)
        {
            if ((string) $org->{$field} !== (string) $value)
            {
                $changes[] = $field.': '.$org->{$field}.' -> '.$value;
            }
        }

        $this->organization_model->update($org->id, array(
            'name'     => $name,
            'org_code' => $org_code,
            'biz_no'   => $biz_no,
            'phone'    => $phone,
            'plan'     => $plan === '' ? 'basic' : $plan,
            'country'  => $country,
            'locale'   => $locale,
            'timezone' => $timezone,
        ));

        $this->log_model->audit($org->id, $this->agent->id, 'org_update_by_operator',
            empty($changes) ? '변경 없음' : implode(', ', $changes), client_ip());

        api_response(TRUE, $name.' 조직 정보를 저장했습니다.', array('changed' => count($changes)));
    }

    /**
     * POST /operator/api/org_approve
     */
    public function api_org_approve()
    {
        $org = $this->require_org((int) $this->input->post('org_id'));

        if ($org->status === 'active')
        {
            api_response(TRUE, '이미 승인된 조직입니다.', array('status' => 'active'));
            return;
        }

        $this->organization_model->approve($org->id, $this->agent->id);
        $this->log_model->audit($org->id, $this->agent->id, 'org_approved',
            'org='.$org->org_code, client_ip());

        api_response(TRUE, $org->name.' 조직을 승인했습니다.', array('status' => 'active'));
    }

    /**
     * POST /operator/api/org_reject
     */
    public function api_org_reject()
    {
        $org    = $this->require_org((int) $this->input->post('org_id'));
        $reason = trim((string) $this->input->post('reason', TRUE));

        if ($reason === '')
        {
            api_response(FALSE, '반려 사유를 입력해 주세요.', array(), 400);
            return;
        }

        $this->organization_model->set_status($org->id, 'rejected', $this->agent->id, $reason);
        $this->log_model->audit($org->id, $this->agent->id, 'org_rejected',
            'org='.$org->org_code.' reason='.$reason, client_ip());

        api_response(TRUE, $org->name.' 조직 신청을 반려했습니다.', array('status' => 'rejected'));
    }

    /**
     * POST /operator/api/org_suspend
     */
    public function api_org_suspend()
    {
        $org    = $this->require_org((int) $this->input->post('org_id'));
        $reason = trim((string) $this->input->post('reason', TRUE));

        if ($reason === '')
        {
            api_response(FALSE, '중지 사유를 입력해 주세요.', array(), 400);
            return;
        }

        if ($org->org_code === 'system')
        {
            api_response(FALSE, '플랫폼 운영 조직은 중지할 수 없습니다.', array(), 400);
            return;
        }

        $this->organization_model->set_status($org->id, 'suspended', $this->agent->id, $reason);
        $this->log_model->audit($org->id, $this->agent->id, 'org_suspended',
            'org='.$org->org_code.' reason='.$reason, client_ip());

        api_response(TRUE, $org->name.' 조직 이용을 중지했습니다.', array('status' => 'suspended'));
    }

    /**
     * POST /operator/api/org_restore
     * 반려되거나 중지된 조직을 다시 활성화한다.
     */
    public function api_org_restore()
    {
        $org = $this->require_org((int) $this->input->post('org_id'));

        $this->organization_model->approve($org->id, $this->agent->id);
        $this->log_model->audit($org->id, $this->agent->id, 'org_restored',
            'org='.$org->org_code, client_ip());

        api_response(TRUE, $org->name.' 조직을 다시 활성화했습니다.', array('status' => 'active'));
    }

    /**
     * 대상 조직을 확인한다. 없으면 즉시 응답하고 종료한다.
     */
    protected function require_org($org_id)
    {
        $org = $this->organization_model->get_by_id($org_id);

        if (empty($org))
        {
            api_response(FALSE, '조직을 찾을 수 없습니다.', array(), 404);
            exit;
        }

        return $org;
    }
}
