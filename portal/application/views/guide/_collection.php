<?php
/**
 * 파일 위치: application/views/guide/_collection.php
 * 역할: 모음 페이지 본문 고르기 ($view 가 features 면 새 기능 모음, videos 면 동영상 모음) — 모든 테마 공통
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="guide-collection">
<?php $this->load->view($view === 'videos' ? 'guide/_videos' : 'guide/_features'); ?>
</div>
