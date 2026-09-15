<?php
/**
 * 파일 위치: application/views/admin/organization.php
 * 역할: 조직 기본 정보 수정
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<h1 class="mb-4 text-xl font-bold text-slate-800">조직 정보</h1>

<div class="card max-w-2xl">
  <div class="card-body space-y-4">
    <div>
      <label class="form-label">고객 접속 주소</label>
      <input class="form-input bg-slate-50" value="<?= base_url($org->org_code) ?>" readonly>
    </div>
    <div>
      <label class="form-label" for="orgName">조직명</label>
      <input class="form-input" id="orgName" value="<?= html_escape($org->name) ?>">
    </div>
    <div>
      <label class="form-label" for="orgPhone">대표 전화</label>
      <input class="form-input" id="orgPhone" value="<?= html_escape($org->phone) ?>">
    </div>
    <div>
      <label class="form-label">사업자등록번호</label>
      <input class="form-input bg-slate-50" value="<?= html_escape($org->biz_no) ?>" readonly>
    </div>
    <button class="btn btn-primary" id="btnSaveOrg">저장</button>
  </div>
</div>

<script>
$(function () {
    $('#btnSaveOrg').on('click', function () {
        apiPost('<?= base_url('admin/api/org_save') ?>', {
            name: $('#orgName').val(),
            phone: $('#orgPhone').val()
        }, function (data, message) {
            showToast(message, 'success');
        });
    });
});
</script>
