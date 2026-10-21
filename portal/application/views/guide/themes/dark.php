<?php
/**
 * 파일 위치: application/views/guide/themes/dark.php
 * 역할: 공개 가이드 5번 테마 "다크 미니멀형" — 어두운 배경, 큰 글씨, 번호 목록과 이전/다음 이동
 *
 * 받는 값은 Guide_model::page_data() 와 views/guide/page.php 의 $link, $is_current.
 * 본문 공통 조각(_article)의 밝은 색은 guide-themes.css 의 .gt-dark 규칙이 어둡게 덮는다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$number = 0;
?>
<div class="gt-dark-page">
  <header class="gt-dark-head">
    <a class="gt-dark-brand" href="<?= html_escape($base) ?>">
      <span class="gt-dark-dot"></span><?= html_escape(lang_text('guide.page_title', array('org' => $org->name))) ?>
    </a>
    <form class="gt-dark-search" method="get" action="<?= html_escape($base) ?>">
      <input type="search" name="q" value="<?= html_escape($q) ?>" maxlength="100"
             placeholder="<?= html_escape(lang_text('guide.search_placeholder')) ?>" aria-label="<?= html_escape(lang_text('guide.search_button')) ?>">
    </form>
  </header>

  <main class="gt-dark-main">

  <?php if ($results !== NULL): ?>
    <div class="gt-dark-bar">
      <h1><?= html_escape(lang_text('guide.results', array('q' => $q))) ?></h1>
      <a href="<?= html_escape($base) ?>"><?= html_escape(lang_text('guide.reset')) ?></a>
    </div>
    <?php if (empty($results)): ?>
      <p class="gt-dark-muted"><?= html_escape(lang_text('guide.no_result', array('q' => $q))) ?></p>
    <?php else: ?>
      <div class="gt-dark-list">
        <?php foreach ($results as $item): ?>
        <a class="gt-dark-row" href="<?= html_escape($link($item)) ?>">
          <span class="gt-dark-num">→</span>
          <span><strong><?= html_escape($item['title']) ?></strong>
          <?php if ( ! empty($item['subtitle'])): ?><small><?= html_escape($item['subtitle']) ?></small><?php endif; ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif ($article !== NULL): ?>
    <nav class="gt-dark-crumb">
      <a href="<?= html_escape($base) ?>"><?= html_escape(lang_text('guide.home')) ?></a>
      <?php if ($category !== ''): ?><span>/</span><span><?= html_escape($category) ?></span><?php endif; ?>
    </nav>
    <article class="gt-dark-article">
      <?php $this->load->view('guide/_article', array('article' => $article)); ?>
    </article>
    <nav class="gt-dark-pager">
      <?php if ($prev): ?>
      <a href="<?= html_escape($link($prev)) ?>"><small>← <?= html_escape(lang_text('guide.prev')) ?></small><?= html_escape($prev->title) ?></a>
      <?php else: ?><span></span><?php endif; ?>
      <?php if ($next): ?>
      <a class="is-next" href="<?= html_escape($link($next)) ?>"><small><?= html_escape(lang_text('guide.next')) ?> →</small><?= html_escape($next->title) ?></a>
      <?php endif; ?>
    </nav>

  <?php elseif (empty($groups)): ?>
    <p class="gt-dark-muted"><?= html_escape(lang_text('guide.empty')) ?></p>

  <?php else: ?>
    <h1 class="gt-dark-headline"><?= html_escape(lang_text('guide.hero')) ?></h1>
    <p class="gt-dark-muted"><?= html_escape(lang_text('guide.subtitle')) ?></p>
    <?php foreach ($groups as $group): ?>
    <section class="gt-dark-group">
      <h2><?= html_escape($group['name']) ?></h2>
      <div class="gt-dark-list">
        <?php foreach ($group['articles'] as $item): $number++; ?>
        <a class="gt-dark-row" href="<?= html_escape($link($item)) ?>">
          <span class="gt-dark-num"><?= html_escape(sprintf('%02d', $number)) ?></span>
          <span><strong><?= html_escape($item->title) ?></strong>
          <?php if ( ! empty($item->subtitle)): ?><small><?= html_escape($item->subtitle) ?></small><?php endif; ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>
  <?php endif; ?>

    <div class="gt-dark-unsolved">
      <span><?= html_escape(lang_text('guide.unsolved')) ?> <?= html_escape(lang_text('guide.unsolved_body')) ?></span>
      <a href="<?= html_escape(base_url($org->org_code.'/')) ?>"><?= html_escape(lang_text('guide.remote_button')) ?></a>
    </div>
  </main>
</div>
