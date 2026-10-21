<?php
/**
 * 파일 위치: application/views/guide/page.php
 * 역할: 공개 가이드 화면의 테마 고르기 (목록/검색/상세 공통, 데이터는 Guide_model::page_data)
 *
 * 테마 뷰는 views/guide/themes/{테마}.php 이며 $article 이 있으면 상세, $results 가 있으면 검색 결과, 둘 다 없으면 목록을 그린다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

// 페이지 안 이동은 지금 연 주소를 따른다. 고객에게 보내는 주소만 customer_url() 로 만든다(CLAUDE.md 3.13).
$link = function ($item) use ($base) {
    $item = (array) $item;
    return $base.'/'.rawurlencode($item['slug'] ?: $item['id']);
};
$is_current = function ($item) use ($article) {
    return $article !== NULL && (int) $article->id === (int) (is_array($item) ? $item['id'] : $item->id);
};
?>
<?php if ( ! empty($preview)): ?>
<div class="gt-preview-bar"><?= html_escape(lang_text('guide.preview_notice')) ?></div>
<?php endif; ?>
<?php $this->load->view('guide/themes/'.$theme, array('link' => $link, 'is_current' => $is_current)); ?>
