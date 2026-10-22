/**
 * 파일 위치: assets/js/console/macro-editor.js
 * 역할: 매크로 편집 드로어(만들기/고치기) — 범위 선택, 글자 수, 변수 넣기, 저장
 *
 * 마크업은 templates/console/_macro_edit.html 의 Bootstrap 6 드로어, 설정은 그 뷰가 넘기는 MACRO_EDITOR.
 * 숨기기는 d-none 이다.
 *
 * 사용 예
 *   RHMacroEditor.open({ macro: row });                       // 고치기
 *   RHMacroEditor.open({ scope: 'org' });                     // 새로 만들기
 *   RHMacroEditor.open({ body: '보낸 글', hint: '...' });      // 보낸 메시지로 만들기
 *   RHMacroEditor.onSaved(function (id, message) { ... });
 */
(function (window, $) {
    'use strict';

    var conf = window.MACRO_EDITOR || {};
    var editingId = 0;
    var listeners = [];

    function updateCounts() {
        $('#macroNameCount').text($('#macroName').val().length + '/' + conf.nameMax);
        $('#macroBodyCount').text($('#macroBody').val().length + '/' + conf.bodyMax);
    }

    /** 이름 칸이 비었으면 내용 첫 줄 앞 20자로 채운다(보낸 메시지로 만들기). */
    function suggestName(body) {
        var first = String(body).split('\n')[0].trim();
        return first.length > 20 ? first.substring(0, 20) : first;
    }

    function open(options) {
        options = options || {};
        var macro = options.macro || null;

        editingId = macro ? macro.id : 0;

        $('#macroEditTitle').text(macro ? '매크로 고치기' : '새 매크로');
        $('#macroName').val(macro ? macro.name : (options.body ? suggestName(options.body) : ''));
        $('#macroBody').val(macro ? macro.body : (options.body || ''));
        $('#macroKeywords').val(macro ? (macro.keywords || '') : '');

        // 고칠 때는 범위를 바꾸지 않는다.
        if (macro) {
            $('#macroScopeField').addClass('d-none');
            $('#macroScopeFixed').removeClass('d-none')
                .text('범위: ' + (macro.scope === 'org' ? '공용(조직의 모든 상담원)' : '개인(나만)'));
        } else {
            var scope = options.scope === 'org' && conf.isAdmin ? 'org' : 'personal';
            $('#macroScopeField').removeClass('d-none');
            $('#macroScopeFixed').addClass('d-none');
            $('input[name="macroScope"][value="' + scope + '"]').prop('checked', true);
        }

        $('#macroHint').toggleClass('d-none', !options.hint).text(options.hint || '');

        updateCounts();
        openDrawer('macroEditDrawer');
    }

    function save() {
        var $btn = $('#btnMacroSave');
        var data = {
            id: editingId,
            scope: $('input[name="macroScope"]:checked').val() || 'personal',
            name: $('#macroName').val(),
            body: $('#macroBody').val(),
            keywords: $('#macroKeywords').val()
        };

        $btn.prop('disabled', true);

        apiPost(conf.saveUrl, data, function (res, message) {
            closeDrawer('macroEditDrawer');
            showToast(message, message.indexOf('알 수 없는 변수') >= 0 ? 'warning' : 'success');
            listeners.forEach(function (fn) { fn(res.id, message); });
        }).always(function () { $btn.prop('disabled', false); });
    }

    /** 커서 자리에 {변수} 를 넣는다. */
    function insertVariable(key) {
        var el = document.getElementById('macroBody');
        var token = '{' + key + '}';
        var start = el.selectionStart || 0;
        var end = el.selectionEnd || 0;

        el.value = el.value.substring(0, start) + token + el.value.substring(end);
        el.focus();
        el.selectionStart = el.selectionEnd = start + token.length;
        updateCounts();
    }

    $(function () {
        $('#macroName, #macroBody').on('input', updateCounts);
        $('#btnMacroSave').on('click', save);
        $('[data-macro-var]').on('click', function () { insertVariable($(this).data('macro-var')); });
    });

    window.RHMacroEditor = {
        open: open,
        onSaved: function (fn) { listeners.push(fn); }
    };
})(window, jQuery);
