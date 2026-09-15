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
          <label class="form-label" for="country">국가</label>
          <select class="form-input" id="country">
            <?php foreach ($countries as $code => $info): ?>
              <option value="<?= $code ?>"
                      data-locale="<?= $info['locale'] ?>"
                      data-timezone="<?= $info['timezone'] ?>"
                      <?= $code === 'KR' ? 'selected' : '' ?>><?= html_escape($info['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-help">국가를 고르면 언어와 시간대가 자동으로 맞춰집니다.</div>
        </div>

        <div>
          <label class="form-label" for="locale">화면 언어</label>
          <select class="form-input" id="locale">
            <?php foreach ($locales as $code => $info): ?>
              <option value="<?= $code ?>" <?= $code === 'ko' ? 'selected' : '' ?>><?= html_escape($info['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-help">고객 접속 페이지와 상담원 화면에 쓰입니다.</div>
        </div>

        <div class="sm:col-span-2">
          <label class="form-label" for="timezone">시간대</label>
          <select class="form-input" id="timezone">
            <?php foreach ($timezones as $tz => $label): ?>
              <option value="<?= $tz ?>" <?= $tz === 'Asia/Seoul' ? 'selected' : '' ?>><?= html_escape($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-help">
            상담 이력과 통계의 시각이 이 시간대로 표시됩니다.
            현재 시각: <span id="tzPreview" class="font-medium text-slate-700"></span>
          </div>
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
    /**
     * 선택한 시간대의 현재 시각을 미리 보여준다.
     */
    function updateTimezonePreview() {
        var tz = $('#timezone').val();
        var intl = $('#locale').val() === 'ja' ? 'ja-JP'
                 : ($('#locale').val() === 'en' ? 'en-US' : 'ko-KR');

        try {
            $('#tzPreview').text(new Intl.DateTimeFormat(intl, {
                timeZone: tz, dateStyle: 'medium', timeStyle: 'short'
            }).format(new Date()));
        } catch (e) {
            $('#tzPreview').text('-');
        }
    }

    // 국가를 바꾸면 언어와 시간대 기본값을 따라 바꾼다.
    $('#country').on('change', function () {
        var opt = $(this).find('option:selected');
        $('#locale').val(opt.data('locale'));
        $('#timezone').val(opt.data('timezone'));
        updateTimezonePreview();
    });

    $('#locale, #timezone').on('change', updateTimezonePreview);
    updateTimezonePreview();
    setInterval(updateTimezonePreview, 30000);

    $('#signupForm').on('submit', function (e) {
        e.preventDefault();
        apiPost('<?= base_url('signup') ?>', {
            org_name: $('#org_name').val(),
            org_code: $('#org_code').val(),
            biz_no: $('#biz_no').val(),
            phone: $('#phone').val(),
            country: $('#country').val(),
            locale: $('#locale').val(),
            timezone: $('#timezone').val(),
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
