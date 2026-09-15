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
      <h1 class="text-xl font-bold text-slate-800">프로그램을 준비 중입니다</h1>
      <p class="mt-3 text-sm text-slate-500">
        <?= html_escape($org->name) ?> 원격지원 프로그램 배포 파일이 아직 등록되지 않았습니다.<br>
        상담원에게 문의해 주세요.
      </p>
      <a class="btn btn-secondary mt-5" href="<?= base_url($org->org_code) ?>">안내 페이지로 돌아가기</a>
    </div>
  </div>
</div>
