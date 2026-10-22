<?php
/**
 * 파일 위치: application/models/Guide_model.php
 * 역할: 조직 가이드(분류, 글, 동의어) 저장/조회, 입력 검사, 매칭 색인 갱신, 외부 이미지 저장, guide.json 내보내기/가져오기
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

    /**
     * 공개 가이드의 모음 페이지 주소(/{조직코드}/guide/{낱말}). 글 주소(slug)로 쓸 수 없다.
     * routes.php 의 (:any)/guide/{낱말} 규칙과 같아야 한다(check-conventions 가 대조한다).
     */
    const RESERVED_SLUGS = array('features', 'videos');

    /** 새 기능 모음 한 쪽 개수, 동영상 모음 한 쪽 개수, [더 보기] 최대 쪽 */
    const FEATURE_PAGE = 20;
    const VIDEO_PAGE   = 12;
    const MAX_PAGE     = 50;

    /** 조직 시간대 캐시 (org_id => IANA 이름) */
    protected $timezones = array();

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
                        . 'a.is_feature, a.feature_at, a.feature_label, a.published_at, '
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
            'is_feature'    => empty($clean['is_feature']) ? 0 : 1,
            'feature_at'    => isset($clean['feature_at']) ? $clean['feature_at'] : NULL,
            'feature_label' => isset($clean['feature_label']) ? $clean['feature_label'] : NULL,
            'updated_by'  => $agent_id === NULL ? NULL : (int) $agent_id,
        );

        if ($id > 0)
        {
            $this->mark_published($clean['status']);
            $this->db->where('org_id', (int) $org_id)->where('id', (int) $id)->update('guide_articles', $data);
        }
        else
        {
            $data['org_id'] = (int) $org_id;
            $data['published_at'] = $clean['status'] === 'published' ? date('Y-m-d H:i:s') : NULL;
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

        $this->mark_published($status);
        $this->db->where('org_id', (int) $org_id)->where('id', (int) $id)
                 ->update('guide_articles', array('status' => $status));
        $this->rebuild_index($org_id, $id);

        return 1;
    }

    /**
     * 공개로 바꾸는 갱신이면 처음 공개한 시각(published_at)을 함께 찍는다. 이미 찍힌 값은 바꾸지 않는다.
     * 새 기능 출시일을 비워 두었을 때 이 값을 쓴다.
     */
    protected function mark_published($status)
    {
        if ($status === 'published')
        {
            $this->db->set('published_at', 'IFNULL(published_at, '.$this->db->escape(date('Y-m-d H:i:s')).')', FALSE);
        }
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

        if ($slug !== '' && ( ! preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $slug) || strlen($slug) > 60 || ctype_digit($slug)))
        {
            return '주소는 영문 소문자, 숫자, 하이픈(-), 밑줄(_)로 60자까지 쓸 수 있고 숫자만으로는 만들 수 없습니다.';
        }

        if (in_array($slug, self::RESERVED_SLUGS, TRUE))
        {
            return '"'.$slug.'" 는 공개 가이드의 모음 페이지 주소라 쓸 수 없습니다. 다른 주소를 입력해 주세요.';
        }

        $feature = $this->clean_feature($org_id, $input);

        if (is_string($feature))
        {
            return $feature;
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
            'is_feature'    => $feature['is_feature'],
            'feature_at'    => $feature['feature_at'],
            'feature_label' => $feature['feature_label'],
        );
    }

    /**
     * 새 기능 표시를 검사한다. 화면은 is_feature / feature_date / feature_label 로,
     * guide.json 은 feature: {date, label} 로 보낸다. 날짜는 조직 시간대의 날짜(YYYY-MM-DD)이며
     * 그날 00:00 을 UTC 로 바꿔 저장한다(CLAUDE.md 3.4). 비우면 처음 공개한 시각을 출시일로 쓴다.
     *
     * @return array|string {is_feature, feature_at, feature_label} 또는 거부 사유
     */
    protected function clean_feature($org_id, $input)
    {
        $json = $this->value($input, 'feature', NULL);

        if (is_array($json))
        {
            $is_feature = TRUE;
            $date  = (string) $this->value($json, 'date');
            $label = (string) $this->value($json, 'label');
        }
        else
        {
            $is_feature = in_array((string) $this->value($input, 'is_feature'), array('1', 'true', 'on'), TRUE);
            $date  = (string) $this->value($input, 'feature_date');
            $label = (string) $this->value($input, 'feature_label');
        }

        if ( ! $is_feature)
        {
            return array('is_feature' => 0, 'feature_at' => NULL, 'feature_label' => NULL);
        }

        $date = trim($date);
        $feature_at = NULL;

        if ($date !== '')
        {
            $local = DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone($this->org_timezone($org_id)));

            if ( ! $local || $local->format('Y-m-d') !== $date)
            {
                return '출시일은 YYYY-MM-DD 형식의 날짜여야 합니다.';
            }

            $local->setTimezone(new DateTimeZone('UTC'));
            $feature_at = $local->format('Y-m-d H:i:s');
        }

        return array(
            'is_feature'    => 1,
            'feature_at'    => $feature_at,
            'feature_label' => $this->nullable(mb_substr(trim($label), 0, 30)),
        );
    }

    /**
     * 조직 시간대(IANA). 허용 목록 밖이거나 없으면 Asia/Seoul.
     */
    public function org_timezone($org_id)
    {
        $org_id = (int) $org_id;

        if ( ! isset($this->timezones[$org_id]))
        {
            $row = $this->db->select('timezone')->get_where('organizations', array('id' => $org_id))->row();
            $tz = $row ? (string) $row->timezone : '';
            $this->timezones[$org_id] = in_array($tz, timezone_identifiers_list(), TRUE) ? $tz : 'Asia/Seoul';
        }

        return $this->timezones[$org_id];
    }

    /**
     * 새 기능 출시일(UTC). 지정한 출시일이 없으면 처음 공개한 시각.
     *
     * @param object|array $article
     */
    public static function feature_date($article)
    {
        $article = (array) $article;

        if ( ! empty($article['feature_at']))
        {
            return $article['feature_at'];
        }

        return empty($article['published_at']) ? NULL : $article['published_at'];
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

                // 이 조직이 올린 파일이나 외부 https 주소만 받는다. 다른 조직 경로, http, data:, javascript: 는 막는다.
                if ( ! $this->is_own_image($org_id, $src) && ! self::is_external_image($src))
                {
                    return '이 조직에 올린 이미지나 https 주소가 아니어서 뺐습니다('.mb_substr($src, 0, 80).').';
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

    /**
     * 외부 이미지 주소로 받을 수 있는지 본다. https 만 받는다(https 화면에 http 이미지는 브라우저가 막는다).
     * 다른 사이트의 가이드를 guide.json 으로 옮겨 올 때 쓴다. 파일은 그 사이트에 있으므로 지우거나 정리하지 않는다.
     */
    public static function is_external_image($src)
    {
        $src = (string) $src;

        if (strlen($src) > 1000 || ! preg_match('#^https://[^\s"\'<>]+$#i', $src))
        {
            return FALSE;
        }

        $host = parse_url($src, PHP_URL_HOST);

        return is_string($host) && $host !== '' && filter_var($src, FILTER_VALIDATE_URL) !== FALSE;
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
    // 외부 이미지를 조직 폴더로 저장
    // ------------------------------------------------------------------

    /** 외부 이미지 한 개의 최대 크기(2MB, 직접 올리는 이미지와 같다) */
    const FETCH_MAX_BYTES = 2097152;

    /** 리다이렉트를 따라가는 최대 횟수 */
    const FETCH_MAX_REDIRECTS = 3;

    /**
     * 이 조직 글의 외부 https 이미지를 받아 조직 폴더(image_dir)에 저장하고 블록 주소를 바꾼다.
     * 같은 주소는 한 번만 받는다. 받지 못한 이미지는 외부 주소 그대로 둔다.
     *
     * @return array saved(저장한 이미지 수), articles(바뀐 글 수), failed([[주소, 사유], ...])
     */
    public function localize_external_images($org_id, $agent_id = NULL)
    {
        $done   = array();   // 외부 주소 => 저장한 경로(실패하면 FALSE)
        $failed = array();
        $saved  = 0;
        $changed_articles = 0;

        $ids = $this->db->select('id')->where('org_id', (int) $org_id)->order_by('id', 'ASC')
                        ->get('guide_articles')->result();

        foreach ($ids as $row)
        {
            $article = $this->get_article($org_id, $row->id);
            $changed = FALSE;

            foreach ($article->blocks as $index => $block)
            {
                if ( ! isset($block['type'], $block['src']) || $block['type'] !== 'image'
                    || ! self::is_external_image($block['src']))
                {
                    continue;
                }

                $url = $block['src'];

                if ( ! array_key_exists($url, $done))
                {
                    $result = $this->fetch_external_image($org_id, $url);

                    if (is_array($result))
                    {
                        $done[$url] = FALSE;
                        $failed[]   = array($url, $result['error']);
                    }
                    else
                    {
                        $done[$url] = $result;
                        $saved++;
                    }
                }

                if ($done[$url] !== FALSE)
                {
                    $article->blocks[$index]['src'] = $done[$url];
                    $changed = TRUE;
                }
            }

            if ($changed)
            {
                // 블록 주소만 바꾼다. 나머지 값은 저장된 그대로 넘긴다(새 기능 표시도 넘겨야 지워지지 않는다).
                $this->save_article($org_id, $article->id, array(
                    'category_id' => $article->category_id,
                    'slug'        => $article->slug,
                    'title'       => $article->title,
                    'subtitle'    => $article->subtitle,
                    'keywords'    => $article->keywords,
                    'blocks'      => $article->blocks,
                    'status'      => $article->status,
                    'sort_order'  => (int) $article->sort_order,
                    'is_feature'    => (int) $article->is_feature,
                    'feature_at'    => $article->feature_at,
                    'feature_label' => $article->feature_label,
                ), $agent_id);
                $changed_articles++;
            }
        }

        return array('saved' => $saved, 'articles' => $changed_articles, 'failed' => $failed);
    }

    /**
     * 외부 이미지 한 개를 받아 조직 폴더에 저장한다.
     * 서버가 대신 요청하므로 내부망으로 향하지 않게 막는다(SSRF): https 만, 공인 IPv4 만,
     * 확인한 IP 로 고정해 접속(CURLOPT_RESOLVE), 리다이렉트는 직접 따라가며 매번 다시 검사한다.
     *
     * @return string|array 저장한 경로, 또는 array('error' => 사유)
     */
    protected function fetch_external_image($org_id, $url)
    {
        for ($hop = 0; $hop <= self::FETCH_MAX_REDIRECTS; $hop++)
        {
            if ( ! self::is_external_image($url))
            {
                return array('error' => 'https 주소가 아닙니다.');
            }

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
            $ip   = $this->public_ipv4($host);

            if ($ip === NULL)
            {
                return array('error' => '주소를 찾지 못했거나 내부망 주소입니다.');
            }

            $body = '';
            $too_big = FALSE;
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RESOLVE         => array($host.':'.$port.':'.$ip),
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION  => FALSE,
                CURLOPT_CONNECTTIMEOUT  => 5,
                CURLOPT_TIMEOUT         => 15,
                CURLOPT_SSL_VERIFYPEER  => TRUE,
                CURLOPT_SSL_VERIFYHOST  => 2,
                CURLOPT_USERAGENT       => 'RemoteHelp-Guide/1.0',
                CURLOPT_HTTPHEADER      => array('Accept: image/png,image/jpeg,image/webp,image/*;q=0.8'),
                CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$body, &$too_big) {
                    if (strlen($body) + strlen($chunk) > self::FETCH_MAX_BYTES)
                    {
                        $too_big = TRUE;
                        return 0;   // 받은 길이와 다르게 돌려주면 전송이 끊긴다.
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ));

            $ok       = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($too_big)
            {
                return array('error' => (self::FETCH_MAX_BYTES / 1048576).'MB 보다 큽니다.');
            }

            if ($ok === FALSE)
            {
                return array('error' => '받지 못했습니다('.mb_substr($error, 0, 80).').');
            }

            if ($status >= 300 && $status < 400 && $location !== '')
            {
                $url = $location;
                continue;
            }

            if ($status !== 200)
            {
                return array('error' => '응답 코드 '.$status.'.');
            }

            return $this->store_fetched_image($org_id, $body);
        }

        return array('error' => '리다이렉트가 너무 많습니다.');
    }

    /**
     * 호스트의 IPv4 주소 중 첫 번째를 돌려준다. 하나라도 사설/예약 대역이면 NULL(섞어 두고 고르는 우회를 막는다).
     */
    protected function public_ipv4($host)
    {
        if ($host === '' || $host === 'localhost')
        {
            return NULL;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : (gethostbynamel($host) ?: array());

        foreach ($ips as $ip)
        {
            if ( ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))
            {
                return NULL;
            }
        }

        return empty($ips) ? NULL : $ips[0];
    }

    /**
     * 받은 내용을 임시 파일로 쓰고, 올린 이미지와 같은 검사(image_file_ext)를 거쳐 조직 폴더로 옮긴다.
     */
    protected function store_fetched_image($org_id, $body)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rhimg');

        if ($tmp === FALSE || file_put_contents($tmp, $body) === FALSE)
        {
            return array('error' => '임시 파일을 만들지 못했습니다.');
        }

        $ext = image_file_ext($tmp);

        if ($ext === NULL)
        {
            @unlink($tmp);
            return array('error' => 'PNG, JPG, WEBP 이미지가 아닙니다.');
        }

        $dir = $this->image_dir($org_id);
        is_dir(FCPATH.$dir) OR @mkdir(FCPATH.$dir, 0775, TRUE);
        $src = $dir.bin2hex(random_bytes(10)).'.'.$ext;

        if ( ! @rename($tmp, FCPATH.$src))
        {
            @unlink($tmp);
            return array('error' => '저장하지 못했습니다. 업로드 폴더 권한을 확인해 주세요.');
        }

        @chmod(FCPATH.$src, 0664);

        return $src;
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
    // 공개 화면 (테마, 목차)
    // ------------------------------------------------------------------

    /**
     * 공개 가이드 테마. 키는 organizations.guide_theme 에 저장되는 값이자 views/guide/themes/{키}.php 이름이다.
     * 순서가 관리 화면의 번호(1~5)다.
     */
    public static function themes()
    {
        return array(
            'card'   => array('name' => '기본 카드형',     'desc' => '가운데 한 줄로 분류와 가이드를 보여줍니다. 가이드가 적을 때 알맞습니다.'),
            'docs'   => array('name' => '문서형 (왼쪽 목차)', 'desc' => '왼쪽에 번호 목차를 두고 오른쪽에 본문을 읽습니다. 사용 설명서처럼 글이 많을 때 알맞습니다.'),
            'portal' => array('name' => '헬프센터형',       'desc' => '큰 검색창과 분류 카드로 시작합니다. 고객이 먼저 검색하도록 이끌 때 알맞습니다.'),
            'faq'    => array('name' => 'FAQ 아코디언형',   'desc' => '질문을 누르면 그 자리에서 답이 펼쳐집니다. 짧은 자주 묻는 질문에 알맞습니다.'),
            'dark'   => array('name' => '다크 미니멀형',    'desc' => '어두운 배경에 큰 글씨로 읽습니다. 기술 서비스나 야간 사용이 많을 때 알맞습니다.'),
        );
    }

    /**
     * 조직 테마. 허용 목록 밖이면 기본(card).
     */
    public static function theme_of($org)
    {
        $themes = self::themes();
        $theme  = isset($org->guide_theme) ? (string) $org->guide_theme : 'card';

        return isset($themes[$theme]) ? $theme : 'card';
    }

    public function set_theme($org_id, $theme)
    {
        $themes = self::themes();

        if ( ! isset($themes[$theme]))
        {
            return 0;
        }

        $this->db->where('id', (int) $org_id)->update('organizations', array('guide_theme' => $theme));

        return 1;
    }

    // ------------------------------------------------------------------
    // 공개 페이지 구성 (새 기능 모음, 동영상 모음) — docs/guide-collections-plan.md
    // ------------------------------------------------------------------

    /** NEW 배지 기간(일). 0 은 끔 */
    public static function new_days_options()
    {
        return array(0, 7, 14, 30, 60, 90);
    }

    /** 동영상 정렬 */
    public static function video_sorts()
    {
        return array('toc' => '가이드 목차 순', 'updated' => '최근 수정순');
    }

    /**
     * 조직의 공개 페이지 구성. 옵션 목록과 기본값은 여기 한 곳에 둔다.
     */
    public static function page_options($org)
    {
        $days  = isset($org->guide_feature_new_days) ? (int) $org->guide_feature_new_days : 30;
        $sort  = isset($org->guide_video_sort) ? (string) $org->guide_video_sort : 'toc';
        $sorts = self::video_sorts();

        return array(
            'feature_enabled'  => ! empty($org->guide_feature_enabled),
            'feature_new_days' => in_array($days, self::new_days_options(), TRUE) ? $days : 30,
            'feature_banner'   => ! empty($org->guide_feature_banner),
            'video_enabled'    => ! empty($org->guide_video_enabled),
            'video_sort'       => isset($sorts[$sort]) ? $sort : 'toc',
            'video_filter'     => ! empty($org->guide_video_filter),
        );
    }

    /**
     * 공개 페이지 구성을 저장한다. 허용 목록 밖의 값은 거부한다.
     *
     * @return array|string 저장한 값(page_options 형식) 또는 거부 사유
     */
    public function set_page_options($org_id, $input)
    {
        $days = (string) $this->value($input, 'feature_new_days', '30');
        $sort = (string) $this->value($input, 'video_sort', 'toc');

        if ( ! ctype_digit($days) || ! in_array((int) $days, self::new_days_options(), TRUE))
        {
            return 'NEW 배지 기간을 목록에서 골라 주세요.';
        }

        if ( ! array_key_exists($sort, self::video_sorts()))
        {
            return '동영상 정렬을 목록에서 골라 주세요.';
        }

        $flag = function ($key) use ($input) {
            return in_array((string) $this->value($input, $key), array('1', 'true', 'on'), TRUE) ? 1 : 0;
        };

        $data = array(
            'guide_feature_enabled'  => $flag('feature_enabled'),
            'guide_feature_new_days' => (int) $days,
            'guide_feature_banner'   => $flag('feature_banner'),
            'guide_video_enabled'    => $flag('video_enabled'),
            'guide_video_sort'       => $sort,
            'guide_video_filter'     => $flag('video_filter'),
        );

        $this->db->where('id', (int) $org_id)->update('organizations', $data);

        return self::page_options((object) $data);
    }

    /** 새 기능으로 표시된 공개 글 수 */
    public function count_features($org_id)
    {
        return (int) $this->db->where('org_id', (int) $org_id)
                              ->where('status', 'published')
                              ->where('is_feature', 1)
                              ->count_all_results('guide_articles');
    }

    /**
     * 새 기능으로 표시된 공개 글. 출시일(없으면 첫 공개 시각) 최신순.
     * 각 행에 feature_date(UTC) 를 붙인다.
     */
    public function public_features($org_id, $limit)
    {
        $rows = $this->db->select('id, slug, title, subtitle, category_id, feature_at, feature_label, published_at, updated_at')
                         ->where('org_id', (int) $org_id)
                         ->where('status', 'published')
                         ->where('is_feature', 1)
                         ->order_by('COALESCE(feature_at, published_at, updated_at)', 'DESC', FALSE)
                         ->order_by('id', 'DESC')
                         ->limit((int) $limit)
                         ->get('guide_articles')
                         ->result();

        foreach ($rows as $row)
        {
            $row->feature_date = self::feature_date($row) ?: $row->updated_at;
        }

        return $rows;
    }

    /**
     * NEW 배지를 붙일 글 id (id => TRUE). 새 기능 모음이 꺼져 있거나 기간이 0 이면 빈 배열.
     */
    public function new_feature_ids($org)
    {
        $options = self::page_options($org);

        if ( ! $options['feature_enabled'] || $options['feature_new_days'] === 0)
        {
            return array();
        }

        $since = gmdate('Y-m-d H:i:s', time() - $options['feature_new_days'] * 86400);
        $rows = $this->db->select('id')
                         ->where('org_id', (int) $org->id)
                         ->where('status', 'published')
                         ->where('is_feature', 1)
                         ->where('COALESCE(feature_at, published_at) >=', $since)
                         ->get('guide_articles')
                         ->result();
        $ids = array();

        foreach ($rows as $row)
        {
            $ids[(int) $row->id] = TRUE;
        }

        return $ids;
    }

    /**
     * 공개 글의 유튜브 동영상을 모은다. 따로 저장하지 않고 본문 블록에서 그때그때 꺼낸다(원본은 가이드 본문 하나).
     * 같은 영상이 여러 글에 있으면 처음 나온 것 하나만 두고, 그 영상이 있는 글을 articles 에 함께 담는다.
     *
     * @param string $sort toc(가이드 목차 순) | updated(최근 수정순)
     * @return array [{youtube_id, caption, category_id, category_name, articles: [{id, slug, title}]}]
     */
    public function public_videos($org_id, $sort = 'toc')
    {
        $this->db->select('a.id, a.slug, a.title, a.category_id, a.blocks, c.name AS category_name')
                 ->from('guide_articles a')
                 ->join('guide_categories c', 'c.id = a.category_id', 'left')
                 ->where('a.org_id', (int) $org_id)
                 ->where('a.status', 'published')
                 // 동영상 블록이 있는 글만 읽는다.
                 ->where("JSON_SEARCH(a.blocks, 'one', 'video', NULL, '$[*].type') IS NOT NULL", NULL, FALSE);

        if ($sort === 'updated')
        {
            $this->db->order_by('a.updated_at', 'DESC')->order_by('a.id', 'DESC');
        }
        else
        {
            // list_articles() 와 같은 목차 순서
            $this->db->order_by('a.category_id IS NULL', 'ASC', FALSE)
                     ->order_by('c.sort_order', 'ASC')
                     ->order_by('a.category_id', 'ASC')
                     ->order_by('a.sort_order', 'ASC')
                     ->order_by('a.id', 'ASC');
        }

        $videos = array();

        foreach ($this->db->get()->result() as $row)
        {
            $row = $this->decode($row);
            $article = array('id' => (int) $row->id, 'slug' => $row->slug, 'title' => $row->title);

            foreach ($row->blocks as $block)
            {
                if ( ! isset($block['type'], $block['youtube_id']) || $block['type'] !== 'video'
                    || ! preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $block['youtube_id']))
                {
                    continue;
                }

                $id = $block['youtube_id'];

                if (isset($videos[$id]))
                {
                    if ( ! in_array($article, $videos[$id]['articles'], TRUE))
                    {
                        $videos[$id]['articles'][] = $article;
                    }
                    continue;
                }

                $videos[$id] = array(
                    'youtube_id'    => $id,
                    'caption'       => isset($block['caption']) ? (string) $block['caption'] : '',
                    'category_id'   => $row->category_id ? (int) $row->category_id : 0,
                    'category_name' => $row->category_name ?: lang_text('guide.uncategorized'),
                    'articles'      => array($article),
                );
            }
        }

        return array_values($videos);
    }

    /**
     * 공개 목차. 분류별 묶음과, 이전/다음 글을 찾기 위한 한 줄 목록을 함께 돌려준다.
     *
     * @return array {groups: [{id, name, articles: [..]}], flat: [..]}
     */
    public function public_nav($org_id)
    {
        $groups = array();
        $flat = array();

        foreach ($this->list_articles($org_id, TRUE) as $article)
        {
            $key = $article->category_id ? (int) $article->category_id : 0;

            if ( ! isset($groups[$key]))
            {
                $groups[$key] = array(
                    'id'       => $key,
                    'name'     => $article->category_name ?: lang_text('guide.uncategorized'),
                    'articles' => array(),
                );
            }

            $groups[$key]['articles'][] = $article;
            $flat[] = $article;
        }

        return array('groups' => array_values($groups), 'flat' => $flat);
    }

    /**
     * 공개 화면(목록/검색/상세) 공통 데이터. 공개 화면과 관리자 미리보기가 같은 값을 쓴다.
     * 테마 뷰(views/guide/themes/*.php)는 이 값만 보고 그린다.
     *
     * @param object|null $article 상세면 글, 목록이면 NULL
     * @param string      $q       검색어(목록에서만)
     * @param string      $view    list | features | videos (모음 페이지는 Guide 컨트롤러가 옵션을 확인한 뒤 넘긴다)
     * @param array       $params  모음 페이지의 page, category
     */
    public function page_data($org, $article = NULL, $q = '', $theme = NULL, $view = 'list', $params = array())
    {
        $nav = $this->public_nav($org->id);
        $results = NULL;
        $view = in_array($view, array('features', 'videos'), TRUE) ? $view : 'list';

        if ($article === NULL && $q !== '')
        {
            $this->load->library('guide_matcher');
            $results = $this->guide_matcher->match($org->id, $q, 'search', 20);

            // 낱말이 하나도 안 맞으면 제목/부제목에 그대로 들어 있는지 한 번 더 본다(1글자 질문 등).
            if (empty($results))
            {
                $results = $this->title_contains($org->id, $q);
            }
        }

        // 상세: 분류 이름과 목차상 이전/다음 글
        $prev = $next = NULL;
        $category = '';

        if ($article !== NULL)
        {
            foreach ($nav['flat'] as $index => $item)
            {
                if ((int) $item->id === (int) $article->id)
                {
                    $prev = $index > 0 ? $nav['flat'][$index - 1] : NULL;
                    $next = isset($nav['flat'][$index + 1]) ? $nav['flat'][$index + 1] : NULL;
                    $category = $item->category_name ?: '';
                }
            }

            if ($category === '' && $article->category_id)
            {
                $row = $this->get_category($org->id, $article->category_id);
                $category = $row ? $row->name : '';
            }
        }

        $theme = $theme ?: self::theme_of($org);

        // FAQ 테마는 목록에서 답을 그 자리에서 펼치므로 본문까지 읽는다. 다른 테마는 목록에 본문이 필요 없다.
        $bodies = array();

        if ($theme === 'faq' && $article === NULL && $results === NULL)
        {
            $rows = $this->db->select('id, blocks, updated_at')
                             ->where('org_id', (int) $org->id)
                             ->where('status', 'published')
                             ->get('guide_articles')
                             ->result();

            foreach ($rows as $row)
            {
                $bodies[(int) $row->id] = $this->decode($row);
            }
        }

        // 테마 강조색은 상담 위젯 색을 따른다. 허용 형식이 아니면 기본 파랑.
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $org->widget_color) ? $org->widget_color : '#2563eb';

        $base = base_url($org->org_code.'/guide');
        $options = self::page_options($org);
        $collection = $this->collection_data($org, $view, $options, $base, $params);

        // 첫 화면(검색 전 목록)의 최근 새 기능 띠
        $strip = array();

        if ($view === 'list' && $article === NULL && $results === NULL && $options['feature_enabled'] && $options['feature_banner'])
        {
            $strip = $this->public_features($org->id, 3);
        }

        // 탭은 켜 둔 모음이 있을 때만 보인다. 관리자 미리보기로 연 모음은 꺼져 있어도 탭에 넣는다.
        $tabs = array();

        if ($options['feature_enabled'] || $options['video_enabled'] || $view !== 'list')
        {
            $tabs[] = array('key' => 'list', 'label' => lang_text('guide.tab_all'), 'url' => $base);

            if ($options['feature_enabled'] || $view === 'features')
            {
                $tabs[] = array('key' => 'features', 'label' => lang_text('guide.tab_features'), 'url' => $base.'/features');
            }

            if ($options['video_enabled'] || $view === 'videos')
            {
                $tabs[] = array('key' => 'videos', 'label' => lang_text('guide.tab_videos'), 'url' => $base.'/videos');
            }
        }

        return $collection + array(
            'view'      => $view,
            'options'   => $options,
            'tabs'      => $tabs,
            'strip'     => $strip,
            'new_ids'   => $this->new_feature_ids($org),
            'timezone'  => $this->org_timezone($org->id),
            'org'       => $org,
            'theme'     => $theme,
            'bodies'    => $bodies,
            'accent'    => $accent,
            'base'      => $base,
            'groups'    => $nav['groups'],
            'total'     => count($nav['flat']),
            'q'         => $q,
            'results'   => $results,
            'article'   => $article,
            'category'  => $category,
            'prev'      => $prev,
            'next'      => $next,
        );
    }

    /**
     * 모음 페이지 데이터. [더 보기] 는 page 쪽까지 이어서 보여준다(?page=2 면 처음부터 2쪽 분량).
     */
    protected function collection_data($org, $view, $options, $base, $params)
    {
        $page = isset($params['page']) ? max(1, min(self::MAX_PAGE, (int) $params['page'])) : 1;
        $data = array('page' => $page, 'more_url' => '', 'features' => array(), 'videos' => array(),
                      'video_total' => 0, 'video_categories' => array(), 'video_category' => 0);

        if ($view === 'features')
        {
            $limit = $page * self::FEATURE_PAGE;
            $rows = $this->public_features($org->id, $limit + 1);
            $tz = $this->org_timezone($org->id);

            if (count($rows) > $limit)
            {
                $rows = array_slice($rows, 0, $limit);
                $data['more_url'] = $base.'/features?page='.($page + 1);
            }

            // 조직 시간대 기준 월별 묶음
            foreach ($rows as $row)
            {
                $month = to_timezone($row->feature_date, $tz, 'Y-m');

                if ( ! isset($data['features'][$month]))
                {
                    list($year, $mon) = explode('-', $month);
                    $data['features'][$month] = array(
                        'label' => lang_text('guide.month_label', array('year' => $year, 'month' => (int) $mon)),
                        'items' => array(),
                    );
                }

                $data['features'][$month]['items'][] = $row;
            }

            $data['features'] = array_values($data['features']);
        }
        elseif ($view === 'videos')
        {
            $videos = $this->public_videos($org->id, $options['video_sort']);

            // 분류 거르기: 동영상이 있는 분류만 고를 수 있다(허용 목록).
            if ($options['video_filter'])
            {
                foreach ($videos as $video)
                {
                    $data['video_categories'][$video['category_id']] = $video['category_name'];
                }

                $category = isset($params['category']) ? (int) $params['category'] : 0;

                if (isset($data['video_categories'][$category]) && count($data['video_categories']) > 1)
                {
                    $data['video_category'] = $category;
                    $videos = array_values(array_filter($videos, function ($video) use ($category) {
                        return $video['category_id'] === $category;
                    }));
                }
            }

            $limit = $page * self::VIDEO_PAGE;
            $data['video_total'] = count($videos);
            $data['videos'] = array_slice($videos, 0, $limit);

            if (count($videos) > $limit)
            {
                $query = array('page' => $page + 1);

                if ($data['video_category'] > 0)
                {
                    $query['category'] = $data['video_category'];
                }

                $data['more_url'] = $base.'/videos?'.http_build_query($query);
            }
        }

        return $data;
    }

    protected function title_contains($org_id, $q)
    {
        $rows = $this->db->select('id, slug, title, subtitle')
                         ->where('org_id', (int) $org_id)
                         ->where('status', 'published')
                         ->group_start()->like('title', $q)->or_like('subtitle', $q)->group_end()
                         ->limit(20)
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

    // ------------------------------------------------------------------
    // 샘플
    // ------------------------------------------------------------------

    /**
     * 가이드 관리를 처음 열 때 글이 없으면 샘플(config/guide_samples.php)을 만든다.
     * 조직마다 한 번만 한다. 표시(guide_seeded_at)를 먼저 찍어 동시에 두 번 열어도 한 번만 만들고,
     * 관리자가 샘플을 모두 지운 뒤 다시 열어도 되살리지 않는다. 이미 글이 있는 조직은 표시만 찍는다.
     * 샘플은 모두 임시(draft) 상태라 고객 화면과 상담 추천에 바로 나오지 않는다.
     *
     * @return int 만든 글 수
     */
    public function seed_samples($org, $agent_id = NULL)
    {
        $this->db->where('id', (int) $org->id)
                 ->where('guide_seeded_at IS NULL', NULL, FALSE)
                 ->update('organizations', array('guide_seeded_at' => date('Y-m-d H:i:s')));

        if ($this->db->affected_rows() === 0 || $this->count_articles($org->id) > 0)
        {
            return 0;
        }

        $this->config->load('guide_samples', TRUE);
        $samples = $this->config->item('guide_samples');

        $replace = array(
            '{org}'          => $org->name,
            '{phone}'        => $org->phone ?: '-',
            '{customer_url}' => customer_url($org->org_code),
        );

        $category_ids = array();

        foreach ($samples['categories'] as $category)
        {
            $category_ids[$category['key']] = $this->save_category($org->id, 0, $category['name'], $category['sort_order']);
        }

        $this->replace_synonyms($org->id, $samples['synonyms']);
        $created = 0;

        foreach ($samples['articles'] as $article)
        {
            $blocks = array();

            foreach ($article['blocks'] as $block)
            {
                if ($block['type'] === 'text')
                {
                    $block['text'] = strtr($block['text'], $replace);
                }
                elseif ($block['type'] === 'image')
                {
                    $block['src'] = $this->copy_sample_image($org->id, $block['sample']);
                    unset($block['sample']);

                    if ($block['src'] === NULL)
                    {
                        continue;
                    }
                }

                $blocks[] = $block;
            }

            $article['blocks'] = $blocks;
            $article['status'] = 'draft';
            $article['category_id'] = isset($category_ids[$article['category']]) ? $category_ids[$article['category']] : 0;

            $clean = $this->clean_article($org->id, $article);

            // 같은 주소가 있으면 주소 없이 만든다(번호로 접속).
            if (is_array($clean) && $this->slug_taken($org->id, $clean['slug']))
            {
                $clean['slug'] = NULL;
            }

            if (is_array($clean))
            {
                $this->save_article($org->id, 0, $clean, $agent_id);
                $created++;
            }
        }

        return $created;
    }

    /**
     * 샘플 이미지(assets/img/guide-samples/)를 조직 이미지 폴더로 복사한다. 실패하면 NULL.
     * 조직 폴더에 있어야 글 블록에 들어갈 수 있다(is_own_image).
     */
    protected function copy_sample_image($org_id, $name)
    {
        if ( ! preg_match('/^[a-z0-9-]+\.(png|jpg|webp)$/', (string) $name, $m))
        {
            return NULL;
        }

        $from = FCPATH.'assets/img/guide-samples/'.$name;
        $dir  = $this->image_dir($org_id);
        is_dir(FCPATH.$dir) OR @mkdir(FCPATH.$dir, 0775, TRUE);
        $src  = $dir.bin2hex(random_bytes(10)).'.'.$m[1];

        return (is_file($from) && @copy($from, FCPATH.$src)) ? $src : NULL;
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
                // 새 기능 표시. 날짜는 사람이 고치는 형식이라 조직 시간대 날짜로 내보낸다.
                // 출시일을 비워 둔 글은 첫 공개 날짜를 적는다(다시 가져와도 날짜가 바뀌지 않게).
                'feature'    => (int) $row->is_feature === 1 ? array(
                    'date'  => self::feature_date($row) ? to_timezone(self::feature_date($row), $this->org_timezone($org->id), 'Y-m-d') : '',
                    'label' => (string) $row->feature_label,
                ) : NULL,
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

            // 모음 페이지 주소(features, videos)는 글 주소로 쓸 수 없으므로 비우고(번호로 접속) 알린다.
            if (in_array(strtolower(trim((string) $this->value($item, 'slug'))), self::RESERVED_SLUGS, TRUE))
            {
                $warnings[] = '주소 "'.$item['slug'].'" 는 모음 페이지 주소라 비웠습니다(번호로 접속).';
                $item['slug'] = '';
            }

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
