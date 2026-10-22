<?php
/**
 * 파일 위치: application/controllers/Guide.php
 * 역할: 고객이 보는 공개 가이드 (목록, 검색, 상세) — /{조직코드}/guide
 *
 * 고객 화면이므로 조직이 정한 언어를 쓴다(CLAUDE.md 3.2). 공개(published) 글만 보인다.
 * 화면 모양은 조직이 고른 테마(organizations.guide_theme)를 따른다. views/guide/page.php 가 테마 뷰를 고른다.
 * 새 기능 모음(/guide/features)과 동영상 모음(/guide/videos)은 조직이 켤 때만 열린다(docs/guide-collections-plan.md).
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Guide extends MY_Controller {

    public function __construct()
    {
        parent::__construct();
        $this->load->model('guide_model');
    }

    /**
     * GET /{org_code}/guide?q=
     */
    public function index($org_code = '')
    {
        $org = $this->require_org($org_code);
        $q = trim(mb_substr((string) $this->input->get('q'), 0, 100));

        $this->render_page($org, NULL, $q, lang_text('guide.page_title', array('org' => $org->name)));
    }

    /**
     * GET /{org_code}/guide/features?page=
     */
    public function features($org_code = '')
    {
        $org = $this->require_org($org_code);
        $this->require_collection($org, 'feature_enabled');

        $this->render_page($org, NULL, '', lang_text('guide.features_title').' - '.$org->name, 'features');
    }

    /**
     * GET /{org_code}/guide/videos?page=&category=
     */
    public function videos($org_code = '')
    {
        $org = $this->require_org($org_code);
        $this->require_collection($org, 'video_enabled');

        $this->render_page($org, NULL, '', lang_text('guide.videos_title').' - '.$org->name, 'videos');
    }

    /**
     * GET /{org_code}/guide/{slug 또는 번호}
     */
    public function article($org_code = '', $slug = '')
    {
        $org = $this->require_org($org_code);
        $article = $this->guide_model->get_published($org->id, $slug);

        if (empty($article))
        {
            show_404();
            return;
        }

        // 같은 브라우저에서 다시 열면 조회수를 올리지 않는다.
        $seen = (array) $this->session->userdata('guide_seen');

        if ( ! in_array((int) $article->id, $seen, TRUE))
        {
            $this->guide_model->increment_view($article->id);
            $seen[] = (int) $article->id;
            $this->session->set_userdata('guide_seen', array_slice($seen, -200));
        }

        $this->render_page($org, $article, '', $article->title);
    }

    // ------------------------------------------------------------------

    protected function render_page($org, $article, $q, $title, $view = 'list')
    {
        $data = $this->guide_model->page_data($org, $article, $q, $this->preview_theme($org), $view, array(
            'page'     => (int) $this->input->get('page'),
            'category' => (int) $this->input->get('category'),
        ));

        $data['page_title'] = $title;
        $data['preview']    = FALSE;
        // 조직이 상담 위젯을 켜 두었으면 가이드를 읽다가 바로 상담을 열 수 있게 한다.
        $data['widget_key'] = ((int) $org->widget_enabled === 1) ? (string) $org->widget_key : '';

        $this->render('guide/page', $data, 'layouts/guide');
    }

    /**
     * ?theme= 로 다른 테마를 미리 본다. 그 조직 관리자로 로그인한 경우에만 받는다(가이드 관리 > 테마 미리보기).
     */
    protected function preview_theme($org)
    {
        $theme = (string) $this->input->get('theme');
        $themes = Guide_model::themes();

        if ($theme === '' || ! isset($themes[$theme]))
        {
            return NULL;
        }

        return $this->is_org_admin($org) ? $theme : NULL;
    }

    /**
     * 모음 페이지는 조직 옵션을 켰을 때만 연다. 꺼져 있어도 그 조직 관리자 세션이면 미리 볼 수 있다(가이드 관리 > 미리보기).
     */
    protected function require_collection($org, $option)
    {
        $options = Guide_model::page_options($org);

        if ( ! $options[$option] && ! $this->is_org_admin($org))
        {
            show_404();
            exit;
        }
    }

    protected function is_org_admin($org)
    {
        return $this->session->userdata('agent_role') === 'admin'
            && (int) $this->session->userdata('org_id') === (int) $org->id;
    }

    protected function require_org($org_code)
    {
        $org = $this->organization_model->get_active_by_code($org_code);

        if (empty($org))
        {
            show_404();
            exit;
        }

        $this->use_org_locale($org);

        return $org;
    }
}
