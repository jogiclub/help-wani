<?php
/**
 * 파일 위치: application/views/console/_widget_settings.php
 * 역할: 상담 대화 화면의 상담 위젯 설정 드로어 (설치 스니펫, 키 재발급, 사용 여부, 모양, 허용 도메인, 오프라인 알림, 가이드 자동 추천)
 *
 * 조직 관리자에게만 그린다(Console::chat 의 is_admin). 저장은 admin/api/* 가 권한을 다시 확인한다.
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="drawer-root" id="widgetSettingsDrawer" aria-hidden="true">
  <div class="drawer-backdrop" data-drawer-close="widgetSettingsDrawer"></div>

  <aside class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="widgetSettingsTitle" tabindex="-1">
    <div class="drawer-header">
      <h2 class="drawer-title" id="widgetSettingsTitle">상담 위젯 설정</h2>
      <button type="button" class="btn btn-sm btn-ghost ml-auto" data-drawer-close="widgetSettingsDrawer">닫기</button>
    </div>

    <div class="drawer-body">
      <section class="space-y-2">
        <label class="form-label" for="widgetSnippet">설치 스니펫</label>
        <textarea class="form-input bg-slate-50 font-mono text-xs" id="widgetSnippet" rows="3" readonly><?= html_escape($snippet) ?></textarea>
        <div class="form-help">고객사 웹사이트의 &lt;/body&gt; 바로 앞에 붙여 넣으면 상담 버블이 나타납니다.
          고객 접속 페이지(<?= html_escape(customer_url($org->org_code)) ?>)에는 따로 붙이지 않아도 나옵니다.</div>
        <div class="flex flex-wrap gap-2">
          <button class="btn btn-sm btn-primary" type="button" id="btnCopySnippet">스니펫 복사</button>
          <button class="btn btn-sm border border-red-300 text-red-600" type="button" id="btnRegenerate">키 재발급</button>
        </div>
      </section>

      <section class="space-y-4 border-t border-slate-100 pt-5">
        <label class="flex items-center gap-2">
          <input type="checkbox" id="widgetEnabled" class="rounded border-slate-300" <?= (int) $org->widget_enabled === 1 ? 'checked' : '' ?>>
          <span class="text-sm font-medium text-slate-700">위젯 사용</span>
        </label>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <label class="form-label" for="widgetColor">버블 색상</label>
            <input type="color" class="form-input h-10 p-1" id="widgetColor" value="<?= html_escape($org->widget_color) ?>">
          </div>
          <div>
            <label class="form-label" for="widgetPosition">버블 위치</label>
            <select class="form-input" id="widgetPosition">
              <option value="right" <?= $org->widget_position === 'right' ? 'selected' : '' ?>>오른쪽 아래</option>
              <option value="left" <?= $org->widget_position === 'left' ? 'selected' : '' ?>>왼쪽 아래</option>
            </select>
          </div>
        </div>

        <div>
          <label class="form-label" for="widgetDomains">허용 도메인</label>
          <textarea class="form-input font-mono text-xs" id="widgetDomains" rows="3"
                    placeholder="shop.example.com"><?= html_escape($org->widget_domains) ?></textarea>
          <div class="form-help">한 줄에 하나씩 적습니다. 하위 도메인도 함께 허용됩니다. 비워 두면 어느 사이트에서나 동작합니다.</div>
        </div>

        <div>
          <label class="flex items-center gap-2">
            <input type="checkbox" id="widgetGuideSuggest" class="rounded border-slate-300" <?= (int) $org->guide_autosuggest === 1 ? 'checked' : '' ?>>
            <span class="text-sm font-medium text-slate-700">가이드 자동 추천</span>
          </label>
          <div class="form-help">방문자 질문이 공개 가이드의 키워드와 잘 맞으면 상담원보다 먼저 가이드 카드를 보여줍니다.
            같은 가이드는 한 번만, 대화당 3번까지 보냅니다. 가이드는 <a class="text-brand-600 hover:underline" href="<?= base_url('admin/guide') ?>">가이드 관리</a>에서 만듭니다.</div>
        </div>

        <div>
          <label class="form-label" for="widgetOfflineEmail">오프라인 알림 받을 주소</label>
          <input type="email" class="form-input" id="widgetOfflineEmail"
                 value="<?= html_escape($org->widget_offline_email) ?>" placeholder="support@example.com">
          <div class="form-help">상담원이 모두 자리에 없을 때 문의가 오면 이 주소로 알립니다. 비워 두면 대표 관리자에게 보냅니다.</div>
        </div>
      </section>
    </div>

    <div class="drawer-footer">
      <button class="btn btn-secondary" type="button" data-drawer-close="widgetSettingsDrawer">취소</button>
      <button class="btn btn-primary" type="button" id="btnSaveWidget">저장</button>
    </div>
  </aside>
</div>

<script>
var WIDGET_URLS = {
    save:       '<?= base_url('admin/api/widget_save') ?>',
    regenerate: '<?= base_url('admin/api/widget_regenerate') ?>'
};

$(function () {
    $('#btnChatSettings').on('click', function () { openDrawer('widgetSettingsDrawer'); });

    $('#btnCopySnippet').on('click', function () {
        var $snippet = $('#widgetSnippet');
        $snippet.get(0).select();

        // 보안 컨텍스트가 아니면 클립보드 API 를 쓸 수 없어 선택 상태로만 둔다.
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText($snippet.val()).then(function () {
                showToast('스니펫을 복사했습니다.', 'success');
            });
        } else {
            showToast('스니펫을 선택했습니다. Ctrl+C 로 복사하세요.', 'info');
        }
    });

    $('#btnSaveWidget').on('click', function () {
        var $btn = $(this).prop('disabled', true);

        apiPost(WIDGET_URLS.save, {
            enabled: $('#widgetEnabled').is(':checked') ? 1 : 0,
            color: $('#widgetColor').val(),
            position: $('#widgetPosition').val(),
            domains: $('#widgetDomains').val(),
            offline_email: $('#widgetOfflineEmail').val(),
            guide_autosuggest: $('#widgetGuideSuggest').is(':checked') ? 1 : 0
        }, function (data, message) {
            showToast(message, 'success');
            closeDrawer('widgetSettingsDrawer');
        }).always(function () { $btn.prop('disabled', false); });
    });

    $('#btnRegenerate').on('click', function () {
        showConfirmModal('키 재발급',
            '새 키를 발급하면 지금 설치된 스니펫은 즉시 동작하지 않습니다. 계속할까요?',
            function () {
                apiPost(WIDGET_URLS.regenerate, {}, function (data, message) {
                    $('#widgetSnippet').val(data.snippet);
                    showToast(message, 'success');
                });
            });
    });

    <?php if ( ! empty($open_settings)): ?>
    openDrawer('widgetSettingsDrawer');
    <?php endif; ?>
});
</script>
