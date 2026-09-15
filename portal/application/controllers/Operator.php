<?php
/**
 * 파일 위치: application/controllers/Operator.php
 * 역할: 플랫폼 운영자 콘솔 (조직 가입 승인, 반려, 이용 중지)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Operator extends Operator_Controller {

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
            'filter'     => $status,
            'counts'     => $this->organization_model->count_by_status(),
            'orgs'       => $this->organization_model->list_for_review($status),
        ));
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
