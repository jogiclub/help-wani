<?php
/**
 * 파일 위치: application/views/console/history.php
 * 역할: 상담 이력 검색 및 메모 조회
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<h1 class="h4 mb-3">상담 이력</h1>

<form class="row g-2 mb-3" method="get" action="<?= base_url('console/history') ?>">
  <div class="col-md-3">
    <input type="text" class="form-control" name="keyword" placeholder="코드/PC이름/상담원"
           value="<?= html_escape($filters['keyword']) ?>">
  </div>
  <div class="col-md-2">
    <select class="form-select" name="status">
      <option value="">전체 상태</option>
      <?php foreach (array('connected','ended','expired','canceled') as $st): ?>
      <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= status_label($st) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2"><input type="date" class="form-control" name="date_from" value="<?= html_escape($filters['date_from']) ?>"></div>
  <div class="col-md-2"><input type="date" class="form-control" name="date_to" value="<?= html_escape($filters['date_to']) ?>"></div>
  <div class="col-md-2"><button class="btn btn-primary w-100">검색</button></div>
</form>

<div class="table-responsive">
  <table class="table table-sm table-hover bg-white align-middle">
    <thead class="table-light">
      <tr>
        <th>일시</th><th>코드</th><th>상담원</th><th>고객 PC</th>
        <th>상태</th><th>소요시간</th><th>만족도</th><th>메모</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($sessions)): ?>
      <tr><td colspan="8" class="text-center text-muted py-4">검색 결과가 없습니다.</td></tr>
    <?php else: foreach ($sessions as $s):
        $duration = ($s->started_at && $s->ended_at) ? strtotime($s->ended_at) - strtotime($s->started_at) : 0; ?>
      <tr>
        <td class="small"><?= html_escape($s->created_at) ?></td>
        <td><?= html_escape($s->code) ?></td>
        <td><?= html_escape($s->agent_name) ?></td>
        <td><?= html_escape($s->customer_pc_name ?: '-') ?></td>
        <td><span class="badge text-bg-secondary"><?= status_label($s->status) ?></span></td>
        <td><?= format_duration($duration) ?></td>
        <td><?= $s->survey_score ? str_repeat('★', (int) $s->survey_score) : '-' ?></td>
        <td>
          <button class="btn btn-sm btn-outline-secondary btn-notes" data-id="<?= (int) $s->id ?>">보기</button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="notesModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">상담 메모 및 이벤트</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="notesBody"></div>
    </div>
  </div>
</div>

<script>
$(function () {
    $('.btn-notes').on('click', function () {
        var id = $(this).data('id');
        window.location.href = '<?= base_url('console/session/') ?>' + id;
    });
});
</script>
