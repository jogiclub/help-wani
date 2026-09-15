<?php
/**
 * 파일 위치: application/views/console/viewer.php
 * 역할: 상담원 원격 화면(noVNC)과 상담 메모
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="row g-3">
  <div class="col-lg-9">
    <div class="card">
      <div class="card-header d-flex flex-wrap align-items-center viewer-toolbar">
        <span class="fw-semibold me-3">
          코드 <?= html_escape($session->code) ?>
          <span class="badge text-bg-secondary ms-1" id="viewerStatus"><?= html_escape(status_label($session->status)) ?></span>
        </span>
        <div class="btn-group btn-group-sm me-2">
          <button class="btn btn-outline-secondary" id="btnConnect">연결</button>
          <button class="btn btn-outline-secondary" id="btnFit">화면 맞춤</button>
          <button class="btn btn-outline-secondary" id="btnCad">Ctrl+Alt+Del</button>
          <button class="btn btn-outline-secondary" id="btnClipboard">클립보드</button>
          <button class="btn btn-outline-secondary" id="btnFullscreen">전체화면</button>
        </div>
        <button class="btn btn-sm btn-danger ms-auto" id="btnEnd">원격 종료</button>
      </div>
      <div class="card-body p-0">
        <div id="screen"></div>
      </div>
      <div class="card-footer small text-muted" id="viewerMessage">
        [연결] 을 누르면 고객 화면에 접속합니다.
      </div>
    </div>
  </div>

  <div class="col-lg-3">
    <div class="card mb-3">
      <div class="card-header">고객 정보</div>
      <ul class="list-group list-group-flush small">
        <li class="list-group-item">PC 이름: <?= html_escape($session->customer_pc_name ?: '-') ?></li>
        <li class="list-group-item">OS: <?= html_escape($session->customer_os ?: '-') ?></li>
        <li class="list-group-item">IP: <?= html_escape($session->customer_ip ?: '-') ?></li>
        <li class="list-group-item">시작: <?= html_escape($session->started_at ?: '-') ?></li>
      </ul>
    </div>

    <div class="card">
      <div class="card-header">상담 메모</div>
      <div class="card-body">
        <div id="noteList" class="mb-3">
          <?php foreach ($notes as $note): ?>
          <div class="note-item ps-2 mb-2">
            <div class="small text-muted"><?= html_escape($note->agent_name) ?> · <?= html_escape($note->created_at) ?></div>
            <div><?= nl2br(html_escape($note->content)) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <textarea class="form-control mb-2" id="noteContent" rows="3" placeholder="상담 내용을 기록하세요"></textarea>
        <button class="btn btn-sm btn-primary w-100" id="btnNote">메모 저장</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="clipboardModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">클립보드 보내기</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <textarea class="form-control" id="clipboardText" rows="5"></textarea>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">닫기</button>
        <button class="btn btn-primary" id="btnClipboardSend">고객 PC 로 보내기</button>
      </div>
    </div>
  </div>
</div>

<script>
var VIEWER_CONFIG = {
    sessionId: <?= (int) $session->id ?>,
    tokenUrl:  '<?= base_url('console/api/session/viewer-token') ?>',
    endUrl:    '<?= base_url('console/api/session/end') ?>',
    noteUrl:   '<?= base_url('console/api/session/note') ?>',
    logUrl:    '<?= base_url('console/api/session/log') ?>',
    detailUrl: '<?= base_url('console/api/session/detail/'.$session->id) ?>',
    queueUrl:  '<?= base_url('console') ?>'
};
</script>
<script type="module" src="<?= base_url('assets/js/console/viewer.js') ?>"></script>
