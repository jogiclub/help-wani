<?php
/**
 * 파일 위치: application/libraries/Guide_matcher.php
 * 역할: 가이드 키워드 매칭 (문장 정규화, 색인 낱말 생성, 질문으로 가이드 찾기)
 *
 * 외부 API 를 부르지 않는다. 문자열 처리와 guide_terms 색인 조회 한 번으로 끝난다.
 * 색인과 질문에 같은 정규화를 거쳐야 맞으므로 정규화는 이 파일 한 곳에만 둔다. (docs/guide-plan.md 5장)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Guide_matcher {

    /** 색인 낱말 최대 길이 (guide_terms.term) */
    const TERM_MAX = 40;

    protected $CI;
    protected $conf;
    protected $suffixes;
    protected $stopwords;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('guide_match', TRUE);
        $this->conf = $this->CI->config->item('guide_match');

        $this->suffixes = $this->conf['suffixes'];
        // 긴 조사부터 비교해야 '에서' 를 '서' 로 잘못 떼지 않는다.
        usort($this->suffixes, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });
        $this->stopwords = array_flip($this->conf['stopwords']);
    }

    public function min_score($usage)
    {
        return isset($this->conf['min_score'][$usage]) ? (int) $this->conf['min_score'][$usage] : 1;
    }

    // ------------------------------------------------------------------
    // 정규화
    // ------------------------------------------------------------------

    /**
     * 문장을 낱말 목록으로 바꾼다. 소문자화, 기호 제거, 조사 떼기, 불용어 제거까지 한다.
     */
    public function words($text)
    {
        $text = (string) $text;

        // intl 확장이 있으면 전각/반각 등을 맞춘다. 없어도 동작한다.
        if (class_exists('Normalizer'))
        {
            $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);
            $text = $normalized === FALSE ? $text : $normalized;
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
        $out = array();

        foreach (preg_split('/\s+/u', trim($text)) as $word)
        {
            if ($word === '')
            {
                continue;
            }

            // '안녕하세요' 가 '안녕하' 로 남아 빠져나가지 않도록 조사를 떼기 전과 후를 모두 본다.
            if (isset($this->stopwords[$word]))
            {
                continue;
            }

            $word = $this->strip_suffix($word);

            if (mb_strlen($word) < 2 || isset($this->stopwords[$word]))
            {
                continue;
            }

            $out[] = mb_substr($word, 0, self::TERM_MAX);
        }

        return $out;
    }

    /**
     * 한글 낱말 끝의 조사/어미를 한 번만 뗀다.
     */
    protected function strip_suffix($word)
    {
        if ( ! preg_match('/\p{Hangul}$/u', $word))
        {
            return $word;
        }

        $length = mb_strlen($word);

        foreach ($this->suffixes as $suffix)
        {
            $cut = mb_strlen($suffix);

            if ($length - $cut >= 2 && mb_substr($word, -$cut) === $suffix)
            {
                return mb_substr($word, 0, $length - $cut);
            }
        }

        return $word;
    }

    /**
     * 3글자 이상 한글 낱말을 2글자 조각으로 나눈다. 붙여 쓴 말과 합성어를 잡기 위한 것이다.
     */
    public function grams($word)
    {
        if ( ! preg_match('/\p{Hangul}/u', $word) || mb_strlen($word) < 3)
        {
            return array();
        }

        $out = array();
        $length = mb_strlen($word);

        for ($i = 0; $i < $length - 1; $i++)
        {
            $gram = mb_substr($word, $i, 2);

            if (preg_match('/^\p{Hangul}{2}$/u', $gram))
            {
                $out[] = $gram;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 색인
    // ------------------------------------------------------------------

    /**
     * 글 하나의 색인 낱말과 가중치. 같은 낱말은 가장 높은 가중치 하나만 남긴다.
     *
     * @param array $article title, subtitle, keywords(문자열), blocks(배열)
     * @return array term => weight
     */
    public function index_terms($article)
    {
        $body = array();

        foreach ((array) $article['blocks'] as $block)
        {
            if ($block['type'] === 'text')
            {
                $body[] = $block['text'];
            }
            elseif ( ! empty($block['caption']))
            {
                $body[] = $block['caption'];
            }
        }

        $sources = array(
            'keywords' => str_replace(',', ' ', (string) $article['keywords']),
            'title'    => (string) $article['title'],
            'subtitle' => (string) $article['subtitle'],
            'body'     => implode(' ', $body),
        );

        $terms = array();

        foreach ($sources as $field => $text)
        {
            $weight = $this->conf['weights'][$field];

            foreach ($this->words($text) as $word)
            {
                $this->keep_max($terms, $word, $weight['word']);

                if ($weight['gram'] > 0)
                {
                    foreach ($this->grams($word) as $gram)
                    {
                        $this->keep_max($terms, $gram, $weight['gram']);
                    }
                }
            }
        }

        arsort($terms);

        return array_slice($terms, 0, (int) $this->conf['max_terms_per_article'], TRUE);
    }

    protected function keep_max(&$terms, $term, $weight)
    {
        if ( ! isset($terms[$term]) || $terms[$term] < $weight)
        {
            $terms[$term] = $weight;
        }
    }

    // ------------------------------------------------------------------
    // 질문
    // ------------------------------------------------------------------

    /**
     * 질문을 조회용 낱말 목록으로 바꾼다. 붙여 쓴 형태와 동의어도 함께 넣는다.
     *
     * @param array $synonyms 동의어 묶음 [[낱말, 낱말], ...] (정규화 전)
     */
    public function query_terms($text, $synonyms = array())
    {
        $words = array_slice($this->words($text), 0, (int) $this->conf['max_query_words']);
        $terms = array();

        foreach ($words as $index => $word)
        {
            $terms[$word] = TRUE;

            foreach ($this->grams($word) as $gram)
            {
                $terms[$gram] = TRUE;
            }

            // '원격 지원' 처럼 띄어 쓴 질문이 '원격지원' 키워드에 맞도록 이웃 낱말을 붙여 본다.
            if ($index > 0 && preg_match('/\p{Hangul}/u', $words[$index - 1].$word))
            {
                $terms[mb_substr($words[$index - 1].$word, 0, self::TERM_MAX)] = TRUE;
            }
        }

        foreach ($synonyms as $group)
        {
            $normalized = array();

            foreach ($group as $item)
            {
                $normalized[] = implode('', $this->words($item));
            }

            $normalized = array_values(array_filter($normalized));

            if (array_intersect($normalized, array_keys($terms)))
            {
                foreach ($normalized as $item)
                {
                    $terms[$item] = TRUE;
                }
            }
        }

        return array_keys($terms);
    }

    /**
     * 질문에 맞는 공개 가이드를 점수순으로 돌려준다.
     *
     * @return array [{id, slug, title, subtitle, score, hits}]
     */
    public function match($org_id, $text, $usage = 'console', $limit = 3, $exclude_ids = array())
    {
        $this->CI->load->model('guide_model');

        $terms = $this->query_terms($text, $this->CI->guide_model->synonym_groups($org_id));

        if (empty($terms))
        {
            return array();
        }

        $this->CI->db->select('t.article_id, SUM(t.weight) AS score, COUNT(*) AS hits, '
                            . 'a.slug, a.title, a.subtitle', FALSE)
                     ->from('guide_terms t')
                     ->join('guide_articles a', 'a.id = t.article_id')
                     ->where('t.org_id', (int) $org_id)
                     ->where('a.org_id', (int) $org_id)
                     ->where('a.status', 'published')
                     ->where_in('t.term', $terms);

        if ( ! empty($exclude_ids))
        {
            $this->CI->db->where_not_in('t.article_id', array_map('intval', $exclude_ids));
        }

        $rows = $this->CI->db->group_by('t.article_id')
                             ->having('score >=', $this->min_score($usage))
                             ->order_by('score', 'DESC')
                             ->order_by('hits', 'DESC')
                             ->limit((int) $limit)
                             ->get()
                             ->result();

        $out = array();

        foreach ($rows as $row)
        {
            $out[] = array(
                'id'       => (int) $row->article_id,
                'slug'     => $row->slug,
                'title'    => $row->title,
                'subtitle' => $row->subtitle,
                'score'    => (int) $row->score,
                'hits'     => (int) $row->hits,
            );
        }

        return $out;
    }
}
