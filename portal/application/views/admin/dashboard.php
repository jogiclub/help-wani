<?php
/**
 * 파일 위치: application/views/admin/dashboard.php
 * 역할: 조직 관리 대시보드 (최근 30일 통계)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
$summary = $stats['summary'];
$survey  = $stats['survey'];
$max_cnt = 1;
foreach ($stats['daily'] as $d) { $max_cnt = max($max_cnt, (int) $d->cnt); }
?>
<div class="mb-4 flex items-center justify-between">
  <h1 class="text-xl font-bold text-slate-800">조직 관리</h1>
  <div class="flex gap-2">
    <a class="btn btn-sm btn-secondary" href="<?= base_url('admin/agents') ?>">상담원 관리</a>
    <a class="btn btn-sm btn-secondary" href="<?= base_url('admin/organization') ?>">조직 정보</a>
  </div>
</div>

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">최근 30일 상담 건수</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= (int) $summary->total ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">정상 종료</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= (int) $summary->ended ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">평균 상담 시간</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= format_duration($summary->avg_seconds) ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">평균 만족도 (<?= (int) $survey->cnt ?>건)</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= $survey->avg_score ? number_format($survey->avg_score, 1) : '-' ?></div>
  </div></div>
</div>

<div class="card">
  <div class="card-header">일별 상담 건수</div>
  <div class="card-body">
    <?php if (empty($stats['daily'])): ?>
      <p class="text-sm text-slate-400">데이터가 없습니다.</p>
    <?php else: ?>
      <div class="space-y-2">
      <?php foreach ($stats['daily'] as $d): ?>
        <div class="flex items-center gap-3">
          <span class="w-24 shrink-0 text-xs text-slate-500"><?= html_escape($d->day) ?></span>
          <div class="h-3 rounded bg-brand-500"
               style="width: <?= max(2, round((int) $d->cnt / $max_cnt * 100)) ?>%"></div>
          <span class="text-sm text-slate-700"><?= (int) $d->cnt ?></span>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
