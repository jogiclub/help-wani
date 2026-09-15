<?php
/**
 * 파일 위치: application/views/layouts/main.php
 * 역할: 조직 소속 사용자(상담원, 조직 관리자) 레이아웃
 */
defined('BASEPATH') OR exit('No direct script access allowed');
$is_super = (bool) $this->session->userdata('is_super');
?>
<!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('layouts/_head', array('use_grid' => isset($use_grid) ? $use_grid : FALSE)); ?>
</head>
<body class="min-h-screen">

<header class="bg-slate-900 text-white">
  <div class="mx-auto flex max-w-7xl items-center gap-6 px-4 py-3">
    <a class="text-lg font-bold" href="<?= base_url('console') ?>"><?= html_escape($app_name) ?></a>

    <nav class="hidden gap-1 sm:flex">
      <a class="rounded px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800 hover:text-white"
         href="<?= base_url('console') ?>">대기열</a>
      <a class="rounded px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800 hover:text-white"
         href="<?= base_url('console/history') ?>">상담 이력</a>
      <?php if ($this->session->userdata('agent_role') === 'admin'): ?>
      <a class="rounded px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800 hover:text-white"
         href="<?= base_url('admin') ?>">조직 관리</a>
      <?php endif; ?>
    </nav>

    <div class="ml-auto flex items-center gap-3">
      <span class="hidden text-sm text-slate-300 md:inline">
        <?= html_escape($this->session->userdata('org_name')) ?> ·
        <?= html_escape($this->session->userdata('agent_name')) ?>
      </span>
      <?php if ($is_super): ?>
      <!-- 운영자 계정만 보이는 콘솔 전환 링크. 메뉴 자체는 분리되어 있다. -->
      <a class="btn btn-sm border border-amber-400/60 text-amber-300 hover:bg-amber-400/10"
         href="<?= base_url('operator') ?>">운영자 콘솔</a>
      <?php endif; ?>
      <a class="btn btn-sm border border-slate-600 text-slate-200 hover:bg-slate-800"
         href="<?= base_url('logout') ?>">로그아웃</a>
    </div>
  </div>
</header>

<main class="mx-auto <?= isset($fluid) && $fluid ? 'max-w-full' : 'max-w-7xl' ?> px-4 py-6">
<?php $this->load->view($content_view); ?>
</main>

<?php $this->load->view('layouts/_partials'); ?>
</body>
</html>
