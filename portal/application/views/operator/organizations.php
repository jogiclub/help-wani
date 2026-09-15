<?php
/**
 * 파일 위치: application/views/operator/organizations.php
 * 역할: 플랫폼 운영자의 조직 가입 승인 목록 (AG Grid)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$rows = array();

foreach ($orgs as $org)
{
    $rows[] = array(
        'id'            => (int) $org->id,
        'org_code'      => $org->org_code,
        'name'          => $org->name,
        'status'        => $org->status,
        'biz_no'        => $org->biz_no,
        'phone'         => $org->phone,
        'owner_name'    => $org->owner ? $org->owner->name : '',
        'owner_email'   => $org->owner ? $org->owner->email : '',
        'agent_count'   => (int) $org->agent_count,
        'session_count' => (int) $org->session_count,
        'created_at'    => $org->created_at,
        'approved_at'   => $org->approved_at,
        'status_reason' => $org->status_reason,
    );
}
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-xl font-bold text-slate-800">조직 가입 관리</h1>
  <?php if ($counts['pending'] > 0): ?>
    <span class="badge badge-amber">승인 대기 <?= (int) $counts['pending'] ?>건</span>
  <?php endif; ?>
</div>

<div class="mb-4 flex flex-wrap gap-2">
  <a class="btn btn-sm <?= $filter === NULL ? 'btn-primary' : 'btn-secondary' ?>"
     href="<?= base_url('operator') ?>">전체</a>
  <?php foreach (array('pending' => '승인 대기', 'active' => '사용 중',
                       'rejected' => '반려', 'suspended' => '이용 중지') as $key => $label): ?>
    <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-secondary' ?>"
       href="<?= base_url('operator?status='.$key) ?>">
      <?= $label ?> (<?= (int) $counts[$key] ?>)
    </a>
  <?php endforeach; ?>
</div>

<div class="card overflow-hidden">
  <div id="orgGrid" style="height: calc(100vh - 300px); min-height: 420px;"></div>
</div>

<!-- 사유 입력 모달 -->
<div id="reasonModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold text-slate-800" id="reasonModalTitle"></h2>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="reasonModal">×</button>
    </div>
    <div class="p-5">
      <p class="mb-2 text-sm text-slate-500" id="reasonModalDesc"></p>
      <textarea class="form-input" id="reasonText" rows="3" placeholder="사유를 입력하세요"></textarea>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
      <button class="btn btn-secondary" data-modal-close="reasonModal">취소</button>
      <button class="btn btn-primary" id="reasonModalOk">확인</button>
    </div>
  </div>
</div>

<script>
var ORG_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
var ORG_URLS = {
    approve: '<?= base_url('operator/api/org_approve') ?>',
    reject:  '<?= base_url('operator/api/org_reject') ?>',
    suspend: '<?= base_url('operator/api/org_suspend') ?>',
    restore: '<?= base_url('operator/api/org_restore') ?>'
};
var ORG_BASE_URL = '<?= base_url() ?>';
</script>
<script src="<?= base_url('assets/js/operator/organizations.js') ?>"></script>
