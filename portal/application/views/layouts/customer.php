<?php
/**
 * 파일 위치: application/views/layouts/customer.php
 * 역할: 고객 화면 레이아웃
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<!doctype html>
<html lang="<?= html_escape(isset($i18n) ? $i18n["locale"] : "ko") ?>">
<head>
<?php $this->load->view('layouts/_head'); ?>
</head>
<body class="min-h-screen">
<main class="mx-auto max-w-3xl px-4 py-8">
<?php $this->load->view($content_view); ?>
</main>
<?php $this->load->view('layouts/_partials'); ?>
</body>
</html>
