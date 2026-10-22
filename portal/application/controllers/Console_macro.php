<?php
/**
 * 파일 위치: application/controllers/Console_macro.php
 * 역할: 상담 대화 매크로 관리 화면과 API (목록, 만들기/고치기, 삭제, 개인으로 복사, 즐겨찾기, 추천, CSV 양식/내려받기/올리기)
 *
 * 모든 상담원이 들어온다. 공용 매크로를 바꾸는 요청은 Macro_model 이 조직 관리자인지 다시 확인한다.
 * 매크로로 채운 글의 전송은 기존 Console::api_chat_send 가 한다(저장·발행 경로를 하나로 둔다). (docs/macro-plan.md)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Console_macro extends Agent_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->model('macro_model');
    }

    // ------------------------------------------------------------------
    // 화면
    // ------------------------------------------------------------------

    /**
     * GET /console/macro
     */
    public function index()
    {
        $this->render('console/macro', array(
            'page_title'   => '매크로',
            'use_grid'     => TRUE,
            'use_dropzone' => TRUE,
            'is_admin'     => $this->agent->role === 'admin',
            'rows'         => $this->macro_model->list_for_agent($this->agent),
            'macro_editor' => $this->macro_model->editor_conf($this->agent),
            'import_rows'  => $this->macro_model->conf('import_max_rows'),
            'import_mb'    => (int) ($this->macro_model->conf('import_max_bytes') / 1024 / 1024),
        ));
    }

    /**
     * GET /console/macro/template
     * 파일 등록 양식(CSV, 예시 한 줄).
     */
    public function template()
    {
        $this->send_csv('macro-template.csv', $this->macro_model->to_csv());
    }

    /**
     * GET /console/macro/export?scope=org|personal
     */
    public function export()
    {
        $scope = $this->macro_model->scope_value((string) $this->input->get('scope', TRUE)) ?: 'personal';
        $rows = $this->macro_model->export_rows($this->agent, $scope);

        if ($scope === 'org')
        {
            $this->log_model->audit($this->agent->org_id, $this->agent->id, 'macro_export', 'rows='.count($rows), client_ip());
        }

        $this->send_csv('macro-'.$scope.'-'.gmdate('Ymd').'.csv', $this->macro_model->to_csv($rows));
    }

    protected function send_csv($name, $content)
    {
        $this->output
            ->set_content_type('text/csv', 'utf-8')
            ->set_header('Content-Disposition: attachment; filename="'.$name.'"')
            ->set_header('Cache-Control: no-store')
            ->set_output($content);
    }

    // ------------------------------------------------------------------
    // API
    // ------------------------------------------------------------------

    /**
     * GET /console/api/macro/list
     * 공용 + 내 개인 매크로 전체. 콘솔 `/` 목록과 관리 목록이 함께 쓴다.
     */
    public function api_list()
    {
        api_response(TRUE, '', array('macros' => $this->macro_model->list_for_agent($this->agent)));
    }

    /**
     * POST /console/api/macro/save  (id, scope = org | personal, name, body, keywords)
     * id 가 있으면 고치기이며 범위는 바꾸지 않는다.
     */
    public function api_save()
    {
        $id = (int) $this->input->post('id');
        $scope = $this->macro_model->scope_value((string) $this->input->post('scope', TRUE));

        if ($id === 0 && $scope === NULL)
        {
            api_response(FALSE, '범위 값이 올바르지 않습니다.', array(), 400);
            return;
        }

        // 내용은 상담원이 쓴 글 그대로 저장하고 화면에서 이스케이프한다(XSS 필터가 글을 바꾸지 않게).
        $saved = $this->macro_model->save($this->agent, $scope, array(
            'name'     => $this->input->post('name'),
            'body'     => $this->input->post('body'),
            'keywords' => $this->input->post('keywords'),
        ), $id);

        if (is_string($saved))
        {
            api_response(FALSE, $saved, array(), 400);
            return;
        }

        $row = $this->macro_model->get_usable($this->agent, $saved);

        if ($row->owner_agent_id === NULL)
        {
            $this->log_model->audit($this->agent->org_id, $this->agent->id, $id > 0 ? 'macro_update' : 'macro_create',
                'macro_id='.$saved.' name='.$row->name, client_ip());
        }

        $unknown = $this->macro_model->unknown_variables($row->body);

        api_response(TRUE, $unknown
            ? '저장했습니다. 알 수 없는 변수 {'.implode('}, {', $unknown).'} 는 바뀌지 않고 그대로 보내집니다.'
            : '저장했습니다.', array('id' => $saved));
    }

    /**
     * POST /console/api/macro/delete  (ids[])
     */
    public function api_delete()
    {
        $ids = (array) $this->input->post('ids');

        if (empty($ids))
        {
            api_response(FALSE, '지울 매크로를 골라 주세요.', array(), 400);
            return;
        }

        $deleted = $this->macro_model->delete($this->agent, $ids);

        foreach ($deleted as $item)
        {
            if ($item['scope'] === 'org')
            {
                $this->log_model->audit($this->agent->org_id, $this->agent->id, 'macro_delete',
                    'macro_id='.$item['id'].' name='.$item['name'], client_ip());
            }
        }

        if (empty($deleted))
        {
            api_response(FALSE, '지울 수 있는 매크로가 없습니다. 공용 매크로는 조직 관리자만 지울 수 있습니다.', array(), 403);
            return;
        }

        $skipped = count(array_unique(array_map('intval', $ids))) - count($deleted);

        api_response(TRUE, count($deleted).'개를 지웠습니다.'.($skipped > 0 ? ' 권한이 없는 '.$skipped.'개는 남겼습니다.' : ''),
            array('ids' => array_column($deleted, 'id')));
    }

    /**
     * POST /console/api/macro/copy  (id)
     * 쓸 수 있는 매크로를 내 개인 매크로로 복사한다.
     */
    public function api_copy()
    {
        $saved = $this->macro_model->copy_to_personal($this->agent, (int) $this->input->post('id'));

        if (is_string($saved))
        {
            api_response(FALSE, $saved, array(), 400);
            return;
        }

        api_response(TRUE, '개인 매크로로 복사했습니다.', array('id' => $saved));
    }

    /**
     * POST /console/api/macro/favorite  (id, on = 0 | 1)
     */
    public function api_favorite()
    {
        $on = $this->input->post('on') ? TRUE : FALSE;
        $result = $this->macro_model->set_favorite($this->agent, (int) $this->input->post('id'), $on);

        if (is_string($result))
        {
            api_response(FALSE, $result, array(), 400);
            return;
        }

        api_response(TRUE, $on ? '즐겨찾기에 넣었습니다.' : '즐겨찾기에서 뺐습니다.');
    }

    /**
     * POST /console/api/macro/favorite_order  (ids[] 위에서부터 차례)
     */
    public function api_favorite_order()
    {
        $this->macro_model->set_favorite_order($this->agent, (array) $this->input->post('ids'));

        api_response(TRUE, '순서를 저장했습니다.');
    }

    /**
     * GET /console/api/macro/suggest/{conversation_id}
     * 그 대화의 마지막 고객 메시지로 매크로를 찾는다(AI 없음).
     */
    public function api_suggest($conversation_id)
    {
        $this->load->model('chat_model');

        $conversation = $this->chat_model->get_conversation($conversation_id);

        if (empty($conversation) || (int) $conversation->org_id !== (int) $this->agent->org_id)
        {
            api_response(FALSE, '대화를 찾을 수 없습니다.', array(), 404);
            return;
        }

        $last = $this->db->select('body')
                         ->where('conversation_id', (int) $conversation->id)
                         ->where('sender_type', 'visitor')
                         ->order_by('id', 'DESC')
                         ->limit(1)
                         ->get('messages')
                         ->row();

        api_response(TRUE, '', array(
            'items' => $last ? $this->macro_model->suggest($this->agent, $last->body) : array(),
        ));
    }

    /**
     * POST /console/api/macro/import  (multipart, 필드명 file, scope = org | personal, mode = skip | overwrite)
     * 오류가 한 행이라도 있으면 아무것도 저장하지 않고 오류 목록을 돌려준다.
     */
    public function api_import()
    {
        $scope = $this->macro_model->scope_value((string) $this->input->post('scope', TRUE));
        $mode = $this->input->post('mode') === 'overwrite' ? 'overwrite' : 'skip';
        $file = isset($_FILES['file']) ? $_FILES['file'] : NULL;

        if ($scope === NULL)
        {
            api_response(FALSE, '범위 값이 올바르지 않습니다.', array(), 400);
            return;
        }

        if ( ! $this->macro_model->can_edit_scope($this->agent, $scope))
        {
            api_response(FALSE, '공용 매크로는 조직 관리자만 올릴 수 있습니다.', array(), 403);
            return;
        }

        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name']))
        {
            api_response(FALSE, 'CSV 파일을 선택해 주세요.', array(), 400);
            return;
        }

        if ($file['size'] > $this->macro_model->conf('import_max_bytes'))
        {
            api_response(FALSE, '파일은 '.(int) ($this->macro_model->conf('import_max_bytes') / 1024 / 1024).'MB 이하로 올려 주세요.', array(), 400);
            return;
        }

        $parsed = $this->macro_model->parse_csv(file_get_contents($file['tmp_name']));

        if (isset($parsed['error']))
        {
            api_response(FALSE, $parsed['error'], array(), 400);
            return;
        }

        if (empty($parsed['rows']))
        {
            api_response(FALSE, '등록할 행이 없습니다.', array(), 400);
            return;
        }

        $result = $this->macro_model->import($this->agent, $scope, $parsed['rows'], $mode);

        if (isset($result['errors']))
        {
            $count = count(array_filter($result['errors'], function ($e) { return $e['row'] > 0; }));

            api_response(FALSE, $count > 0
                ? $count.'개 행에 오류가 있어 아무것도 저장하지 않았습니다.'
                : $result['errors'][0]['message'], array('errors' => $result['errors']), 400);
            return;
        }

        if ($scope === 'org')
        {
            $this->log_model->audit($this->agent->org_id, $this->agent->id, 'macro_import',
                'file='.mb_substr((string) $file['name'], 0, 80).' mode='.$mode.' created='.$result['created']
                .' updated='.$result['updated'].' skipped='.$result['skipped'], client_ip());
        }

        api_response(TRUE, '추가 '.$result['created'].'개, 덮어씀 '.$result['updated'].'개, 건너뜀 '.$result['skipped'].'개', $result);
    }
}
