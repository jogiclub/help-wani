<?php
/**
 * 파일 위치: application/views/admin/agents.php
 * 역할: 상담원 목록과 추가/수정
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">상담원 관리</h1>
  <button class="btn btn-primary btn-sm" id="btnAdd">상담원 추가</button>
</div>

<table class="table bg-white align-middle">
  <thead class="table-light">
    <tr><th>이름</th><th>이메일</th><th>권한</th><th>상태</th><th>최근 로그인</th><th style="width:170px">작업</th></tr>
  </thead>
  <tbody>
  <?php foreach ($agents as $a): ?>
    <tr>
      <td><?= html_escape($a->name) ?></td>
      <td><?= html_escape($a->email) ?></td>
      <td><?= $a->role === 'admin' ? '관리자' : '상담원' ?></td>
      <td><span class="badge text-bg-<?= $a->is_active ? 'success' : 'secondary' ?>"><?= $a->is_active ? '사용' : '중지' ?></span></td>
      <td class="small text-muted"><?= html_escape($a->last_login_at ?: '-') ?></td>
      <td>
        <button class="btn btn-sm btn-outline-secondary btn-edit"
                data-agent='<?= html_escape(json_encode(array(
                    "id" => (int) $a->id, "name" => $a->name, "email" => $a->email,
                    "phone" => $a->phone, "role" => $a->role), JSON_UNESCAPED_UNICODE)) ?>'>수정</button>
        <button class="btn btn-sm btn-outline-danger btn-toggle" data-id="<?= (int) $a->id ?>">
          <?= $a->is_active ? '중지' : '사용' ?>
        </button>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<div class="modal fade" id="agentModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="agentModalTitle">상담원 추가</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" id="agentId">
        <div class="mb-2"><label class="form-label">이름</label><input class="form-control" id="agentName"></div>
        <div class="mb-2"><label class="form-label">이메일</label><input class="form-control" id="agentEmail"></div>
        <div class="mb-2"><label class="form-label">전화</label><input class="form-control" id="agentPhone"></div>
        <div class="mb-2"><label class="form-label">권한</label>
          <select class="form-select" id="agentRole">
            <option value="agent">상담원</option>
            <option value="admin">관리자</option>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">비밀번호(10자 이상, 수정 시 비워두면 유지)</label>
          <input type="password" class="form-control" id="agentPassword"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
        <button class="btn btn-primary" id="btnSaveAgent">저장</button>
      </div>
    </div>
  </div>
</div>

<script>
$(function () {
    var modal = new bootstrap.Modal(document.getElementById('agentModal'));

    function openModal(agent) {
        $('#agentId').val(agent ? agent.id : '');
        $('#agentName').val(agent ? agent.name : '');
        $('#agentEmail').val(agent ? agent.email : '');
        $('#agentPhone').val(agent ? agent.phone : '');
        $('#agentRole').val(agent ? agent.role : 'agent');
        $('#agentPassword').val('');
        $('#agentModalTitle').text(agent ? '상담원 수정' : '상담원 추가');
        modal.show();
    }

    $('#btnAdd').on('click', function () { openModal(null); });
    $('.btn-edit').on('click', function () { openModal($(this).data('agent')); });

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
