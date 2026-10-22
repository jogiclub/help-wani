<?php
/**
 * 파일 위치: application/views/admin/guide_edit.php
 * 역할: 가이드 편집 (제목, 부제목, 주소, 키워드, 분류, 상태, 새 기능 표시, 본문 블록: 글/이미지/유튜브 동영상)
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$a = $article;
$initial = array(
    'id'          => $a ? (int) $a->id : 0,
    'status'      => $a ? $a->status : 'draft',
    'blocks'      => $a ? $a->blocks : array(array('type' => 'text', 'text' => '')),
);
?>
<div class="mb-4 flex flex-wrap items-center gap-2">
  <a class="btn btn-sm btn-ghost" href="<?= base_url('admin/guide') ?>">← 목록</a>
  <h1 class="mr-auto text-xl font-bold text-slate-800"><?= $a ? '가이드 편집' : '새 가이드' ?></h1>
  <?php if ($a): ?>
  <a class="btn btn-sm btn-secondary" href="<?= base_url('admin/guide/preview/'.(int) $a->id) ?>" target="_blank" rel="noopener">미리보기</a>
  <?php endif; ?>
  <button type="button" class="btn btn-sm btn-primary" id="btnSaveGuide">저장</button>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

  <div class="space-y-4 lg:col-span-2">
    <div class="card">
      <div class="card-body space-y-4">
        <div>
          <label class="form-label" for="guideTitle">제목</label>
          <input class="form-input" id="guideTitle" maxlength="100" value="<?= $a ? html_escape($a->title) : '' ?>"
                 placeholder="예) 프린터가 연결되지 않을 때">
        </div>
        <div>
          <label class="form-label" for="guideSubtitle">부제목</label>
          <input class="form-input" id="guideSubtitle" maxlength="200" value="<?= $a ? html_escape($a->subtitle) : '' ?>"
                 placeholder="목록과 검색 결과에 함께 보입니다">
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">본문</div>
      <div class="card-body space-y-3">
        <div id="blockList" class="space-y-3"></div>
        <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-3">
          <button type="button" class="btn btn-sm btn-secondary" data-add-block="text">+ 글</button>
          <button type="button" class="btn btn-sm btn-secondary" data-add-block="image">+ 이미지</button>
          <button type="button" class="btn btn-sm btn-secondary" data-add-block="video">+ 유튜브 동영상</button>
        </div>
        <div class="form-help">글은 HTML 을 쓸 수 없고 그대로 보입니다. https:// 로 시작하는 주소는 링크가 됩니다.
          이미지는 PNG, JPG, WEBP 2MB 이하. 블록은 50개까지 넣을 수 있습니다.</div>
      </div>
    </div>
  </div>

  <div class="space-y-4">
    <div class="card">
      <div class="card-body space-y-4">
        <div>
          <span class="form-label">상태</span>
          <div class="flex gap-4 text-sm">
            <label class="flex items-center gap-2">
              <input type="radio" name="guideStatus" value="draft" <?= $initial['status'] === 'draft' ? 'checked' : '' ?>> 임시
            </label>
            <label class="flex items-center gap-2">
              <input type="radio" name="guideStatus" value="published" <?= $initial['status'] === 'published' ? 'checked' : '' ?>> 공개
            </label>
          </div>
          <div class="form-help">공개한 가이드만 고객 페이지에 보이고 상담 중에 추천됩니다.</div>
        </div>

        <div>
          <label class="form-label" for="guideCategory">분류</label>
          <select class="form-input" id="guideCategory">
            <option value="0">분류 없음</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c->id ?>" <?= ($a && (int) $a->category_id === (int) $c->id) ? 'selected' : '' ?>><?= html_escape($c->name) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="form-label" for="guideSort">정렬 순서</label>
          <input class="form-input" id="guideSort" type="number" value="<?= $a ? (int) $a->sort_order : 0 ?>">
          <div class="form-help">작은 숫자가 위에 보입니다.</div>
        </div>

        <div>
          <label class="form-label" for="guideSlug">주소</label>
          <div class="text-xs break-all text-slate-400"><?= html_escape($guide_base) ?></div>
          <input class="form-input font-mono text-sm" id="guideSlug" maxlength="60" value="<?= $a ? html_escape($a->slug) : '' ?>"
                 placeholder="printer-connect">
          <div class="form-help">영문 소문자, 숫자, 하이픈(-), 밑줄(_). 비워 두면 번호로 접속합니다.
            guide.json 을 병합으로 올릴 때 같은 주소의 글을 덮어씁니다.</div>
          <?php if ($public_url): ?>
          <a class="mt-1 block truncate text-xs text-brand-600 hover:underline" id="guidePublicLink"
             href="<?= html_escape($public_url) ?>" target="_blank" rel="noopener"><?= html_escape($public_url) ?></a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-body space-y-3">
        <label class="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <input type="checkbox" id="guideIsFeature" <?= ($a && (int) $a->is_feature === 1) ? 'checked' : '' ?>> 새 기능으로 표시
        </label>
        <div class="space-y-3" id="featureFields">
          <div>
            <label class="form-label" for="guideFeatureDate">출시일</label>
            <input class="form-input" id="guideFeatureDate" type="date" value="<?= html_escape($feature_date) ?>">
            <div class="form-help">조직 시간대(<?= html_escape($timezone) ?>) 기준 날짜입니다.
              비우면 처음 공개한 날<?= $published_on !== '' ? '('.html_escape($published_on).')' : '' ?>을 씁니다.</div>
          </div>
          <div>
            <label class="form-label" for="guideFeatureLabel">버전</label>
            <input class="form-input" id="guideFeatureLabel" maxlength="30" value="<?= $a ? html_escape($a->feature_label) : '' ?>"
                   placeholder="예) v2.3 (선택)">
          </div>
        </div>
        <div class="form-help">공개 가이드의 <b>새 기능</b> 모음에 출시일 최신순으로 보입니다.
          모음은 가이드 관리의 "공개 페이지 구성" 에서 켭니다.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <label class="form-label" for="guideKeywords">키워드</label>
        <textarea class="form-input" id="guideKeywords" rows="3"
                  placeholder="프린터, 인쇄, 출력, printer"><?= $a ? html_escape($a->keywords) : '' ?></textarea>
        <div class="form-help">쉼표로 나눠 20개까지. <b>고객이 실제로 쓰는 말</b>을 적을수록 상담 중에 정확히 추천됩니다.
          키워드는 고객 화면에 보이지 않습니다.</div>
      </div>
    </div>
  </div>
</div>

<script>
var GUIDE_ARTICLE = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
var GUIDE_URLS = {
    save:      '<?= base_url('admin/guide/api/save') ?>',
    image:     '<?= base_url('admin/guide/api/image') ?>',
    assetBase: '<?= base_url() ?>'
};
</script>
<script src="<?= asset_url('assets/js/admin/guide-edit.js') ?>"></script>
