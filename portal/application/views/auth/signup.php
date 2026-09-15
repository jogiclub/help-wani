<?php
/**
 * 파일 위치: application/views/auth/signup.php
 * 역할: 조직 가입 신청 화면
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="row justify-content-center">
  <div class="col-md-7">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h4 mb-1">조직 가입 신청</h1>
        <p class="text-muted small mb-4">신청 후 운영자 승인이 완료되면 로그인할 수 있습니다.</p>
        <form id="signupForm">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="org_name">조직명</label>
              <input type="text" class="form-control" id="org_name" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="org_code">조직 주소(영문/숫자)</label>
              <div class="input-group">
                <span class="input-group-text"><?= base_url() ?></span>
                <input type="text" class="form-control" id="org_code" required>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="biz_no">사업자등록번호</label>
              <input type="text" class="form-control" id="biz_no" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="phone">대표 전화</label>
              <input type="text" class="form-control" id="phone">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="name">담당자명</label>
              <input type="text" class="form-control" id="name" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="email">담당자 이메일</label>
              <input type="email" class="form-control" id="email" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="password">비밀번호(10자 이상)</label>
              <input type="password" class="form-control" id="password" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="password_confirm">비밀번호 확인</label>
              <input type="password" class="form-control" id="password_confirm" required>
            </div>
          </div>
          <button type="submit" class="btn btn-primary mt-4">가입 신청</button>
          <a href="<?= base_url('login') ?>" class="btn btn-link mt-4">로그인으로</a>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
$(function () {
    $('#signupForm').on('submit', function (e) {
        e.preventDefault();
        apiPost('<?= base_url('signup') ?>', {
            org_name: $('#org_name').val(),
            org_code: $('#org_code').val(),
            biz_no: $('#biz_no').val(),
            phone: $('#phone').val(),
            name: $('#name').val(),
            email: $('#email').val(),
            password: $('#password').val(),
            password_confirm: $('#password_confirm').val()
        }, function (data, message) {
            showToast(message, 'success');
            setTimeout(function () { window.location.href = data.redirect; }, 1500);
        });
    });
});
</script>
