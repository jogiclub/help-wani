<?php
/**
 * 파일 위치: application/views/console/history.php
 * 역할: 상담 이력 검색 및 조회
 */
defined('BASEPATH') OR exit('No direct script access allowed');
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
  <div class="overflow-x-auto">
    <table class="table">
      <thead>
        <tr>
          <th>일시</th><th>코드</th><th>상담원</th><th>고객 PC</th>
          <th>상태</th><th>소요시간</th><th>만족도</th><th class="w-20">상세</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($sessions)): ?>
        <tr><td colspan="8" class="py-8 text-center text-slate-400">검색 결과가 없습니다.</td></tr>
      <?php else: foreach ($sessions as $s):
          $duration = ($s->started_at && $s->ended_at) ? strtotime($s->ended_at) - strtotime($s->started_at) : 0; ?>
        <tr>
          <td class="text-xs text-slate-500"><?= html_escape($s->created_at) ?></td>
          <td class="font-mono"><?= html_escape($s->code) ?></td>
          <td><?= html_escape($s->agent_name) ?></td>
          <td><?= html_escape($s->customer_pc_name ?: '-') ?></td>
          <td><span class="badge badge-gray"><?= status_label($s->status) ?></span></td>
          <td><?= format_duration($duration) ?></td>
          <td class="text-amber-500"><?= $s->survey_score ? str_repeat('★', (int) $s->survey_score) : '<span class="text-slate-300">-</span>' ?></td>
          <td>
            <a class="btn btn-sm btn-secondary" href="<?= base_url('console/session/'.$s->id) ?>">보기</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
