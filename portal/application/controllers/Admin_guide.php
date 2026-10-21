<?php
/**
 * 파일 위치: application/controllers/Admin_guide.php
 * 역할: 조직 관리 > 가이드 관리 (목록, 편집, 이미지 업로드, 분류/동의어, guide.json 내려받기/올리기, 매칭 시험)
 *
 * 조직 관리자만 쓴다(Admin_Controller). 상담원은 콘솔에서 검색하고 보내기만 한다. (docs/guide-plan.md)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Admin_guide extends Admin_Controller {

    /** 가이드 이미지 최대 크기(2MB) */
    const IMAGE_MAX_BYTES = 2097152;

    /** guide.json 최대 크기(2MB) */
    const IMPORT_MAX_BYTES = 2097152;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('guide_model');
    }

    /**
     * GET /admin/guide
     */
    public function index()
    {
        $org = $this->organization_model->get_by_id($this->agent->org_id);
        $rows = array();

        foreach ($this->guide_model->list_articles($org->id) as $a)
        {
            $rows[] = array(
                'id'            => (int) $a->id,
                'category_name' => $a->category_name,
                'title'         => $a->title,
                'subtitle'      => $a->subtitle,
                'keywords'      => $a->keywords,
                'status'        => $a->status,
                'view_count'    => (int) $a->view_count,
                'updated_at'    => $a->updated_at,
                'public_url'    => $this->public_url($org, $a),
            );
        }

        $synonyms = array();

        foreach ($this->guide_model->synonym_groups($org->id) as $group)
        {
            $synonyms[] = implode(', ', $group);
        }

        $this->render('admin/guide', array(
            'page_title'   => '가이드 관리',
            'use_grid'     => TRUE,
            'use_dropzone' => TRUE,
            'org'          => $org,
            'rows'         => $rows,
            'categories'   => $this->guide_model->list_categories($org->id),
            'synonyms'     => implode("\n", $synonyms),
            'guide_url'    => customer_url($org->org_code.'/guide'),
        ));
    }

    /**
     * GET /admin/guide/new, /admin/guide/edit/{id}
     */
    public function edit($id = 0)
    {
        $org = $this->organization_model->get_by_id($this->agent->org_id);
        $article = NULL;

        if ($id > 0)
        {
            $article = $this->guide_model->get_article($org->id, $id);

            if (empty($article))
            {
                show_404();
                return;
            }
        }

        $this->render('admin/guide_edit', array(
            'page_title'   => $article ? '가이드 편집' : '새 가이드',
            'use_dropzone' => TRUE,
            'org'          => $org,
            'article'      => $article,
            'categories'   => $this->guide_model->list_categories($org->id),
            'guide_base'   => customer_url($org->org_code.'/guide/'),
            'public_url'   => $article ? $this->public_url($org, $article) : '',
        ));
    }

    /**
     * GET /admin/guide/preview/{id}
     * 임시 글도 고객 화면과 같은 모양으로 본다. 고객 화면이므로 조직 언어를 쓴다.
     */
    public function preview($id = 0)
    {
        $org = $this->organization_model->get_by_id($this->agent->org_id);
        $article = $this->guide_model->get_article($org->id, $id);

        if (empty($article))
        {
            show_404();
            return;
        }

        $this->use_org_locale($org);

        $this->render('guide/article', array(
            'page_title' => $article->title,
            'org'        => $org,
            'article'    => $article,
            'preview'    => TRUE,
        ), 'layouts/customer');
    }

    /**
     * GET /admin/guide/export
     * 조직 가이드 전체를 guide.json 으로 내려준다. 서버에 파일을 남기지 않는다.
     */
    public function export()
    {
        $org  = $this->organization_model->get_by_id($this->agent->org_id);
        $data = $this->guide_model->export($org);

        $this->log_model->audit($org->id, $this->agent->id, 'guide_export',
            'articles='.count($data['articles']), client_ip());

        $name = 'guide-'.preg_replace('/[^A-Za-z0-9_-]/', '', $org->org_code).'-'.gmdate('Ymd').'.json';

        $this->output
            ->set_content_type('application/json', 'utf-8')
            ->set_header('Content-Disposition: attachment; filename="'.$name.'"')
            ->set_header('Cache-Control: no-store')
            ->set_output(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    // ------------------------------------------------------------------
    // 글
    // ------------------------------------------------------------------

    /**
     * POST /admin/guide/api/save
     * blocks 는 JSON 문자열로 받는다.
     */
    public function api_save()
    {
        $org_id = (int) $this->agent->org_id;
        $id = (int) $this->input->post('id');

        if ($id > 0 && empty($this->guide_model->get_article($org_id, $id)))
        {
            api_response(FALSE, '가이드를 찾을 수 없습니다.', array(), 404);
            return;
        }

        if ($id === 0 && $this->guide_model->count_articles($org_id) >= Guide_model::MAX_ARTICLES)
        {
            api_response(FALSE, '가이드는 조직당 '.Guide_model::MAX_ARTICLES.'건까지 만들 수 있습니다.', array(), 400);
            return;
        }

        // 원문을 받는다. 출력할 때 html_escape 하므로 저장 단계에서 걸러 내용을 바꾸지 않는다.
        $warnings = array();
        $clean = $this->guide_model->clean_article($org_id, array(
            'title'       => $this->input->post('title'),
            'subtitle'    => $this->input->post('subtitle'),
            'slug'        => $this->input->post('slug'),
            'keywords'    => $this->input->post('keywords'),
            'status'      => $this->input->post('status'),
            'sort_order'  => $this->input->post('sort_order'),
            'category_id' => $this->input->post('category_id'),
            'blocks'      => (string) $this->input->post('blocks'),
        ), $warnings);

        if (is_string($clean))
        {
            api_response(FALSE, $clean, array(), 400);
            return;
        }

        if ($this->guide_model->slug_taken($org_id, $clean['slug'], $id))
        {
            api_response(FALSE, '이미 쓰는 주소입니다. 다른 주소를 입력해 주세요.', array(), 409);
            return;
        }

        $saved_id = $this->guide_model->save_article($org_id, $id, $clean, $this->agent->id);
        $this->log_model->audit($org_id, $this->agent->id, $id > 0 ? 'guide_update' : 'guide_create',
            'article_id='.$saved_id.' status='.$clean['status'], client_ip());

        $org = $this->organization_model->get_by_id($org_id);
        $article = $this->guide_model->get_article($org_id, $saved_id);

        api_response(TRUE, empty($warnings) ? '가이드를 저장했습니다.' : '가이드를 저장했습니다. 일부 블록은 빠졌습니다.', array(
            'id'         => $saved_id,
            'warnings'   => $warnings,
            'public_url' => $this->public_url($org, $article),
            'edit_url'   => base_url('admin/guide/edit/'.$saved_id),
        ));
    }

    /**
     * POST /admin/guide/api/delete
     */
    public function api_delete()
    {
        $id = (int) $this->input->post('id');

        if ( ! $this->guide_model->delete_article($this->agent->org_id, $id))
        {
            api_response(FALSE, '가이드를 찾을 수 없습니다.', array(), 404);
            return;
        }

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'guide_delete', 'article_id='.$id, client_ip());
        api_response(TRUE, '가이드를 삭제했습니다.');
    }

    /**
     * POST /admin/guide/api/status
     */
    public function api_status()
    {
        $id = (int) $this->input->post('id');
        $status = (string) $this->input->post('status', TRUE);

        if ( ! in_array($status, array('draft', 'published'), TRUE)
            || empty($this->guide_model->get_article($this->agent->org_id, $id)))
        {
            api_response(FALSE, '바꿀 수 없는 상태입니다.', array(), 400);
            return;
        }

        $this->guide_model->set_status($this->agent->org_id, $id, $status);
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'guide_status',
            'article_id='.$id.' status='.$status, client_ip());

        api_response(TRUE, $status === 'published' ? '공개했습니다.' : '임시 저장으로 바꿨습니다.', array('status' => $status));
    }

    /**
     * POST /admin/guide/api/image  (multipart, 필드명 image)
     * 올린 파일은 글을 저장할 때 블록에 들어간다. 저장하지 않고 남은 파일은 하루 뒤 정리된다.
     */
    public function api_image()
    {
        $file = isset($_FILES['image']) ? $_FILES['image'] : NULL;
        $ext  = check_uploaded_image($file, self::IMAGE_MAX_BYTES);

        if (is_array($ext))
        {
            api_response(FALSE, $ext['error'], array(), 400);
            return;
        }

        $rel = $this->guide_model->image_dir($this->agent->org_id);
        is_dir(FCPATH.$rel) OR @mkdir(FCPATH.$rel, 0775, TRUE);
        $src = $rel.bin2hex(random_bytes(10)).'.'.$ext;

        if ( ! @move_uploaded_file($file['tmp_name'], FCPATH.$src))
        {
            api_response(FALSE, '이미지를 저장하지 못했습니다. 업로드 폴더 권한을 확인해 주세요.', array(), 500);
            return;
        }

        $this->guide_model->cleanup_images($this->agent->org_id);

        api_response(TRUE, '이미지를 올렸습니다.', array('src' => $src, 'url' => base_url($src)));
    }

    // ------------------------------------------------------------------
    // 분류, 동의어, 색인
    // ------------------------------------------------------------------

    /**
     * POST /admin/guide/api/category_save
     */
    public function api_category_save()
    {
        $id   = (int) $this->input->post('id');
        $name = trim((string) $this->input->post('name'));

        if ($name === '')
        {
            api_response(FALSE, '분류 이름을 입력해 주세요.', array(), 400);
            return;
        }

        if ($id > 0 && empty($this->guide_model->get_category($this->agent->org_id, $id)))
        {
            api_response(FALSE, '분류를 찾을 수 없습니다.', array(), 404);
            return;
        }

        $saved = $this->guide_model->save_category($this->agent->org_id, $id, $name, $this->input->post('sort_order'));
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'guide_category_save', 'category_id='.$saved, client_ip());

        api_response(TRUE, '분류를 저장했습니다.', array('id' => $saved));
    }

    /**
     * POST /admin/guide/api/category_delete
     */
    public function api_category_delete()
    {
        $id = (int) $this->input->post('id');

        if ( ! $this->guide_model->delete_category($this->agent->org_id, $id))
        {
            api_response(FALSE, '분류를 찾을 수 없습니다.', array(), 404);
            return;
        }

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'guide_category_delete', 'category_id='.$id, client_ip());
        api_response(TRUE, '분류를 삭제했습니다. 그 분류의 가이드는 분류 없음으로 남습니다.');
    }

    /**
     * POST /admin/guide/api/synonyms_save
     * 한 줄에 한 묶음, 쉼표로 낱말을 나눈다.
     */
    public function api_synonyms_save()
    {
        $groups = array();

        foreach (preg_split('/\r\n|\r|\n/', (string) $this->input->post('synonyms')) as $line)
        {
            $groups[] = explode(',', $line);
        }

        $saved = $this->guide_model->replace_synonyms($this->agent->org_id, $groups);
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'guide_synonyms_save', 'groups='.$saved, client_ip());

        api_response(TRUE, '동의어 '.$saved.'묶음을 저장했습니다.', array('count' => $saved));
    }

    /**
     * POST /admin/guide/api/reindex
     * 매칭 설정(config/guide_match.php)을 바꾼 뒤 색인을 한꺼번에 다시 만든다.
     */
    public function api_reindex()
    {
        $terms = $this->guide_model->rebuild_org_index($this->agent->org_id);
        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'guide_reindex', 'terms='.$terms, client_ip());

        api_response(TRUE, '색인을 다시 만들었습니다. (낱말 '.$terms.'개)', array('terms' => $terms));
    }

    /**
     * GET /admin/guide/api/match_test?q=
     * 문장을 넣으면 어떤 낱말로 바뀌고 어떤 글이 몇 점으로 나오는지 보여준다. 키워드를 다듬을 때 쓴다.
     */
    public function api_match_test()
    {
        $this->load->library('guide_matcher');
        $q = mb_substr((string) $this->input->get('q'), 0, 500);
        $org_id = (int) $this->agent->org_id;

        api_response(TRUE, '', array(
            'terms'   => $this->guide_matcher->query_terms($q, $this->guide_model->synonym_groups($org_id)),
            'results' => $this->guide_matcher->match($org_id, $q, 'search', 10),
            'min'     => array(
                'console' => $this->guide_matcher->min_score('console'),
                'widget'  => $this->guide_matcher->min_score('widget'),
            ),
        ));
    }

    // ------------------------------------------------------------------
    // guide.json 올리기
    // ------------------------------------------------------------------

    /**
     * POST /admin/guide/api/import  (multipart, 필드명 guide, mode=merge|replace)
     */
    public function api_import()
    {
        $file = isset($_FILES['guide']) ? $_FILES['guide'] : NULL;

        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name']))
        {
            api_response(FALSE, 'guide.json 파일을 선택해 주세요.', array(), 400);
            return;
        }

        if ($file['size'] > self::IMPORT_MAX_BYTES)
        {
            api_response(FALSE, '파일은 2MB 이하로 올려 주세요.', array(), 400);
            return;
        }

        $data = json_decode((string) file_get_contents($file['tmp_name']), TRUE);

        if ( ! is_array($data))
        {
            api_response(FALSE, 'JSON 형식이 아닙니다. 내려받은 guide.json 을 그대로 올려 주세요.', array(), 400);
            return;
        }

        $mode = $this->input->post('mode') === 'replace' ? 'replace' : 'merge';
        $result = $this->guide_model->import($this->agent->org_id, $data, $mode, $this->agent->id);

        if (is_string($result))
        {
            api_response(FALSE, $result, array(), 400);
            return;
        }

        $this->log_model->audit($this->agent->org_id, $this->agent->id, 'guide_import',
            'mode='.$mode.' created='.$result['created'].' updated='.$result['updated']
            .' skipped='.count($result['skipped']), client_ip());

        api_response(TRUE, '가져왔습니다. 새 글 '.$result['created'].'건, 바뀐 글 '.$result['updated'].'건, 건너뜀 '
            .count($result['skipped']).'건', $result);
    }

    // ------------------------------------------------------------------

    protected function public_url($org, $article)
    {
        return $this->guide_model->public_url($org, $article);
    }
}
