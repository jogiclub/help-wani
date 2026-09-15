<?php
/**
 * 파일 위치: application/views/auth/login.php
 * 역할: 상담원 로그인 화면
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="mx-auto max-w-md">
  <div class="card">
    <div class="card-body">
      <h1 class="mb-6 text-center text-xl font-bold text-slate-800">
        <?= html_escape($app_name) ?> 상담원 로그인
      </h1>

      <form id="loginForm" class="space-y-4">
        <div>
          <label class="form-label" for="email">이메일</label>
          <input type="email" class="form-input" id="email" name="email" required autofocus>
        </div>
        <div>
          <label class="form-label" for="password">비밀번호</label>
          <input type="password" class="form-input" id="password" name="password" required>
        </div>
        <button type="submit" class="btn btn-primary w-full">로그인</button>
      </form>

      <div class="mt-4 text-center text-sm">
        <a class="text-brand-600 hover:underline" href="<?= base_url('signup') ?>">조직 가입 신청</a>
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
