/**
 * 파일 위치: assets/js/console/macro-picker.js
 * 역할: 상담 대화 입력창의 매크로 목록 — `/` 로 열기, 즐겨찾기 → 추천 → 전체 순서, 이름·초성·내용 검색,
 *       키보드 조작(↑↓ Enter Tab Esc), 고르면 변수를 채워 입력창에 넣기, 보낼 때 쓴 매크로 id 넘기기
 *
 * 매크로를 고르면 입력창에 채울 뿐 보내지 않는다. 전송은 chat.js 가 기존 API 로 한다. (docs/macro-plan.md M2)
 *
 * 사용 예 (chat.js)
 *   RHMacroPicker.attach({ input, popup, button, listUrl, suggestUrl, context: function () { ... } });
 *   keydown 에서 RHMacroPicker.handleKey(event) 가 true 면 그 키는 목록이 썼다(Enter 전송을 막는다).
 *   보낼 때 RHMacroPicker.takeMacroId() 로 매크로 id 를 받는다.
 */
(function (window, $) {
    'use strict';

    /** 목록을 다시 받아 오는 간격. 관리 화면에서 바뀐 것을 반영한다. */
    var RELOAD_MS = 60000;
    /** 한 번에 그리는 항목 수 상한 */
    var MAX_ITEMS = 60;

    var opts = null;
    var macros = [];
    var loadedAt = 0;
    var suggested = { conversationId: 0, ids: [] };
    var items = [];
    var active = 0;
    var open = false;
    var token = null;     // { start, end, query } 입력창에서 '/검색어' 가 있는 자리
    var picked = null;    // { id } 마지막으로 넣은 매크로. 입력창이 비면 지운다.

    function el() { return opts.input; }

    function load(force) {
        if (!force && Date.now() - loadedAt < RELOAD_MS) {
            return;
        }

        loadedAt = Date.now();
        apiGet(opts.listUrl, function (data) {
            macros = data.macros;
            if (open) { render(); }
        });
    }

    function loadSuggestions(conversationId) {
        if (!conversationId || !opts.suggestUrl) {
            return;
        }

        apiGet(opts.suggestUrl + conversationId, function (data) {
            suggested = { conversationId: conversationId, ids: data.items.map(function (item) { return item.id; }) };
            if (open) { render(); }
        });
    }

    /**
     * 커서 앞이 '/검색어' 인지 본다. 줄 처음이나 공백 뒤의 / 만 매크로로 본다(주소 안의 / 는 무시).
     */
    function findToken() {
        var input = el();
        var caret = input.selectionStart;

        if (caret !== input.selectionEnd) {
            return null;
        }

        var before = input.value.substring(0, caret);
        var match = /(^|\s)\/([^\s\/]{0,40})$/.exec(before);

        if (!match) {
            return null;
        }

        return { start: caret - match[2].length - 1, end: caret, query: match[2] };
    }

    // ------------------------------------------------------------------
    // 목록 만들기
    // ------------------------------------------------------------------

    function build(query) {
        var used = {};
        var out = [];
        var ctx = opts.context();

        function push(section, macro) {
            if (used[macro.id] || out.length >= MAX_ITEMS) { return; }
            used[macro.id] = true;
            out.push({ section: section, macro: macro });
        }

        var favorites = macros.filter(function (m) { return m.favorite && RHMacro.rank(m, query) >= 0; })
                              .sort(function (a, b) { return a.fav_order - b.fav_order; });
        favorites.forEach(function (m) { push('즐겨찾기', m); });

        if (suggested.conversationId === ctx.conversationId) {
            suggested.ids.forEach(function (id) {
                macros.forEach(function (m) {
                    if (m.id === id && RHMacro.rank(m, query) >= 0) { push('추천', m); }
                });
            });
        }

        var rest = [];
        macros.forEach(function (m) {
            var r = RHMacro.rank(m, query);
            if (r >= 0) { rest.push({ macro: m, rank: r }); }
        });

        // 검색어가 있으면 맞는 정도 순, 같으면 서버 순서(개인 → 공용, 최근 사용 순)
        rest.sort(function (a, b) { return a.rank - b.rank; });
        rest.forEach(function (item) { push('전체', item.macro); });

        return out;
    }

    function render() {
        var popup = opts.popup;
        var conf = window.MACRO_EDITOR || {};

        items = build(token ? token.query : '');
        active = Math.min(active, Math.max(items.length - 1, 0));
        popup.innerHTML = '';

        var list = document.createElement('div');
        list.className = 'max-h-72 overflow-y-auto py-1';

        if (!items.length) {
            var empty = document.createElement('p');
            empty.className = 'px-3 py-4 text-center text-sm text-slate-400';
            empty.textContent = macros.length ? '맞는 매크로가 없습니다.' : '아직 매크로가 없습니다. 아래 [매크로 관리] 에서 만들어 보세요.';
            list.appendChild(empty);
        }

        var lastSection = '';

        items.forEach(function (item, index) {
            if (item.section !== lastSection) {
                lastSection = item.section;
                var head = document.createElement('div');
                head.className = 'px-3 pb-0.5 pt-2 text-[11px] font-semibold text-slate-400';
                head.textContent = item.section === '즐겨찾기' ? '★ 즐겨찾기' : item.section;
                list.appendChild(head);
            }

            var row = document.createElement('button');
            row.type = 'button';
            row.dataset.index = index;
            row.className = 'flex w-full items-baseline gap-3 px-3 py-1.5 text-left text-sm '
                + (index === active ? 'bg-brand-50' : 'hover:bg-slate-50');

            var name = document.createElement('span');
            name.className = 'w-40 shrink-0 truncate font-medium text-slate-800';
            name.textContent = item.macro.name;

            var body = document.createElement('span');
            body.className = 'min-w-0 flex-1 text-slate-500 ' + (index === active ? 'line-clamp-3 whitespace-pre-wrap' : 'truncate');
            body.textContent = RHMacro.label(item.macro.body, conf.variables);

            var scope = document.createElement('span');
            scope.className = 'shrink-0 text-[11px] text-slate-400';
            scope.textContent = item.macro.scope === 'org' ? '공용' : '개인';

            row.appendChild(name);
            row.appendChild(body);
            row.appendChild(scope);
            list.appendChild(row);
        });

        var foot = document.createElement('div');
        foot.className = 'flex items-center gap-3 border-t border-slate-100 px-3 py-1.5 text-[11px] text-slate-400';
        foot.appendChild(document.createTextNode('↑↓ 이동 · Enter 넣기 · Esc 닫기'));

        if (opts.manageUrl) {
            var manage = document.createElement('a');
            manage.className = 'ml-auto text-brand-600 hover:underline';
            manage.href = opts.manageUrl;
            manage.target = '_blank';
            manage.rel = 'noopener';
            manage.textContent = '매크로 관리';
            foot.appendChild(manage);
        }

        popup.appendChild(list);
        popup.appendChild(foot);

        var current = list.querySelector('[data-index="' + active + '"]');
        if (current) { current.scrollIntoView({ block: 'nearest' }); }
    }

    function show() {
        if (!open) {
            open = true;
            active = 0;
            opts.popup.classList.remove('hidden');
            load(false);
            loadSuggestions(opts.context().conversationId);
        }
        render();
    }

    function hide() {
        open = false;
        token = null;
        opts.popup.classList.add('hidden');
    }

    // ------------------------------------------------------------------
    // 고르기
    // ------------------------------------------------------------------

    function pick(index) {
        var item = items[index];

        if (!item) {
            return;
        }

        var input = el();
        var ctx = opts.context();
        var text = RHMacro.fill(item.macro.body, ctx.values);
        var start = token ? token.start : input.selectionStart;
        var end = token ? token.end : input.selectionEnd;

        input.value = input.value.substring(0, start) + text + input.value.substring(end);
        input.focus();
        input.selectionStart = input.selectionEnd = start + text.length;

        picked = { id: item.macro.id };
        hide();
        $(input).trigger('input');
    }

    function onInput() {
        if (el().value === '') {
            picked = null;
        }

        token = findToken();

        if (token) {
            show();
        } else if (open) {
            hide();
        }
    }

    /**
     * 목록이 열려 있을 때 키를 처리한다. 쓴 키면 true(호출한 쪽은 그 키로 아무것도 하지 않는다).
     */
    function handleKey(event) {
        // 한글 조합 중의 Enter 는 글자를 확정하는 키라 목록이 쓰지 않는다.
        if (!open || event.isComposing) {
            return false;
        }

        switch (event.key) {
            case 'ArrowDown':
                active = items.length ? (active + 1) % items.length : 0;
                render();
                break;
            case 'ArrowUp':
                active = items.length ? (active - 1 + items.length) % items.length : 0;
                render();
                break;
            case 'Enter':
            case 'Tab':
                if (!items.length) { hide(); return false; }
                pick(active);
                break;
            case 'Escape':
                hide();
                break;
            default:
                return false;
        }

        event.preventDefault();
        return true;
    }

    /** 버튼으로 열 때: 커서 자리에 '/' 를 넣는다(앞이 글자면 한 칸 띄운다). */
    function openFromButton() {
        var input = el();
        var caret = input.selectionStart;
        var before = input.value.substring(0, caret);
        var insert = (before === '' || /\s$/.test(before)) ? '/' : ' /';

        input.value = before + insert + input.value.substring(input.selectionEnd);
        input.focus();
        input.selectionStart = input.selectionEnd = caret + insert.length;
        onInput();
    }

    function attach(options) {
        opts = options;

        var input = el();

        input.addEventListener('input', onInput);
        input.addEventListener('click', onInput);
        input.addEventListener('blur', function () {
            // 목록 항목을 누르는 동안은 닫지 않는다(mousedown 에서 막는다).
            window.setTimeout(function () { if (document.activeElement !== input) { hide(); } }, 150);
        });

        opts.popup.addEventListener('mousedown', function (event) {
            var row = event.target.closest('[data-index]');
            if (row) {
                event.preventDefault();
                pick(Number(row.dataset.index));
            }
        });

        if (opts.button) {
            opts.button.addEventListener('click', openFromButton);
        }

        if (window.RHMacroEditor) {
            window.RHMacroEditor.onSaved(function () { load(true); });
        }

        load(true);
    }

    window.RHMacroPicker = {
        attach: attach,
        handleKey: handleKey,
        isOpen: function () { return open; },
        close: hide,
        /** 보낼 때 부른다. 매크로로 채운 글이면 그 id, 아니면 0. 부르면 지운다. */
        takeMacroId: function () {
            var id = picked ? picked.id : 0;
            picked = null;
            return id;
        },
        reset: function () { picked = null; hide(); }
    };
})(window, jQuery);
