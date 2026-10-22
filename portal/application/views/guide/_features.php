<?php
/**
 * 파일 위치: application/views/guide/_features.php
 * 역할: 새 기능 모음 본문 (출시일 최신순, 조직 시간대 기준 월별 묶음) — 모든 테마 공통
 *
 * 받는 값은 Guide_model::page_data() 의 features([{label, items}]), more_url, new_ids 와 page.php 의 $link.
 * 날짜는 data-i18n-date 로 조직 시간대에 맞춰 다시 그린다(CLAUDE.md 3.4).
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<header class="guide-collection-head">
  <h1><?= html_escape(lang_text('guide.features_title')) ?></h1>
  <p><?= html_escape(lang_text('guide.features_desc')) ?></p>
</header>

<?php if (empty($features)): ?>
  <p class="guide-collection-empty"><?= html_escape(lang_text('guide.features_empty')) ?></p>
<?php else: ?>
  <?php foreach ($features as $group): ?>
  <section class="guide-feature-month">
    <h2><?= html_escape($group['label']) ?></h2>
    <div class="guide-feature-list">
      <?php foreach ($group['items'] as $item): ?>
      <a class="guide-feature-item" href="<?= html_escape($link($item)) ?>">
        <span class="guide-feature-meta">
          <?php $this->load->view('guide/_new_badge', array('badge_id' => $item->id)); ?>
          <?php if ( ! empty($item->feature_label)): ?><span class="guide-feature-label"><?= html_escape($item->feature_label) ?></span><?php endif; ?>
          <span data-i18n-date="<?= html_escape($item->feature_date) ?>" data-i18n-date-style="date"></span>
        </span>
        <strong><?= html_escape($item->title) ?></strong>
        <?php if ( ! empty($item->subtitle)): ?><small><?= html_escape($item->subtitle) ?></small><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <?php if ($more_url !== ''): ?>
  <a class="guide-more" href="<?= html_escape($more_url) ?>"><?= html_escape(lang_text('guide.load_more')) ?></a>
  <?php endif; ?>
<?php endif; ?>
