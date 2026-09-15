<?php
/**
 * 파일 위치: application/views/layouts/blank.php
 * 역할: 로그인/가입 등 단독 화면 레이아웃
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<!doctype html>
<html lang="<?= html_escape(isset($i18n) ? $i18n["locale"] : "ko") ?>">
<head>
<?php $this->load->view('layouts/_head'); ?>
</head>
<body class="min-h-screen">
<main class="mx-auto max-w-4xl px-4 py-10">
<?php $this->load->view($content_view); ?>
</main>
<?php $this->load->view('layouts/_partials'); ?>
</body>
</html>
