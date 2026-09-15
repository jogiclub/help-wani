<?php
/**
 * 파일 위치: application/views/layouts/operator.php
 * 역할: 플랫폼 운영자 콘솔 레이아웃 (조직 소속 사용자 메뉴와 분리)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
$current = uri_string();
?>
<!doctype html>
<html lang="ko">
<head>
<?php $this->load->view('layouts/_head', array('use_grid' => isset($use_grid) ? $use_grid : FALSE)); ?>
</head>
<body class="min-h-screen">

<header class="border-b-2 border-amber-400 bg-slate-800 text-white">
  <div class="mx-auto flex max-w-7xl items-center gap-6 px-4 py-3">
    <a class="flex items-center gap-2" href="<?= base_url('operator') ?>">
      <span class="text-lg font-bold"><?= html_escape($app_name) ?></span>
      <span class="badge badge-amber">운영자</span>
    </a>

    <nav class="hidden gap-1 sm:flex">
      <a class="rounded px-3 py-1.5 text-sm <?= strpos($current, 'operator/audit') === FALSE ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' ?>"
         href="<?= base_url('operator') ?>">조직 가입 관리</a>
      <a class="rounded px-3 py-1.5 text-sm <?= strpos($current, 'operator/audit') !== FALSE ? 'bg-slate-700 text-white' : 'text-slate-300 hover:bg-slate-700 hover:text-white' ?>"
         href="<?= base_url('operator/audit') ?>">감사 로그</a>
    </nav>

    <div class="ml-auto flex items-center gap-3">
      <span class="hidden text-sm text-slate-300 md:inline">
        <?= html_escape($this->session->userdata('agent_name')) ?>
      </span>
      <a class="btn btn-sm border border-slate-600 text-slate-200 hover:bg-slate-700"
         href="<?= base_url('console') ?>">상담 콘솔</a>
      <a class="btn btn-sm border border-slate-600 text-slate-200 hover:bg-slate-700"
         href="<?= base_url('logout') ?>">로그아웃</a>
    </div>
  </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-6">
<?php $this->load->view($content_view); ?>
</main>

<?php $this->load->view('layouts/_partials'); ?>
</body>
</html>
