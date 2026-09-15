<?php
/**
 * 파일 위치: application/views/admin/organization.php
 * 역할: 조직 기본 정보 수정
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<h1 class="h4 mb-3">조직 정보</h1>

<div class="card">
  <div class="card-body">
    <div class="mb-3">
      <label class="form-label">고객 접속 주소</label>
      <input class="form-control" value="<?= base_url($org->org_code) ?>" readonly>
    </div>
    <div class="mb-3">
      <label class="form-label" for="orgName">조직명</label>
      <input class="form-control" id="orgName" value="<?= html_escape($org->name) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label" for="orgPhone">대표 전화</label>
      <input class="form-control" id="orgPhone" value="<?= html_escape($org->phone) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label">사업자등록번호</label>
      <input class="form-control" value="<?= html_escape($org->biz_no) ?>" readonly>
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
