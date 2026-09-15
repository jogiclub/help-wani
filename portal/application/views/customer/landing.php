<?php
/**
 * 파일 위치: application/views/customer/landing.php
 * 역할: 고객 접속 안내 페이지 (조직이 설정한 언어로 표시)
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
  <h1 class="mt-3 text-2xl font-bold text-slate-800">
    <?= html_escape(lang_text('customer.title', array('org' => $org->name))) ?>
  </h1>
  <p class="mt-1 text-sm text-slate-500"><?= html_escape(lang_text('customer.subtitle')) ?></p>
</div>

<div class="alert alert-danger mt-6">
  <p class="font-bold"><?= html_escape(lang_text('customer.warning.title')) ?></p>
  <p class="mt-1 leading-relaxed"><?= html_escape(lang_text('customer.warning.body')) ?></p>
</div>

<!-- 1단계: 설치 -->
<div class="card mt-4">
  <div class="card-body">
    <div class="flex gap-4">
      <span class="step-badge">1</span>
      <div class="min-w-0 flex-1">
        <div class="font-semibold text-slate-800"><?= html_escape(lang_text('customer.step1.title')) ?></div>
        <div class="mt-1 text-sm text-slate-500"><?= html_escape(lang_text('customer.step1.body')) ?></div>

        <a class="btn btn-primary btn-lg mt-3 w-full sm:w-auto" href="<?= html_escape($store_app_url) ?>">
          <?= html_escape(lang_text('customer.step1.button')) ?>
        </a>

        <div class="mt-2">
          <a class="text-xs text-brand-600 hover:underline"
             href="<?= html_escape($store_web_url) ?>" target="_blank" rel="noopener">
            <?= html_escape(lang_text('customer.step1.fallback')) ?>
          </a>
        </div>

        <?php if ($show_download): ?>
        <div class="mt-3 border-t border-slate-100 pt-3">
          <div class="text-xs text-slate-500"><?= html_escape(lang_text('customer.step1.download_note')) ?></div>
          <a class="btn btn-secondary btn-sm mt-2" href="<?= html_escape($download_url) ?>">
            <?= html_escape(lang_text('customer.step1.download_button')) ?>
          </a>
          <div class="form-help">
            RemoteHelp_<?= html_escape($org->org_code) ?>.exe ·
            <?= html_escape($download_info['size_mb']) ?>MB ·
            <span data-i18n-date="<?= html_escape($download_info['modified']) ?>" data-i18n-date-style="date"></span>
          </div>
          <details class="mt-2 text-xs text-slate-400">
            <summary class="cursor-pointer"><?= html_escape(lang_text('customer.step1.checksum')) ?></summary>
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
        <div class="font-semibold text-slate-800"><?= html_escape(lang_text('customer.step2.title')) ?></div>
        <div class="mt-1 text-sm text-slate-500"><?= html_escape(lang_text('customer.step2.body')) ?></div>
        <a class="btn btn-success mt-3" href="<?= html_escape($protocol_url) ?>">
          <?= html_escape(lang_text('customer.step2.button')) ?>
        </a>
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
        <div class="font-semibold text-slate-800"><?= html_escape(lang_text('customer.step3.title')) ?></div>
        <div class="mt-1 text-sm text-slate-500"><?= html_escape(lang_text('customer.step3.body')) ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card mt-4">
  <div class="card-body text-sm text-slate-500">
    <div class="mb-2 font-semibold text-slate-700"><?= html_escape(lang_text('customer.notice.title')) ?></div>
    <ul class="list-disc space-y-1 pl-5">
      <li><?= html_escape(lang_text('customer.notice.item1')) ?></li>
      <li><?= html_escape(lang_text('customer.notice.item2')) ?></li>
      <li><?= html_escape(lang_text('customer.notice.item3')) ?></li>
      <li><?= html_escape(lang_text('customer.notice.item4')) ?></li>
    </ul>
  </div>
</div>
