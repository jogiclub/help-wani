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
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
<!-- 각 화면의 인라인 스크립트가 본문에서 바로 실행되므로 라이브러리를 먼저 불러온다. -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= base_url('assets/js/common.js') ?>"></script>
</head>
<body class="bg-body-tertiary">

<nav class="navbar navbar-expand-lg bg-dark" data-bs-theme="dark">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="<?= base_url('console') ?>"><?= html_escape($app_name) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="<?= base_url('console') ?>">대기열</a></li>
        <li class="nav-item"><a class="nav-link" href="<?= base_url('console/history') ?>">상담 이력</a></li>
        <?php if ($this->session->userdata('agent_role') === 'admin'): ?>
        <li class="nav-item"><a class="nav-link" href="<?= base_url('admin') ?>">조직 관리</a></li>
        <?php endif; ?>
      </ul>
      <span class="navbar-text me-3">
        <?= html_escape($this->session->userdata('org_name')) ?> ·
        <?= html_escape($this->session->userdata('agent_name')) ?>
      </span>
      <a class="btn btn-outline-light btn-sm" href="<?= base_url('logout') ?>">로그아웃</a>
    </div>
  </div>
</nav>

<main class="<?= isset($fluid) && $fluid ? 'container-fluid' : 'container' ?> py-4">
<?php $this->load->view($content_view); ?>
</main>

<div class="toast-container position-fixed top-0 end-0 p-3" id="toastContainer"></div>

<div class="modal fade" id="confirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="confirmModalTitle"></h5></div>
      <div class="modal-body" id="confirmModalBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="confirmModalCancel">취소</button>
        <button type="button" class="btn btn-primary" id="confirmModalOk">확인</button>
      </div>
    </div>
  </div>
</div>

<?php if (isset($page_scripts)): foreach ($page_scripts as $src): ?>
<script <?= (isset($module_scripts) && in_array($src, $module_scripts, TRUE)) ? 'type="module"' : '' ?> src="<?= $src ?>"></script>
<?php endforeach; endif; ?>
</body>
</html>
