<?php
/**
 * 파일 위치: application/views/operator/organizations.php
 * 역할: 플랫폼 운영자의 조직 가입 승인 목록 (AG Grid)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$rows = array();

foreach ($orgs as $org)
{
    $rows[] = array(
        'id'            => (int) $org->id,
        'org_code'      => $org->org_code,
        'name'          => $org->name,
        'status'        => $org->status,
        'biz_no'        => $org->biz_no,
        'phone'         => $org->phone,
        'plan'          => $org->plan,
        'country'       => $org->country,
        'locale'        => $org->locale,
        'timezone'      => $org->timezone,
        'owner_name'    => $org->owner ? $org->owner->name : '',
        'owner_email'   => $org->owner ? $org->owner->email : '',
        'agent_count'   => (int) $org->agent_count,
        'session_count' => (int) $org->session_count,
        'created_at'    => $org->created_at,
        'approved_at'   => $org->approved_at,
        'status_reason' => $org->status_reason,
    );
}
?>
<div class="mb-4 flex flex-wrap items-center justify-between gap-2">
  <h1 class="text-xl font-bold text-slate-800">조직 가입 관리</h1>
  <?php if ($counts['pending'] > 0): ?>
    <span class="badge badge-amber">승인 대기 <?= (int) $counts['pending'] ?>건</span>
  <?php endif; ?>
</div>

<div class="mb-4 flex flex-wrap gap-2">
  <a class="btn btn-sm <?= $filter === NULL ? 'btn-primary' : 'btn-secondary' ?>"
     href="<?= base_url('operator') ?>">전체</a>
  <?php foreach (array('pending' => '승인 대기', 'active' => '사용 중',
                       'rejected' => '반려', 'suspended' => '이용 중지') as $key => $label): ?>
    <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-secondary' ?>"
       href="<?= base_url('operator?status='.$key) ?>">
      <?= $label ?> (<?= (int) $counts[$key] ?>)
    </a>
  <?php endforeach; ?>
</div>

<div class="card overflow-hidden">
  <div id="orgGrid" style="height: calc(100vh - 300px); min-height: 420px;"></div>
</div>

<!-- 조직 정보 수정 모달 (그리드 행 클릭 시 열림) -->
<div id="orgModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <div class="flex items-center gap-2">
        <h2 class="font-semibold text-slate-800">조직 정보 수정</h2>
        <span class="badge badge-gray" id="orgModalStatus"></span>
      </div>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="orgModal">×</button>
    </div>

    <div class="space-y-4 p-5">
      <input type="hidden" id="orgModalId">

      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <label class="form-label" for="orgModalName">조직명</label>
          <input class="form-input" id="orgModalName">
        </div>
        <div>
          <label class="form-label" for="orgModalCode">조직 주소</label>
          <div class="flex">
            <span class="flex items-center rounded-l-md border border-r-0 border-slate-300 bg-slate-50 px-2 text-xs text-slate-500">
              <?= base_url() ?>
            </span>
            <input class="form-input rounded-l-none" id="orgModalCode">
          </div>
          <div class="form-help text-amber-600">
            바꾸면 기존 안내 링크와 내려받은 실행 파일 이름이 더 이상 맞지 않습니다.
          </div>
        </div>
        <div>
          <label class="form-label" for="orgModalBizNo">사업자등록번호</label>
          <input class="form-input" id="orgModalBizNo">
        </div>
        <div>
          <label class="form-label" for="orgModalPhone">대표 전화</label>
          <input class="form-input" id="orgModalPhone">
        </div>
        <div>
          <label class="form-label" for="orgModalPlan">요금제</label>
          <input class="form-input" id="orgModalPlan" placeholder="basic">
        </div>
        <div>
          <label class="form-label" for="orgModalCountry">국가</label>
          <select class="form-input" id="orgModalCountry">
            <?php foreach ($countries as $code => $info): ?>
              <option value="<?= $code ?>"
                      data-locale="<?= $info['locale'] ?>"
                      data-timezone="<?= $info['timezone'] ?>"><?= html_escape($info['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="orgModalLocale">화면 언어</label>
          <select class="form-input" id="orgModalLocale">
            <?php foreach ($locales as $code => $info): ?>
              <option value="<?= $code ?>"><?= html_escape($info['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="orgModalTimezone">시간대</label>
          <select class="form-input" id="orgModalTimezone">
            <?php foreach ($timezones as $tz => $label): ?>
              <option value="<?= $tz ?>"><?= html_escape($label) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-help">현재 시각: <span id="orgModalTzPreview" class="font-medium text-slate-700"></span></div>
        </div>
      </div>

      <div class="rounded-md bg-slate-50 p-4">
        <div class="mb-2 text-sm font-semibold text-slate-700">조회 정보</div>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm text-slate-600 sm:grid-cols-3">
          <div class="flex gap-2"><dt class="text-slate-400">담당자</dt><dd id="orgModalOwner">-</dd></div>
          <div class="flex gap-2"><dt class="text-slate-400">상담원</dt><dd id="orgModalAgents">-</dd></div>
          <div class="flex gap-2"><dt class="text-slate-400">세션</dt><dd id="orgModalSessions">-</dd></div>
          <div class="flex gap-2"><dt class="text-slate-400">신청일</dt><dd id="orgModalCreated">-</dd></div>
          <div class="flex gap-2"><dt class="text-slate-400">승인일</dt><dd id="orgModalApproved">-</dd></div>
          <div class="flex gap-2 sm:col-span-3"><dt class="shrink-0 text-slate-400">사유</dt><dd id="orgModalReason">-</dd></div>
        </dl>
      </div>
    </div>

    <div class="flex items-center justify-between gap-2 border-t border-slate-200 px-5 py-3">
      <a class="btn btn-ghost btn-sm" id="orgModalVisit" target="_blank" rel="noopener">고객 페이지 열기</a>
      <div class="flex gap-2">
        <button class="btn btn-secondary" data-modal-close="orgModal">취소</button>
        <button class="btn btn-primary" id="orgModalSave">저장</button>
      </div>
    </div>
  </div>
</div>

<!-- 사유 입력 모달 -->
<div id="reasonModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold text-slate-800" id="reasonModalTitle"></h2>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="reasonModal">×</button>
    </div>
    <div class="p-5">
      <p class="mb-2 text-sm text-slate-500" id="reasonModalDesc"></p>
      <textarea class="form-input" id="reasonText" rows="3" placeholder="사유를 입력하세요"></textarea>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
      <button class="btn btn-secondary" data-modal-close="reasonModal">취소</button>
      <button class="btn btn-primary" id="reasonModalOk">확인</button>
    </div>
  </div>
</div>

<script>
var ORG_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
var ORG_URLS = {
    save:    '<?= base_url('operator/api/org_save') ?>',
    approve: '<?= base_url('operator/api/org_approve') ?>',
    reject:  '<?= base_url('operator/api/org_reject') ?>',
    suspend: '<?= base_url('operator/api/org_suspend') ?>',
    restore: '<?= base_url('operator/api/org_restore') ?>'
};
var ORG_BASE_URL = '<?= base_url() ?>';
</script>
<script src="<?= base_url('assets/js/operator/organizations.js') ?>"></script>
