<?php
/**
 * 파일 위치: application/views/guide/_new_badge.php
 * 역할: 새 기능 NEW 배지 (목록, 검색 결과, 새 기능 모음 공통)
 *
 * $badge_id 가 $new_ids(Guide_model::new_feature_ids, 새 기능 모음이 꺼져 있으면 비어 있음)에 있을 때만 그린다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<?php if ( ! empty($new_ids) && isset($new_ids[(int) $badge_id])): ?><span class="guide-new"><?= html_escape(lang_text('guide.new_badge')) ?></span><?php endif; ?>
