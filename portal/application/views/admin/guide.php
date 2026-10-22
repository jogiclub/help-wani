<?php
/**
 * 파일 위치: application/views/admin/guide.php
 * 역할: 가이드 관리 목록(wgrid), 외부 이미지 저장, 공개 페이지 테마와 구성(새 기능/동영상 모음), 매칭 시험, 분류/동의어 관리, guide.json 내려받기/올리기
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$category_rows = array();

foreach ($categories as $c)
{
    $category_rows[] = array('id' => (int) $c->id, 'name' => $c->name, 'sort_order' => (int) $c->sort_order);
}
?>
<div class="mb-4 flex flex-wrap items-center gap-2">
  <h1 class="mr-auto text-xl font-bold text-slate-800">가이드</h1>
  <a class="btn btn-sm btn-secondary" href="<?= html_escape($guide_url) ?>" target="_blank" rel="noopener">공개 페이지</a>
  <button type="button" class="btn btn-sm btn-secondary" id="btnCategories">분류</button>
  <button type="button" class="btn btn-sm btn-secondary" id="btnSynonyms">동의어</button>
  <button type="button" class="btn btn-sm btn-secondary" id="btnImport">guide.json 올리기</button>
  <a class="btn btn-sm btn-secondary" href="<?= base_url('admin/guide/export') ?>">guide.json 내려받기</a>
  <button type="button" class="btn btn-sm btn-secondary" id="btnLocalizeImages">외부 이미지를 본 서버로 저장</button>
  <a class="btn btn-sm btn-primary" href="<?= base_url('admin/guide/new') ?>">새 가이드</a>
</div>

<?php if ( ! empty($seeded)): ?>
<div class="alert alert-info mb-4">
  처음 오셨네요. 어떻게 쓰는지 볼 수 있도록 <b>샘플 가이드 <?= (int) $seeded ?>개</b>를 만들었습니다.
  모두 <b>임시</b> 상태라 고객에게는 아직 보이지 않습니다. 내용을 우리 조직에 맞게 고친 뒤 공개하거나, 필요 없으면 삭제하세요.
  샘플은 다시 만들어지지 않습니다.
</div>
<?php endif; ?>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
  <!-- 높이를 주지 않는다. 그리드는 한 쪽 개수(20/50/100...)만큼의 행 높이로 늘어난다. -->
  <div class="lg:col-span-2">
    <div class="mb-2 flex items-center gap-3 text-sm text-slate-600">
      <label class="flex items-center gap-2">
        <input type="checkbox" id="filterFeature"> 새 기능만
      </label>
    </div>
    <div class="card overflow-hidden">
      <div id="guideGrid"></div>
    </div>
  </div>

  <div class="space-y-4">
    <div class="card">
      <div class="card-header flex items-center justify-between">
        <span>공개 페이지 테마</span>
        <button type="button" class="btn btn-sm btn-primary" id="btnSaveTheme">적용</button>
      </div>
      <div class="card-body space-y-2" id="themeList">
        <?php $no = 0; foreach ($themes as $key => $t): $no++; ?>
        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
          <input type="radio" name="guideTheme" value="<?= html_escape($key) ?>" class="mt-1" <?= $key === $theme ? 'checked' : '' ?>>
          <span class="theme-thumb theme-thumb-<?= html_escape($key) ?>" aria-hidden="true"><i></i><i></i><i></i></span>
          <span class="min-w-0 flex-1">
            <span class="block text-sm font-semibold text-slate-800"><?= $no ?>. <?= html_escape($t['name']) ?>
              <?php if ($key === $theme): ?><span class="badge badge-green ml-1" data-theme-current>사용 중</span><?php endif; ?></span>
            <span class="mt-0.5 block text-xs text-slate-500"><?= html_escape($t['desc']) ?></span>
            <a class="mt-1 inline-block text-xs text-brand-600 hover:underline" target="_blank" rel="noopener"
               href="<?= html_escape($preview_base.'?theme='.$key) ?>">미리보기</a>
          </span>
        </label>
        <?php endforeach; ?>
        <div class="form-help">미리보기는 이 조직 관리자로 로그인한 브라우저에서만 보입니다. 강조색은 상담 위젯 색을 따릅니다.</div>
      </div>
    </div>

    <div class="card" id="pageOptions">
      <div class="card-header flex items-center justify-between">
        <span>공개 페이지 구성</span>
        <button type="button" class="btn btn-sm btn-primary" id="btnSavePageOptions">저장</button>
      </div>
      <div class="card-body space-y-4 text-sm">
        <div class="space-y-2">
          <label class="flex items-center gap-2 font-semibold text-slate-800">
            <input type="checkbox" id="optFeatureEnabled" <?= $page_options['feature_enabled'] ? 'checked' : '' ?>> 새 기능 모음 보이기
          </label>
          <div class="space-y-2 pl-6" data-option-group="feature">
            <label class="flex items-center gap-2">
              <span class="w-24 shrink-0 text-slate-500">NEW 배지 기간</span>
              <select class="form-input" id="optFeatureNewDays">
                <?php foreach ($new_days as $days): ?>
                <option value="<?= (int) $days ?>" <?= $days === $page_options['feature_new_days'] ? 'selected' : '' ?>><?= $days === 0 ? '끔' : (int) $days.'일' ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="optFeatureBanner" <?= $page_options['feature_banner'] ? 'checked' : '' ?>> 가이드 첫 화면에 최근 새 기능 3건 보이기
            </label>
          </div>
        </div>

        <div class="space-y-2">
          <label class="flex items-center gap-2 font-semibold text-slate-800">
            <input type="checkbox" id="optVideoEnabled" <?= $page_options['video_enabled'] ? 'checked' : '' ?>> 동영상 모음 보이기
          </label>
          <div class="space-y-2 pl-6" data-option-group="video">
            <label class="flex items-center gap-2">
              <span class="w-24 shrink-0 text-slate-500">정렬</span>
              <select class="form-input" id="optVideoSort">
                <?php foreach ($video_sorts as $key => $label): ?>
                <option value="<?= html_escape($key) ?>" <?= $key === $page_options['video_sort'] ? 'selected' : '' ?>><?= html_escape($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="optVideoFilter" <?= $page_options['video_filter'] ? 'checked' : '' ?>> 분류로 거르기
            </label>
          </div>
        </div>

        <div class="border-t border-slate-100 pt-3 text-xs text-slate-500">
          새 기능 표시된 공개 가이드 <b id="featureCount"><?= (int) $feature_count ?></b>건 ·
          공개 가이드의 동영상 <b id="videoCount"><?= (int) $video_count ?></b>개
        </div>
        <div class="flex flex-wrap gap-3 text-xs">
          <a class="text-brand-600 hover:underline" target="_blank" rel="noopener" href="<?= html_escape($preview_base.'/features') ?>">미리보기: 새 기능</a>
          <a class="text-brand-600 hover:underline" target="_blank" rel="noopener" href="<?= html_escape($preview_base.'/videos') ?>">미리보기: 동영상</a>
        </div>
        <div class="form-help">새 기능은 가이드 편집에서 글마다 "새 기능으로 표시" 합니다. 동영상은 공개한 가이드 본문의 유튜브 동영상을 모읍니다.
          꺼 둔 모음도 이 조직 관리자로 로그인한 브라우저에서는 미리 볼 수 있습니다.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">매칭 시험</div>
      <div class="card-body space-y-3">
        <form id="matchForm" class="flex gap-2">
          <input class="form-input flex-1" id="matchQuery" placeholder="예) 프린터 연결이 안 돼요">
          <button class="btn btn-primary shrink-0" type="submit">시험</button>
        </form>
        <div class="form-help">고객 문장을 넣으면 상담 중에 어떤 가이드가 몇 점으로 추천되는지 보여줍니다.
          공개한 가이드만 추천됩니다.</div>
        <div id="matchTerms" class="hidden text-xs text-slate-500"></div>
        <ul id="matchResults" class="space-y-2 text-sm"></ul>
      </div>
    </div>

    <div class="card">
      <div class="card-header">추천 방식</div>
      <div class="card-body space-y-2 text-sm text-slate-600">
        <p>AI 를 쓰지 않고 낱말이 겹치는 정도로 점수를 매깁니다.</p>
        <ul class="list-disc space-y-1 pl-5 text-xs">
          <li><b>키워드</b>가 가장 중요합니다(10점). 고객이 실제로 쓰는 말을 적어 주세요.</li>
          <li>제목 6점, 부제목 4점, 본문 1점. 붙여 쓴 말은 2글자 조각으로도 찾습니다.</li>
          <li>뜻이 같은 말은 <b>동의어</b>로 묶으면 함께 찾습니다.</li>
          <li>상담원 콘솔은 4점, 위젯 자동 추천은 10점 이상만 보여줍니다.</li>
        </ul>
        <button type="button" class="btn btn-sm btn-secondary" id="btnReindex">색인 다시 만들기</button>
      </div>
    </div>
  </div>
</div>

<!-- 분류 -->
<div id="categoryModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold text-slate-800">분류</h2>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="categoryModal">×</button>
    </div>
    <div class="space-y-3 p-5">
      <div id="categoryList" class="space-y-2"></div>
      <div class="flex gap-2 border-t border-slate-100 pt-3">
        <input class="form-input flex-1" id="newCategoryName" placeholder="새 분류 이름" maxlength="60">
        <input class="form-input w-20" id="newCategorySort" type="number" value="0" title="정렬 순서">
        <button type="button" class="btn btn-primary shrink-0" id="btnAddCategory">추가</button>
      </div>
      <div class="form-help">정렬 순서가 작은 분류부터 공개 페이지에 보입니다. 분류를 지워도 가이드는 남습니다.</div>
    </div>
  </div>
</div>

<!-- 동의어 -->
<div id="synonymModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold text-slate-800">동의어</h2>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="synonymModal">×</button>
    </div>
    <div class="space-y-2 p-5">
      <textarea class="form-input font-mono text-sm" id="synonymText" rows="8"
                placeholder="로그인, 접속, 사인인&#10;프린터, 인쇄기, 복합기"><?= html_escape($synonyms) ?></textarea>
      <div class="form-help">한 줄에 한 묶음, 쉼표로 나눕니다. 고객이 묶음 안의 한 낱말을 쓰면 나머지 낱말로도 찾습니다.</div>
    </div>
    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
      <button type="button" class="btn btn-secondary" data-modal-close="synonymModal">취소</button>
      <button type="button" class="btn btn-primary" id="btnSaveSynonyms">저장</button>
    </div>
  </div>
</div>

<!-- guide.json 올리기 -->
<div id="importModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
      <h2 class="font-semibold text-slate-800">guide.json 올리기</h2>
      <button type="button" class="text-slate-400 hover:text-slate-600" data-modal-close="importModal">×</button>
    </div>
    <div class="space-y-4 p-5">
      <div class="space-y-2 text-sm">
        <label class="flex items-start gap-2">
          <input type="radio" name="importMode" value="merge" class="mt-1" checked>
          <span><b>병합</b> — 주소(slug)가 같은 글은 덮어쓰고, 나머지는 새로 만듭니다.</span>
        </label>
        <label class="flex items-start gap-2">
          <input type="radio" name="importMode" value="replace" class="mt-1">
          <span><b class="text-red-600">전체 교체</b> — 지금 있는 가이드, 분류, 동의어를 모두 지우고 파일 내용으로 바꿉니다.</span>
        </label>
      </div>
      <!-- 끌어다 놓거나 눌러서 고른다(Dropzone.js, assets/js/image-drop.js). 고르면 바로 올라간다. -->
      <div class="image-drop" id="importDrop">
        <span class="image-drop-hint">guide.json 을 여기로 끌어다 놓거나 눌러서 선택하세요 (2MB 이하)</span>
      </div>
      <div class="form-help">이미지는 이 조직에 올린 파일과 외부 https 주소가 이어집니다. 외부 이미지는 그 사이트에 있는 파일을 그대로 보여주므로
        그 사이트에서 지우면 함께 사라집니다. 다른 조직 폴더의 이미지와 http 주소는 빠집니다.</div>
      <div id="importResult" class="hidden max-h-48 overflow-y-auto rounded border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600"></div>
    </div>
  </div>
</div>

<script>
var GUIDE_ROWS = <?= json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var GUIDE_CATEGORIES = <?= json_encode($category_rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var GUIDE_URLS = {
    edit:           '<?= base_url('admin/guide/edit/') ?>',
    remove:         '<?= base_url('admin/guide/api/delete') ?>',
    status:         '<?= base_url('admin/guide/api/status') ?>',
    importFile:     '<?= base_url('admin/guide/api/import') ?>',
    categorySave:   '<?= base_url('admin/guide/api/category_save') ?>',
    categoryDelete: '<?= base_url('admin/guide/api/category_delete') ?>',
    synonymsSave:   '<?= base_url('admin/guide/api/synonyms_save') ?>',
    reindex:        '<?= base_url('admin/guide/api/reindex') ?>',
    matchTest:      '<?= base_url('admin/guide/api/match_test') ?>',
    themeSave:      '<?= base_url('admin/guide/api/theme_save') ?>',
    pageOptionsSave:'<?= base_url('admin/guide/api/page_options_save') ?>',
    localizeImages: '<?= base_url('admin/guide/api/localize_images') ?>'
};
</script>
<script src="<?= asset_url('assets/js/admin/guide.js') ?>"></script>
