<?php
/**
 * 파일 위치: application/views/layouts/customer.php
 * 역할: 고객 화면 레이아웃
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
<main class="mx-auto max-w-3xl px-4 py-8">
<?php $this->load->view($content_view); ?>
</main>
<?php $this->load->view('layouts/_partials'); ?>
</body>
</html>
