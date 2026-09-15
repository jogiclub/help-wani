<?php
/**
 * 파일 위치: application/views/layouts/public.php
 * 역할: 로그인하지 않은 방문자용 홍보 페이지 레이아웃
 */
defined('BASEPATH') OR exit('No direct script access allowed');
$logged_in = (bool) $this->session->userdata('agent_id');
?>
<!doctype html>
<html lang="<?= html_escape(isset($i18n) ? $i18n['locale'] : 'ko') ?>">
<head>
<?php $this->load->view('layouts/_head'); ?>
<meta name="description" content="<?= html_escape(lang_text('home.meta.description')) ?>">
</head>
<body class="min-h-screen bg-white">

<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
  <div class="mx-auto flex max-w-6xl items-center gap-6 px-4 py-3">
    <a class="text-lg font-bold text-slate-900" href="<?= base_url() ?>"><?= html_escape($app_name) ?></a>

    <nav class="hidden gap-1 md:flex">
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#how"><?= html_escape(lang_text('nav.how')) ?></a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#features"><?= html_escape(lang_text('nav.features')) ?></a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#security"><?= html_escape(lang_text('nav.security')) ?></a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#pricing"><?= html_escape(lang_text('nav.pricing')) ?></a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#faq"><?= html_escape(lang_text('nav.faq')) ?></a>
    </nav>

    <div class="ml-auto flex items-center gap-2">
      <?php if ($logged_in): ?>
        <a class="btn btn-primary btn-sm" href="<?= base_url('console') ?>"><?= html_escape(lang_text('nav.console')) ?></a>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="<?= base_url('login') ?>"><?= html_escape(lang_text('nav.login')) ?></a>
        <a class="btn btn-primary btn-sm" href="<?= base_url('signup') ?>"><?= html_escape(lang_text('nav.signup')) ?></a>
      <?php endif; ?>

      <!-- 언어 선택. 고른 언어는 쿠키에 1년간 기억한다. -->
      <div class="relative" id="langPicker">
        <button type="button" id="langToggle"
                class="btn btn-sm btn-secondary"
                aria-haspopup="true" aria-expanded="false"
                title="<?= html_escape(lang_text('nav.language')) ?>">
          <span class="text-base leading-none">🌐</span>
          <span><?= html_escape($locales[$i18n['locale']]['name']) ?></span>
        </button>

        <div id="langMenu"
             class="absolute right-0 z-50 mt-1 hidden w-36 overflow-hidden rounded-md border border-slate-200 bg-white py-1 shadow-lg">
          <?php foreach ($locales as $code => $info): ?>
            <a class="flex items-center justify-between px-3 py-2 text-sm hover:bg-slate-50 <?= $code === $i18n['locale'] ? 'font-semibold text-brand-600' : 'text-slate-600' ?>"
               href="<?= base_url('lang/'.$code) ?>">
              <?= html_escape($info['name']) ?>
              <?php if ($code === $i18n['locale']): ?><span>✓</span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</header>

<main>
<?php $this->load->view($content_view); ?>
</main>

<footer class="border-t border-slate-200 bg-slate-50">
  <div class="mx-auto max-w-6xl px-4 py-10">
    <div class="flex flex-wrap items-start justify-between gap-6">
      <div>
        <div class="text-base font-bold text-slate-900"><?= html_escape($app_name) ?></div>
        <p class="mt-2 max-w-md text-sm text-slate-500"><?= html_escape(lang_text('footer.tagline')) ?></p>
      </div>

      <div class="text-sm text-slate-500">
        <div class="mb-2 font-semibold text-slate-700"><?= html_escape(lang_text('footer.links')) ?></div>
        <ul class="space-y-1">
          <li><a class="hover:underline" href="<?= base_url('login') ?>"><?= html_escape(lang_text('footer.login')) ?></a></li>
          <li><a class="hover:underline" href="<?= base_url('signup') ?>"><?= html_escape(lang_text('footer.signup')) ?></a></li>
        </ul>
      </div>

      <?php if ( ! empty($contact_email) || ! empty($contact_phone)): ?>
      <div class="text-sm text-slate-500">
        <div class="mb-2 font-semibold text-slate-700"><?= html_escape(lang_text('footer.contact')) ?></div>
        <ul class="space-y-1">
          <?php if ( ! empty($contact_phone)): ?>
            <li><?= html_escape($contact_phone) ?></li>
          <?php endif; ?>
          <?php if ( ! empty($contact_email)): ?>
            <li><a class="hover:underline" href="mailto:<?= html_escape($contact_email) ?>"><?= html_escape($contact_email) ?></a></li>
          <?php endif; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>

    <div class="mt-8 border-t border-slate-200 pt-6 text-xs text-slate-400">
      &copy; <?= date('Y') ?> <?= html_escape($app_name) ?>. <?= html_escape(lang_text('footer.copyright')) ?>
    </div>
  </div>
</footer>

<?php $this->load->view('layouts/_partials'); ?>

<script>
$(function () {
    // 언어 메뉴 열고 닫기
    $('#langToggle').on('click', function (e) {
        e.stopPropagation();
        var expanded = $('#langMenu').toggleClass('hidden').is(':visible');
        $(this).attr('aria-expanded', expanded);
    });

    $(document).on('click', function () {
        $('#langMenu').addClass('hidden');
        $('#langToggle').attr('aria-expanded', 'false');
    });
});
</script>
</body>
</html>
