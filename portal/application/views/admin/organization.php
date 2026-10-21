<?php
/**
 * 파일 위치: application/views/admin/organization.php
 * 역할: 조직 기본 정보 수정 (고객 접속 페이지에 보이는 로고와 소개 포함)
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<h1 class="mb-4 text-xl font-bold text-slate-800">조직 정보</h1>

<div class="card max-w-2xl">
  <div class="card-body space-y-4">
    <div>
      <label class="form-label" for="customerUrl">고객 접속 주소</label>
      <div class="flex gap-2">
        <input class="form-input bg-slate-50" id="customerUrl" value="<?= html_escape(customer_url($org->org_code)) ?>" readonly>
        <button type="button" class="btn btn-secondary shrink-0" id="btnCopyUrl">복사</button>
        <a class="btn btn-secondary shrink-0" href="<?= html_escape(customer_url($org->org_code)) ?>" target="_blank" rel="noopener">열기</a>
      </div>
      <div class="form-help">이 주소에 아래 로고와 소개 문구가 함께 보입니다.</div>
    </div>

    <div>
      <label class="form-label">로고</label>
      <div class="flex flex-wrap items-stretch gap-3">
        <!-- 끌어다 놓거나 눌러서 고른다(Dropzone.js, assets/js/image-drop.js). 올리면 바로 저장된다. -->
        <div class="image-drop min-w-0 flex-1" id="logoDrop">
          <img id="logoPreview" class="max-h-14 max-w-40 <?= $org->logo_path ? '' : 'hidden' ?>"
               src="<?= $org->logo_path ? base_url($org->logo_path) : '' ?>" alt="로고">
          <span id="logoEmpty" class="text-xs text-slate-400 <?= $org->logo_path ? 'hidden' : '' ?>">로고 없음</span>
          <span class="image-drop-hint">이미지를 여기로 끌어다 놓거나 눌러서 선택하세요</span>
        </div>
        <button type="button" class="btn btn-secondary self-center <?= $org->logo_path ? '' : 'hidden' ?>" id="btnLogoDelete">삭제</button>
      </div>
      <div class="form-help">PNG, JPG, WEBP / 1MB 이하. 가로로 긴 이미지(높이 64px 이상)를 권장합니다. 원격지원 프로그램에도 표시됩니다.</div>
    </div>
    <div>
      <label class="form-label" for="orgName">조직명</label>
      <input class="form-input" id="orgName" value="<?= html_escape($org->name) ?>">
    </div>
    <div>
      <label class="form-label" for="orgPhone">대표 전화</label>
      <input class="form-input" id="orgPhone" value="<?= html_escape($org->phone) ?>">
      <div class="form-help">고객 접속 페이지에 문의처로 표시됩니다.</div>
    </div>
    <div>
      <label class="form-label" for="orgIntro">조직 소개</label>
      <textarea class="form-input" id="orgIntro" rows="4" maxlength="500"
                placeholder="예) 고객님의 PC 문제를 원격으로 빠르게 해결해 드립니다. 상담 시간: 평일 09:00~18:00"><?= html_escape((string) $org->intro) ?></textarea>
      <div class="form-help">고객 접속 페이지 상단에 보입니다. 최대 500자, 줄바꿈 유지. <span id="introCount"></span></div>
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
var ORG_URLS = {
    logoUpload: '<?= base_url('admin/api/logo_upload') ?>',
    logoDelete: '<?= base_url('admin/api/logo_delete') ?>'
};

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

    function updateIntroCount() {
        $('#introCount').text('(' + $('#orgIntro').val().length + '/500)');
    }
    $('#orgIntro').on('input', updateIntroCount);
    updateIntroCount();

    $('#btnCopyUrl').on('click', function () {
        var url = $('#customerUrl').val();
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url).then(function () { showToast('주소를 복사했습니다.', 'success'); });
        } else {
            $('#customerUrl').trigger('select');
            document.execCommand('copy');
            showToast('주소를 복사했습니다.', 'success');
        }
    });

    function showLogo(url) {
        $('#logoPreview').attr('src', url || '').toggleClass('hidden', !url);
        $('#logoEmpty').toggleClass('hidden', !!url);
        $('#btnLogoDelete').toggleClass('hidden', !url);
    }

    RHImageDrop.create('#logoDrop', {
        url: ORG_URLS.logoUpload,
        paramName: 'logo',
        onSuccess: function (data, message) {
            showLogo(data.logo_url);
            showToast(message, 'success');
        }
    });

    $('#btnLogoDelete').on('click', function () {
        showConfirmModal('로고 삭제', '로고를 삭제하면 고객 접속 페이지에 조직 이름만 보입니다.', function () {
            apiPost(ORG_URLS.logoDelete, {}, function (data, message) {
                showLogo('');
                showToast(message, 'success');
            });
        });
    });

    $('#btnSaveOrg').on('click', function () {
        apiPost('<?= base_url('admin/api/org_save') ?>', {
            name: $('#orgName').val(),
            phone: $('#orgPhone').val(),
            intro: $('#orgIntro').val(),
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
