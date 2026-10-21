<?php
/**
 * 파일 위치: application/views/guide/themes/card.php
 * 역할: 공개 가이드 1번 테마 "기본 카드형" (가운데 한 줄, 분류별 카드 목록)
 *
 * 받는 값은 Guide_model::page_data() 와 views/guide/page.php 의 $link, $is_current.
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<main class="mx-auto max-w-3xl px-4 py-8">
<?php $this->load->view('guide/_header'); ?>

<?php if ($article !== NULL): ?>
  <div class="mt-4 mb-2">
    <a class="text-sm text-brand-600 hover:underline" href="<?= html_escape($base) ?>">← <?= html_escape(lang_text('guide.back')) ?></a>
  </div>
  <article class="card">
    <div class="card-body">
      <?php $this->load->view('guide/_article', array('article' => $article)); ?>
    </div>
  </article>

<?php else: ?>
  <form class="mt-4 flex gap-2" method="get" action="<?= html_escape($base) ?>">
    <input class="form-input flex-1" name="q" value="<?= html_escape($q) ?>" maxlength="100"
           placeholder="<?= html_escape(lang_text('guide.search_placeholder')) ?>">
    <button class="btn btn-primary shrink-0" type="submit"><?= html_escape(lang_text('guide.search_button')) ?></button>
  </form>

  <?php if ($results !== NULL): ?>
    <div class="mt-5 mb-2 flex items-center gap-3">
      <h2 class="font-semibold text-slate-700"><?= html_escape(lang_text('guide.results', array('q' => $q))) ?></h2>
      <a class="ml-auto text-sm text-brand-600 hover:underline" href="<?= html_escape($base) ?>"><?= html_escape(lang_text('guide.back')) ?></a>
    </div>
    <?php if (empty($results)): ?>
      <div class="card"><div class="card-body text-sm text-slate-500"><?= html_escape(lang_text('guide.no_result', array('q' => $q))) ?></div></div>
    <?php else: ?>
      <div class="card divide-y divide-slate-100">
        <?php foreach ($results as $item): ?>
        <a class="block px-5 py-3 hover:bg-slate-50" href="<?= html_escape($link($item)) ?>">
          <div class="font-medium text-slate-800"><?= html_escape($item['title']) ?></div>
          <?php if ( ! empty($item['subtitle'])): ?>
          <div class="mt-0.5 text-sm text-slate-500"><?= html_escape($item['subtitle']) ?></div>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php elseif (empty($groups)): ?>
    <div class="card mt-5"><div class="card-body text-center text-sm text-slate-500"><?= html_escape(lang_text('guide.empty')) ?></div></div>

  <?php else: ?>
    <?php foreach ($groups as $group): ?>
    <h2 class="mt-6 mb-2 font-semibold text-slate-700"><?= html_escape($group['name']) ?></h2>
    <div class="card divide-y divide-slate-100">
      <?php foreach ($group['articles'] as $item): ?>
      <a class="block px-5 py-3 hover:bg-slate-50" href="<?= html_escape($link($item)) ?>">
        <div class="font-medium text-slate-800"><?= html_escape($item->title) ?></div>
        <?php if ( ! empty($item->subtitle)): ?>
        <div class="mt-0.5 text-sm text-slate-500"><?= html_escape($item->subtitle) ?></div>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<?php $this->load->view('guide/_unsolved'); ?>
</main>
