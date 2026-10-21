<?php
/**
 * 파일 위치: application/views/guide/_article.php
 * 역할: 가이드 본문 그리기 (제목, 부제목, 글/이미지/유튜브 블록) — 모든 테마 공통
 *
 * 모든 값은 이스케이프해서 내보낸다. 글 블록은 guide_text_html() 이 이스케이프한 뒤 https 주소만 링크로 바꾼다.
 * 이미지는 이 조직에 올린 파일 또는 외부 https 주소다(guide_image_url). 외부 사이트에 고객 페이지 주소를 넘기지 않도록 Referer 를 보내지 않는다.
 * 유튜브는 저장된 영상 id 로만 주소를 만들고, 누르기 전에는 유튜브에 요청하지 않는다(assets/js/guide.js).
 *
 * guide-* 클래스는 테마가 모양을 덧입히는 자리다(assets/css/guide-themes.css). 기본 모양은 Tailwind 클래스로 둔다.
 * $hide_heading 이 TRUE 면 제목/부제목을 빼고 본문만 그린다(제목을 테마가 따로 그릴 때).
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$hide_heading = ! empty($hide_heading);
?>
<div class="guide-article">
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
    <button type="button" class="guide-video flex aspect-video w-full items-center justify-center rounded-lg bg-slate-900 text-white hover:bg-slate-800"
            data-youtube-id="<?= html_escape($block['youtube_id']) ?>">
      <span class="guide-play rounded-full bg-red-600 px-5 py-2 text-sm font-semibold">▶ <?= html_escape(lang_text('guide.video_play')) ?></span>
    </button>
    <?php if ($block['caption'] !== ''): ?>
    <figcaption class="guide-caption mt-1 text-sm text-slate-500"><?= html_escape($block['caption']) ?></figcaption>
    <?php endif; ?>
  </figure>
  <?php endif; ?>
<?php endforeach; ?>
</div>
</div>
