<?php
/**
 * 파일 위치: application/models/Guide_model.php
 * 역할: 조직 가이드(분류, 글, 동의어) 저장/조회, 입력 검사, 매칭 색인 갱신, guide.json 내보내기/가져오기
 *
 * 원본은 DB 이고 guide.json 은 주고받는 형식이다. 화면 저장과 파일 가져오기는 같은 검사(clean_article)를 거친다.
 * 모든 조회는 org_id 로 거른다. (docs/guide-plan.md)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Guide_model extends CI_Model {

    /** guide.json 형식 이름과 버전 */
    const FORMAT  = 'remotehelp-guide';
    const VERSION = 1;

    /** 조직당 글 수 상한 */
    const MAX_ARTICLES = 500;

    /** 글 하나의 블록 수 상한 */
    const MAX_BLOCKS = 50;

    /** 글 블록 한 개의 글자 수 상한 */
    const MAX_TEXT = 5000;

    /** 키워드 개수 상한 */
    const MAX_KEYWORDS = 20;

    // ------------------------------------------------------------------
    // 분류
    // ------------------------------------------------------------------

    public function list_categories($org_id)
    {
        return $this->db->where('org_id', (int) $org_id)
                        ->order_by('sort_order', 'ASC')
                        ->order_by('id', 'ASC')
                        ->get('guide_categories')
                        ->result();
    }

    public function get_category($org_id, $id)
    {
        return $this->db->get_where('guide_categories', array(
            'org_id' => (int) $org_id,
            'id'     => (int) $id,
        ))->row();
    }

    public function save_category($org_id, $id, $name, $sort_order)
    {
        $data = array(
            'name'       => mb_substr(trim((string) $name), 0, 60),
            'sort_order' => (int) $sort_order,
        );

        if ($id > 0)
        {
            $this->db->where('org_id', (int) $org_id)->where('id', (int) $id)->update('guide_categories', $data);
            return (int) $id;
        }

        $data['org_id'] = (int) $org_id;
        $this->db->insert('guide_categories', $data);

        return (int) $this->db->insert_id();
    }

    /**
     * 분류를 지운다. 그 분류의 글은 지우지 않고 '분류 없음' 으로 남는다(FK SET NULL).
     */
    public function delete_category($org_id, $id)
    {
        $this->db->where('org_id', (int) $org_id)->where('id', (int) $id)->delete('guide_categories');

        return $this->db->affected_rows();
    }

    // ------------------------------------------------------------------
    // 글
    // ------------------------------------------------------------------

    /**
     * 관리 화면 목록. 본문 블록은 빼고 내려준다.
     */
    public function list_articles($org_id, $published_only = FALSE)
    {
        $this->db->select('a.id, a.category_id, a.slug, a.title, a.subtitle, a.keywords, a.status, '
                        . 'a.sort_order, a.view_count, a.updated_at, a.created_at, '
                        . 'c.name AS category_name, c.sort_order AS category_sort')
                 ->from('guide_articles a')
                 ->join('guide_categories c', 'c.id = a.category_id', 'left')
                 ->where('a.org_id', (int) $org_id);

        if ($published_only)
        {
            $this->db->where('a.status', 'published');
        }

        // 분류 없는 글은 맨 뒤로 보낸다.
        return $this->db->order_by('a.category_id IS NULL', 'ASC', FALSE)
                        ->order_by('c.sort_order', 'ASC')
                        ->order_by('a.category_id', 'ASC')
                        ->order_by('a.sort_order', 'ASC')
                        ->order_by('a.id', 'ASC')
                        ->get()
                        ->result();
    }

    public function count_published($org_id)
    {
        return (int) $this->db->where('org_id', (int) $org_id)
                              ->where('status', 'published')
                              ->count_all_results('guide_articles');
    }

    public function get_article($org_id, $id)
    {
        $row = $this->db->get_where('guide_articles', array(
            'org_id' => (int) $org_id,
            'id'     => (int) $id,
        ))->row();

        return $this->decode($row);
    }

    /**
     * 공개 주소의 slug 또는 숫자 id 로 공개 글을 찾는다.
     */
    public function get_published($org_id, $slug_or_id)
    {
        $slug_or_id = (string) $slug_or_id;
        $this->db->where('org_id', (int) $org_id)->where('status', 'published');

        if (ctype_digit($slug_or_id))
        {
            $this->db->where('id', (int) $slug_or_id);
        }
        else
        {
            $this->db->where('slug', $slug_or_id);
        }

        return $this->decode($this->db->get('guide_articles')->row());
    }

    protected function decode($row)
    {
        if ( ! empty($row))
        {
            $blocks = json_decode((string) $row->blocks, TRUE);
            $row->blocks = is_array($blocks) ? $blocks : array();
        }

        return $row;
    }

    /**
     * 고객에게 보내는 가이드 주소. 상담원이 localhost 로 콘솔을 열어도 공개 주소가 나가야 한다(CLAUDE.md 3.13).
     *
     * @param object|array $article id, slug 를 가진 값
     */
    public function public_url($org, $article)
    {
        $article = (array) $article;

        return customer_url($org->org_code.'/guide/'.rawurlencode($article['slug'] ?: $article['id']));
    }

    /**
     * 상담 메시지에 붙이는 가이드 카드 정보(messages.meta)
     */
    public function card($org, $article)
    {
        $article = (array) $article;

        return array(
            'type'       => 'guide',
            'article_id' => (int) $article['id'],
            'title'      => $article['title'],
            'subtitle'   => isset($article['subtitle']) ? (string) $article['subtitle'] : '',
            'url'        => $this->public_url($org, $article),
        );
    }

    public function count_articles($org_id)
    {
        return (int) $this->db->where('org_id', (int) $org_id)->count_all_results('guide_articles');
    }

    /**
     * 검사를 마친 글(clean_article 결과)을 저장하고 색인을 다시 만든다.
     * 블록에서 빠진 이미지 파일은 지운다.
     *
     * @return int 글 id
     */
    public function save_article($org_id, $id, $clean, $agent_id = NULL)
    {
        $old_images = array();

        if ($id > 0)
        {
            $old = $this->get_article($org_id, $id);
            $old_images = $old ? $this->image_paths($old->blocks) : array();
        }

        $data = array(
            'category_id' => $clean['category_id'],
            'slug'        => $clean['slug'],
            'title'       => $clean['title'],
            'subtitle'    => $clean['subtitle'],
            'keywords'    => $clean['keywords'],
            'blocks'      => json_encode($clean['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'      => $clean['status'],
            'sort_order'  => $clean['sort_order'],
            'updated_by'  => $agent_id === NULL ? NULL : (int) $agent_id,
        );

        if ($id > 0)
        {
            $this->db->where('org_id', (int) $org_id)->where('id', (int) $id)->update('guide_articles', $data);
        }
        else
        {
            $data['org_id'] = (int) $org_id;
            $this->db->insert('guide_articles', $data);
            $id = (int) $this->db->insert_id();
        }

        $this->rebuild_index($org_id, $id);
        $this->remove_images($org_id, array_diff($old_images, $this->image_paths($clean['blocks'])));

        return (int) $id;
    }

    public function delete_article($org_id, $id)
    {
        $article = $this->get_article($org_id, $id);

        if (empty($article))
        {
            return 0;
        }

        // 색인은 FK CASCADE 로 함께 지워진다.
        $this->db->where('org_id', (int) $org_id)->where('id', (int) $id)->delete('guide_articles');
        $this->remove_images($org_id, $this->image_paths($article->blocks));

        return 1;
    }

    public function set_status($org_id, $id, $status)
    {
        if ( ! in_array($status, array('draft', 'published'), TRUE))
        {
            return 0;
        }

        $this->db->where('org_id', (int) $org_id)->where('id', (int) $id)
                 ->update('guide_articles', array('status' => $status));
        $this->rebuild_index($org_id, $id);

        return 1;
    }

    public function increment_view($article_id)
    {
        // 조회수만 올리고 수정 시각은 그대로 둔다.
        $this->db->set('view_count', 'view_count + 1', FALSE)
                 ->set('updated_at', 'updated_at', FALSE)
                 ->where('id', (int) $article_id)
                 ->update('guide_articles');
    }

    public function slug_taken($org_id, $slug, $except_id = 0)
    {
        if ($slug === NULL || $slug === '')
        {
            return FALSE;
        }

        return $this->db->where('org_id', (int) $org_id)
                        ->where('slug', $slug)
                        ->where('id !=', (int) $except_id)
                        ->count_all_results('guide_articles') > 0;
    }

    // ------------------------------------------------------------------
    // 입력 검사 (화면 저장과 guide.json 가져오기 공통)
    // ------------------------------------------------------------------

    /**
     * 글 입력을 검사하고 저장할 값으로 바꾼다.
     * 허용 목록 밖의 값은 버리고, 통과하지 못한 블록은 빼고 경고에 사유를 남긴다.
     *
     * @param array $input   title, subtitle, slug, keywords(문자열|배열), status, sort_order, category_id, blocks
     * @param array $warnings 블록 단위 경고가 쌓인다
     * @return array|string  저장할 값, 또는 거부 사유 문자열
     */
    public function clean_article($org_id, $input, &$warnings = array())
    {
        $title = mb_substr(trim((string) $this->value($input, 'title')), 0, 100);

        if ($title === '')
        {
            return '제목을 입력해 주세요.';
        }

        $slug = strtolower(trim((string) $this->value($input, 'slug')));

        if ($slug !== '' && ( ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || strlen($slug) > 60 || ctype_digit($slug)))
        {
            return '주소는 영문 소문자, 숫자, 하이픈(-)으로 60자까지 쓸 수 있고 숫자만으로는 만들 수 없습니다.';
        }

        $status = (string) $this->value($input, 'status');
        $status = in_array($status, array('draft', 'published'), TRUE) ? $status : 'draft';

        $category_id = (int) $this->value($input, 'category_id');

        if ($category_id > 0 && empty($this->get_category($org_id, $category_id)))
        {
            $category_id = 0;
        }

        $raw_blocks = $this->value($input, 'blocks', array());

        if (is_string($raw_blocks))
        {
            $raw_blocks = json_decode($raw_blocks, TRUE);
        }

        if ( ! is_array($raw_blocks))
        {
            $raw_blocks = array();
        }

        if (count($raw_blocks) > self::MAX_BLOCKS)
        {
            $warnings[] = '본문 블록은 '.self::MAX_BLOCKS.'개까지만 저장합니다.';
            $raw_blocks = array_slice($raw_blocks, 0, self::MAX_BLOCKS);
        }

        $blocks = array();

        foreach (array_values($raw_blocks) as $index => $block)
        {
            $result = $this->clean_block($org_id, $block);

            if (is_string($result))
            {
                $warnings[] = ($index + 1).'번째 블록: '.$result;
            }
            elseif ($result !== NULL)
            {
                $blocks[] = $result;
            }
        }

        return array(
            'title'       => $title,
            'subtitle'    => $this->nullable(mb_substr(trim((string) $this->value($input, 'subtitle')), 0, 200)),
            'slug'        => $slug === '' ? NULL : $slug,
            'keywords'    => $this->nullable($this->clean_keywords($this->value($input, 'keywords'))),
            'status'      => $status,
            'sort_order'  => max(-100000, min(100000, (int) $this->value($input, 'sort_order'))),
            'category_id' => $category_id > 0 ? $category_id : NULL,
            'blocks'      => $blocks,
        );
    }

    /**
     * 블록 하나를 검사한다. 빈 블록은 NULL, 거부하면 사유 문자열.
     */
    protected function clean_block($org_id, $block)
    {
        if ( ! is_array($block) || ! isset($block['type']))
        {
            return '형식을 알 수 없습니다.';
        }

        $caption = mb_substr(trim((string) $this->value($block, 'caption')), 0, 200);

        switch ($block['type'])
        {
            case 'text':
                $text = trim(str_replace("\r\n", "\n", (string) $this->value($block, 'text')));

                if ($text === '')
                {
                    return NULL;
                }

                return array('type' => 'text', 'text' => mb_substr($text, 0, self::MAX_TEXT));

            case 'image':
                $src = (string) $this->value($block, 'src');

                if ($src === '')
                {
                    return NULL;
                }

                // 이 조직이 올린 파일만 받는다. 외부 주소와 다른 조직 경로는 고객 화면에 끌어오지 않는다.
                if ( ! $this->is_own_image($org_id, $src))
                {
                    return '이 조직에 올린 이미지가 아니어서 뺐습니다('.mb_substr($src, 0, 80).').';
                }

                return array('type' => 'image', 'src' => $src, 'caption' => $caption);

            case 'video':
                $id = (string) $this->value($block, 'youtube_id');

                if ($id === '' && $this->value($block, 'url'))
                {
                    $id = (string) self::youtube_id($this->value($block, 'url'));
                }

                if ($id === '' && ! $this->value($block, 'url'))
                {
                    return NULL;
                }

                if ( ! preg_match('/^[A-Za-z0-9_-]{11}$/', $id))
                {
                    return '유튜브 주소를 알아보지 못했습니다.';
                }

                return array('type' => 'video', 'youtube_id' => $id, 'caption' => $caption);
        }

        return '허용하지 않는 블록 종류입니다('.mb_substr((string) $block['type'], 0, 20).').';
    }

    /**
     * 유튜브 주소에서 영상 id 만 꺼낸다. 허용 형식이 아니면 NULL.
     * watch?v=, youtu.be/, shorts/, embed/ 형식만 받는다(docs/guide-plan.md 3-2).
     */
    public static function youtube_id($url)
    {
        $url = trim((string) $url);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $url))
        {
            return $url;
        }

        $parts = parse_url($url);

        if (empty($parts['host']) || ! isset($parts['scheme']) || ! in_array(strtolower($parts['scheme']), array('http', 'https'), TRUE))
        {
            return NULL;
        }

        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $parts['host']));
        $path = isset($parts['path']) ? $parts['path'] : '';

        if ($host === 'youtu.be' && preg_match('#^/([A-Za-z0-9_-]{11})$#', $path, $m))
        {
            return $m[1];
        }

        if ($host !== 'youtube.com')
        {
            return NULL;
        }

        if ($path === '/watch' && isset($parts['query']))
        {
            parse_str($parts['query'], $query);

            return (isset($query['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', $query['v'])) ? $query['v'] : NULL;
        }

        if (preg_match('#^/(shorts|embed)/([A-Za-z0-9_-]{11})/?$#', $path, $m))
        {
            return $m[2];
        }

        return NULL;
    }

    /**
     * 쉼표/줄바꿈 구분 키워드(또는 배열)를 정리한다. 중복을 빼고 20개까지.
     */
    protected function clean_keywords($keywords)
    {
        $list = is_array($keywords) ? $keywords : preg_split('/[,\n]+/u', (string) $keywords);
        $out = array();

        foreach ($list as $keyword)
        {
            $keyword = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $keyword)), 0, 40);

            if ($keyword !== '' && ! in_array($keyword, $out, TRUE))
            {
                $out[] = $keyword;
            }
        }

        return mb_substr(implode(', ', array_slice($out, 0, self::MAX_KEYWORDS)), 0, 500);
    }

    protected function value($array, $key, $default = '')
    {
        return (is_array($array) && isset($array[$key])) ? $array[$key] : $default;
    }

    protected function nullable($text)
    {
        return $text === '' ? NULL : $text;
    }

    // ------------------------------------------------------------------
    // 이미지 파일
    // ------------------------------------------------------------------

    /** 조직 이미지 폴더 (FCPATH 기준 상대 경로) */
    public function image_dir($org_id)
    {
        return 'assets/uploads/guides/'.(int) $org_id.'/';
    }

    public function is_own_image($org_id, $src)
    {
        $pattern = '#^'.preg_quote($this->image_dir($org_id), '#').'[A-Za-z0-9_-]+\.(png|jpg|webp)$#';

        return (bool) preg_match($pattern, (string) $src) && is_file(FCPATH.$src);
    }

    protected function image_paths($blocks)
    {
        $out = array();

        foreach ((array) $blocks as $block)
        {
            if (isset($block['type'], $block['src']) && $block['type'] === 'image')
            {
                $out[] = $block['src'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 이미지 파일을 지운다. 다른 글이 같은 파일을 쓰고 있으면 남긴다(가져오기로 같은 경로가 들어올 수 있다).
     */
    protected function remove_images($org_id, $paths)
    {
        foreach ($paths as $path)
        {
            if ( ! preg_match('#^'.preg_quote($this->image_dir($org_id), '#').'[A-Za-z0-9_-]+\.(png|jpg|webp)$#', $path))
            {
                continue;
            }

            $in_use = $this->db->where('org_id', (int) $org_id)
                               ->like('blocks', '"'.$path.'"')
                               ->count_all_results('guide_articles');

            if ($in_use === 0 && is_file(FCPATH.$path))
            {
                @unlink(FCPATH.$path);
            }
        }
    }

    /**
     * 어떤 글에도 쓰이지 않는 이미지를 지운다. 편집 중에 올리고 아직 저장하지 않은 파일을 지우지 않도록
     * 하루가 지난 것만 대상으로 한다.
     */
    public function cleanup_images($org_id)
    {
        $dir = FCPATH.$this->image_dir($org_id);

        if ( ! is_dir($dir))
        {
            return;
        }

        $used = array();

        foreach ($this->db->select('blocks')->where('org_id', (int) $org_id)->get('guide_articles')->result() as $row)
        {
            $blocks = json_decode((string) $row->blocks, TRUE);
            $used = array_merge($used, $this->image_paths(is_array($blocks) ? $blocks : array()));
        }

        $used = array_flip($used);

        foreach (glob($dir.'*.{png,jpg,webp}', GLOB_BRACE) ?: array() as $file)
        {
            $rel = $this->image_dir($org_id).basename($file);

            if ( ! isset($used[$rel]) && filemtime($file) < time() - 86400)
            {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------
    // 동의어
    // ------------------------------------------------------------------

    /**
     * 동의어 묶음 [[낱말, 낱말], ...]
     */
    public function synonym_groups($org_id)
    {
        $out = array();

        foreach ($this->db->where('org_id', (int) $org_id)->order_by('id')->get('guide_synonyms')->result() as $row)
        {
            $words = array_values(array_filter(array_map('trim', explode(',', $row->words)), 'strlen'));

            if (count($words) >= 2)
            {
                $out[] = $words;
            }
        }

        return $out;
    }

    /**
     * 동의어를 통째로 바꾼다. 묶음 하나는 낱말 2개 이상이어야 한다.
     *
     * @param array $groups [[낱말, ...], ...]
     * @return int 저장한 묶음 수
     */
    public function replace_synonyms($org_id, $groups)
    {
        $this->db->where('org_id', (int) $org_id)->delete('guide_synonyms');
        $saved = 0;

        foreach (array_slice((array) $groups, 0, 200) as $group)
        {
            $words = array();

            foreach ((array) $group as $word)
            {
                $word = mb_substr(trim(str_replace(',', ' ', (string) $word)), 0, 40);

                if ($word !== '' && ! in_array($word, $words, TRUE))
                {
                    $words[] = $word;
                }
            }

            if (count($words) < 2)
            {
                continue;
            }

            $this->db->insert('guide_synonyms', array(
                'org_id' => (int) $org_id,
                'words'  => mb_substr(implode(', ', $words), 0, 300),
            ));
            $saved++;
        }

        return $saved;
    }

    // ------------------------------------------------------------------
    // 색인
    // ------------------------------------------------------------------

    /**
     * 글 하나의 색인을 다시 만든다. 공개 글만 색인한다.
     */
    public function rebuild_index($org_id, $article_id)
    {
        $this->db->where('article_id', (int) $article_id)->delete('guide_terms');

        $article = $this->get_article($org_id, $article_id);

        if (empty($article) || $article->status !== 'published')
        {
            return 0;
        }

        $this->load->library('guide_matcher');
        $rows = array();

        foreach ($this->guide_matcher->index_terms((array) $article) as $term => $weight)
        {
            $rows[] = array(
                'org_id'     => (int) $org_id,
                'term'       => $term,
                'article_id' => (int) $article_id,
                'weight'     => (int) $weight,
            );
        }

        if ( ! empty($rows))
        {
            $this->db->insert_batch('guide_terms', $rows);
        }

        return count($rows);
    }

    public function rebuild_org_index($org_id)
    {
        $this->db->where('org_id', (int) $org_id)->delete('guide_terms');
        $count = 0;

        foreach ($this->db->select('id')->where('org_id', (int) $org_id)->get('guide_articles')->result() as $row)
        {
            $count += $this->rebuild_index($org_id, $row->id);
        }

        return $count;
    }

    // ------------------------------------------------------------------
    // guide.json
    // ------------------------------------------------------------------

    /**
     * 조직의 가이드 전체를 guide.json 구조로 만든다. 분류는 DB id 대신 key 로 잇는다.
     */
    public function export($org)
    {
        $categories = array();
        $keys = array();

        foreach ($this->list_categories($org->id) as $category)
        {
            $keys[$category->id] = 'c'.$category->id;
            $categories[] = array(
                'key'        => $keys[$category->id],
                'name'       => $category->name,
                'sort_order' => (int) $category->sort_order,
            );
        }

        $articles = array();

        foreach ($this->db->where('org_id', (int) $org->id)->order_by('id')->get('guide_articles')->result() as $row)
        {
            $row = $this->decode($row);
            $articles[] = array(
                'slug'       => $row->slug,
                'category'   => isset($keys[$row->category_id]) ? $keys[$row->category_id] : NULL,
                'title'      => $row->title,
                'subtitle'   => $row->subtitle,
                'keywords'   => $row->keywords ? array_map('trim', explode(',', $row->keywords)) : array(),
                'status'     => $row->status,
                'sort_order' => (int) $row->sort_order,
                'blocks'     => $row->blocks,
                'updated_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($row->updated_at.' UTC')),
            );
        }

        return array(
            'format'      => self::FORMAT,
            'version'     => self::VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'org_code'    => $org->org_code,
            'categories'  => $categories,
            'synonyms'    => $this->synonym_groups($org->id),
            'articles'    => $articles,
        );
    }

    /**
     * guide.json 을 가져온다.
     *
     * merge   : slug 가 같은 글은 덮어쓰고 나머지는 새로 만든다. 분류는 이름이 같으면 그대로 쓴다.
     * replace : 기존 글, 분류, 동의어를 지우고 파일 내용으로 바꾼다.
     *
     * @return array|string 결과 {created, updated, skipped, warnings} 또는 거부 사유
     */
    public function import($org_id, $data, $mode, $agent_id)
    {
        if ( ! is_array($data) || $this->value($data, 'format') !== self::FORMAT)
        {
            return 'RemoteHelp 가이드 파일(guide.json)이 아닙니다.';
        }

        if ((int) $this->value($data, 'version') !== self::VERSION)
        {
            return '지원하지 않는 파일 버전입니다('.(int) $this->value($data, 'version').').';
        }

        $mode = $mode === 'replace' ? 'replace' : 'merge';
        $articles = $this->value($data, 'articles', array());
        $articles = is_array($articles) ? array_values($articles) : array();

        if (count($articles) > self::MAX_ARTICLES)
        {
            return '가이드는 '.self::MAX_ARTICLES.'건까지 가져올 수 있습니다.';
        }

        if ($mode === 'merge' && $this->count_articles($org_id) + count($articles) > self::MAX_ARTICLES * 2)
        {
            return '가이드가 너무 많습니다. 전체 교체로 가져오거나 기존 글을 정리해 주세요.';
        }

        $result = array('created' => 0, 'updated' => 0, 'skipped' => array(), 'warnings' => array());

        $this->db->trans_start();

        if ($mode === 'replace')
        {
            // 이미지 파일은 남겨 둔다. 가져오는 파일이 같은 경로를 쓸 수 있고, 남는 파일은 cleanup_images 가 정리한다.
            $this->db->where('org_id', (int) $org_id)->delete('guide_articles');
            $this->db->where('org_id', (int) $org_id)->delete('guide_categories');
            $this->db->where('org_id', (int) $org_id)->delete('guide_synonyms');
        }

        // 분류: key -> 이 조직의 분류 id
        $category_map = array();
        $existing = array();

        foreach ($this->list_categories($org_id) as $category)
        {
            $existing[$category->name] = (int) $category->id;
        }

        foreach ((array) $this->value($data, 'categories', array()) as $category)
        {
            $key  = (string) $this->value($category, 'key');
            $name = mb_substr(trim((string) $this->value($category, 'name')), 0, 60);

            if ($key === '' || $name === '')
            {
                continue;
            }

            if ( ! isset($existing[$name]))
            {
                $existing[$name] = $this->save_category($org_id, 0, $name, (int) $this->value($category, 'sort_order'));
            }

            $category_map[$key] = $existing[$name];
        }

        // 동의어: 병합이면 기존 묶음 뒤에 새 묶음을 붙인다.
        $groups = $this->value($data, 'synonyms', array());

        if (is_array($groups) && ! empty($groups))
        {
            $current = $mode === 'merge' ? $this->synonym_groups($org_id) : array();
            $this->replace_synonyms($org_id, array_merge($current, $groups));
        }

        $saved_ids = array();

        foreach ($articles as $index => $item)
        {
            if ( ! is_array($item))
            {
                $result['skipped'][] = array('slug' => '', 'reason' => ($index + 1).'번째 항목 형식 오류');
                continue;
            }

            $category_key = (string) $this->value($item, 'category');
            $item['category_id'] = isset($category_map[$category_key]) ? $category_map[$category_key] : 0;

            $warnings = array();
            $clean = $this->clean_article($org_id, $item, $warnings);
            $label = (string) $this->value($item, 'slug') ?: mb_substr((string) $this->value($item, 'title'), 0, 40);

            if (is_string($clean))
            {
                $result['skipped'][] = array('slug' => $label, 'reason' => $clean);
                continue;
            }

            foreach ($warnings as $warning)
            {
                $result['warnings'][] = $label.': '.$warning;
            }

            $target_id = 0;

            if ($clean['slug'] !== NULL)
            {
                $row = $this->db->select('id')->get_where('guide_articles', array(
                    'org_id' => (int) $org_id,
                    'slug'   => $clean['slug'],
                ))->row();
                $target_id = $row ? (int) $row->id : 0;

                // 같은 파일 안에서 slug 가 겹치면 뒤의 것을 건너뛴다.
                if ($target_id > 0 && isset($saved_ids[$target_id]))
                {
                    $result['skipped'][] = array('slug' => $label, 'reason' => '파일 안에서 주소(slug)가 겹칩니다.');
                    continue;
                }
            }

            $id = $this->save_article($org_id, $target_id, $clean, $agent_id);
            $saved_ids[$id] = TRUE;
            $result[$target_id > 0 ? 'updated' : 'created']++;
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === FALSE)
        {
            return '가져오는 중 오류가 나서 아무것도 바꾸지 않았습니다.';
        }

        $this->cleanup_images($org_id);

        return $result;
    }
}
