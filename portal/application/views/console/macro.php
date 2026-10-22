<?php
/**
 * 파일 위치: application/views/console/macro.php
 * 역할: 상담 대화 매크로 관리 — 목록(wgrid, 범위 탭·검색·미사용 필터), 즐겨찾기 순서, 편집 드로어(_macro_edit), CSV 양식/내려받기/올리기
 *
 * 동작은 assets/js/console/macro.js. 설계는 docs/macro-plan.md.
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="mb-4 flex flex-wrap items-center gap-2">
  <h1 class="text-xl font-bold text-slate-800">매크로</h1>
  <a class="text-sm text-brand-600 hover:underline" href="<?= base_url('console/chat') ?>">상담 대화로 돌아가기</a>
  <div class="ml-auto flex flex-wrap gap-2">
    <a class="btn btn-sm btn-secondary" href="<?= base_url('console/macro/template') ?>">양식 받기</a>
    <button type="button" class="btn btn-sm btn-secondary" id="btnMacroImport">파일로 등록</button>
    <?php if ($macro_editor['isAdmin']): ?>
    <a class="btn btn-sm btn-secondary" href="<?= base_url('console/macro/export?scope=org') ?>">공용 내려받기</a>
    <?php endif; ?>
    <a class="btn btn-sm btn-secondary" href="<?= base_url('console/macro/export?scope=personal') ?>">개인 내려받기</a>
    <button type="button" class="btn btn-sm btn-primary" id="btnMacroNew">새 매크로</button>
  </div>
</div>

<div class="alert alert-info mb-4 text-sm">
  상담 대화 입력창에서 <b>/</b> 를 치면 매크로 목록이 열립니다. 고르면 입력창에 채워지며, 고친 뒤 보내면 됩니다.
  <b>공용</b>은 조직의 모든 상담원이 쓰고 조직 관리자만 고칩니다. <b>개인</b>은 나만 보고 씁니다.
</div>

<div class="card mb-4 flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 text-sm">
  <nav class="flex gap-1" id="macroTabs">
    <button type="button" class="btn btn-sm btn-primary" data-scope="all">전체</button>
    <button type="button" class="btn btn-sm btn-ghost" data-scope="org">공용</button>
    <button type="button" class="btn btn-sm btn-ghost" data-scope="personal">개인</button>
    <button type="button" class="btn btn-sm btn-ghost" data-scope="favorite">즐겨찾기</button>
  </nav>
  <label class="flex items-center gap-1.5 text-slate-600">
    <input type="checkbox" id="macroUnused" class="rounded border-slate-300">
    90일 이상 안 쓴 것
  </label>
  <input class="form-input w-full py-1 text-sm sm:ml-auto sm:w-64" id="macroSearch" placeholder="이름·내용·키워드 검색" maxlength="100">
  <button type="button" class="btn btn-sm border border-red-300 text-red-600" id="btnMacroDelete">선택 삭제</button>
</div>

<!-- 높이를 주지 않는다. 그리드는 한 쪽 개수(20/50/100...)만큼의 행 높이로 늘어난다. -->
<div class="card overflow-hidden" id="macroGridCard">
  <div id="macroGrid"></div>
</div>

<!-- 즐겨찾기 탭: 끌어서 순서를 바꾼다. 이 순서가 입력창 / 목록 맨 위 순서다. -->
<div class="card hidden" id="macroFavCard">
  <div class="card-header flex items-center justify-between">
    <span>즐겨찾기 <span class="text-xs font-normal text-slate-500" id="macroFavCount"></span></span>
    <span class="text-xs font-normal text-slate-500">끌어서 순서를 바꾸면 바로 저장됩니다.</span>
  </div>
  <ul class="divide-y divide-slate-100" id="macroFavList"></ul>
  <p class="hidden p-6 text-center text-sm text-slate-400" id="macroFavEmpty">
    즐겨찾기한 매크로가 없습니다. 목록의 ☆ 를 누르면 넣을 수 있습니다(<?= (int) $macro_editor['maxFavorites'] ?>개까지).
  </p>
</div>

<!-- CSV 파일로 등록 -->
<div id="macroImportModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold text-slate-800">파일로 등록</h2>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="macroImportModal">×</button>
    </div>
    <div class="space-y-4 p-5 text-sm">
      <div>
        <span class="form-label">어디에 넣을까요</span>
        <div class="flex gap-4">
          <?php if ($macro_editor['isAdmin']): ?>
          <label class="flex items-center gap-1.5"><input type="radio" name="macroImportScope" value="org"> 공용</label>
          <?php endif; ?>
          <label class="flex items-center gap-1.5"><input type="radio" name="macroImportScope" value="personal" checked> 개인</label>
        </div>
      </div>
      <div>
        <span class="form-label">같은 이름이 이미 있으면</span>
        <div class="space-y-1">
          <label class="flex items-center gap-1.5"><input type="radio" name="macroImportMode" value="skip" checked> 건너뛰기 — 기존 매크로를 그대로 둡니다</label>
          <label class="flex items-center gap-1.5"><input type="radio" name="macroImportMode" value="overwrite"> 덮어쓰기 — 내용과 키워드를 파일 값으로 바꿉니다(사용 횟수·즐겨찾기는 유지)</label>
        </div>
      </div>
      <!-- 끌어다 놓거나 눌러서 고른다(Dropzone.js, assets/js/image-drop.js). 고르면 바로 올라간다. -->
      <div class="image-drop" id="macroImportDrop">
        <span class="image-drop-hint">CSV 파일을 여기로 끌어다 놓거나 눌러서 선택하세요 (<?= (int) $import_mb ?>MB, <?= number_format($import_rows) ?>행 이하)</span>
      </div>
      <div class="form-help">
        첫 줄은 머리글 <code>name, content, keywords</code> 입니다. [양식 받기] 파일에 이어 쓰면 됩니다.
        한 칸 안의 줄바꿈은 엑셀에서 Alt+Enter 로 넣습니다.
        <b>한 행이라도 오류가 있으면 아무것도 저장하지 않고</b> 오류 행을 알려 드립니다.
      </div>
      <div id="macroImportResult" class="hidden max-h-48 overflow-y-auto rounded border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600"></div>
    </div>
  </div>
</div>

<?php $this->load->view('console/_macro_edit'); ?>

<script>
var MACRO_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var MACRO_IMPORT_MB = <?= (int) $import_mb ?>;
var MACRO_URLS = {
    list:          '<?= base_url('console/api/macro/list') ?>',
    remove:        '<?= base_url('console/api/macro/delete') ?>',
    copy:          '<?= base_url('console/api/macro/copy') ?>',
    favorite:      '<?= base_url('console/api/macro/favorite') ?>',
    favoriteOrder: '<?= base_url('console/api/macro/favorite_order') ?>',
    importFile:    '<?= base_url('console/api/macro/import') ?>'
};
</script>
<script src="<?= asset_url('assets/js/console/macro.js') ?>"></script>
