<?php
/**
 * 파일 위치: application/views/console/_widget_settings.php
 * 역할: 상담 대화 화면의 상담 위젯 설정 드로어
 *       탭: 설치(스니펫, 키 재발급, 사용 여부, 허용 도메인) / 모양 / 인사말(문구, 변수, 바로가기 버튼) /
 *       운영 시간(요일별 시간, 휴일, 운영 시간 외 위젯, 전화 버튼) / 자동 응답(부재중 문구, 답변 속도 안내, 오프라인 알림, 가이드 자동 추천) /
 *       연락처·알림(연락처 카드 항목, 필수 여부, 안내 문구, 팔로업 메일)
 *
 * 조직 관리자에게만 그린다(Console::chat 의 is_admin). 저장은 admin/api/* 가 권한을 다시 확인한다.
 * 동작은 assets/js/console/widget-settings.js. 운영 시간 설계는 docs/chat-operation-plan.md, 인사말과 연락처·알림은 docs/widget-ops-plan.md.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

$hours_out = array();
foreach ($hours as $weekday => $ranges)
{
    $hours_out[$weekday] = array_map(function ($range) {
        return array(sprintf('%02d:%02d', intdiv($range[0], 60), $range[0] % 60),
                     sprintf('%02d:%02d', intdiv($range[1], 60), $range[1] % 60));
    }, $ranges);
}
$offhours_modes = array(
    'accept' => array('그대로 받기', '위젯에 운영 시간 외임을 표시하고 문의를 받습니다. 고객에게 자동 응답이 나갑니다.'),
    'info'   => array('안내만 표시', '대화창에 운영 시간만 안내하고 새 문의는 받지 않습니다. 이미 대화 중인 고객은 계속 쓸 수 있습니다.'),
    'hide'   => array('위젯 숨기기', '상담 버블을 보이지 않습니다. 이미 대화 중인 고객에게는 그대로 보입니다.'),
);
$reply_choices = array(5 => '몇 분 안에', 10 => '10분 안에', 30 => '30분 안에', 60 => '1시간 안에', 180 => '몇 시간 안에', 1440 => '하루 안에');
$auto_messages = array(
    'closed'  => array('운영 시간 외', $org->chat_msg_closed, 'widget.auto.closed'),
    'holiday' => array('휴일', $org->chat_msg_holiday, 'widget.auto.holiday'),
    'away'    => array('상담원 부재(운영 시간 중)', $org->chat_msg_away, 'widget.auto.away'),
);
?>
<div class="drawer-root" id="widgetSettingsDrawer" aria-hidden="true">
  <div class="drawer-backdrop" data-drawer-close="widgetSettingsDrawer"></div>

  <aside class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="widgetSettingsTitle" tabindex="-1">
    <div class="drawer-header">
      <h2 class="drawer-title" id="widgetSettingsTitle">상담 위젯 설정</h2>
      <button type="button" class="btn btn-sm btn-ghost ml-auto" data-drawer-close="widgetSettingsDrawer">닫기</button>
    </div>

    <nav class="drawer-tabs" role="tablist">
      <button type="button" class="drawer-tab is-active" data-tab="install" role="tab">설치</button>
      <button type="button" class="drawer-tab" data-tab="look" role="tab">모양</button>
      <button type="button" class="drawer-tab" data-tab="greeting" role="tab">인사말</button>
      <button type="button" class="drawer-tab" data-tab="hours" role="tab">운영 시간</button>
      <button type="button" class="drawer-tab" data-tab="reply" role="tab">자동 응답</button>
      <button type="button" class="drawer-tab" data-tab="contact" role="tab">연락처·알림</button>
    </nav>

    <div class="drawer-body">

      <!-- 설치 -->
      <div class="space-y-5" data-tab-panel="install">
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

          <div>
            <label class="form-label" for="widgetDomains">허용 도메인</label>
            <textarea class="form-input font-mono text-xs" id="widgetDomains" rows="3"
                      placeholder="shop.example.com"><?= html_escape($org->widget_domains) ?></textarea>
            <div class="form-help">한 줄에 하나씩 적습니다. 하위 도메인도 함께 허용됩니다. 비워 두면 어느 사이트에서나 동작합니다.</div>
          </div>
        </section>
      </div>

      <!-- 모양 -->
      <div class="hidden space-y-5" data-tab-panel="look">
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
      </div>

      <!-- 인사말 (docs/widget-ops-plan.md 2장) -->
      <div class="hidden space-y-5" data-tab-panel="greeting">
        <section class="space-y-2">
          <label class="form-label" for="widgetGreeting">인사말</label>
          <textarea class="form-input text-sm" id="widgetGreeting" rows="4" maxlength="1000"
                    placeholder="{visitor_name}님 안녕하세요! {org_name}에 오신 것을 환영해요. 무엇을 도와드릴까요?"><?= html_escape($org->chat_greeting) ?></textarea>
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-slate-500">변수 넣기</span>
            <button type="button" class="btn btn-sm btn-secondary" data-greeting-var="visitor_name">고객 이름</button>
            <button type="button" class="btn btn-sm btn-secondary" data-greeting-var="org_name">조직 이름</button>
          </div>
          <div class="form-help">고객이 상담 창을 열면 대화 맨 위에 보입니다. 비워 두면 인사말 없이 시작합니다.
            고객 이름을 모르면 <code>{visitor_name}</code> 자리에 "<?= html_escape(lang_text('macro.visitor_fallback', array(), $org->locale)) ?>" 이 들어갑니다.
            고객이 첫 메시지를 보내면 인사말도 대화에 함께 남아 상담원이 볼 수 있습니다.</div>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-5">
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-semibold text-slate-700">바로가기 버튼</h3>
            <button type="button" class="btn btn-sm btn-secondary ml-auto" id="btnGreetingButtonAdd">버튼 추가</button>
          </div>
          <ul id="widgetGreetingButtons" class="space-y-2"></ul>
          <div class="form-help">인사말 아래에 3개까지 둡니다. 공지사항, 배송 조회, 공개 가이드처럼 고객이 스스로 찾아갈 곳을 넣습니다.
            주소는 https:// 로 시작해야 하며 새 탭으로 열립니다.</div>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-5">
          <h3 class="text-sm font-semibold text-slate-700">미리보기</h3>
          <div class="rounded-md bg-slate-50 p-3">
            <div id="widgetGreetingPreview" class="inline-block max-w-[85%] whitespace-pre-wrap break-words rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"></div>
            <div id="widgetGreetingPreviewButtons" class="mt-2 flex flex-wrap gap-1.5"></div>
          </div>
        </section>
      </div>

      <!-- 운영 시간 -->
      <div class="hidden space-y-5" data-tab-panel="hours">
        <section class="space-y-3">
          <label class="flex items-center gap-2">
            <input type="checkbox" id="widgetHoursEnabled" class="rounded border-slate-300" <?= (int) $org->chat_hours_enabled === 1 ? 'checked' : '' ?>>
            <span class="text-sm font-medium text-slate-700">운영 시간 사용</span>
          </label>
          <div class="form-help">
            시간대: <?= html_escape($org->timezone) ?> (<?= html_escape(timezone_offset_label($org->timezone)) ?>).
            <a class="text-brand-600 hover:underline" href="<?= base_url('admin/organization') ?>">조직 정보</a>에서 바꿉니다.
            끄면 응대 가능한 상담원이 있는지로만 판단합니다.
          </div>

          <div id="widgetHoursEditor" class="space-y-1.5"></div>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-secondary" id="btnHoursCopyWeekdays">월요일 시간을 화-금에 적용</button>
          </div>
          <div class="form-help">하루에 시간대를 3개까지 둘 수 있습니다. 점심시간은 09:00-12:00, 13:00-18:00 처럼 나눠 넣습니다.
            자정을 넘기면 24:00 까지와 다음 날 00:00 부터로 나눠 넣습니다.</div>
        </section>

        <section class="space-y-3 border-t border-slate-100 pt-5">
          <div class="flex items-center gap-2">
            <h3 class="text-sm font-semibold text-slate-700">휴일</h3>
            <button type="button" class="btn btn-sm btn-secondary ml-auto" id="btnHolidayCandidates">공휴일 불러오기</button>
          </div>

          <div id="widgetHolidayCandidates" class="hidden rounded-md border border-slate-200 bg-slate-50 p-3">
            <p class="mb-2 text-xs text-slate-500">조직 국가의 공휴일입니다. 쉬는 날만 골라 추가하세요.</p>
            <ul class="max-h-48 space-y-1 overflow-y-auto text-sm" id="widgetHolidayCandidateList"></ul>
            <div class="mt-2 flex justify-end gap-2">
              <button type="button" class="btn btn-sm btn-ghost" id="btnHolidayCandidatesClose">닫기</button>
              <button type="button" class="btn btn-sm btn-primary" id="btnHolidayCandidatesAdd">선택 추가</button>
            </div>
          </div>

          <ul id="widgetHolidayList" class="divide-y divide-slate-100 rounded-md border border-slate-200 text-sm"></ul>

          <div class="space-y-2 rounded-md border border-dashed border-slate-300 p-3">
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="form-label text-xs" for="holidayStart">시작일</label>
                <input type="date" class="form-input py-1 text-sm" id="holidayStart">
              </div>
              <div>
                <label class="form-label text-xs" for="holidayEnd">종료일 (하루면 비움)</label>
                <input type="date" class="form-input py-1 text-sm" id="holidayEnd">
              </div>
            </div>
            <input type="text" class="form-input py-1 text-sm" id="holidayName" maxlength="60" placeholder="휴일 이름 (예: 창립기념일)">
            <div class="flex flex-wrap items-center gap-3 text-sm">
              <label class="flex items-center gap-1.5"><input type="radio" name="holidayMode" value="closed" checked> 종일 휴무</label>
              <label class="flex items-center gap-1.5"><input type="radio" name="holidayMode" value="short"> 단축 운영</label>
              <span id="holidayShortTimes" class="hidden items-center gap-1">
                <select class="form-input w-auto py-1 text-sm" id="holidayShortStart"></select> -
                <select class="form-input w-auto py-1 text-sm" id="holidayShortEnd"></select>
              </span>
            </div>
            <div class="flex items-center gap-3">
              <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" id="holidayRepeat" class="rounded border-slate-300"> 매년 반복</label>
              <button type="button" class="btn btn-sm btn-primary ml-auto" id="btnHolidayAdd">휴일 추가</button>
            </div>
          </div>
          <div class="form-help">휴일은 바로 저장됩니다. 휴일이 요일별 운영 시간보다 우선합니다.
            매년 반복은 양력 날짜만 맞춥니다(설날·추석은 해마다 불러오기로 넣습니다).</div>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-5">
          <h3 class="text-sm font-semibold text-slate-700">운영 시간이 아닐 때 위젯을</h3>
          <?php foreach ($offhours_modes as $value => $mode): ?>
          <label class="flex items-start gap-2 rounded-md border border-slate-200 p-2.5">
            <input type="radio" name="widgetOffhours" value="<?= $value ?>" class="mt-0.5" <?= $org->chat_offhours_mode === $value ? 'checked' : '' ?>>
            <span>
              <span class="block text-sm font-medium text-slate-700"><?= $mode[0] ?></span>
              <span class="block text-xs text-slate-500"><?= $mode[1] ?></span>
            </span>
          </label>
          <?php endforeach; ?>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-5">
          <h3 class="text-sm font-semibold text-slate-700">전화 상담 버튼</h3>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="widgetPhoneButton" class="rounded border-slate-300" <?= (int) $org->chat_phone_button === 1 ? 'checked' : '' ?>>
            위젯에 대표 전화 버튼 표시
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="widgetPhoneHoursOnly" class="rounded border-slate-300" <?= (int) $org->chat_phone_hours_only === 1 ? 'checked' : '' ?>>
            운영 시간에만 표시
          </label>
          <div class="form-help">
            <?php if (trim((string) $org->phone) === ''): ?>
              <span class="text-amber-700">대표 전화가 비어 있어 버튼이 나오지 않습니다.</span>
            <?php else: ?>
              번호: <?= html_escape($org->phone) ?>.
            <?php endif; ?>
            <a class="text-brand-600 hover:underline" href="<?= base_url('admin/organization') ?>">조직 정보</a>의 대표 전화를 씁니다.
            휴대폰에서는 눌러서 바로 걸고, PC 에서는 번호를 보여 주고 복사할 수 있습니다.
          </div>
        </section>
      </div>

      <!-- 자동 응답 -->
      <div class="hidden space-y-5" data-tab-panel="reply">
        <section class="space-y-4">
          <div class="form-help">고객이 글을 남겼을 때 상담원 대신 바로 나가는 안내입니다. 같은 상황에서는 대화당 한 번만 보냅니다.
            비워 두면 아래 회색 기본 문구를 씁니다. <code>{next_open}</code> 은 다음 운영 시각, <code>{holiday}</code> 는 휴일 이름으로 바뀝니다.</div>
          <?php foreach ($auto_messages as $kind => $item): ?>
          <div>
            <label class="form-label" for="widgetMsg_<?= $kind ?>"><?= $item[0] ?></label>
            <textarea class="form-input text-sm" id="widgetMsg_<?= $kind ?>" rows="2" maxlength="500"
                      placeholder="<?= html_escape(lang_text($item[2], array(), $org->locale)) ?>"><?= html_escape($item[1]) ?></textarea>
          </div>
          <?php endforeach; ?>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-5">
          <h3 class="text-sm font-semibold text-slate-700">답변 속도 안내</h3>
          <div class="form-help mt-0">상담 가능할 때 위젯 머리에 "보통 5분 안에 답변합니다" 처럼 보입니다. 자리 비움이나 운영 시간 외에는 숨깁니다.</div>
          <label class="flex items-center gap-2 text-sm">
            <input type="radio" name="widgetReplyMode" value="off" <?= $org->chat_reply_mode === 'off' ? 'checked' : '' ?>> 표시 안 함
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input type="radio" name="widgetReplyMode" value="manual" <?= $org->chat_reply_mode === 'manual' ? 'checked' : '' ?>> 직접 지정
            <select class="form-input w-auto py-1 text-sm" id="widgetReplyMinutes">
              <?php foreach ($reply_choices as $minutes => $label): ?>
              <option value="<?= $minutes ?>" <?= (int) $org->chat_reply_minutes === $minutes ? 'selected' : '' ?>>보통 <?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="flex items-start gap-2 text-sm">
            <input type="radio" name="widgetReplyMode" value="auto" class="mt-0.5" <?= $org->chat_reply_mode === 'auto' ? 'checked' : '' ?>>
            <span>실제 기록으로
              <span class="block text-xs text-slate-500">
                최근 7일, 운영 중에 들어온 문의의 첫 답변 시간 중앙값을 구간으로 올려 보여 줍니다. 10건이 안 되면 보이지 않습니다.
                <?php if ($reply_preview === NULL): ?>
                  지금은 표본이 모자랍니다.
                <?php else: ?>
                  지금 기록: 약 <?= html_escape(format_duration($reply_preview)) ?>.
                <?php endif; ?>
              </span>
            </span>
          </label>
        </section>

        <section class="space-y-4 border-t border-slate-100 pt-5">
          <div>
            <label class="form-label" for="widgetOfflineEmail">부재 알림 받을 주소</label>
            <input type="email" class="form-input" id="widgetOfflineEmail"
                   value="<?= html_escape($org->widget_offline_email) ?>" placeholder="support@example.com">
            <div class="form-help">응대할 상담원이 없거나 운영 시간이 아닐 때 문의가 오면 이 주소로 알립니다(대화당 한 번). 비워 두면 대표 관리자에게 보냅니다.</div>
          </div>

          <div>
            <label class="flex items-center gap-2">
              <input type="checkbox" id="widgetGuideSuggest" class="rounded border-slate-300" <?= (int) $org->guide_autosuggest === 1 ? 'checked' : '' ?>>
              <span class="text-sm font-medium text-slate-700">가이드 자동 추천</span>
            </label>
            <div class="form-help">방문자 질문이 공개 가이드의 키워드와 잘 맞으면 상담원보다 먼저 가이드 카드를 보여줍니다.
              같은 가이드는 한 번만, 대화당 3번까지 보냅니다. 가이드는 <a class="text-brand-600 hover:underline" href="<?= base_url('admin/guide') ?>">가이드</a>에서 만듭니다.</div>
          </div>
        </section>
      </div>

      <!-- 연락처·알림 (docs/widget-ops-plan.md 3장) -->
      <div class="hidden space-y-5" data-tab-panel="contact">
        <section class="space-y-2">
          <h3 class="text-sm font-semibold text-slate-700">고객에게 받을 연락처</h3>
          <div class="form-help mt-0">고객이 첫 메시지를 보내면 대화 안에 연락처 카드가 한 번 나옵니다. 고객은 건너뛸 수 있습니다.</div>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="widgetAskName" class="rounded border-slate-300" <?= (int) $org->chat_ask_name === 1 ? 'checked' : '' ?>> 이름
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="widgetAskEmail" class="rounded border-slate-300" <?= (int) $org->chat_ask_email === 1 ? 'checked' : '' ?>> 이메일
            <span class="text-xs text-slate-500">답장 알림 메일을 받는 주소</span>
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" id="widgetAskPhone" class="rounded border-slate-300" <?= (int) $org->chat_ask_phone === 1 ? 'checked' : '' ?>> 휴대폰
            <span class="text-xs text-slate-500">저장만 합니다. 문자 알림은 아직 보내지 않습니다.</span>
          </label>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-5">
          <label class="flex items-start gap-2">
            <input type="checkbox" id="widgetContactRequired" class="mt-0.5 rounded border-slate-300" <?= (int) $org->chat_contact_required === 1 ? 'checked' : '' ?>>
            <span>
              <span class="block text-sm font-medium text-slate-700">연락처를 남긴 고객에게만 문의받기</span>
              <span class="block text-xs text-slate-500">상담 창을 열면 연락처 카드가 먼저 나오고, 위에서 고른 항목을 모두 적어야 메시지를 보낼 수 있습니다.
                연락처를 적지 않고 떠난 고객의 문의는 상담 대화에 들어오지 않습니다. 이미 대화 중인 고객은 그대로 이어 갑니다.</span>
            </span>
          </label>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-5">
          <label class="form-label" for="widgetContactNotice">연락처 카드 안내 문구</label>
          <textarea class="form-input text-sm" id="widgetContactNotice" rows="2" maxlength="500"
                    placeholder="<?= html_escape(lang_text('widget.contact_notice', array(), $org->locale)) ?>"><?= html_escape($org->chat_contact_notice) ?></textarea>
          <div class="form-help">비워 두면 회색 기본 문구를 씁니다. 무엇에 쓰는지 고객이 알 수 있게 적습니다.</div>
        </section>

        <section class="space-y-2 border-t border-slate-100 pt-5">
          <label class="flex items-start gap-2">
            <input type="checkbox" id="widgetFollowupEmail" class="mt-0.5 rounded border-slate-300" <?= (int) $org->chat_followup_email === 1 ? 'checked' : '' ?>>
            <span>
              <span class="block text-sm font-medium text-slate-700">고객이 자리를 비우면 답장을 이메일로 알리기</span>
              <span class="block text-xs text-slate-500">고객이 상담 창을 닫았거나 사이트를 떠난 뒤 상담원이 답하면, 고객이 남긴 이메일로 알림을 보냅니다.
                메일의 주소를 누르면 어느 기기에서든 대화를 이어 갈 수 있습니다(<?= (int) (Chat_followup::RESUME_TTL / 86400) ?>일 동안).
                고객이 다시 대화를 볼 때까지는 한 번만 보냅니다.</span>
              <?php if (empty($mail_ready)): ?>
              <span class="mt-1 block text-xs text-amber-700">메일 서버(SMTP)가 설정되지 않아 지금은 보내지 않습니다.</span>
              <?php endif; ?>
            </span>
          </label>
        </section>

        <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs leading-relaxed text-amber-900">
          받은 연락처는 고객이 요청한 상담에 답하는 목적으로만 써야 합니다.
          홍보 등 다른 목적에 쓰려면 고객에게 따로 동의를 받아야 합니다.
        </div>
      </div>
    </div>

    <div class="drawer-footer">
      <span class="mr-auto self-center text-xs text-slate-400">휴일을 뺀 모든 탭의 값을 함께 저장합니다.</span>
      <button class="btn btn-secondary" type="button" data-drawer-close="widgetSettingsDrawer">취소</button>
      <button class="btn btn-primary" type="button" id="btnSaveWidget">저장</button>
    </div>
  </aside>
</div>

<script>
var WIDGET_URLS = {
    save:             '<?= base_url('admin/api/widget_save') ?>',
    regenerate:       '<?= base_url('admin/api/widget_regenerate') ?>',
    holidayAdd:       '<?= base_url('admin/api/holiday_add') ?>',
    holidayDelete:    '<?= base_url('admin/api/holiday_delete') ?>',
    holidayCandidates:'<?= base_url('admin/api/holiday_candidates') ?>'
};
var WIDGET_HOURS = <?= json_encode((object) $hours_out, JSON_UNESCAPED_UNICODE) ?>;
var WIDGET_HOLIDAYS = <?= json_encode($holidays, JSON_UNESCAPED_UNICODE) ?>;
var WIDGET_OPEN_SETTINGS = <?= ! empty($open_settings) ? 'true' : 'false' ?>;
// 인사말 버튼과 미리보기 값. 이름 없는 고객의 호칭은 고객이 읽으므로 조직 언어로 만든다(CLAUDE.md 3.2).
var WIDGET_GREETING = <?= json_encode(array(
    'buttons'  => $greeting_buttons,
    'orgName'  => $org->name,
    'fallback' => lang_text('macro.visitor_fallback', array(), $org->locale),
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="<?= asset_url('assets/js/console/widget-settings.js') ?>"></script>
