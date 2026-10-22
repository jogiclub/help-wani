<?php
/**
 * 파일 위치: application/views/layouts/customer.php
 * 역할: 고객 화면 레이아웃 (조직이 상담 위젯을 켜 두면 그 조직의 상담 버블을 함께 띄운다. 팔로업 메일로 왔으면 대화를 펼친다)
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
<?php if ( ! empty($widget_key)): ?>
<!-- 고객사 사이트에 붙이는 스니펫과 같은 형태다. 응대는 이 조직의 상담원이 한다. -->
<script src="<?= base_url('widget.js') ?>" data-key="<?= html_escape($widget_key) ?>"<?php if ( ! empty($widget_resume)): ?> data-resume="<?= html_escape($widget_resume) ?>" data-open="1"<?php endif; ?> defer></script>
<?php endif; ?>
</body>
</html>
