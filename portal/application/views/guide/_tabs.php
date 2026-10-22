<?php
/**
 * 파일 위치: application/views/guide/_tabs.php
 * 역할: 공개 가이드 탭 줄 (전체 / 새 기능 / 동영상) — 모든 테마 공통
 *
 * 탭은 Guide_model::page_data() 가 만든다. 켜 둔 모음이 없으면 $tabs 가 비어 아무것도 그리지 않는다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<?php if ( ! empty($tabs)): ?>
<nav class="guide-tabs" aria-label="<?= html_escape(lang_text('guide.tabs_label')) ?>">
  <?php foreach ($tabs as $tab): ?>
  <?php if ($tab['key'] === $view): ?>
  <a class="guide-tab is-active" href="<?= html_escape($tab['url']) ?>" aria-current="page"><?= html_escape($tab['label']) ?></a>
  <?php else: ?>
  <a class="guide-tab" href="<?= html_escape($tab['url']) ?>"><?= html_escape($tab['label']) ?></a>
  <?php endif; ?>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
