<?php
/**
 * 파일 위치: application/views/operator/organizations.php
 * 역할: 플랫폼 운영자의 조직 가입 승인 화면
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$status_badge = array(
    'pending'   => array('badge-amber', '승인 대기'),
    'active'    => array('badge-green', '사용 중'),
    'rejected'  => array('badge-red',   '반려'),
    'suspended' => array('badge-dark',  '이용 중지'),
);
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
  <?php foreach ($status_badge as $key => $meta): ?>
    <a class="btn btn-sm <?= $filter === $key ? 'btn-primary' : 'btn-secondary' ?>"
       href="<?= base_url('operator?status='.$key) ?>">
      <?= $meta[1] ?> (<?= (int) $counts[$key] ?>)
    </a>
  <?php endforeach; ?>
</div>

<?php if (empty($orgs)): ?>
  <div class="card"><div class="card-body text-center text-slate-400">해당하는 조직이 없습니다.</div></div>
<?php else: ?>
<div class="space-y-3">
  <?php foreach ($orgs as $org):
      $badge = isset($status_badge[$org->status]) ? $status_badge[$org->status] : array('badge-gray', $org->status); ?>
  <div class="card">
    <div class="card-body">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-base font-semibold text-slate-800"><?= html_escape($org->name) ?></span>
            <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?></span>
          </div>

          <dl class="mt-2 grid grid-cols-1 gap-x-6 gap-y-1 text-sm text-slate-600 sm:grid-cols-2">
            <div class="flex gap-2">
              <dt class="w-20 shrink-0 text-slate-400">주소</dt>
              <dd class="font-mono"><?= base_url($org->org_code) ?></dd>
            </div>
            <div class="flex gap-2">
              <dt class="w-20 shrink-0 text-slate-400">사업자번호</dt>
              <dd><?= html_escape($org->biz_no ?: '-') ?></dd>
            </div>
            <div class="flex gap-2">
              <dt class="w-20 shrink-0 text-slate-400">담당자</dt>
              <dd>
                <?php if ($org->owner): ?>
                  <?= html_escape($org->owner->name) ?>
                  &lt;<?= html_escape($org->owner->email) ?>&gt;
                <?php else: ?>
                  -
                <?php endif; ?>
              </dd>
            </div>
            <div class="flex gap-2">
              <dt class="w-20 shrink-0 text-slate-400">연락처</dt>
              <dd><?= html_escape($org->phone ?: '-') ?></dd>
            </div>
            <div class="flex gap-2">
              <dt class="w-20 shrink-0 text-slate-400">신청일</dt>
              <dd><?= html_escape($org->created_at) ?></dd>
            </div>
            <div class="flex gap-2">
              <dt class="w-20 shrink-0 text-slate-400">현황</dt>
              <dd>상담원 <?= (int) $org->agent_count ?>명 · 세션 <?= (int) $org->session_count ?>건</dd>
            </div>
            <?php if ($org->approved_at): ?>
            <div class="flex gap-2">
              <dt class="w-20 shrink-0 text-slate-400">승인일</dt>
              <dd><?= html_escape($org->approved_at) ?></dd>
            </div>
            <?php endif; ?>
          </dl>

          <?php if ($org->status_reason): ?>
            <div class="mt-2 rounded bg-slate-50 px-3 py-2 text-sm text-slate-600">
              사유: <?= html_escape($org->status_reason) ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="flex shrink-0 flex-wrap gap-2">
          <?php if ($org->status === 'pending'): ?>
            <button class="btn btn-sm btn-primary btn-approve"
                    data-id="<?= (int) $org->id ?>" data-name="<?= html_escape($org->name) ?>">승인</button>
            <button class="btn btn-sm btn-secondary btn-reject"
                    data-id="<?= (int) $org->id ?>" data-name="<?= html_escape($org->name) ?>">반려</button>
          <?php elseif ($org->status === 'active' && $org->org_code !== 'system'): ?>
            <button class="btn btn-sm btn-secondary btn-suspend"
                    data-id="<?= (int) $org->id ?>" data-name="<?= html_escape($org->name) ?>">이용 중지</button>
          <?php elseif (in_array($org->status, array('rejected', 'suspended'), TRUE)): ?>
            <button class="btn btn-sm btn-primary btn-restore"
                    data-id="<?= (int) $org->id ?>" data-name="<?= html_escape($org->name) ?>">다시 활성화</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

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
$(function () {
    var URLS = {
        approve: '<?= base_url('operator/api/org_approve') ?>',
        reject:  '<?= base_url('operator/api/org_reject') ?>',
        suspend: '<?= base_url('operator/api/org_suspend') ?>',
        restore: '<?= base_url('operator/api/org_restore') ?>'
    };

    function post(url, data) {
        apiPost(url, data, function (res, message) {
            showToast(message, 'success');
            setTimeout(function () { window.location.reload(); }, 800);
        });
    }

    /**
     * 사유가 필요한 작업은 모달로 입력받는다.
     */
    function askReason(title, desc, onConfirm) {
        $('#reasonModalTitle').text(title);
        $('#reasonModalDesc').text(desc);
        $('#reasonText').val('');
        openModal('reasonModal');

        $('#reasonModalOk').off('click').on('click', function () {
            var reason = $('#reasonText').val().trim();
            if (!reason) {
                showToast('사유를 입력해 주세요.', 'warning');
                return;
            }
            closeModal('reasonModal');
            onConfirm(reason);
        });
    }

    $('.btn-approve').on('click', function () {
        var id = $(this).data('id');
        var name = $(this).data('name');
        showConfirmModal('조직 승인', name + ' 조직을 승인할까요? 승인하면 담당자가 바로 로그인할 수 있습니다.',
            function () { post(URLS.approve, { org_id: id }); });
    });

    $('.btn-reject').on('click', function () {
        var id = $(this).data('id');
        var name = $(this).data('name');
        askReason('가입 반려', name + ' 조직의 신청을 반려합니다. 사유는 담당자 로그인 화면에 표시됩니다.',
            function (reason) { post(URLS.reject, { org_id: id, reason: reason }); });
    });

    $('.btn-suspend').on('click', function () {
        var id = $(this).data('id');
        var name = $(this).data('name');
        askReason('이용 중지', name + ' 조직의 이용을 중지합니다. 소속 상담원이 모두 로그인할 수 없게 됩니다.',
            function (reason) { post(URLS.suspend, { org_id: id, reason: reason }); });
    });

    $('.btn-restore').on('click', function () {
        var id = $(this).data('id');
        var name = $(this).data('name');
        showConfirmModal('다시 활성화', name + ' 조직을 다시 활성화할까요?',
            function () { post(URLS.restore, { org_id: id }); });
    });
});
</script>
