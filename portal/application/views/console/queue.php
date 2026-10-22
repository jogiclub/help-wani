<?php
/**
 * 파일 위치: application/views/console/queue.php
 * 역할: 원격지원 화면 (코드 발급, 진행 중/지난 원격 세션 검색, 3초 갱신, wgrid)
 *       예전 원격 대기열과 원격 이력을 합친 화면이다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$status_options = array('active', 'issued', 'verified', 'waiting', 'connected', 'ended', 'expired', 'canceled');
?>
<div class="mb-4 flex items-center justify-between">
  <h1 class="text-xl font-bold text-slate-800">원격지원</h1>
  <button class="btn btn-primary" id="btnCreate">원격지원 코드 발급</button>
</div>

<div class="card mb-4">
  <div class="card-body flex flex-wrap items-center gap-2 text-sm">
    <span class="text-slate-500">고객 접속 주소</span>
    <a class="font-medium text-brand-600 hover:underline" id="customerUrl" href="<?= html_escape($customer_url) ?>"
       target="_blank" rel="noopener"><?= html_escape($customer_url) ?></a>
    <button type="button" class="btn btn-secondary btn-sm" id="btnCopyUrl">복사</button>
  </div>
</div>

<div class="card mb-4 hidden" id="codeCard">
  <div class="card-body text-center">
    <div class="text-sm text-slate-500">고객에게 안내할 접속 코드</div>
    <div class="code-display my-3" id="codeValue">------</div>
    <div class="mb-2 text-sm text-slate-600">
      남은 시간 <span class="badge badge-gray" id="codeRemain">10:00</span>
    </div>
    <div class="text-xs text-slate-500">
      고객에게 위 고객 접속 주소를 열고 이 코드를 입력하도록 안내하세요.
    </div>
  </div>
</div>

<!-- 검색 조건은 바로 목록에 반영되고, 3초 갱신도 같은 조건으로 한다. -->
<form class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-6" id="filterForm">
  <input type="text" class="form-input lg:col-span-2" name="keyword" maxlength="50" placeholder="코드 / PC이름 / 상담원"
         value="<?= html_escape((string) $filters['keyword']) ?>">
  <select class="form-input" name="status">
    <option value="">전체 상태</option>
    <?php foreach ($status_options as $st): ?>
    <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>>
      <?= $st === 'active' ? '진행 중' : html_escape(status_label($st)) ?>
    </option>
    <?php endforeach; ?>
  </select>
  <input type="date" class="form-input" name="date_from" value="<?= html_escape((string) $filters['date_from']) ?>">
  <input type="date" class="form-input" name="date_to" value="<?= html_escape((string) $filters['date_to']) ?>">
  <div class="flex gap-2">
    <button class="btn btn-primary flex-1">검색</button>
    <button type="button" class="btn btn-secondary" id="btnReset">초기화</button>
  </div>
</form>

<div class="card overflow-hidden">
  <div id="sessionGrid" style="height: calc(100vh - 380px); min-height: 380px;"></div>
</div>

<script>
var CONSOLE_URLS = {
    create: '<?= base_url('console/api/session/create') ?>',
    list:   '<?= base_url('console/api/session/list') ?>',
    end:    '<?= base_url('console/api/session/end') ?>',
    viewer: '<?= base_url('console/session/') ?>'
};
</script>
<script src="<?= asset_url('assets/js/console/queue.js') ?>"></script>
