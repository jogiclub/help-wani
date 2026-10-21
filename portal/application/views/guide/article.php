<?php
/**
 * 파일 위치: application/views/guide/article.php
 * 역할: 고객이 보는 공개 가이드 상세 (관리자 미리보기도 같은 화면을 쓴다)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<?php if ( ! empty($preview)): ?>
<div class="alert alert-info mb-4"><?= html_escape(lang_text('guide.preview_notice')) ?></div>
<?php endif; ?>

<?php $this->load->view('guide/_header'); ?>

<div class="mt-4 mb-2">
  <a class="text-sm text-brand-600 hover:underline" href="<?= html_escape(base_url($org->org_code.'/guide')) ?>">← <?= html_escape(lang_text('guide.back')) ?></a>
</div>

<article class="card">
  <div class="card-body">
    <?php $this->load->view('guide/_article', array('article' => $article)); ?>
  </div>
</article>

<?php $this->load->view('guide/_unsolved'); ?>

<script src="<?= base_url('assets/js/guide.js') ?>"></script>
