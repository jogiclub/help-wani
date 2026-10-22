<?php
/**
 * 파일 위치: application/views/admin/dashboard.php
 * 역할: 조직 관리 대시보드 (최근 30일 원격지원·상담 대화·매크로 사용 통계)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
$summary = $stats['summary'];
$survey  = $stats['survey'];
$max_cnt = 1;
foreach ($stats['daily'] as $d) { $max_cnt = max($max_cnt, (int) $d->cnt); }
?>
<!-- 상담원 관리 / 조직 정보 / 상담 위젯 메뉴는 상단 헤더(layouts/main.php)에 있다. -->
<h1 class="mb-4 text-xl font-bold text-slate-800">조직 관리</h1>

<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">최근 30일 원격지원</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= (int) $summary->total ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">정상 종료</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= (int) $summary->ended ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">평균 원격 시간</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= format_duration($summary->avg_seconds) ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">평균 만족도 (<?= (int) $survey->cnt ?>건)</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= $survey->avg_score ? number_format($survey->avg_score, 1) : '-' ?></div>
  </div></div>
</div>

<!-- 상담 대화(위젯). 첫 답변 시간은 운영 중에 들어온 문의만으로 계산한다(docs/chat-operation-plan.md F6). -->
<div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">최근 30일 상담 문의</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= (int) $chat_stats['total'] ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">상담원이 답한 문의</div>
    <div class="mt-1 text-2xl font-bold text-slate-800">
      <?= (int) $chat_stats['answered'] ?>
      <?php if ($chat_stats['total'] > 0): ?>
      <span class="text-sm font-medium text-slate-500">(<?= round($chat_stats['answered'] * 100 / $chat_stats['total']) ?>%)</span>
      <?php endif; ?>
    </div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">첫 답변 시간 (중앙값, 운영 중 문의)</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= $chat_stats['median_seconds'] === NULL ? '-' : format_duration($chat_stats['median_seconds']) ?></div>
  </div></div>
  <div class="card"><div class="card-body">
    <div class="text-xs text-slate-500">운영 시간 외 문의</div>
    <div class="mt-1 text-2xl font-bold text-slate-800"><?= (int) $chat_stats['offhours'] ?></div>
  </div></div>
</div>

<!-- 매크로 사용(docs/macro-plan.md M8). 상담 대화에서 매크로로 채워 보낸 글을 센다. -->
<?php $macro_max = 1; foreach ($macro_stats['top'] as $m) { $macro_max = max($macro_max, $m['uses']); } ?>
<div class="card mb-6">
  <div class="card-header flex flex-wrap items-center gap-2">
    <span>많이 쓴 공용 매크로 (최근 30일)</span>
    <span class="ml-auto text-xs font-normal text-slate-500">
      매크로로 보낸 답변 <?= (int) $macro_stats['total_uses'] ?>건 · 그중 개인 매크로 <?= (int) $macro_stats['personal_uses'] ?>건
    </span>
    <a class="text-xs font-normal text-brand-600 hover:underline" href="<?= base_url('console/macro') ?>">매크로 관리</a>
  </div>
  <div class="card-body">
    <?php if (empty($macro_stats['top'])): ?>
      <p class="text-sm text-slate-400">최근 30일 동안 쓴 공용 매크로가 없습니다.</p>
    <?php else: ?>
      <div class="space-y-2">
      <?php foreach ($macro_stats['top'] as $m): ?>
        <div class="flex items-center gap-3">
          <span class="w-40 shrink-0 truncate text-xs text-slate-600" title="<?= html_escape($m['name']) ?>"><?= html_escape($m['name']) ?></span>
          <div class="h-3 rounded bg-brand-500" style="width: <?= max(2, round($m['uses'] / $macro_max * 100)) ?>%"></div>
          <span class="text-sm text-slate-700"><?= (int) $m['uses'] ?></span>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header">일별 원격지원 건수</div>
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
