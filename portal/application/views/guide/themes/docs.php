<?php
/**
 * 파일 위치: application/views/guide/themes/docs.php
 * 역할: 공개 가이드 2번 테마 "문서형 (왼쪽 목차)" — 상단 검색 + 왼쪽 번호 목차 + 오른쪽 본문 카드
 *
 * 받는 값은 Guide_model::page_data() 와 views/guide/page.php 의 $link, $is_current.
 * 좁은 화면에서는 목차를 접고 머리말의 목차 버튼으로 연다(assets/js/guide.js 의 data-gt-toc).
 * 넓은 화면에서는 목차와 본문 사이를 Split.js 로 끌어 너비를 바꾼다(data-gt-split, layouts/guide.php 가 docs 테마에서만 불러온다).
 * 아이콘은 아이콘 글꼴 대신 SVG 로 넣는다(아이콘 목록 등록 불필요).
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<header class="gt-docs-header">
  <a class="gt-docs-brand" href="<?= html_escape($base) ?>">
    <?php if ($org->logo_path): ?>
      <img src="<?= html_escape(base_url($org->logo_path)) ?>" alt="<?= html_escape($org->name) ?>" class="gt-docs-logo">
    <?php else: ?>
      <span class="gt-docs-initial"><?= html_escape(mb_substr($org->name, 0, 1)) ?></span>
      <span class="gt-docs-org"><?= html_escape($org->name) ?></span>
    <?php endif; ?>
    <span class="gt-docs-title"><?= html_escape(lang_text('guide.brand')) ?></span>
  </a>
  <div class="gt-docs-tools">
    <form class="gt-docs-search" method="get" action="<?= html_escape($base) ?>">
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" name="q" value="<?= html_escape($q) ?>" maxlength="100"
             placeholder="<?= html_escape(lang_text('guide.search_placeholder')) ?>" aria-label="<?= html_escape(lang_text('guide.search_button')) ?>">
    </form>
    <a class="gt-docs-cta" href="<?= html_escape(base_url($org->org_code.'/')) ?>"><?= html_escape(lang_text('guide.remote_button')) ?></a>
    <button type="button" class="gt-docs-menu" data-gt-toc="gtToc" aria-controls="gtToc" aria-label="<?= html_escape(lang_text('guide.toc')) ?>"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
  </div>
</header>

<div class="gt-docs-shell" data-gt-split data-gt-split-label="<?= html_escape(lang_text('guide.toc_resize')) ?>">
  <aside class="gt-docs-side" id="gtToc">
    <?php foreach ($groups as $group): ?>
    <div class="gt-docs-heading"><?= html_escape($group['name']) ?></div>
    <ol class="gt-docs-list">
      <?php foreach ($group['articles'] as $item): ?>
      <li><a class="gt-docs-link <?= html_escape($is_current($item) ? 'is-active' : '') ?>" href="<?= html_escape($link($item)) ?>"><?= html_escape($item->title) ?> <?php $this->load->view('guide/_new_badge', array('badge_id' => $item->id)); ?></a></li>
      <?php endforeach; ?>
    </ol>
    <?php endforeach; ?>
  </aside>

  <main class="gt-docs-main">
    <div class="gt-docs-wrap">
    <?php $this->load->view('guide/_tabs'); ?>

    <?php if ($view !== 'list'): ?>
      <section class="gt-docs-card">
        <div class="gt-docs-card-body"><?php $this->load->view('guide/_collection'); ?></div>
      </section>

    <?php elseif ($results !== NULL): ?>
      <section class="gt-docs-card">
        <div class="gt-docs-card-head">
          <h1 class="gt-docs-card-title"><?= html_escape(lang_text('guide.results', array('q' => $q))) ?></h1>
          <a class="gt-docs-reset" href="<?= html_escape($base) ?>"><?= html_escape(lang_text('guide.reset')) ?></a>
        </div>
        <?php if (empty($results)): ?>
          <p class="gt-docs-empty"><?= html_escape(lang_text('guide.no_result', array('q' => $q))) ?></p>
        <?php else: ?>
          <div class="gt-docs-results">
            <?php foreach ($results as $item): ?>
            <a href="<?= html_escape($link($item)) ?>">
              <strong><?= html_escape($item['title']) ?> <?php $this->load->view('guide/_new_badge', array('badge_id' => $item['id'])); ?></strong>
              <?php if ( ! empty($item['subtitle'])): ?><span><?= html_escape($item['subtitle']) ?></span><?php endif; ?>
            </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    <?php elseif ($article !== NULL): ?>
      <section class="gt-docs-card">
        <div class="gt-docs-card-head">
          <h1 class="gt-docs-card-title"><?= html_escape($category !== '' ? $category : $article->title) ?></h1>
          <nav class="gt-docs-crumb" aria-label="breadcrumb">
            <a href="<?= html_escape($base) ?>"><?= html_escape(lang_text('guide.home')) ?></a>
            <?php if ($category !== ''): ?><span>/</span><span><?= html_escape($category) ?></span><?php endif; ?>
            <span>/</span><span class="is-current"><?= html_escape($article->title) ?></span>
          </nav>
        </div>
        <div class="gt-docs-card-body">
          <?php if ( ! empty($article->subtitle)): ?>
          <p class="gt-docs-summary"><?= html_escape($article->subtitle) ?></p>
          <?php endif; ?>
          <div class="gt-docs-section">
            <h2><?= html_escape($article->title) ?></h2>
            <?php $this->load->view('guide/_article', array('article' => $article, 'hide_heading' => TRUE)); ?>
          </div>
          <p class="gt-docs-updated">
            <?= html_escape(lang_text('guide.updated')) ?>
            <span data-i18n-date="<?= html_escape($article->updated_at) ?>" data-i18n-date-style="date"></span>
          </p>
          <nav class="gt-docs-pager">
            <?php if ($prev): ?>
            <a class="is-prev" href="<?= html_escape($link($prev)) ?>"><small><?= html_escape(lang_text('guide.prev')) ?></small><?= html_escape($prev->title) ?></a>
            <?php else: ?><span></span><?php endif; ?>
            <?php if ($next): ?>
            <a class="is-next" href="<?= html_escape($link($next)) ?>"><small><?= html_escape(lang_text('guide.next')) ?></small><?= html_escape($next->title) ?></a>
            <?php endif; ?>
          </nav>
        </div>
      </section>

    <?php else: ?>
      <section class="gt-docs-card">
        <div class="gt-docs-card-head">
          <h1 class="gt-docs-card-title"><?= html_escape(lang_text('guide.page_title', array('org' => $org->name))) ?></h1>
          <nav class="gt-docs-crumb"><span class="is-current"><?= html_escape(lang_text('guide.home')) ?></span></nav>
        </div>
        <div class="gt-docs-card-body">
          <p class="gt-docs-summary"><?= html_escape(lang_text('guide.subtitle')) ?></p>
          <?php $this->load->view('guide/_feature_strip'); ?>
          <?php if (empty($groups)): ?>
            <p class="gt-docs-empty"><?= html_escape(lang_text('guide.empty')) ?></p>
          <?php else: ?>
          <div class="gt-docs-overview">
            <?php foreach ($groups as $group): ?>
            <div class="gt-docs-section">
              <h2><?= html_escape($group['name']) ?> <small><?= html_escape(lang_text('guide.count', array('n' => count($group['articles'])))) ?></small></h2>
              <ol>
                <?php foreach ($group['articles'] as $item): ?>
                <li><a href="<?= html_escape($link($item)) ?>"><?= html_escape($item->title) ?> <?php $this->load->view('guide/_new_badge', array('badge_id' => $item->id)); ?></a></li>
                <?php endforeach; ?>
              </ol>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    </div>
  </main>
</div>
