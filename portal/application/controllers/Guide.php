<?php
/**
 * 파일 위치: application/controllers/Guide.php
 * 역할: 고객이 보는 공개 가이드 (목록, 검색, 상세) — /{조직코드}/guide
 *
 * 고객 화면이므로 조직이 정한 언어를 쓴다(CLAUDE.md 3.2). 공개(published) 글만 보인다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Guide extends MY_Controller {

    /** 검색 결과 최대 건수 */
    const SEARCH_LIMIT = 20;

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
        $results = NULL;

        if ($q !== '')
        {
            $this->load->library('guide_matcher');
            $results = $this->guide_matcher->match($org->id, $q, 'search', self::SEARCH_LIMIT);

            // 낱말이 하나도 안 맞으면 제목에 그대로 들어 있는지 한 번 더 본다(1글자 질문 등).
            if (empty($results))
            {
                $results = $this->title_contains($org->id, $q);
            }
        }

        $groups = array();

        if ($results === NULL)
        {
            foreach ($this->guide_model->list_articles($org->id, TRUE) as $article)
            {
                $key = $article->category_id ? (int) $article->category_id : 0;

                if ( ! isset($groups[$key]))
                {
                    $groups[$key] = array(
                        'name'     => $article->category_name ?: lang_text('guide.uncategorized'),
                        'articles' => array(),
                    );
                }

                $groups[$key]['articles'][] = $article;
            }
        }

        $this->render('guide/index', array(
            'page_title' => lang_text('guide.page_title', array('org' => $org->name)),
            'org'        => $org,
            'q'          => $q,
            'results'    => $results,
            'groups'     => $groups,
            'widget_key' => $this->widget_key($org),
        ), 'layouts/customer');
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

        $this->render('guide/article', array(
            'page_title' => $article->title,
            'org'        => $org,
            'article'    => $article,
            'preview'    => FALSE,
            'widget_key' => $this->widget_key($org),
        ), 'layouts/customer');
    }

    // ------------------------------------------------------------------

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

    /**
     * 조직이 상담 위젯을 켜 두었으면 가이드를 읽다가 바로 상담을 열 수 있게 한다.
     */
    protected function widget_key($org)
    {
        return ((int) $org->widget_enabled === 1) ? (string) $org->widget_key : '';
    }

    protected function title_contains($org_id, $q)
    {
        $rows = $this->db->select('id, slug, title, subtitle')
                         ->where('org_id', (int) $org_id)
                         ->where('status', 'published')
                         ->group_start()->like('title', $q)->or_like('subtitle', $q)->group_end()
                         ->limit(self::SEARCH_LIMIT)
                         ->get('guide_articles')
                         ->result();
        $out = array();

        foreach ($rows as $row)
        {
            $out[] = array('id' => (int) $row->id, 'slug' => $row->slug, 'title' => $row->title,
                           'subtitle' => $row->subtitle, 'score' => 0, 'hits' => 0);
        }

        return $out;
    }
}
