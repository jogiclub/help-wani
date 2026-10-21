<?php
/**
 * 파일 위치: application/views/guide/_header.php
 * 역할: 공개 가이드 화면 상단 (조직 로고, 이름, 안내 문구)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="card">
  <div class="card-body flex items-center gap-4">
    <?php if ($org->logo_path): ?>
      <img src="<?= base_url($org->logo_path) ?>" alt="<?= html_escape($org->name) ?>" class="max-h-12 max-w-32 shrink-0">
    <?php else: ?>
      <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-xl font-bold text-white">
        <?= html_escape(mb_substr($org->name, 0, 1)) ?>
      </div>
    <?php endif; ?>
    <div class="min-w-0">
      <a class="text-lg font-bold text-slate-800 hover:underline" href="<?= html_escape(base_url($org->org_code.'/guide')) ?>">
        <?= html_escape(lang_text('guide.page_title', array('org' => $org->name))) ?>
      </a>
      <p class="text-sm text-slate-500"><?= html_escape(lang_text('guide.subtitle')) ?></p>
    </div>
  </div>
</div>
