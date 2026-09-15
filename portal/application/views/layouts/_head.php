<?php
/**
 * 파일 위치: application/views/layouts/_head.php
 * 역할: 모든 레이아웃이 공유하는 head 구성 (메타, 스타일, 공통 스크립트)
 *
 * 각 화면의 인라인 스크립트가 본문에서 바로 실행되므로 라이브러리를 먼저 불러온다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');
$use_grid = isset($use_grid) ? $use_grid : FALSE;
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-name" content="<?= $this->security->get_csrf_token_name() ?>">
<meta name="csrf-hash" content="<?= $this->security->get_csrf_hash() ?>">
<title><?= html_escape(isset($page_title) ? $page_title : $app_name) ?> · <?= html_escape($app_name) ?></title>
<link href="<?= base_url('assets/css/app.css') ?>" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<?php if ($use_grid): ?>
<!-- AG Grid Community v36. 테마는 CSS 파일 없이 Theming API 로 적용한다. -->
<script src="https://cdn.jsdelivr.net/npm/ag-grid-community@36.1.0/dist/ag-grid-community.min.js"></script>
<script>
    // v33 부터 모듈을 명시적으로 등록해야 한다.
    agGrid.ModuleRegistry.registerModules([agGrid.AllCommunityModule]);
</script>
<script src="<?= base_url('assets/js/grid.js') ?>"></script>
<?php endif; ?>
<script src="<?= base_url('assets/js/common.js') ?>"></script>
