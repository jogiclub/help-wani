<?php
/**
 * 파일 위치: application/views/customer/survey.php
 * 역할: 원격지원 만족도 조사
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card">
      <div class="card-body p-4 text-center">
        <h1 class="h4 mb-1">원격지원 만족도 조사</h1>
        <p class="text-muted small"><?= html_escape($org->name) ?> · <?= html_escape($session->created_at) ?></p>

        <?php if ($existing): ?>
          <div class="alert alert-info mt-3">이미 참여하신 조사입니다. 감사합니다.</div>
        <?php else: ?>
          <div class="star-rating my-3" id="stars">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <span class="star" data-score="<?= $i ?>">★</span>
            <?php endfor; ?>
          </div>
          <textarea class="form-control mb-3" id="comment" rows="4" placeholder="의견을 남겨 주세요 (선택)"></textarea>
          <button class="btn btn-primary w-100" id="btnSubmit">제출</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
$(function () {
    var score = 0;

    $('#stars .star').on('click', function () {
        score = parseInt($(this).data('score'), 10);
        $('#stars .star').each(function () {
            $(this).toggleClass('active', parseInt($(this).data('score'), 10) <= score);
        });
    });

    $('#btnSubmit').on('click', function () {
        if (!score) {
            showToast('별점을 선택해 주세요.', 'warning');
            return;
        }
        apiPost(window.location.href, { score: score, comment: $('#comment').val() }, function (data, message) {
            showToast(message, 'success');
            $('#btnSubmit').prop('disabled', true);
        });
    });
});
</script>
