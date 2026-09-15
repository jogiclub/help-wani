<?php
/**
 * 파일 위치: application/views/console/history.php
 * 역할: 상담 이력 검색 및 조회 (AG Grid)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$rows = array();

foreach ($sessions as $s)
{
    $duration = ($s->started_at && $s->ended_at)
        ? strtotime($s->ended_at) - strtotime($s->started_at)
        : 0;

    $rows[] = array(
        'id'           => (int) $s->id,
        'created_at'   => $s->created_at,
        'code'         => $s->code,
        'agent_name'   => $s->agent_name,
        'pc_name'      => $s->customer_pc_name,
        'customer_ip'  => $s->customer_ip,
        'status'       => $s->status,
        'status_label' => status_label($s->status),
        'duration'     => $duration,
        'survey_score' => $s->survey_score ? (int) $s->survey_score : null,
    );
}
?>
<h1 class="mb-4 text-xl font-bold text-slate-800">상담 이력</h1>

<form class="mb-4 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5"
      method="get" action="<?= base_url('console/history') ?>">
  <input type="text" class="form-input" name="keyword" placeholder="코드 / PC이름 / 상담원"
         value="<?= html_escape($filters['keyword']) ?>">
  <select class="form-input" name="status">
    <option value="">전체 상태</option>
    <?php foreach (array('connected','ended','expired','canceled') as $st): ?>
    <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= status_label($st) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="date" class="form-input" name="date_from" value="<?= html_escape($filters['date_from']) ?>">
  <input type="date" class="form-input" name="date_to" value="<?= html_escape($filters['date_to']) ?>">
  <button class="btn btn-primary">검색</button>
</form>

<div class="card overflow-hidden">
  <div id="historyGrid" style="height: calc(100vh - 330px); min-height: 380px;"></div>
</div>

<script>
var HISTORY_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
var HISTORY_VIEWER_URL = '<?= base_url('console/session/') ?>';
</script>
<script src="<?= base_url('assets/js/console/history.js') ?>"></script>
