<?php
/**
 * 파일 위치: application/views/guide/themes/faq.php
 * 역할: 공개 가이드 4번 테마 "FAQ 아코디언형" — 질문을 누르면 그 자리에서 답이 펼쳐진다
 *
 * 받는 값은 Guide_model::page_data() 와 views/guide/page.php 의 $link, $is_current.
 * 목록에서는 $bodies(글 id => 본문)로 답을 그린다. 펼치기는 <details> 라 스크립트 없이 동작한다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<main class="gt-faq-main">
  <header class="gt-faq-head">
    <a class="gt-faq-org" href="<?= html_escape($base) ?>">
      <?php if ($org->logo_path): ?>
        <img src="<?= html_escape(base_url($org->logo_path)) ?>" alt="<?= html_escape($org->name) ?>">
      <?php endif; ?>
      <span><?= html_escape($org->name) ?></span>
    </a>
    <h1><?= html_escape(lang_text('guide.faq_title')) ?></h1>
    <p><?= html_escape(lang_text('guide.subtitle')) ?></p>
    <form class="gt-faq-search" method="get" action="<?= html_escape($base) ?>">
      <input type="search" name="q" value="<?= html_escape($q) ?>" maxlength="100"
             placeholder="<?= html_escape(lang_text('guide.search_placeholder')) ?>">
      <button type="submit"><?= html_escape(lang_text('guide.search_button')) ?></button>
    </form>
  </header>

<?php if ($results !== NULL): ?>
  <div class="gt-faq-bar">
    <h2><?= html_escape(lang_text('guide.results', array('q' => $q))) ?></h2>
    <a href="<?= html_escape($base) ?>"><?= html_escape(lang_text('guide.reset')) ?></a>
  </div>
  <?php if (empty($results)): ?>
    <p class="gt-faq-empty"><?= html_escape(lang_text('guide.no_result', array('q' => $q))) ?></p>
  <?php else: ?>
    <div class="gt-faq-group">
      <?php foreach ($results as $item): ?>
      <a class="gt-faq-row" href="<?= html_escape($link($item)) ?>">
        <span class="gt-faq-q">Q</span>
        <span><strong><?= html_escape($item['title']) ?></strong>
        <?php if ( ! empty($item['subtitle'])): ?><small><?= html_escape($item['subtitle']) ?></small><?php endif; ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($article !== NULL): ?>
  <div class="gt-faq-bar">
    <a href="<?= html_escape($base) ?>">← <?= html_escape(lang_text('guide.back')) ?></a>
    <?php if ($category !== ''): ?><span class="gt-faq-chip is-static"><?= html_escape($category) ?></span><?php endif; ?>
  </div>
  <article class="gt-faq-item is-single">
    <div class="gt-faq-summary">
      <span class="gt-faq-q">Q</span>
      <span><strong><?= html_escape($article->title) ?></strong>
      <?php if ( ! empty($article->subtitle)): ?><small><?= html_escape($article->subtitle) ?></small><?php endif; ?></span>
    </div>
    <div class="gt-faq-answer">
      <?php $this->load->view('guide/_article', array('article' => $article, 'hide_heading' => TRUE)); ?>
    </div>
  </article>

<?php elseif (empty($groups)): ?>
  <p class="gt-faq-empty"><?= html_escape(lang_text('guide.empty')) ?></p>

<?php else: ?>
  <?php if (count($groups) > 1): ?>
  <nav class="gt-faq-chips">
    <?php foreach ($groups as $group): ?>
    <a class="gt-faq-chip" href="#gt-cat-<?= (int) $group['id'] ?>"><?= html_escape($group['name']) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php foreach ($groups as $group): ?>
  <section class="gt-faq-group" id="gt-cat-<?= (int) $group['id'] ?>">
    <h2><?= html_escape($group['name']) ?></h2>
    <?php foreach ($group['articles'] as $item): ?>
    <details class="gt-faq-item">
      <summary class="gt-faq-summary">
        <span class="gt-faq-q">Q</span>
        <span><strong><?= html_escape($item->title) ?></strong>
        <?php if ( ! empty($item->subtitle)): ?><small><?= html_escape($item->subtitle) ?></small><?php endif; ?></span>
      </summary>
      <div class="gt-faq-answer">
        <?php if (isset($bodies[(int) $item->id])): ?>
          <?php $this->load->view('guide/_article', array('article' => $bodies[(int) $item->id], 'hide_heading' => TRUE)); ?>
        <?php endif; ?>
        <a class="gt-faq-permalink" href="<?= html_escape($link($item)) ?>"><?= html_escape(lang_text('guide.permalink')) ?></a>
      </div>
    </details>
    <?php endforeach; ?>
  </section>
  <?php endforeach; ?>
<?php endif; ?>

  <div class="gt-faq-unsolved">
    <strong><?= html_escape(lang_text('guide.unsolved')) ?></strong>
    <span><?= html_escape(lang_text('guide.unsolved_body')) ?></span>
    <a href="<?= html_escape(base_url($org->org_code.'/')) ?>"><?= html_escape(lang_text('guide.remote_button')) ?></a>
  </div>
</main>
