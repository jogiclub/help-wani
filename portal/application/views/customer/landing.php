<?php
/**
 * 파일 위치: application/views/customer/landing.php
 * 역할: 고객 접속 안내 페이지
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="row justify-content-center">
  <div class="col-lg-7">

    <div class="text-center mb-4">
      <?php if ($org->logo_path): ?>
        <img src="<?= base_url($org->logo_path) ?>" alt="<?= html_escape($org->name) ?>" style="max-height:64px">
      <?php endif; ?>
      <h1 class="h3 mt-3"><?= html_escape($org->name) ?> 원격지원</h1>
      <p class="text-muted">상담원의 안내에 따라 아래 순서대로 진행해 주세요.</p>
    </div>

    <div class="alert alert-danger">
      <strong>보이스피싱 주의</strong><br>
      상담원을 직접 확인한 경우에만 코드를 입력하세요.
      금융기관이나 수사기관은 원격제어를 요구하지 않습니다.
      계좌 비밀번호, 보안카드, OTP 를 요구하면 즉시 연결을 끊고 신고해 주세요.
    </div>

    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex mb-3">
          <span class="customer-step me-3">1</span>
          <div>
            <div class="fw-semibold">원격지원 앱 설치</div>
            <div class="text-muted small">Microsoft Store 에서 무료로 설치합니다.</div>
            <a class="btn btn-primary btn-sm mt-2" href="<?= html_escape($store_app_url) ?>">Microsoft Store 에서 받기</a>
            <a class="btn btn-outline-secondary btn-sm mt-2" href="<?= html_escape($store_web_url) ?>" target="_blank" rel="noopener">웹에서 열기</a>
          </div>
        </div>

        <div class="d-flex mb-3">
          <span class="customer-step me-3">2</span>
          <div>
            <div class="fw-semibold">앱 실행</div>
            <div class="text-muted small">이미 설치했다면 아래 버튼으로 바로 실행합니다.</div>
            <a class="btn btn-success btn-sm mt-2" href="<?= html_escape($protocol_url) ?>">이미 설치했어요 (앱 열기)</a>
          </div>
        </div>

        <div class="d-flex">
          <span class="customer-step me-3">3</span>
          <div>
            <div class="fw-semibold">6자리 코드 입력</div>
            <div class="text-muted small">상담원이 전화로 알려준 6자리 숫자를 앱에 입력하면 연결됩니다.</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body small text-muted">
        <div class="fw-semibold text-body mb-1">안내</div>
        <ul class="mb-0 ps-3">
          <li>원격지원 중에는 화면 오른쪽 위에 "원격지원 중" 표시가 계속 보입니다.</li>
          <li>언제든지 그 표시의 [종료] 를 눌러 연결을 끊을 수 있습니다.</li>
          <li>앱은 상담이 끝나면 어떠한 프로그램도 컴퓨터에 남기지 않습니다.</li>
        </ul>
      </div>
    </div>

  </div>
</div>
