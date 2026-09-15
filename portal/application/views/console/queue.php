<?php
/**
 * 파일 위치: application/views/console/queue.php
 * 역할: 상담원 대기열 화면 (코드 발급, 세션 상태 3초 갱신)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="mb-4 flex items-center justify-between">
  <h1 class="text-xl font-bold text-slate-800">상담 대기열</h1>
  <button class="btn btn-primary" id="btnCreate">새 세션</button>
</div>

<div class="card mb-6 hidden" id="codeCard">
  <div class="card-body text-center">
    <div class="text-sm text-slate-500">고객에게 안내할 접속 코드</div>
    <div class="code-display my-3" id="codeValue">------</div>
    <div class="mb-2 text-sm text-slate-600">
      남은 시간 <span class="badge badge-gray" id="codeRemain">10:00</span>
    </div>
    <div class="text-xs text-slate-500">
      고객 접속 주소: <span class="font-medium text-slate-700" id="customerUrl"></span>
    </div>
  </div>
</div>

<div class="card overflow-hidden">
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr>
          <th class="w-28">코드</th>
          <th class="w-32">상태</th>
          <th>고객 PC</th>
          <th class="w-32">고객 IP</th>
          <th class="w-24">담당</th>
          <th class="w-24">남은 시간</th>
          <th class="w-44">작업</th>
        </tr>
      </thead>
      <tbody id="sessionTbody">
        <tr><td colspan="7" class="py-8 text-center text-slate-400">불러오는 중...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
var CONSOLE_URLS = {
    create: '<?= base_url('console/api/session/create') ?>',
    list:   '<?= base_url('console/api/session/list') ?>',
    end:    '<?= base_url('console/api/session/end') ?>',
    viewer: '<?= base_url('console/session/') ?>'
};
</script>
<script src="<?= base_url('assets/js/console/queue.js') ?>"></script>
