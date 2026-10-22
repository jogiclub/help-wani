<?php
/**
 * 파일 위치: application/models/Macro_model.php
 * 역할: 상담 대화 매크로(자주 쓰는 답변) 저장/조회, 입력 검사, 권한 판정, 즐겨찾기, 사용 기록, 추천 색인, CSV 가져오기/내보내기
 *
 * macros 테이블은 이 모델만 고친다. 저장할 때 검사(clean_macro)와 추천 색인(macro_terms)을 함께 처리하므로
 * 다른 곳에서 직접 고치면 추천과 이름 중복 검사가 어긋난다. (docs/macro-plan.md)
 *
 * 범위: owner_agent_id 가 NULL 이면 조직 공용(scope 'org'), 값이 있으면 그 상담원의 개인 매크로(scope 'personal').
 * 공용은 조직 관리자만 고치고, 개인은 본인만 보고 고친다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Macro_model extends CI_Model {

    /** CSV 머리글. 한글 머리글도 받는다. */
    const CSV_COLUMNS = array('name', 'content', 'keywords');

    protected $conf;

    public function __construct()
    {
        parent::__construct();
        $this->config->load('macro', TRUE);
        $this->conf = $this->config->item('macro');
    }

    public function conf($key)
    {
        return $this->conf[$key];
    }

    /**
     * 편집 드로어(views/console/_macro_edit.php)와 입력창 / 목록이 쓰는 화면 설정.
     */
    public function editor_conf($agent)
    {
        return array(
            'isAdmin'      => $agent->role === 'admin',
            'nameMax'      => (int) $this->conf['name_max'],
            'bodyMax'      => (int) $this->conf['body_max'],
            'keywordsMax'  => (int) $this->conf['keywords_max'],
            'maxFavorites' => (int) $this->conf['max_favorites'],
            'variables'    => $this->conf['variables'],
        );
    }

    // ------------------------------------------------------------------
    // 조회와 권한
    // ------------------------------------------------------------------

    /**
     * 상담원이 쓸 수 있는 매크로 전체(공용 + 본인 개인)와 본인 즐겨찾기 여부.
     * `/` 목록과 관리 목록이 함께 쓴다. 개인 → 공용, 최근 사용 순.
     */
    public function list_for_agent($agent)
    {
        $rows = $this->db->query("
            SELECT m.id, m.owner_agent_id, m.name, m.body, m.keywords, m.use_count, m.last_used_at,
                   m.created_at, m.updated_at, a.name AS created_by_name,
                   f.sort_order AS fav_order
            FROM macros m
            LEFT JOIN agents a ON a.id = m.created_by
            LEFT JOIN macro_favorites f ON f.macro_id = m.id AND f.agent_id = ?
            WHERE m.org_id = ? AND (m.owner_agent_id IS NULL OR m.owner_agent_id = ?)
            ORDER BY (m.owner_agent_id IS NULL) ASC, m.last_used_at IS NULL ASC, m.last_used_at DESC, m.name ASC
        ", array((int) $agent->id, (int) $agent->org_id, (int) $agent->id))->result();

        return array_map(function ($row) use ($agent) {
            return $this->present($row, $agent);
        }, $rows);
    }

    public function present($row, $agent)
    {
        $scope = $row->owner_agent_id === NULL ? 'org' : 'personal';

        return array(
            'id'              => (int) $row->id,
            'scope'           => $scope,
            'name'            => $row->name,
            'body'            => $row->body,
            'keywords'        => (string) $row->keywords,
            'use_count'       => (int) $row->use_count,
            'last_used_at'    => $row->last_used_at,
            'created_at'      => $row->created_at,
            'updated_at'      => $row->updated_at,
            'created_by_name' => isset($row->created_by_name) ? (string) $row->created_by_name : '',
            'favorite'        => isset($row->fav_order) && $row->fav_order !== NULL,
            'fav_order'       => isset($row->fav_order) && $row->fav_order !== NULL ? (int) $row->fav_order : NULL,
            'can_edit'        => $this->can_edit_scope($agent, $scope),
        );
    }

    /**
     * 상담원이 쓸 수 있는(보이는) 매크로 하나. 다른 조직이나 다른 사람의 개인 매크로면 NULL.
     */
    public function get_usable($agent, $id)
    {
        $row = $this->db->get_where('macros', array('id' => (int) $id, 'org_id' => (int) $agent->org_id))->row();

        if (empty($row) || ($row->owner_agent_id !== NULL && (int) $row->owner_agent_id !== (int) $agent->id))
        {
            return NULL;
        }

        return $row;
    }

    /**
     * 상담원이 고칠 수 있는 매크로 하나. 공용은 조직 관리자만, 개인은 본인만.
     */
    public function get_editable($agent, $id)
    {
        $row = $this->get_usable($agent, $id);

        if (empty($row) || ! $this->can_edit_scope($agent, $row->owner_agent_id === NULL ? 'org' : 'personal'))
        {
            return NULL;
        }

        return $row;
    }

    public function can_edit_scope($agent, $scope)
    {
        return $scope === 'personal' || ($scope === 'org' && $agent->role === 'admin');
    }

    /**
     * 범위 값 허용 목록 검사. 틀리면 NULL.
     */
    public function scope_value($value)
    {
        return in_array($value, array('org', 'personal'), TRUE) ? $value : NULL;
    }

    protected function owner_for($agent, $scope)
    {
        return $scope === 'org' ? NULL : (int) $agent->id;
    }

    protected function count_in_scope($agent, $scope)
    {
        $this->db->where('org_id', (int) $agent->org_id);

        if ($scope === 'org')
        {
            $this->db->where('owner_agent_id IS NULL', NULL, FALSE);
        }
        else
        {
            $this->db->where('owner_agent_id', (int) $agent->id);
        }

        return (int) $this->db->count_all_results('macros');
    }

    protected function scope_limit($scope)
    {
        return (int) ($scope === 'org' ? $this->conf['max_org'] : $this->conf['max_personal']);
    }

    protected function find_by_name($agent, $scope, $name)
    {
        return $this->db->get_where('macros', array(
            'org_id'    => (int) $agent->org_id,
            'owner_key' => (int) $this->owner_for($agent, $scope),
            'name'      => $name,
        ))->row();
    }

    // ------------------------------------------------------------------
    // 입력 검사
    // ------------------------------------------------------------------

    /**
     * 화면 저장과 파일 가져오기가 함께 쓰는 검사. 통과하면 정리한 값, 아니면 [필드, 문구].
     *
     * @param array $input name, body, keywords
     * @return array ['ok' => array] 또는 ['error' => [field, message]]
     */
    public function clean_macro($input)
    {
        $name = $this->one_line(isset($input['name']) ? $input['name'] : '');
        $body = $this->multi_line(isset($input['body']) ? $input['body'] : '');
        $keywords = $this->one_line(isset($input['keywords']) ? $input['keywords'] : '');

        if ($name === '')
        {
            return array('error' => array('name', '이름을 입력해 주세요.'));
        }

        if (mb_strlen($name) > $this->conf['name_max'])
        {
            return array('error' => array('name', '이름은 '.$this->conf['name_max'].'자까지입니다.'));
        }

        if ($body === '')
        {
            return array('error' => array('body', '내용을 입력해 주세요.'));
        }

        if (mb_strlen($body) > $this->conf['body_max'])
        {
            return array('error' => array('body', '내용은 '.number_format($this->conf['body_max']).'자까지입니다.'));
        }

        // 키워드는 쉼표로 나눠 빈 칸과 중복을 지운다.
        $parts = array();

        foreach (explode(',', $keywords) as $word)
        {
            $word = trim($word);

            if ($word !== '' && ! in_array($word, $parts, TRUE))
            {
                $parts[] = $word;
            }
        }

        $keywords = implode(', ', $parts);

        if (mb_strlen($keywords) > $this->conf['keywords_max'])
        {
            return array('error' => array('keywords', '키워드는 '.$this->conf['keywords_max'].'자까지입니다.'));
        }

        return array('ok' => array(
            'name'     => $name,
            'body'     => $body,
            'keywords' => $keywords === '' ? NULL : $keywords,
        ));
    }

    /**
     * 내용에 들어 있는 변수 중 모르는 것. 저장은 막지 않고 경고만 한다.
     */
    public function unknown_variables($body)
    {
        preg_match_all('/\{([a-z_]+)\}/', (string) $body, $matches);

        return array_values(array_unique(array_diff($matches[1], array_keys($this->conf['variables']))));
    }

    protected function one_line($value)
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    protected function multi_line($value)
    {
        $value = str_replace(array("\r\n", "\r"), "\n", (string) $value);
        // 줄바꿈과 탭만 남기고 제어 문자를 지운다.
        $value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $value);

        return trim($value);
    }

    // ------------------------------------------------------------------
    // 저장
    // ------------------------------------------------------------------

    /**
     * 만들기/고치기. $id 가 있으면 고치기이며 범위는 바꾸지 않는다.
     *
     * @return int|string 저장한 id 또는 오류 문구
     */
    public function save($agent, $scope, $input, $id = 0)
    {
        $clean = $this->clean_macro($input);

        if (isset($clean['error']))
        {
            return $clean['error'][1];
        }

        $data = $clean['ok'];

        if ($id > 0)
        {
            $row = $this->get_editable($agent, $id);

            if (empty($row))
            {
                return '매크로를 찾을 수 없거나 고칠 권한이 없습니다.';
            }

            $scope = $row->owner_agent_id === NULL ? 'org' : 'personal';
        }
        elseif ( ! $this->can_edit_scope($agent, $scope))
        {
            return '공용 매크로는 조직 관리자만 만들 수 있습니다.';
        }

        $same = $this->find_by_name($agent, $scope, $data['name']);

        if ($same && (int) $same->id !== (int) $id)
        {
            return '같은 이름의 매크로가 이미 있습니다.';
        }

        if ($id > 0)
        {
            $data['updated_by'] = (int) $agent->id;
            $this->db->where('id', (int) $id)->update('macros', $data);
        }
        else
        {
            if ($this->count_in_scope($agent, $scope) >= $this->scope_limit($scope))
            {
                return ($scope === 'org' ? '공용' : '개인').' 매크로는 '.number_format($this->scope_limit($scope)).'개까지 만들 수 있습니다.';
            }

            $owner = $this->owner_for($agent, $scope);
            $data['org_id'] = (int) $agent->org_id;
            $data['owner_agent_id'] = $owner;
            $data['owner_key'] = (int) $owner;
            $data['created_by'] = (int) $agent->id;
            $data['updated_by'] = (int) $agent->id;
            $this->db->insert('macros', $data);
            $id = (int) $this->db->insert_id();
        }

        $this->reindex((int) $agent->org_id, $id, $data['name'], $data['keywords'], $data['body']);

        return (int) $id;
    }

    /**
     * 고칠 수 있는 것만 지운다. 즐겨찾기, 색인은 외래 키로 함께 지워진다.
     *
     * @return array 지운 매크로 [{id, scope, name}]
     */
    public function delete($agent, $ids)
    {
        $deleted = array();

        foreach (array_unique(array_map('intval', (array) $ids)) as $id)
        {
            $row = $this->get_editable($agent, $id);

            if ($row)
            {
                $this->db->where('id', (int) $row->id)->delete('macros');
                $deleted[] = array(
                    'id'    => (int) $row->id,
                    'scope' => $row->owner_agent_id === NULL ? 'org' : 'personal',
                    'name'  => $row->name,
                );
            }
        }

        return $deleted;
    }

    /**
     * 쓸 수 있는 매크로를 내 개인 매크로로 복사한다. 이름이 겹치면 뒤에 (복사본) 을 붙인다.
     *
     * @return int|string 새 id 또는 오류 문구
     */
    public function copy_to_personal($agent, $id)
    {
        $row = $this->get_usable($agent, $id);

        if (empty($row))
        {
            return '매크로를 찾을 수 없습니다.';
        }

        $name = $row->name;
        $suffix = ' (복사본)';

        for ($n = 1; $this->find_by_name($agent, 'personal', $name); $n++)
        {
            $tail = $n === 1 ? $suffix : ' (복사본 '.$n.')';
            $name = mb_substr($row->name, 0, $this->conf['name_max'] - mb_strlen($tail)).$tail;

            if ($n > 50)
            {
                return '복사본 이름을 정하지 못했습니다. 기존 개인 매크로 이름을 정리해 주세요.';
            }
        }

        return $this->save($agent, 'personal', array(
            'name'     => $name,
            'body'     => $row->body,
            'keywords' => $row->keywords,
        ));
    }

    // ------------------------------------------------------------------
    // 즐겨찾기
    // ------------------------------------------------------------------

    /**
     * @return TRUE|string 오류 문구
     */
    public function set_favorite($agent, $macro_id, $on)
    {
        $row = $this->get_usable($agent, $macro_id);

        if (empty($row))
        {
            return '매크로를 찾을 수 없습니다.';
        }

        $key = array('agent_id' => (int) $agent->id, 'macro_id' => (int) $row->id);

        if ( ! $on)
        {
            $this->db->delete('macro_favorites', $key);
            return TRUE;
        }

        if ($this->db->get_where('macro_favorites', $key)->row())
        {
            return TRUE;
        }

        $count = (int) $this->db->where('agent_id', (int) $agent->id)->count_all_results('macro_favorites');

        if ($count >= $this->conf['max_favorites'])
        {
            return '즐겨찾기는 '.$this->conf['max_favorites'].'개까지입니다.';
        }

        $max = $this->db->select_max('sort_order', 'm')->where('agent_id', (int) $agent->id)
                        ->get('macro_favorites')->row();

        $this->db->insert('macro_favorites', $key + array(
            'sort_order' => $max && $max->m !== NULL ? (int) $max->m + 1 : 0,
        ));

        return TRUE;
    }

    /**
     * 즐겨찾기 순서를 화면에서 보낸 차례대로 바꾼다. 목록에 없는 것은 뒤로 민다.
     */
    public function set_favorite_order($agent, $ids)
    {
        $order = 0;

        foreach (array_unique(array_map('intval', (array) $ids)) as $id)
        {
            $this->db->where('agent_id', (int) $agent->id)->where('macro_id', $id)
                     ->update('macro_favorites', array('sort_order' => $order++));
        }
    }

    // ------------------------------------------------------------------
    // 사용 기록
    // ------------------------------------------------------------------

    /**
     * 매크로로 채운 글을 보냈을 때 부른다. 쓸 수 없는 매크로면 조용히 넘긴다(전송은 이미 끝났다).
     */
    public function record_use($agent, $macro_id, $conversation_id, $message_id)
    {
        $row = $this->get_usable($agent, $macro_id);

        if (empty($row))
        {
            return FALSE;
        }

        $this->db->set('use_count', 'use_count + 1', FALSE)
                 ->set('last_used_at', gmdate('Y-m-d H:i:s'))
                 ->where('id', (int) $row->id)
                 ->update('macros');

        $this->db->insert('macro_uses', array(
            'org_id'          => (int) $agent->org_id,
            'macro_id'        => (int) $row->id,
            'agent_id'        => (int) $agent->id,
            'conversation_id' => (int) $conversation_id,
            'message_id'      => (int) $message_id,
        ));

        return TRUE;
    }

    /**
     * 기간 안에 많이 쓴 공용 매크로(조직 관리 통계). 개인 매크로는 이름을 보이지 않고 합계만 낸다.
     *
     * @param string $from_utc 'Y-m-d H:i:s'
     * @return array ['top' => [{id, name, uses}], 'personal_uses' => int, 'total_uses' => int]
     */
    public function usage_stats($org_id, $from_utc, $limit = 10)
    {
        $top = $this->db->query("
            SELECT m.id, m.name, COUNT(*) AS uses
            FROM macro_uses u
            JOIN macros m ON m.id = u.macro_id
            WHERE u.org_id = ? AND u.created_at >= ? AND m.owner_agent_id IS NULL
            GROUP BY m.id, m.name
            ORDER BY uses DESC, m.name ASC
            LIMIT ".(int) $limit."
        ", array((int) $org_id, $from_utc))->result();

        $sums = $this->db->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN m.owner_agent_id IS NOT NULL THEN 1 ELSE 0 END) AS personal
            FROM macro_uses u
            LEFT JOIN macros m ON m.id = u.macro_id
            WHERE u.org_id = ? AND u.created_at >= ?
        ", array((int) $org_id, $from_utc))->row();

        return array(
            'top' => array_map(function ($row) {
                return array('id' => (int) $row->id, 'name' => $row->name, 'uses' => (int) $row->uses);
            }, $top),
            'personal_uses' => (int) $sums->personal,
            'total_uses'    => (int) $sums->total,
        );
    }

    // ------------------------------------------------------------------
    // 추천 (AI 없이 키워드 매칭, 낱말 처리는 Guide_matcher 와 같다)
    // ------------------------------------------------------------------

    protected function reindex($org_id, $macro_id, $name, $keywords, $body)
    {
        $this->load->library('guide_matcher');

        $sources = array(
            'name'     => (string) $name,
            'keywords' => str_replace(',', ' ', (string) $keywords),
            'body'     => (string) $body,
        );
        $terms = array();

        foreach ($sources as $field => $text)
        {
            $weight = $this->conf['weights'][$field];

            foreach ($this->guide_matcher->words($text) as $word)
            {
                $this->keep_max($terms, $word, $weight['word']);

                if ($weight['gram'] > 0)
                {
                    foreach ($this->guide_matcher->grams($word) as $gram)
                    {
                        $this->keep_max($terms, $gram, $weight['gram']);
                    }
                }
            }
        }

        arsort($terms);
        $terms = array_slice($terms, 0, (int) $this->conf['max_terms'], TRUE);

        $this->db->where('macro_id', (int) $macro_id)->delete('macro_terms');

        $rows = array();

        foreach ($terms as $term => $weight)
        {
            $rows[] = array('org_id' => (int) $org_id, 'term' => (string) $term,
                            'macro_id' => (int) $macro_id, 'weight' => (int) $weight);
        }

        if ($rows)
        {
            $this->db->insert_batch('macro_terms', $rows);
        }
    }

    protected function keep_max(&$terms, $term, $weight)
    {
        if ( ! isset($terms[$term]) || $terms[$term] < $weight)
        {
            $terms[$term] = $weight;
        }
    }

    /**
     * 고객 문장에 맞는 매크로 id 를 점수순으로 돌려준다. 공용과 본인 개인만 후보다.
     * 동의어는 가이드 관리의 동의어 묶음을 함께 쓴다.
     *
     * @return array [{id, score}]
     */
    public function suggest($agent, $text)
    {
        $this->load->library('guide_matcher');
        $this->load->model('guide_model');

        $terms = $this->guide_matcher->query_terms($text, $this->guide_model->synonym_groups($agent->org_id));

        if (empty($terms))
        {
            return array();
        }

        $rows = $this->db->select('t.macro_id, SUM(t.weight) AS score', FALSE)
                         ->from('macro_terms t')
                         ->join('macros m', 'm.id = t.macro_id')
                         ->where('t.org_id', (int) $agent->org_id)
                         ->where('m.org_id', (int) $agent->org_id)
                         ->group_start()
                             ->where('m.owner_agent_id IS NULL', NULL, FALSE)
                             ->or_where('m.owner_agent_id', (int) $agent->id)
                         ->group_end()
                         ->where_in('t.term', $terms)
                         ->group_by('t.macro_id')
                         ->having('score >=', (int) $this->conf['suggest_min_score'])
                         ->order_by('score', 'DESC')
                         ->limit((int) $this->conf['suggest_limit'])
                         ->get()
                         ->result();

        return array_map(function ($row) {
            return array('id' => (int) $row->macro_id, 'score' => (int) $row->score);
        }, $rows);
    }

    // ------------------------------------------------------------------
    // CSV 가져오기/내보내기
    // ------------------------------------------------------------------

    /**
     * CSV 내용을 행 배열로 읽는다. 엑셀이 저장한 CP949(EUC-KR) 파일도 받는다.
     *
     * @return array ['rows' => [[row_no, name, content, keywords]]] 또는 ['error' => 문구]
     */
    public function parse_csv($content)
    {
        $content = (string) $content;

        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0)
        {
            $content = substr($content, 3);
        }
        elseif ( ! mb_check_encoding($content, 'UTF-8'))
        {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'CP949');

            if ($converted === FALSE || ! mb_check_encoding($converted, 'UTF-8'))
            {
                return array('error' => '파일 인코딩을 읽지 못했습니다. UTF-8 CSV 로 저장해 주세요.');
            }

            $content = $converted;
        }

        $fp = fopen('php://temp', 'r+');
        fwrite($fp, $content);
        rewind($fp);

        $header = fgetcsv($fp, 0, ',', '"', '');

        if ( ! is_array($header))
        {
            fclose($fp);
            return array('error' => '빈 파일입니다.');
        }

        $aliases = array(
            'name' => 'name', '이름' => 'name', '매크로 이름' => 'name',
            'content' => 'content', '내용' => 'content', '매크로 내용' => 'content',
            'keywords' => 'keywords', '키워드' => 'keywords',
        );
        $index = array();

        foreach ($header as $i => $title)
        {
            $title = mb_strtolower(trim((string) $title));

            if (isset($aliases[$title]) && ! isset($index[$aliases[$title]]))
            {
                $index[$aliases[$title]] = $i;
            }
        }

        if ( ! isset($index['name'], $index['content']))
        {
            fclose($fp);
            return array('error' => '첫 줄 머리글에 name, content 열이 있어야 합니다. [양식 받기] 의 파일을 써 주세요.');
        }

        $rows = array();
        $line = 1;

        while (($cells = fgetcsv($fp, 0, ',', '"', '')) !== FALSE)
        {
            $line++;

            // 완전히 빈 줄은 건너뛴다.
            if (count(array_filter($cells, function ($v) { return trim((string) $v) !== ''; })) === 0)
            {
                continue;
            }

            $get = function ($key) use ($cells, $index) {
                return isset($index[$key], $cells[$index[$key]]) ? $this->from_cell($cells[$index[$key]]) : '';
            };

            $rows[] = array('row' => $line, 'name' => $get('name'), 'content' => $get('content'), 'keywords' => $get('keywords'));

            if (count($rows) > $this->conf['import_max_rows'])
            {
                fclose($fp);
                return array('error' => '한 파일에 '.number_format($this->conf['import_max_rows']).'행까지 올릴 수 있습니다.');
            }
        }

        fclose($fp);

        return array('rows' => $rows);
    }

    /**
     * 행을 모두 검사하고, 오류가 하나도 없을 때만 한 트랜잭션으로 저장한다(전부 또는 전무).
     *
     * @param string $mode skip(같은 이름은 그대로 둠) | overwrite(같은 이름은 내용을 바꿈)
     * @return array ['errors' => [...]] 또는 ['created' => n, 'updated' => n, 'skipped' => n]
     */
    public function import($agent, $scope, $rows, $mode)
    {
        $errors = array();
        $plan = array();
        $seen = array();

        foreach ($rows as $row)
        {
            $clean = $this->clean_macro(array('name' => $row['name'], 'body' => $row['content'], 'keywords' => $row['keywords']));

            if (isset($clean['error']))
            {
                $field = $clean['error'][0] === 'body' ? 'content' : $clean['error'][0];
                $errors[] = array('row' => $row['row'], 'field' => $field, 'message' => $clean['error'][1]);
                continue;
            }

            $data = $clean['ok'];

            if (isset($seen[$data['name']]))
            {
                $errors[] = array('row' => $row['row'], 'field' => 'name',
                                  'message' => '같은 파일의 '.$seen[$data['name']].'행과 이름이 같습니다.');
                continue;
            }

            $seen[$data['name']] = $row['row'];
            $existing = $this->find_by_name($agent, $scope, $data['name']);
            $plan[] = array('data' => $data, 'existing' => $existing);
        }

        if ($errors)
        {
            return array('errors' => $errors);
        }

        $new = count(array_filter($plan, function ($p) { return empty($p['existing']); }));

        if ($this->count_in_scope($agent, $scope) + $new > $this->scope_limit($scope))
        {
            return array('errors' => array(array('row' => 0, 'field' => '',
                'message' => ($scope === 'org' ? '공용' : '개인').' 매크로는 '.number_format($this->scope_limit($scope))
                    .'개까지입니다. 지금 '.number_format($this->count_in_scope($agent, $scope)).'개에 새로 '.$new.'개를 더할 수 없습니다.')));
        }

        $result = array('created' => 0, 'updated' => 0, 'skipped' => 0);

        $this->db->trans_begin();

        foreach ($plan as $item)
        {
            if ($item['existing'] && $mode !== 'overwrite')
            {
                $result['skipped']++;
                continue;
            }

            $saved = $this->save($agent, $scope, $item['data'], $item['existing'] ? (int) $item['existing']->id : 0);

            if (is_string($saved))
            {
                // 검사를 미리 했으므로 여기로 오면 동시에 다른 사람이 같은 이름을 만든 경우다.
                $this->db->trans_rollback();
                return array('errors' => array(array('row' => 0, 'field' => '', 'message' => $saved)));
            }

            $result[$item['existing'] ? 'updated' : 'created']++;
        }

        if ($this->db->trans_status() === FALSE)
        {
            $this->db->trans_rollback();
            return array('errors' => array(array('row' => 0, 'field' => '', 'message' => '저장하지 못했습니다. 다시 시도해 주세요.')));
        }

        $this->db->trans_commit();

        return $result;
    }

    /**
     * CSV 내용(UTF-8 BOM, 엑셀에서 한글이 깨지지 않게). $rows 가 없으면 양식(예시 한 줄)을 만든다.
     */
    public function to_csv($macros = NULL)
    {
        $fp = fopen('php://temp', 'r+');
        fputcsv($fp, self::CSV_COLUMNS, ',', '"', '');

        if ($macros === NULL)
        {
            $macros = array(array(
                'name'     => '환불 규정 안내',
                'body'     => "{visitor_name}님, 환불은 결제일로부터 7일 이내에 신청하실 수 있습니다.\n자세한 내용은 안내 페이지를 확인해 주세요.",
                'keywords' => '환불, 취소, 반품',
            ));
        }

        foreach ($macros as $m)
        {
            fputcsv($fp, array($this->to_cell($m['name']), $this->to_cell($m['body']), $this->to_cell((string) $m['keywords'])), ',', '"', '');
        }

        rewind($fp);
        $out = stream_get_contents($fp);
        fclose($fp);

        return "\xEF\xBB\xBF".$out;
    }

    /**
     * 범위 하나의 매크로를 내보내기용으로 읽는다. 개인은 본인 것만.
     */
    public function export_rows($agent, $scope)
    {
        $this->db->select('name, body, keywords')->where('org_id', (int) $agent->org_id);

        if ($scope === 'org')
        {
            $this->db->where('owner_agent_id IS NULL', NULL, FALSE);
        }
        else
        {
            $this->db->where('owner_agent_id', (int) $agent->id);
        }

        return $this->db->order_by('name', 'ASC')->get('macros')->result_array();
    }

    /**
     * 엑셀이 = + - @ 로 시작하는 칸을 수식으로 실행하지 않도록 앞에 ' 를 붙인다. 가져올 때 from_cell 이 뗀다.
     */
    protected function to_cell($value)
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    protected function from_cell($value)
    {
        $value = (string) $value;

        return preg_match("/^'[=+\-@\t\r]/", $value) ? substr($value, 1) : $value;
    }
}
