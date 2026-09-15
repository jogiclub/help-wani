<?php
/**
 * 파일 위치: application/views/auth/signup.php
 * 역할: 조직 가입 신청 화면
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="mx-auto max-w-2xl">
  <div class="card">
    <div class="card-body">
      <h1 class="text-xl font-bold text-slate-800">조직 가입 신청</h1>
      <p class="mt-1 mb-6 text-sm text-slate-500">신청 후 운영자 승인이 완료되면 로그인할 수 있습니다.</p>

      <form id="signupForm" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="form-label" for="org_name">조직명</label>
          <input type="text" class="form-input" id="org_name" required>
        </div>
        <div>
          <label class="form-label" for="org_code">조직 주소(영문/숫자)</label>
          <div class="flex">
            <span class="flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2 text-xs text-slate-500">
              <?= base_url() ?>
            </span>
            <input type="text" class="form-input rounded-l-none" id="org_code" required>
          </div>
        </div>
        <div>
          <label class="form-label" for="biz_no">사업자등록번호</label>
          <input type="text" class="form-input" id="biz_no" required>
        </div>
        <div>
          <label class="form-label" for="phone">대표 전화</label>
          <input type="text" class="form-input" id="phone">
        </div>
        <div>
          <label class="form-label" for="name">담당자명</label>
          <input type="text" class="form-input" id="name" required>
        </div>
        <div>
          <label class="form-label" for="email">담당자 이메일</label>
          <input type="email" class="form-input" id="email" required>
        </div>
        <div>
          <label class="form-label" for="password">비밀번호(10자 이상)</label>
          <input type="password" class="form-input" id="password" required>
        </div>
        <div>
          <label class="form-label" for="password_confirm">비밀번호 확인</label>
          <input type="password" class="form-input" id="password_confirm" required>
        </div>

        <div class="flex items-center gap-2 sm:col-span-2">
          <button type="submit" class="btn btn-primary">가입 신청</button>
          <a href="<?= base_url('login') ?>" class="btn btn-ghost">로그인으로</a>
        </div>
      </form>
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
