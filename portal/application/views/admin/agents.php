<?php
/**
 * 파일 위치: application/views/admin/agents.php
 * 역할: 상담원 목록과 추가/수정
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="mb-4 flex items-center justify-between">
  <h1 class="text-xl font-bold text-slate-800">상담원 관리</h1>
  <button class="btn btn-sm btn-primary" id="btnAdd">상담원 추가</button>
</div>

<div class="card overflow-hidden">
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr><th>이름</th><th>이메일</th><th>권한</th><th>상태</th><th>최근 로그인</th><th class="w-40">작업</th></tr>
      </thead>
      <tbody>
      <?php foreach ($agents as $a): ?>
        <tr>
          <td class="font-medium text-slate-700"><?= html_escape($a->name) ?></td>
          <td><?= html_escape($a->email) ?></td>
          <td><?= $a->role === 'admin' ? '관리자' : '상담원' ?></td>
          <td>
            <span class="badge <?= $a->is_active ? 'badge-green' : 'badge-gray' ?>">
              <?= $a->is_active ? '사용' : '중지' ?>
            </span>
          </td>
          <td class="text-xs text-slate-500"><?= html_escape($a->last_login_at ?: '-') ?></td>
          <td class="whitespace-nowrap">
            <button class="btn btn-sm btn-secondary btn-edit mr-1"
                    data-agent='<?= html_escape(json_encode(array(
                        "id" => (int) $a->id, "name" => $a->name, "email" => $a->email,
                        "phone" => $a->phone, "role" => $a->role), JSON_UNESCAPED_UNICODE)) ?>'>수정</button>
            <button class="btn btn-sm btn-secondary btn-toggle" data-id="<?= (int) $a->id ?>">
              <?= $a->is_active ? '중지' : '사용' ?>
            </button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
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
$(function () {
    function openAgentModal(agent) {
        $('#agentId').val(agent ? agent.id : '');
        $('#agentName').val(agent ? agent.name : '');
        $('#agentEmail').val(agent ? agent.email : '');
        $('#agentPhone').val(agent ? agent.phone : '');
        $('#agentRole').val(agent ? agent.role : 'agent');
        $('#agentPassword').val('');
        $('#agentModalTitle').text(agent ? '상담원 수정' : '상담원 추가');
        openModal('agentModal');
    }

    $('#btnAdd').on('click', function () { openAgentModal(null); });
    $('.btn-edit').on('click', function () { openAgentModal($(this).data('agent')); });

    $('#btnSaveAgent').on('click', function () {
        apiPost('<?= base_url('admin/api/agent_save') ?>', {
            id: $('#agentId').val(),
            name: $('#agentName').val(),
            email: $('#agentEmail').val(),
            phone: $('#agentPhone').val(),
            role: $('#agentRole').val(),
            password: $('#agentPassword').val()
        }, function (data, message) {
            showToast(message, 'success');
            setTimeout(function () { window.location.reload(); }, 800);
        });
    });

    $('.btn-toggle').on('click', function () {
        var id = $(this).data('id');
        showConfirmModal('상태 변경', '이 상담원의 사용 여부를 변경할까요?', function () {
            apiPost('<?= base_url('admin/api/agent_toggle') ?>', { id: id }, function (data, message) {
                showToast(message, 'success');
                setTimeout(function () { window.location.reload(); }, 800);
            });
        });
    });
});
</script>
