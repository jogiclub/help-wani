<?php
/**
 * 파일 위치: application/views/console/viewer.php
 * 역할: 상담원 원격 화면(자체 캔버스 뷰어)과 상담 메모
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="grid grid-cols-1 gap-4 lg:grid-cols-4">

  <div class="lg:col-span-3">
    <div class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 px-4 py-3">
        <span class="mr-2 text-sm font-semibold text-slate-700">
          코드 <span class="font-mono"><?= html_escape($session->code) ?></span>
          <span class="badge badge-gray ml-1" id="viewerStatus"><?= html_escape(status_label($session->status)) ?></span>
        </span>

        <div class="flex flex-wrap items-center gap-1">
          <button class="btn btn-sm btn-secondary" id="btnConnect">연결</button>
          <button class="btn btn-sm btn-secondary" id="btnFit">화면 맞춤</button>
          <button class="btn btn-sm btn-secondary" id="btnCad">Ctrl+Alt+Del</button>
          <button class="btn btn-sm btn-secondary" id="btnClipboard">클립보드</button>
          <button class="btn btn-sm btn-secondary" id="btnFullscreen">전체화면</button>
          <select class="form-input btn-sm w-auto py-1" id="selQuality" title="화질">
            <option value="50,15,100">빠름 (품질 50 / 15fps)</option>
            <option value="70,10,100" selected>기본 (품질 70 / 10fps)</option>
            <option value="90,6,100">선명 (품질 90 / 6fps)</option>
          </select>
        </div>

        <button class="btn btn-sm btn-danger ml-auto" id="btnEnd">원격 종료</button>
      </div>

      <div id="screenWrap" class="relative flex items-center justify-center bg-black">
        <canvas id="screen" class="remote-screen"></canvas>
        <!-- 고객 PC 의 마우스 위치 표시 (입력을 가로채지 않는다) -->
        <canvas id="cursorLayer" class="pointer-events-none absolute"></canvas>
      </div>

      <div class="card-footer flex justify-between gap-3">
        <span id="viewerMessage">[연결] 을 누르면 고객 화면에 접속합니다.</span>
        <span id="viewerStats" class="shrink-0 text-xs text-slate-400"></span>
      </div>
    </div>
  </div>

  <div class="space-y-4">
    <div class="card">
      <div class="card-header">고객 정보</div>
      <dl class="divide-y divide-slate-100 text-sm">
        <div class="flex justify-between px-5 py-2.5">
          <dt class="text-slate-500">PC 이름</dt>
          <dd class="font-medium text-slate-700"><?= html_escape($session->customer_pc_name ?: '-') ?></dd>
        </div>
        <div class="flex justify-between px-5 py-2.5">
          <dt class="text-slate-500">OS</dt>
          <dd class="font-medium text-slate-700"><?= html_escape($session->customer_os ?: '-') ?></dd>
        </div>
        <div class="flex justify-between px-5 py-2.5">
          <dt class="text-slate-500">IP</dt>
          <dd class="font-medium text-slate-700"><?= html_escape($session->customer_ip ?: '-') ?></dd>
        </div>
        <div class="flex justify-between px-5 py-2.5">
          <dt class="text-slate-500">시작</dt>
          <dd class="font-medium text-slate-700"><?= html_escape(to_timezone($session->started_at, $i18n['timezone'])) ?></dd>
        </div>
      </dl>
    </div>

    <div class="card">
      <div class="card-header">상담 메모</div>
      <div class="card-body">
        <div id="noteList" class="mb-3 space-y-2">
          <?php foreach ($notes as $note): ?>
          <div class="border-l-2 border-brand-500 pl-3">
            <div class="text-xs text-slate-400"><?= html_escape($note->agent_name) ?> · <?= html_escape(to_timezone($note->created_at, $i18n['timezone'])) ?></div>
            <div class="text-sm text-slate-700"><?= nl2br(html_escape($note->content)) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <textarea class="form-input mb-2" id="noteContent" rows="3" placeholder="상담 내용을 기록하세요"></textarea>
        <button class="btn btn-sm btn-primary w-full" id="btnNote">메모 저장</button>
      </div>
    </div>
  </div>
</div>

<!-- 클립보드 전송 모달 -->
<div id="clipboardModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold text-slate-800">클립보드 보내기</h2>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="clipboardModal">×</button>
    </div>
    <div class="p-5">
      <textarea class="form-input" id="clipboardText" rows="5"></textarea>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
      <button class="btn btn-secondary" data-modal-close="clipboardModal">닫기</button>
      <button class="btn btn-primary" id="btnClipboardSend">고객 PC 로 보내기</button>
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
<script src="<?= base_url('assets/js/console/protocol.js') ?>"></script>
<script src="<?= base_url('assets/js/console/keymap.js') ?>"></script>
<script src="<?= base_url('assets/js/console/viewer.js') ?>"></script>
