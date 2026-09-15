<?php
/**
 * 파일 위치: application/views/customer/landing.php
 * 역할: 고객 접속 안내 페이지
 *
 * 1차 배포는 Microsoft Store 단독이다. 직접 다운로드는 코드 서명 인증서가 준비되면
 * .env 의 DIRECT_DOWNLOAD_ENABLED 로 켠다(docs/code-signing.md).
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$show_download = $download_enabled && $download_info;
?>
<div class="text-center">
  <?php if ($org->logo_path): ?>
    <img src="<?= base_url($org->logo_path) ?>" alt="<?= html_escape($org->name) ?>" class="mx-auto max-h-16">
  <?php endif; ?>
  <h1 class="mt-3 text-2xl font-bold text-slate-800"><?= html_escape($org->name) ?> 원격지원</h1>
  <p class="mt-1 text-sm text-slate-500">상담원의 안내에 따라 아래 순서대로 진행해 주세요.</p>
</div>

<div class="alert alert-danger mt-6">
  <p class="font-bold">보이스피싱 주의</p>
  <p class="mt-1 leading-relaxed">
    상담원을 직접 확인한 경우에만 코드를 입력하세요.
    금융기관이나 수사기관은 원격제어를 요구하지 않습니다.
    계좌 비밀번호, 보안카드, OTP 를 요구하면 즉시 연결을 끊고 신고해 주세요.
  </p>
</div>

<!-- 1단계: 설치 -->
<div class="card mt-4">
  <div class="card-body">
    <div class="flex gap-4">
      <span class="step-badge">1</span>
      <div class="min-w-0 flex-1">
        <div class="font-semibold text-slate-800">원격지원 앱 설치</div>
        <div class="mt-1 text-sm text-slate-500">
          Microsoft Store 에서 무료로 설치합니다. Windows 에 기본으로 들어 있는 앱 스토어입니다.
        </div>

        <a class="btn btn-primary btn-lg mt-3 w-full sm:w-auto" href="<?= html_escape($store_app_url) ?>">
          Microsoft Store 에서 설치
        </a>

        <div class="mt-2">
          <a class="text-xs text-brand-600 hover:underline"
             href="<?= html_escape($store_web_url) ?>" target="_blank" rel="noopener">
            버튼이 동작하지 않으면 여기를 눌러 웹에서 여세요
          </a>
        </div>

        <?php if ($show_download): ?>
        <div class="mt-3 border-t border-slate-100 pt-3">
          <div class="text-xs text-slate-500">
            회사 정책으로 Store 를 쓸 수 없다면 실행 파일을 직접 받을 수 있습니다.
          </div>
          <a class="btn btn-secondary btn-sm mt-2" href="<?= html_escape($download_url) ?>">
            실행 파일 직접 다운로드
          </a>
          <div class="form-help">
            RemoteHelp_<?= html_escape($org->org_code) ?>.exe ·
            <?= html_escape($download_info['size_mb']) ?>MB ·
            <?= html_escape($download_info['modified']) ?> 배포
          </div>
          <details class="mt-2 text-xs text-slate-400">
            <summary class="cursor-pointer">파일 무결성 확인 (SHA-256)</summary>
            <code class="mt-1 block break-all"><?= html_escape($download_info['sha256']) ?></code>
          </details>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- 2단계: 실행 -->
<div class="card mt-3">
  <div class="card-body">
    <div class="flex gap-4">
      <span class="step-badge">2</span>
      <div>
        <div class="font-semibold text-slate-800">앱 실행</div>
        <div class="mt-1 text-sm text-slate-500">
          설치가 끝나면 아래 버튼으로 바로 실행할 수 있습니다.
          시작 메뉴에서 "RemoteHelp" 를 찾아 실행해도 됩니다.
        </div>
        <a class="btn btn-success mt-3" href="<?= html_escape($protocol_url) ?>">이미 설치했어요 (앱 열기)</a>
      </div>
    </div>
  </div>
</div>

<!-- 3단계: 코드 입력 -->
<div class="card mt-3">
  <div class="card-body">
    <div class="flex gap-4">
      <span class="step-badge">3</span>
      <div>
        <div class="font-semibold text-slate-800">6자리 코드 입력</div>
        <div class="mt-1 text-sm text-slate-500">
          상담원이 전화로 알려준 6자리 숫자를 입력하고 [연결] 을 누르면 연결됩니다.
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-body text-sm text-slate-500">
    <div class="mb-2 font-semibold text-slate-700">안내</div>
    <ul class="list-disc space-y-1 pl-5">
      <li>원격지원 중에는 화면 오른쪽 위에 "원격지원 중" 표시가 계속 보입니다.</li>
      <li>언제든지 그 표시의 [종료] 를 눌러 연결을 끊을 수 있습니다.</li>
      <li>앱은 상담이 끝나면 어떠한 구성요소도 컴퓨터에 남기지 않습니다.</li>
      <li>Windows 10 이상에서 동작합니다.</li>
    </ul>
  </div>
</div>
