<?php
/**
 * 파일 위치: application/views/guide/index.php
 * 역할: 고객이 보는 공개 가이드 목록과 검색 결과 (조직이 설정한 언어로 표시)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

// 페이지 안 이동은 지금 연 주소를 따른다. 고객에게 보내는 주소만 customer_url() 로 만든다(CLAUDE.md 3.13).
$base = base_url($org->org_code.'/guide');
$link = function ($item) use ($base) {
    $item = (array) $item;
    return $base.'/'.rawurlencode($item['slug'] ?: $item['id']);
};
?>
<?php $this->load->view('guide/_header'); ?>

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

<?php $this->load->view('guide/_unsolved'); ?>
