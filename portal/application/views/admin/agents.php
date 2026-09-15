<?php
/**
 * 파일 위치: application/views/admin/agents.php
 * 역할: 상담원 목록(AG Grid)과 추가/수정
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$rows = array();

foreach ($agents as $a)
{
    $rows[] = array(
        'id'            => (int) $a->id,
        'name'          => $a->name,
        'email'         => $a->email,
        'phone'         => $a->phone,
        'role'          => $a->role,
        'is_active'     => (int) $a->is_active,
        'last_login_at' => $a->last_login_at,
        'created_at'    => $a->created_at,
    );
}
?>
<div class="mb-4 flex items-center justify-between">
  <h1 class="text-xl font-bold text-slate-800">상담원 관리</h1>
  <button class="btn btn-primary btn-sm" id="btnAdd">상담원 추가</button>
</div>

<div class="card overflow-hidden">
  <div id="agentGrid" style="height: calc(100vh - 260px); min-height: 380px;"></div>
</div>

<div id="agentModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold text-slate-800" id="agentModalTitle">상담원 추가</h2>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="agentModal">×</button>
    </div>
    <div class="space-y-3 p-5">
      <input type="hidden" id="agentId">
      <div><label class="form-label">이름</label><input class="form-input" id="agentName"></div>
      <div><label class="form-label">이메일</label><input class="form-input" id="agentEmail"></div>
      <div><label class="form-label">전화</label><input class="form-input" id="agentPhone"></div>
      <div>
        <label class="form-label">권한</label>
        <select class="form-input" id="agentRole">
          <option value="agent">상담원</option>
          <option value="admin">관리자</option>
        </select>
      </div>
      <div>
        <label class="form-label">비밀번호(10자 이상, 수정 시 비워두면 유지)</label>
        <input type="password" class="form-input" id="agentPassword">
      </div>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
      <button class="btn btn-secondary" data-modal-close="agentModal">취소</button>
      <button class="btn btn-primary" id="btnSaveAgent">저장</button>
    </div>
  </div>
</div>

<script>
var AGENT_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
var AGENT_URLS = {
    save:   '<?= base_url('admin/api/agent_save') ?>',
    toggle: '<?= base_url('admin/api/agent_toggle') ?>'
};
</script>
<script src="<?= base_url('assets/js/admin/agents.js') ?>"></script>
