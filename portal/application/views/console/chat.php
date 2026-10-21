<?php
/**
 * 파일 위치: application/views/console/chat.php
 * 역할: 상담 위젯 대화 목록(AG Grid)과 응답 패널, 관리자용 위젯 설정 드로어
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<div class="mb-4 flex items-center gap-3">
  <h1 class="text-xl font-bold text-slate-800">상담 대화</h1>
  <span id="chatUnread" class="badge badge-red hidden"></span>
  <span id="chatLive" class="badge badge-gray hidden"></span>
  <label class="ml-auto flex items-center gap-2 text-sm text-slate-600">
    <input type="checkbox" id="chatShowClosed" class="rounded border-slate-300">
    종료된 대화 포함
  </label>
  <?php if ($is_admin): ?>
  <button type="button" class="btn btn-sm btn-secondary" id="btnChatSettings"
          aria-controls="widgetSettingsDrawer">설정</button>
  <?php endif; ?>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-5">

  <div class="card overflow-hidden lg:col-span-3">
    <div id="chatGrid" style="height: calc(100vh - 260px); min-height: 420px;"></div>
  </div>

  <div class="card flex flex-col lg:col-span-2" style="height: calc(100vh - 260px); min-height: 420px;">

    <div id="chatEmpty" class="flex flex-1 items-center justify-center p-6 text-center text-sm text-slate-400">
      왼쪽 목록에서 대화를 선택하세요.
    </div>

    <div id="chatPanel" class="hidden flex-1 flex-col overflow-hidden">
      <div class="flex items-start gap-2 border-b border-slate-200 px-4 py-3">
        <div class="min-w-0">
          <p id="chatVisitor" class="truncate font-semibold text-slate-800"></p>
          <p id="chatMeta" class="truncate text-xs text-slate-500"></p>
        </div>
        <div class="ml-auto flex shrink-0 gap-2">
          <button type="button" id="chatViewer" class="btn btn-sm hidden border border-slate-300 text-slate-600">원격 화면</button>
          <button type="button" id="chatRemote" class="btn btn-sm btn-primary">원격지원 시작</button>
          <button type="button" id="chatClose" class="btn btn-sm border border-slate-300 text-slate-600">종료</button>
        </div>
      </div>

      <div id="chatMessages" class="flex-1 space-y-3 overflow-y-auto bg-slate-50 px-4 py-4"></div>

      <!-- 추천 가이드: 방문자의 최근 메시지로 키워드 매칭(AI 없음). 검색어를 넣으면 직접 찾는다. -->
      <div class="border-t border-slate-200 px-4 py-2">
        <div class="flex items-center gap-2">
          <span class="shrink-0 text-xs font-semibold text-slate-500" id="chatGuideTitle">추천 가이드</span>
          <input id="chatGuideSearch" class="form-input ml-auto w-44 py-1 text-xs" placeholder="가이드 검색" maxlength="100">
        </div>
        <ul id="chatGuideList" class="mt-1 max-h-28 space-y-1 overflow-y-auto"></ul>
      </div>

      <form id="chatForm" class="flex gap-2 border-t border-slate-200 px-4 py-3">
        <textarea id="chatInput" rows="2" class="form-input flex-1 resize-none"
                  placeholder="답변을 입력하고 Enter 로 보냅니다. 줄바꿈은 Shift+Enter."></textarea>
        <button class="btn btn-primary shrink-0" type="submit">보내기</button>
      </form>
    </div>

  </div>
</div>

<script>
var CHAT_ROWS = <?= json_encode(array_map(function ($c) {
    return array(
        'id'              => (int) $c->id,
        'visitor_name'    => $c->visitor_name,
        'visitor_email'   => $c->visitor_email,
        'page_url'        => $c->page_url,
        'agent_name'      => $c->agent_name,
        'status'          => $c->status,
        'unread'          => (int) $c->unread_for_agent,
        'last_body'       => $c->last_body,
        'last_message_at' => $c->last_message_at,
        'created_at'      => $c->created_at,
        'session_id'      => $c->session_id ? (int) $c->session_id : null,
    );
}, $conversations), JSON_UNESCAPED_UNICODE) ?>;
var CHAT_REALTIME = <?= json_encode($realtime, JSON_UNESCAPED_UNICODE) ?>;
var CHAT_URLS = {
    list:     '<?= base_url('console/api/chat/list') ?>',
    messages: '<?= base_url('console/api/chat/messages/') ?>',
    send:     '<?= base_url('console/api/chat/send') ?>',
    close:    '<?= base_url('console/api/chat/close') ?>',
    remote:   '<?= base_url('console/api/chat/remote') ?>',
    sendGuide:    '<?= base_url('console/api/chat/send_guide') ?>',
    guideSuggest: '<?= base_url('console/api/guide/suggest/') ?>',
    guideSearch:  '<?= base_url('console/api/guide/search') ?>',
    guideAdmin:   '<?= $is_admin ? base_url('admin/guide') : '' ?>',
    viewer:   '<?= base_url('console/session/') ?>'
};
</script>
<script src="<?= base_url('assets/js/console/chat.js') ?>"></script>
<?php if ($is_admin) { $this->load->view('console/_widget_settings'); } ?>
