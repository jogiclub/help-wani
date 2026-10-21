<?php
/**
 * 파일 위치: application/views/guide/_article.php
 * 역할: 가이드 본문 그리기 (제목, 부제목, 글/이미지/유튜브 블록)
 *
 * 모든 값은 이스케이프해서 내보낸다. 글 블록은 guide_text_html() 이 이스케이프한 뒤 https 주소만 링크로 바꾼다.
 * 유튜브는 저장된 영상 id 로만 주소를 만들고, 누르기 전에는 유튜브에 요청하지 않는다(assets/js/guide.js).
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<h1 class="text-2xl font-bold text-slate-800"><?= html_escape($article->title) ?></h1>
<?php if ( ! empty($article->subtitle)): ?>
<p class="mt-1 text-slate-500"><?= html_escape($article->subtitle) ?></p>
<?php endif; ?>
<p class="mt-2 text-xs text-slate-400">
  <?= html_escape(lang_text('guide.updated')) ?>
  <span data-i18n-date="<?= html_escape($article->updated_at) ?>" data-i18n-date-style="date"></span>
</p>

<div class="mt-6 space-y-5">
<?php foreach ($article->blocks as $block): ?>
  <?php if ($block['type'] === 'text'): ?>
  <div class="whitespace-pre-line break-words leading-relaxed text-slate-700"><?= guide_text_html($block['text']) ?></div>

  <?php elseif ($block['type'] === 'image'): ?>
  <figure>
    <img class="max-w-full rounded-lg border border-slate-200" loading="lazy"
         src="<?= html_escape(base_url($block['src'])) ?>" alt="<?= html_escape($block['caption']) ?>">
    <?php if ($block['caption'] !== ''): ?>
    <figcaption class="mt-1 text-sm text-slate-500"><?= html_escape($block['caption']) ?></figcaption>
    <?php endif; ?>
  </figure>

  <?php elseif ($block['type'] === 'video'): ?>
  <figure>
    <button type="button" class="guide-video flex aspect-video w-full items-center justify-center rounded-lg bg-slate-900 text-white hover:bg-slate-800"
            data-youtube-id="<?= html_escape($block['youtube_id']) ?>">
      <span class="rounded-full bg-red-600 px-5 py-2 text-sm font-semibold">▶ <?= html_escape(lang_text('guide.video_play')) ?></span>
    </button>
    <?php if ($block['caption'] !== ''): ?>
    <figcaption class="mt-1 text-sm text-slate-500"><?= html_escape($block['caption']) ?></figcaption>
    <?php endif; ?>
  </figure>
  <?php endif; ?>
<?php endforeach; ?>
</div>
