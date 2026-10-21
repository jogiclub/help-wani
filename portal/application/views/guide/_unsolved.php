<?php
/**
 * 파일 위치: application/views/guide/_unsolved.php
 * 역할: 공개 가이드 하단의 원격지원 안내 (가이드로 해결되지 않을 때 고객 접속 페이지로 보낸다)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="card mt-6">
  <div class="card-body flex flex-wrap items-center gap-3">
    <div class="min-w-0 flex-1">
      <div class="font-semibold text-slate-800"><?= html_escape(lang_text('guide.unsolved')) ?></div>
      <div class="text-sm text-slate-500"><?= html_escape(lang_text('guide.unsolved_body')) ?></div>
    </div>
    <a class="btn btn-primary shrink-0" href="<?= html_escape(base_url($org->org_code.'/')) ?>"><?= html_escape(lang_text('guide.remote_button')) ?></a>
  </div>
</div>
