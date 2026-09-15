<?php
/**
 * 파일 위치: application/views/auth/login.php
 * 역할: 상담원 로그인 화면
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="row justify-content-center">
  <div class="col-md-5">
    <div class="card shadow-sm">
      <div class="card-body p-4">
        <h1 class="h4 mb-4 text-center"><?= html_escape($app_name) ?> 상담원 로그인</h1>
        <form id="loginForm">
          <div class="mb-3">
            <label class="form-label" for="email">이메일</label>
            <input type="email" class="form-control" id="email" name="email" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">비밀번호</label>
            <input type="password" class="form-control" id="password" name="password" required>
          </div>
          <button type="submit" class="btn btn-primary w-100">로그인</button>
        </form>
        <div class="text-center mt-3">
          <a href="<?= base_url('signup') ?>">조직 가입 신청</a>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
$(function () {
    $('#loginForm').on('submit', function (e) {
        e.preventDefault();
        apiPost('<?= base_url('login') ?>', {
            email: $('#email').val(),
            password: $('#password').val()
        }, function (data) {
            window.location.href = data.redirect;
        });
    });
});
</script>
