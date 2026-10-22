<?php
/**
 * 파일 위치: application/libraries/Chat_greeting.php
 * 역할: 상담 위젯 인사말 — 바로가기 버튼 검사, 변수({visitor_name} {org_name}) 채우기 (docs/widget-ops-plan.md 2장)
 *
 * 인사말은 위젯을 열 때 화면에만 그리고, 방문자가 첫 메시지를 보낼 때 시스템 메시지(meta.type = greeting)로 한 번 저장한다.
 * 위젯은 textContent 로 그리므로 HTML 을 받지 않고 글자 그대로 둔다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

class Chat_greeting {

    const BODY_MAX = 1000;
    const BUTTON_MAX = 3;
    const LABEL_MAX = 30;
    const URL_MAX = 500;

    /** 인사말에 쓸 수 있는 변수. 매크로 변수와 같은 문법이다(config/macro.php). */
    const VARIABLES = array('visitor_name', 'org_name');

    /**
     * 관리 화면에서 받은 버튼 목록을 검사한다. 주소는 https 만 받는다.
     *
     * @param string|array $input JSON 문자열 또는 [{label, url}]
     * @return array|string 정리한 목록 또는 오류 문구(관리 화면용)
     */
    public function clean_buttons($input)
    {
        if ($input === '' || $input === NULL)
        {
            return array();
        }

        $items = is_array($input) ? $input : json_decode((string) $input, TRUE);

        if ( ! is_array($items))
        {
            return '바로가기 버튼 형식이 올바르지 않습니다.';
        }

        $out = array();

        foreach ($items as $item)
        {
            $label = trim(preg_replace('/\s+/u', ' ', (string) (isset($item['label']) ? $item['label'] : '')));
            $url = trim((string) (isset($item['url']) ? $item['url'] : ''));

            if ($label === '' && $url === '')
            {
                continue;
            }

            if ($label === '' || mb_strlen($label) > self::LABEL_MAX)
            {
                return '버튼 이름은 1~'.self::LABEL_MAX.'자로 적어 주세요.';
            }

            if ( ! $this->is_https_url($url))
            {
                return '버튼 주소는 https:// 로 시작하는 주소만 쓸 수 있습니다. ('.$label.')';
            }

            $out[] = array('label' => $label, 'url' => $url);
        }

        if (count($out) > self::BUTTON_MAX)
        {
            return '바로가기 버튼은 '.self::BUTTON_MAX.'개까지 둘 수 있습니다.';
        }

        return $out;
    }

    /**
     * 저장된 버튼 목록. 저장 뒤에 규칙이 바뀌었을 수 있으므로 꺼낼 때도 주소를 다시 확인한다.
     */
    public function buttons($org)
    {
        $items = json_decode((string) $org->chat_greeting_buttons, TRUE);

        if ( ! is_array($items))
        {
            return array();
        }

        return array_values(array_filter($items, function ($item) {
            return isset($item['label'], $item['url']) && $this->is_https_url($item['url']);
        }));
    }

    /**
     * 방문자에게 보일 인사말. 비어 있으면 NULL.
     */
    public function render($org, $visitor)
    {
        $body = trim((string) $org->chat_greeting);

        if ($body === '')
        {
            return NULL;
        }

        $name = trim((string) ($visitor ? $visitor->name : ''));

        // 이름이 없을 때의 호칭은 고객이 읽으므로 조직 언어로 만든다(CLAUDE.md 3.2, 매크로와 같은 문구).
        $body = strtr($body, array(
            '{visitor_name}' => $name !== '' ? $name : lang_text('macro.visitor_fallback', array(), $org->locale),
            '{org_name}'     => $org->name,
        ));

        return array('body' => $body, 'buttons' => $this->buttons($org));
    }

    /**
     * 모르는 변수. 저장은 막지 않고 경고만 한다.
     */
    public function unknown_variables($body)
    {
        preg_match_all('/\{([a-z_]+)\}/', (string) $body, $matches);

        return array_values(array_unique(array_diff($matches[1], self::VARIABLES)));
    }

    protected function is_https_url($url)
    {
        $url = (string) $url;

        return mb_strlen($url) <= self::URL_MAX
            && preg_match('#^https://[^\s/?\#]+[^\s]*$#i', $url)
            && filter_var($url, FILTER_VALIDATE_URL) !== FALSE;
    }
}
