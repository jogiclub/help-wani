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
<meta name="description" content="상담원과 통화 중 6자리 코드만 입력하면 시작되는 기업용 원격지원 서비스입니다.">
</head>
<body class="min-h-screen bg-white">

<header class="sticky top-0 z-40 border-b border-slate-200 bg-white/90 backdrop-blur">
  <div class="mx-auto flex max-w-6xl items-center gap-6 px-4 py-3">
    <a class="text-lg font-bold text-slate-900" href="<?= base_url() ?>"><?= html_escape($app_name) ?></a>

    <nav class="hidden gap-1 md:flex">
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#how">이용 방법</a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#features">기능</a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#security">보안</a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#pricing">요금</a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-100" href="#faq">자주 묻는 질문</a>
    </nav>

    <div class="ml-auto flex items-center gap-2">
      <?php if ($logged_in): ?>
        <a class="btn btn-primary btn-sm" href="<?= base_url('console') ?>">콘솔로 이동</a>
      <?php else: ?>
        <a class="btn btn-ghost btn-sm" href="<?= base_url('login') ?>">로그인</a>
        <a class="btn btn-primary btn-sm" href="<?= base_url('signup') ?>">도입 문의</a>
      <?php endif; ?>
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
        <p class="mt-2 max-w-md text-sm text-slate-500">
          기업이 자기 고객의 Windows PC 를 원격으로 지원하는 B2B 원격지원 서비스입니다.
        </p>
      </div>

      <div class="text-sm text-slate-500">
        <div class="mb-2 font-semibold text-slate-700">바로가기</div>
        <ul class="space-y-1">
          <li><a class="hover:underline" href="<?= base_url('login') ?>">상담원 로그인</a></li>
          <li><a class="hover:underline" href="<?= base_url('signup') ?>">조직 가입 신청</a></li>
        </ul>
      </div>

      <?php if ( ! empty($contact_email) || ! empty($contact_phone)): ?>
      <div class="text-sm text-slate-500">
        <div class="mb-2 font-semibold text-slate-700">문의</div>
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
      &copy; <?= date('Y') ?> <?= html_escape($app_name) ?>. 원격지원은 고객의 명시적 동의가 있을 때만 시작됩니다.
    </div>
  </div>
</footer>

<?php $this->load->view('layouts/_partials'); ?>
</body>
</html>
