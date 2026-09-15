<?php
/**
 * 파일 위치: application/views/customer/download_unavailable.php
 * 역할: 배포 파일이 아직 올라가지 않았을 때 보여주는 안내
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="mx-auto max-w-md">
  <div class="card">
    <div class="card-body text-center">
      <h1 class="text-xl font-bold text-slate-800">
        <?= html_escape(lang_text('customer.download.unavailable.title')) ?>
      </h1>
      <p class="mt-3 text-sm text-slate-500">
        <?= html_escape(lang_text('customer.download.unavailable.body', array('org' => $org->name))) ?>
      </p>
      <a class="btn btn-secondary mt-5" href="<?= base_url($org->org_code) ?>">
        <?= html_escape(lang_text('customer.download.back')) ?>
      </a>
    </div>
  </div>
</div>
