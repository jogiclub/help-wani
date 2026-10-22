<?php
/**
 * 파일 위치: application/views/guide/_videos.php
 * 역할: 동영상 모음 본문 (공개 가이드의 유튜브 블록을 모은 격자) — 모든 테마 공통
 *
 * 받는 값은 Guide_model::page_data() 의 videos, video_total, video_categories, video_category, more_url 와 page.php 의 $link.
 * 동영상은 따로 저장하지 않고 공개 글의 본문 블록에서 모은다(Guide_model::public_videos).
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<header class="guide-collection-head">
  <h1><?= html_escape(lang_text('guide.videos_title')) ?> <small><?= (int) $video_total ?></small></h1>
  <p><?= html_escape(lang_text('guide.videos_desc')) ?></p>
</header>

<?php if (count($video_categories) > 1): ?>
<nav class="guide-video-filter" aria-label="<?= html_escape(lang_text('guide.category_filter')) ?>">
  <a class="<?= html_escape($video_category === 0 ? 'is-active' : '') ?>" href="<?= html_escape($base.'/videos') ?>"><?= html_escape(lang_text('guide.category_all')) ?></a>
  <?php foreach ($video_categories as $category_id => $category_name): ?>
  <a class="<?= html_escape($video_category === (int) $category_id ? 'is-active' : '') ?>"
     href="<?= html_escape($base.'/videos?category='.(int) $category_id) ?>"><?= html_escape($category_name) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if (empty($videos)): ?>
  <p class="guide-collection-empty"><?= html_escape(lang_text('guide.videos_empty')) ?></p>
<?php else: ?>
  <div class="guide-video-grid">
    <?php foreach ($videos as $video): ?>
    <?php $video_title = $video['caption'] !== '' ? $video['caption'] : $video['articles'][0]['title']; ?>
    <figure class="guide-video-card">
      <?php $this->load->view('guide/_video', array('yt_id' => $video['youtube_id'], 'yt_title' => $video_title)); ?>
      <figcaption>
        <strong><?= html_escape($video_title) ?></strong>
        <span class="guide-video-from"><?= html_escape(lang_text('guide.videos_in_article')) ?></span>
        <?php foreach ($video['articles'] as $item): ?>
        <a href="<?= html_escape($link($item)) ?>"><?= html_escape($item['title']) ?></a>
        <?php endforeach; ?>
      </figcaption>
    </figure>
    <?php endforeach; ?>
  </div>

  <?php if ($more_url !== ''): ?>
  <a class="guide-more" href="<?= html_escape($more_url) ?>"><?= html_escape(lang_text('guide.load_more')) ?></a>
  <?php endif; ?>
<?php endif; ?>
