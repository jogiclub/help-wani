<?php
/**
 * 파일 위치: application/views/layouts/_partials.php
 * 역할: 모든 레이아웃이 공유하는 토스트 영역과 확인 모달
 */
defined('BASEPATH') OR exit('No direct script access allowed');
?>
<!-- 토스트 -->
<div id="toastContainer"
     class="pointer-events-none fixed top-4 right-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"></div>

<!-- 확인 모달 -->
<div id="confirmModal" data-modal-backdrop
     class="modal-root fixed inset-0 z-50 hidden items-center justify-center bg-slate-900/50 p-4">
  <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
    <div class="px-5 py-4 text-base font-semibold text-slate-800" id="confirmModalTitle"></div>
    <div class="px-5 pb-5 text-sm text-slate-600" id="confirmModalBody"></div>
    <div class="flex justify-end gap-2 border-t border-slate-200 px-5 py-3">
      <button type="button" class="btn btn-secondary" id="confirmModalCancel">취소</button>
      <button type="button" class="btn btn-primary" id="confirmModalOk">확인</button>
    </div>
  </div>
</div>
