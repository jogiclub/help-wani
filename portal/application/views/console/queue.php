<?php
/**
 * 파일 위치: application/views/console/queue.php
 * 역할: 상담원 대기열 화면 (코드 발급, 세션 상태 3초 갱신)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">상담 대기열</h1>
  <button class="btn btn-primary" id="btnCreate">
    <i class="bi bi-plus-lg"></i> 새 세션
  </button>
</div>

<div class="card mb-4 d-none" id="codeCard">
  <div class="card-body text-center">
    <div class="text-muted">고객에게 안내할 접속 코드</div>
    <div class="code-display my-2" id="codeValue">------</div>
    <div class="mb-2">
      남은 시간 <span class="badge text-bg-secondary" id="codeRemain">10:00</span>
    </div>
    <div class="small text-muted">
      고객 접속 주소: <span id="customerUrl" class="fw-semibold"></span>
    </div>
  </div>
</div>

<div class="table-responsive">
  <table class="table table-hover align-middle bg-white">
    <thead class="table-light">
      <tr>
        <th style="width:110px">코드</th>
        <th style="width:140px">상태</th>
        <th>고객 PC</th>
        <th style="width:130px">고객 IP</th>
        <th style="width:110px">담당</th>
        <th style="width:100px">남은 시간</th>
        <th style="width:180px">작업</th>
      </tr>
    </thead>
    <tbody id="sessionTbody">
      <tr><td colspan="7" class="text-center text-muted py-4">불러오는 중...</td></tr>
    </tbody>
  </table>
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
