<?php
/**
 * 파일 위치: application/views/admin/organization.php
 * 역할: 조직 기본 정보 수정
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<h1 class="mb-4 text-xl font-bold text-slate-800">조직 정보</h1>

<div class="card max-w-2xl">
  <div class="card-body space-y-4">
    <div>
      <label class="form-label">고객 접속 주소</label>
      <input class="form-input bg-slate-50" value="<?= base_url($org->org_code) ?>" readonly>
    </div>
    <div>
      <label class="form-label" for="orgName">조직명</label>
      <input class="form-input" id="orgName" value="<?= html_escape($org->name) ?>">
    </div>
    <div>
      <label class="form-label" for="orgPhone">대표 전화</label>
      <input class="form-input" id="orgPhone" value="<?= html_escape($org->phone) ?>">
    </div>
    <div>
      <label class="form-label">사업자등록번호</label>
      <input class="form-input bg-slate-50" value="<?= html_escape($org->biz_no) ?>" readonly>
    </div>

    <div class="grid grid-cols-1 gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2">
      <div>
        <label class="form-label" for="orgLocale">화면 언어</label>
        <select class="form-input" id="orgLocale">
          <?php foreach ($locales as $code => $info): ?>
            <option value="<?= $code ?>" <?= $org->locale === $code ? 'selected' : '' ?>>
              <?= html_escape($info['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-help">고객 접속 페이지와 상담원 화면에 적용됩니다.</div>
      </div>

      <div>
        <label class="form-label" for="orgTimezone">시간대</label>
        <select class="form-input" id="orgTimezone">
          <?php foreach ($timezones as $tz => $label): ?>
            <option value="<?= $tz ?>" <?= $org->timezone === $tz ? 'selected' : '' ?>>
              <?= html_escape($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="form-help">
          현재 시각: <span id="tzPreview" class="font-medium text-slate-700"></span>
        </div>
      </div>
    </div>

    <button class="btn btn-primary" id="btnSaveOrg">저장</button>
  </div>
</div>

<script>
$(function () {
    /** 선택한 시간대의 현재 시각 미리보기 */
    function updateTimezonePreview() {
        try {
            $('#tzPreview').text(new Intl.DateTimeFormat(RHI18n.intlLocale, {
                timeZone: $('#orgTimezone').val(), dateStyle: 'medium', timeStyle: 'short'
            }).format(new Date()));
        } catch (e) {
            $('#tzPreview').text('-');
        }
    }

    $('#orgTimezone').on('change', updateTimezonePreview);
    updateTimezonePreview();

    $('#btnSaveOrg').on('click', function () {
        apiPost('<?= base_url('admin/api/org_save') ?>', {
            name: $('#orgName').val(),
            phone: $('#orgPhone').val(),
            locale: $('#orgLocale').val(),
            timezone: $('#orgTimezone').val()
        }, function (data, message) {
            showToast(message, 'success');
            // 언어나 시간대가 바뀌면 화면 전체에 반영되도록 다시 불러온다.
            setTimeout(function () { window.location.reload(); }, 800);
        });
    });
});
</script>
