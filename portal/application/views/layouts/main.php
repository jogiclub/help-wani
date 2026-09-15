<?php
/**
 * 파일 위치: application/views/layouts/main.php
 * 역할: 상담원/관리자 공통 레이아웃
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-name" content="<?= $this->security->get_csrf_token_name() ?>">
<meta name="csrf-hash" content="<?= $this->security->get_csrf_hash() ?>">
<title><?= html_escape(isset($page_title) ? $page_title : $app_name) ?> · <?= html_escape($app_name) ?></title>
<link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
<!-- 각 화면의 인라인 스크립트가 본문에서 바로 실행되므로 라이브러리를 먼저 불러온다. -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="<?= base_url('assets/js/common.js') ?>"></script>
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
      <?php if ($this->session->userdata('is_super')):
          $pending = $this->organization_model->count_by_status(); ?>
      <a class="flex items-center gap-1.5 rounded px-3 py-1.5 text-sm text-slate-300 hover:bg-slate-800 hover:text-white"
         href="<?= base_url('operator') ?>">
        조직 가입 관리
        <?php if ($pending['pending'] > 0): ?>
          <span class="badge badge-amber"><?= (int) $pending['pending'] ?></span>
        <?php endif; ?>
      </a>
      <?php endif; ?>
    </nav>

    <div class="ml-auto flex items-center gap-3">
      <span class="hidden text-sm text-slate-300 md:inline">
        <?= html_escape($this->session->userdata('org_name')) ?> ·
        <?= html_escape($this->session->userdata('agent_name')) ?>
      </span>
      <a class="btn btn-sm border border-slate-600 text-slate-200 hover:bg-slate-800"
         href="<?= base_url('logout') ?>">로그아웃</a>
    </div>
  </div>
</header>

<main class="mx-auto <?= isset($fluid) && $fluid ? 'max-w-full' : 'max-w-7xl' ?> px-4 py-6">
<?php $this->load->view($content_view); ?>
</main>

<?php $this->load->view('layouts/_partials'); ?>

<?php if (isset($page_scripts)): foreach ($page_scripts as $src): ?>
<script <?= (isset($module_scripts) && in_array($src, $module_scripts, TRUE)) ? 'type="module"' : '' ?> src="<?= $src ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
