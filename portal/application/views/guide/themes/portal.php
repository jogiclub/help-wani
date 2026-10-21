<?php
/**
 * 파일 위치: application/views/guide/themes/portal.php
 * 역할: 공개 가이드 3번 테마 "헬프센터형" — 강조색 검색 배너 + 분류 카드, 상세는 본문과 같은 분류 목록
 *
 * 받는 값은 Guide_model::page_data() 와 views/guide/page.php 의 $link, $is_current.
 * 분류 카드에는 처음 5개만 보이고 나머지는 "더 보기" 로 펼친다(글이 많은 분류가 화면을 덮지 않게).
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$same_category = array();

if ($article !== NULL)
{
    foreach ($groups as $group)
    {
        if ((int) $group['id'] === (int) $article->category_id)
        {
            $same_category = $group['articles'];
        }
    }
}
?>
<section class="gt-portal-hero <?= html_escape($article !== NULL ? 'is-compact' : '') ?>">
  <div class="gt-portal-top">
    <a class="gt-portal-brand" href="<?= html_escape($base) ?>">
      <?php if ($org->logo_path): ?>
        <span class="gt-portal-logo"><img src="<?= html_escape(base_url($org->logo_path)) ?>" alt="<?= html_escape($org->name) ?>"></span>
      <?php endif; ?>
      <span><?= html_escape(lang_text('guide.page_title', array('org' => $org->name))) ?></span>
    </a>
    <a class="gt-portal-cta" href="<?= html_escape(base_url($org->org_code.'/')) ?>"><?= html_escape(lang_text('guide.remote_button')) ?></a>
  </div>
  <?php if ($article === NULL): ?>
  <h1 class="gt-portal-headline"><?= html_escape(lang_text('guide.hero')) ?></h1>
  <?php endif; ?>
  <form class="gt-portal-search" method="get" action="<?= html_escape($base) ?>">
    <input type="search" name="q" value="<?= html_escape($q) ?>" maxlength="100"
           placeholder="<?= html_escape(lang_text('guide.search_placeholder')) ?>">
    <button type="submit"><?= html_escape(lang_text('guide.search_button')) ?></button>
  </form>
</section>

<main class="gt-portal-main">

<?php if ($results !== NULL): ?>
  <div class="gt-portal-bar">
    <h2><?= html_escape(lang_text('guide.results', array('q' => $q))) ?></h2>
    <a href="<?= html_escape($base) ?>"><?= html_escape(lang_text('guide.reset')) ?></a>
  </div>
  <?php if (empty($results)): ?>
    <div class="gt-portal-panel gt-portal-empty"><?= html_escape(lang_text('guide.no_result', array('q' => $q))) ?></div>
  <?php else: ?>
    <div class="gt-portal-panel gt-portal-list">
      <?php foreach ($results as $item): ?>
      <a href="<?= html_escape($link($item)) ?>">
        <strong><?= html_escape($item['title']) ?></strong>
        <?php if ( ! empty($item['subtitle'])): ?><span><?= html_escape($item['subtitle']) ?></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($article !== NULL): ?>
  <nav class="gt-portal-crumb">
    <a href="<?= html_escape($base) ?>"><?= html_escape(lang_text('guide.home')) ?></a>
    <?php if ($category !== ''): ?><span>›</span><span><?= html_escape($category) ?></span><?php endif; ?>
  </nav>
  <div class="gt-portal-detail">
    <article class="gt-portal-panel gt-portal-article">
      <?php $this->load->view('guide/_article', array('article' => $article)); ?>
    </article>
    <?php if (count($same_category) > 1): ?>
    <aside class="gt-portal-panel gt-portal-related">
      <h2><?= html_escape(lang_text('guide.related')) ?></h2>
      <?php foreach ($same_category as $item): ?>
      <a class="<?= html_escape($is_current($item) ? 'is-active' : '') ?>" href="<?= html_escape($link($item)) ?>"><?= html_escape($item->title) ?></a>
      <?php endforeach; ?>
    </aside>
    <?php endif; ?>
  </div>

<?php elseif (empty($groups)): ?>
  <div class="gt-portal-panel gt-portal-empty"><?= html_escape(lang_text('guide.empty')) ?></div>

<?php else: ?>
  <div class="gt-portal-grid">
    <?php foreach ($groups as $group): ?>
    <section class="gt-portal-panel gt-portal-category">
      <h2><?= html_escape($group['name']) ?></h2>
      <p class="gt-portal-count"><?= html_escape(lang_text('guide.count', array('n' => count($group['articles'])))) ?></p>
      <ul>
        <?php foreach (array_slice($group['articles'], 0, 5) as $item): ?>
        <li><a href="<?= html_escape($link($item)) ?>"><?= html_escape($item->title) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($group['articles']) > 5): ?>
      <details>
        <summary><?= html_escape(lang_text('guide.more', array('n' => count($group['articles']) - 5))) ?></summary>
        <ul>
          <?php foreach (array_slice($group['articles'], 5) as $item): ?>
          <li><a href="<?= html_escape($link($item)) ?>"><?= html_escape($item->title) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </details>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

  <div class="gt-portal-unsolved">
    <div>
      <strong><?= html_escape(lang_text('guide.unsolved')) ?></strong>
      <span><?= html_escape(lang_text('guide.unsolved_body')) ?></span>
    </div>
    <a href="<?= html_escape(base_url($org->org_code.'/')) ?>"><?= html_escape(lang_text('guide.remote_button')) ?></a>
  </div>
</main>
