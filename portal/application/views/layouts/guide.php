<?php
/**
 * 파일 위치: application/views/layouts/guide.php
 * 역할: 공개 가이드 레이아웃 (테마마다 화면 폭과 배경이 달라 본문 틀을 두지 않는다)
 *
 * 테마 모양은 assets/css/guide-themes.css 에 있다. body 의 gt-{테마} 클래스와 --gt-accent(강조색)로 고른다.
 * 조직이 상담 위젯을 켜 두면 그 조직의 상담 버블을 함께 띄운다(layouts/customer.php 와 같다).
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<!doctype html>
<html lang="<?= html_escape(isset($i18n) ? $i18n["locale"] : "ko") ?>">
<head>
<?php $this->load->view('layouts/_head'); ?>
<link href="<?= asset_url('assets/css/guide-themes.css') ?>" rel="stylesheet">
</head>
<body class="gt gt-<?= html_escape($theme) ?>" style="--gt-accent: <?= html_escape($accent) ?>;">
<?php $this->load->view($content_view); ?>
<?php $this->load->view('layouts/_partials'); ?>
<?php if ($theme === 'docs'): ?>
<!-- Split.js 1.6.5 (MIT). 문서형 테마의 목차/본문 사이 너비 조절. window.Split 을 만든다. -->
<script src="https://cdn.jsdelivr.net/npm/split.js@1.6.5/dist/split.min.js"
        integrity="sha384-q2ksSc8z6Q4ZUnxlfZj9AXZLpSdWmD3q/YrId1twTeNHh56fNh98YbJSpppzGUvL" crossorigin="anonymous"></script>
<?php endif; ?>
<script src="<?= asset_url('assets/js/guide.js') ?>"></script>
<?php if ( ! empty($widget_key)): ?>
<!-- 고객사 사이트에 붙이는 스니펫과 같은 형태다. 응대는 이 조직의 상담원이 한다. -->
<script src="<?= base_url('widget.js') ?>" data-key="<?= html_escape($widget_key) ?>" defer></script>
<?php endif; ?>
</body>
</html>
