<?php
/**
 * 파일 위치: application/views/customer/landing.php
 * 역할: 고객 접속 안내 페이지 (조직 로고/소개/문의처와 접속 절차, 조직이 설정한 언어로 표시)
 *
 * 1차 배포는 Microsoft Store 단독이다. 직접 다운로드는 코드 서명 인증서가 준비되면
 * .env 의 DIRECT_DOWNLOAD_ENABLED 로 켠다(docs/code-signing.md).
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$show_download = $download_enabled && $download_info;
?>
<!-- 조직 소개: 로고, 이름, 소개 문구, 문의처 (조직 정보 화면에서 관리) -->
<div class="card">
  <div class="card-body text-center">
    <?php if ($org->logo_path): ?>
      <img src="<?= base_url($org->logo_path) ?>" alt="<?= html_escape($org->name) ?>" class="mx-auto max-h-16 max-w-full">
    <?php else: ?>
      <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 text-2xl font-bold text-white">
        <?= html_escape(mb_substr($org->name, 0, 1)) ?>
      </div>
    <?php endif; ?>
    <h1 class="mt-3 text-2xl font-bold text-slate-800">
      <?= html_escape(lang_text('customer.title', array('org' => $org->name))) ?>
    </h1>
    <?php if ( ! empty($org->intro)): ?>
      <p class="mx-auto mt-2 max-w-xl whitespace-pre-line text-sm leading-relaxed text-slate-600"><?= html_escape($org->intro) ?></p>
    <?php endif; ?>
    <?php if ( ! empty($org->phone)): ?>
      <p class="mt-3 text-sm text-slate-500">
        <?= html_escape(lang_text('customer.contact')) ?>
        <a class="font-semibold text-brand-600 hover:underline" href="tel:<?= html_escape(preg_replace('/[^0-9+]/', '', $org->phone)) ?>"><?= html_escape($org->phone) ?></a>
      </p>
    <?php endif; ?>
  </div>
</div>
<p class="mt-4 text-center text-sm text-slate-500"><?= html_escape(lang_text('customer.subtitle')) ?></p>

<?php if ( ! empty($guide_count)): ?>
<!-- 공개 가이드 (가이드 관리에서 공개한 글이 있을 때만) -->
<a class="card mt-4 flex items-center gap-3 px-5 py-3 hover:bg-slate-50" href="<?= html_escape(base_url($org->org_code.'/guide')) ?>">
  <span class="min-w-0 flex-1">
    <span class="block font-semibold text-slate-800"><?= html_escape(lang_text('customer.guide_link')) ?></span>
    <span class="block text-sm text-slate-500"><?= html_escape(lang_text('customer.guide_hint')) ?></span>
  </span>
  <span class="shrink-0 text-brand-600">→</span>
</a>
<?php endif; ?>

<div class="alert alert-danger mt-4">
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

        <?php if ($show_download): ?>
        <a class="btn btn-primary btn-lg mt-3 w-full sm:w-auto" href="<?= html_escape($download_url) ?>">
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
        <div class="mt-3 border-t border-slate-100 pt-3 text-xs text-slate-500">
          <?= html_escape(lang_text('customer.step1.store_note')) ?>
          <a class="text-brand-600 hover:underline" href="<?= html_escape($store_app_url) ?>">
            <?= html_escape(lang_text('customer.step1.button')) ?>
          </a>
        </div>
        <?php else: ?>
        <a class="btn btn-primary btn-lg mt-3 w-full sm:w-auto" href="<?= html_escape($store_app_url) ?>">
          <?= html_escape(lang_text('customer.step1.button')) ?>
        </a>
        <div class="mt-2">
          <a class="text-xs text-brand-600 hover:underline"
             href="<?= html_escape($store_web_url) ?>" target="_blank" rel="noopener">
            <?= html_escape(lang_text('customer.step1.fallback')) ?>
          </a>
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
