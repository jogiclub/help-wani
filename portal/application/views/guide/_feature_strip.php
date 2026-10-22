<?php
/**
 * 파일 위치: application/views/guide/_feature_strip.php
 * 역할: 공개 가이드 첫 화면의 최근 새 기능 띠 (조직 옵션 "가이드 첫 화면에 최근 새 기능 보이기") — 모든 테마 공통
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<?php if ( ! empty($strip)): ?>
<div class="guide-strip">
  <span class="guide-strip-label"><?= html_escape(lang_text('guide.features_strip')) ?></span>
  <span class="guide-strip-items">
    <?php foreach ($strip as $item): ?>
    <a href="<?= html_escape($link($item)) ?>"><?= html_escape($item->title) ?></a>
    <?php endforeach; ?>
  </span>
  <a class="guide-strip-all" href="<?= html_escape($base.'/features') ?>"><?= html_escape(lang_text('guide.view_all')) ?></a>
</div>
<?php endif; ?>
