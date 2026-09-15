<?php
/**
 * 파일 위치: application/views/admin/dashboard.php
 * 역할: 조직 관리 대시보드 (최근 30일 통계)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
$summary = $stats['summary'];
$survey  = $stats['survey'];
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">조직 관리</h1>
  <div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= base_url('admin/agents') ?>">상담원 관리</a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= base_url('admin/organization') ?>">조직 정보</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">최근 30일 상담 건수</div>
      <div class="h3 mb-0"><?= (int) $summary->total ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">정상 종료</div>
      <div class="h3 mb-0"><?= (int) $summary->ended ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">평균 상담 시간</div>
      <div class="h3 mb-0"><?= format_duration($summary->avg_seconds) ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">평균 만족도 (<?= (int) $survey->cnt ?>건)</div>
      <div class="h3 mb-0"><?= $survey->avg_score ? number_format($survey->avg_score, 1) : '-' ?></div>
    </div></div>
  </div>
</div>

<div class="card">
  <div class="card-header">일별 상담 건수</div>
  <div class="card-body">
    <table class="table table-sm mb-0">
      <thead><tr><th style="width:140px">날짜</th><th>건수</th></tr></thead>
      <tbody>
      <?php if (empty($stats['daily'])): ?>
        <tr><td colspan="2" class="text-muted">데이터가 없습니다.</td></tr>
      <?php else: foreach ($stats['daily'] as $d): ?>
        <tr>
          <td><?= html_escape($d->day) ?></td>
          <td>
            <div class="d-flex align-items-center">
              <div class="bg-primary me-2" style="height:12px;width:<?= min(400, (int) $d->cnt * 20) ?>px"></div>
              <span><?= (int) $d->cnt ?></span>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
