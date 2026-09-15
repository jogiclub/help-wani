<?php
/**
 * 파일 위치: application/views/operator/audit.php
 * 역할: 전체 조직의 관리 행위 감사 로그 (AG Grid)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$rows = array();

foreach ($logs as $log)
{
    $rows[] = array(
        'created_at'  => $log->created_at,
        'org_name'    => $log->org_name ?: '-',
        'org_code'    => $log->org_code ?: '',
        'agent_name'  => $log->agent_name ?: '-',
        'agent_email' => $log->agent_email ?: '',
        'action'      => $log->action,
        'detail'      => $log->detail,
        'ip'          => $log->ip,
    );
}
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-xl font-bold text-slate-800">감사 로그</h1>
  <span class="text-sm text-slate-500">최근 <?= count($rows) ?>건</span>
</div>

<div class="card overflow-hidden">
  <div id="auditGrid" style="height: calc(100vh - 230px); min-height: 420px;"></div>
</div>

<script>
var AUDIT_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= base_url('assets/js/operator/audit.js') ?>"></script>
