<?php
/**
 * 파일 위치: application/views/guide/_article.php
 * 역할: 가이드 본문 그리기 (제목, 부제목, 글/이미지/유튜브 블록) — 모든 테마 공통
 *
 * 모든 값은 이스케이프해서 내보낸다. 글 블록은 guide_text_html() 이 이스케이프한 뒤 https 주소만 링크로 바꾼다.
 * 이미지는 이 조직에 올린 파일 또는 외부 https 주소다(guide_image_url). 외부 사이트에 고객 페이지 주소를 넘기지 않도록 Referer 를 보내지 않는다.
 * 유튜브는 저장된 영상 id 로만 주소를 만들고 처음부터 iframe 으로 그린다(_video.php 한 곳에서 만든다).
 * 새 기능으로 표시된 글이면 맨 위에 "새 기능 · 버전 · 출시일" 을 보인다(제목을 테마가 그릴 때도).
 *
 * guide-* 클래스는 테마가 모양을 덧입히는 자리다(assets/css/guide-themes.css). 기본 모양은 Tailwind 클래스로 둔다.
 * $hide_heading 이 TRUE 면 제목/부제목을 빼고 본문만 그린다(제목을 테마가 따로 그릴 때).
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$hide_heading = ! empty($hide_heading);
?>
<div class="guide-article">
<?php if ( ! empty($article->is_feature)): ?>
<p class="guide-feature-tag">
  <span class="guide-feature-kind"><?= html_escape(lang_text('guide.tab_features')) ?></span>
  <?php if ( ! empty($article->feature_label)): ?><span class="guide-feature-label"><?= html_escape($article->feature_label) ?></span><?php endif; ?>
  <?php if (Guide_model::feature_date($article)): ?>
  <span><?= html_escape(lang_text('guide.released_on')) ?> <span data-i18n-date="<?= html_escape(Guide_model::feature_date($article)) ?>" data-i18n-date-style="date"></span></span>
  <?php endif; ?>
</p>
<?php endif; ?>
<?php if ( ! $hide_heading): ?>
<h1 class="guide-title text-2xl font-bold text-slate-800"><?= html_escape($article->title) ?></h1>
<?php if ( ! empty($article->subtitle)): ?>
<p class="guide-subtitle mt-1 text-slate-500"><?= html_escape($article->subtitle) ?></p>
<?php endif; ?>
<p class="guide-meta mt-2 text-xs text-slate-400">
  <?= html_escape(lang_text('guide.updated')) ?>
  <span data-i18n-date="<?= html_escape($article->updated_at) ?>" data-i18n-date-style="date"></span>
</p>
<?php endif; ?>

<div class="guide-body <?= html_escape($hide_heading ? '' : 'mt-6') ?> space-y-5">
<?php foreach ($article->blocks as $block): ?>
  <?php if ($block['type'] === 'text'): ?>
  <div class="guide-text whitespace-pre-line break-words leading-relaxed text-slate-700"><?= guide_text_html($block['text']) ?></div>

  <?php elseif ($block['type'] === 'image'): ?>
  <figure class="guide-figure">
    <img class="guide-img max-w-full rounded-lg border border-slate-200" loading="lazy" referrerpolicy="no-referrer"
         src="<?= html_escape(guide_image_url($block['src'])) ?>" alt="<?= html_escape($block['caption']) ?>">
    <?php if ($block['caption'] !== ''): ?>
    <figcaption class="guide-caption mt-1 text-sm text-slate-500"><?= html_escape($block['caption']) ?></figcaption>
    <?php endif; ?>
  </figure>

  <?php elseif ($block['type'] === 'video'): ?>
  <figure class="guide-figure guide-figure-video">
    <?php $this->load->view('guide/_video', array('yt_id' => $block['youtube_id'], 'yt_title' => $block['caption'])); ?>
    <?php if ($block['caption'] !== ''): ?>
    <figcaption class="guide-caption mt-1 text-sm text-slate-500"><?= html_escape($block['caption']) ?></figcaption>
    <?php endif; ?>
  </figure>
  <?php endif; ?>
<?php endforeach; ?>
</div>
</div>
