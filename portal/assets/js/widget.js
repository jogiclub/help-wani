/**
 * 파일 위치: portal/assets/js/widget.js
 * 역할: 고객사 웹사이트에 삽입되는 상담 위젯 (버블, 대화창, 폴링)
 *
 * 이 파일은 우리 화면이 아니라 고객사 사이트 안에서 돈다. (CLAUDE.md 3.9)
 *   - 포털 자산(Tailwind, 공통 스크립트, 번역 스크립트)과 jQuery 를 쓰지 않는다.
 *   - 화면과 스타일은 Shadow DOM 안에 가둬 고객사 CSS 와 섞이지 않게 한다.
 *   - 문구는 서버가 /api/widget/init 응답으로 내려준다.
 */
(function () {
    'use strict';

    var script = document.currentScript;

    if (!script) {
        return;
    }

    var widgetKey = script.getAttribute('data-key') || '';
    var origin = new URL(script.src, window.location.href).origin;

    if (!widgetKey) {
        return;
    }

    /**
     * 버블 아이콘. 포털의 다른 아이콘과 같은 Material Symbols Outlined(Apache-2.0) 의 공식 SVG 경로를 그대로 옮겼다.
     * 출처: npm @material-symbols/svg-400@0.47.5 outlined/chat.svg, outlined/close.svg
     * 위젯은 고객사 페이지 안에서 돌아 아이콘 폰트를 불러올 수 없으므로 SVG 로 넣는다(CLAUDE.md 3.9).
     */
    var ICONS = {
        chat:  'M240-399h313v-60H240v60Zm0-130h480v-60H240v60Zm0-130h480v-60H240v60ZM80-80v-740q0-24 18-42t42-18h680q24 0 42 18t18 42v520q0 24-18 42t-42 18H240L80-80Zm134-220h606v-520H140v600l74-80Zm-74 0v-520 520Z',
        close: 'm249-207-42-42 231-231-231-231 42-42 231 231 231-231 42 42-231 231 231 231-42 42-231-231-231 231Z'
    };

    function icon(name) {
        return '<svg viewBox="0 -960 960 960" width="28" height="28" fill="currentColor" aria-hidden="true">'
            + '<path d="' + ICONS[name] + '"/></svg>';
    }

    var STORAGE_KEY = 'rh_widget_visitor_' + widgetKey;
    /** 실시간 연결이 없을 때의 폴링 간격 */
    var POLL_INTERVAL_MS = 3000;

    /** 실시간으로 받고 있을 때의 확인 간격 (놓친 메시지 보정용) */
    var POLL_INTERVAL_LIVE_MS = 30000;

    var state = {
        visitorKey: '',
        texts: {},
        config: {},
        realtime: null,
        socket: null,
        live: false,
        retry: 0,
        lastId: 0,
        open: false,
        timer: null,
        ready: false,
    };

    var dom = {};

    // ------------------------------------------------------------------
    // 통신
    // ------------------------------------------------------------------

    /**
     * Content-Type 을 text/plain 으로 보내 CORS 사전 요청(OPTIONS)을 피한다.
     * 본문은 JSON 이고 서버의 json_input() 이 그대로 읽는다.
     */
    function post(path, body, onDone) {
        fetch(origin + path, {
            method: 'POST',
            headers: { 'Content-Type': 'text/plain;charset=UTF-8' },
            body: JSON.stringify(body),
        })
            .then(function (res) { return res.json(); })
            .then(onDone)
            .catch(function () { onDone(null); });
    }

    function get(path, params, onDone) {
        var query = Object.keys(params)
            .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); })
            .join('&');

        fetch(origin + path + '?' + query)
            .then(function (res) { return res.json(); })
            .then(onDone)
            .catch(function () { onDone(null); });
    }

    function readStored() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) || '';
        } catch (e) {
            return '';
        }
    }

    function store(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // 저장이 막혀 있어도 이번 방문 동안에는 동작한다.
        }
    }

    // ------------------------------------------------------------------
    // 화면
    // ------------------------------------------------------------------

    function styles(color, position) {
        var side = position === 'left' ? 'left' : 'right';

        return [
            ':host{all:initial}',
            '*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif}',
            '.bubble{position:fixed;bottom:20px;' + side + ':20px;width:56px;height:56px;border:0;border-radius:28px;',
            'background:' + color + ';color:#fff;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25);z-index:2147483000;',
            'display:flex;align-items:center;justify-content:center;padding:0}',
            '.bubble svg{display:block}',
            '.bubble:hover{opacity:.92}',
            '.panel{position:fixed;bottom:88px;' + side + ':20px;width:340px;max-height:70vh;display:none;',
            'flex-direction:column;background:#fff;border-radius:12px;overflow:hidden;',
            'box-shadow:0 12px 32px rgba(0,0,0,.24);z-index:2147483000}',
            '.panel.open{display:flex}',
            '.head{background:' + color + ';color:#fff;padding:14px 16px}',
            '.head h3{margin:0;font-size:15px;font-weight:600}',
            '.head p{margin:4px 0 0;font-size:12px;opacity:.85;line-height:1.4}',
            '.status{display:inline-block;margin-top:6px;font-size:11px;padding:2px 8px;border-radius:10px;background:rgba(255,255,255,.2)}',
            '.list{flex:1;overflow-y:auto;padding:14px;background:#f8fafc;min-height:180px}',
            '.empty{color:#94a3b8;font-size:13px;text-align:center;padding:24px 8px}',
            '.msg{margin-bottom:10px;display:flex;flex-direction:column}',
            '.msg .text{display:inline-block;max-width:80%;padding:8px 11px;border-radius:10px;font-size:13px;line-height:1.5;white-space:pre-wrap;word-break:break-word}',
            '.msg .time{font-size:10px;color:#94a3b8;margin-top:3px}',
            '.msg.visitor{align-items:flex-end}',
            '.msg.visitor .text{background:' + color + ';color:#fff}',
            '.msg.agent{align-items:flex-start}',
            '.msg.agent .text{background:#fff;color:#0f172a;border:1px solid #e2e8f0}',
            '.msg.system{align-items:center}',
            '.msg.system .text{background:#fef9c3;color:#713f12;border:1px solid #fde68a;max-width:100%;text-align:center}',
            '.guide{display:block;max-width:85%;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:13px;line-height:1.45;color:#0f172a;text-align:left}',
            '.guide .label{display:block;font-size:11px;color:#64748b;margin-bottom:4px}',
            '.guide .title{display:block;font-weight:600}',
            '.guide .sub{display:block;font-size:12px;color:#64748b;margin-top:2px}',
            '.guide a{display:inline-block;margin-top:8px;padding:5px 10px;border-radius:7px;background:' + color + ';color:#fff;font-size:12px;text-decoration:none}',
            '.profile{display:flex;gap:6px;padding:10px 12px 0}',
            '.profile input{flex:1;min-width:0;padding:7px 9px;border:1px solid #e2e8f0;border-radius:7px;font-size:12px}',
            '.hint{font-size:11px;color:#94a3b8;padding:6px 12px 0}',
            '.form{display:flex;gap:6px;padding:10px 12px 12px;border-top:1px solid #e2e8f0;background:#fff}',
            '.form textarea{flex:1;resize:none;height:38px;padding:9px 10px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;line-height:1.3}',
            '.form button{border:0;border-radius:8px;background:' + color + ';color:#fff;font-size:13px;padding:0 14px;cursor:pointer}',
            '.form button:disabled{opacity:.5;cursor:default}',
            '@media (max-width:420px){.panel{width:auto;left:12px;right:12px;bottom:84px}}',
        ].join('');
    }

    function build() {
        var host = document.createElement('div');
        host.setAttribute('data-remotehelp-widget', '');
        document.body.appendChild(host);

        var root = host.attachShadow({ mode: 'open' });
        var style = document.createElement('style');
        style.textContent = styles(state.config.color || '#2563eb', state.config.position);
        root.appendChild(style);

        var wrap = document.createElement('div');
        wrap.innerHTML = [
            '<button class="bubble" type="button" part="bubble">' + icon('chat') + '</button>',
            '<section class="panel">',
            '  <div class="head">',
            '    <h3></h3><p></p><span class="status"></span>',
            '  </div>',
            '  <div class="list"></div>',
            '  <div class="hint"></div>',
            '  <div class="profile">',
            '    <input type="text" data-field="name" autocomplete="name">',
            '    <input type="email" data-field="email" autocomplete="email">',
            '  </div>',
            '  <form class="form">',
            '    <textarea rows="1"></textarea><button type="submit"></button>',
            '  </form>',
            '</section>',
        ].join('');
        root.appendChild(wrap);

        dom.root = root;
        dom.bubble = root.querySelector('.bubble');
        dom.panel = root.querySelector('.panel');
        dom.title = root.querySelector('.head h3');
        dom.subtitle = root.querySelector('.head p');
        dom.status = root.querySelector('.status');
        dom.list = root.querySelector('.list');
        dom.hint = root.querySelector('.hint');
        dom.profile = root.querySelector('.profile');
        dom.name = root.querySelector('[data-field="name"]');
        dom.email = root.querySelector('[data-field="email"]');
        dom.form = root.querySelector('.form');
        dom.input = root.querySelector('.form textarea');
        dom.submit = root.querySelector('.form button');

        dom.bubble.addEventListener('click', toggle);
        dom.form.addEventListener('submit', onSubmit);
        dom.input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                onSubmit(e);
            }
        });
    }

    function applyTexts() {
        dom.bubble.setAttribute('aria-label', state.texts.open || '');
        dom.title.textContent = state.texts.title || '';
        dom.subtitle.textContent = state.texts.subtitle || '';
        dom.hint.textContent = state.texts.profile_hint || '';
        dom.name.placeholder = state.texts.name || '';
        dom.email.placeholder = state.texts.email || '';
        dom.input.placeholder = state.texts.placeholder || '';
        dom.submit.textContent = state.texts.send || '';
        applyPresence(state.config.online);
    }

    function applyPresence(online) {
        dom.status.textContent = online ? (state.texts.online || '') : (state.texts.offline || '');
    }

    function formatTime(utc) {
        // 서버는 UTC 로 보내고, 표시할 때만 조직 시간대로 바꾼다. (CLAUDE.md 3.4)
        var date = new Date(utc.replace(' ', 'T') + 'Z');

        try {
            return new Intl.DateTimeFormat(state.config.intl || 'ko-KR', {
                timeZone: state.config.timezone || 'UTC',
                hour: '2-digit',
                minute: '2-digit',
            }).format(date);
        } catch (e) {
            return '';
        }
    }

    function appendMessages(messages) {
        if (!messages || !messages.length) {
            return;
        }

        var placeholder = dom.list.querySelector('.empty');

        if (placeholder) {
            placeholder.remove();
        }

        messages.forEach(function (message) {
            if (message.id <= state.lastId) {
                return;
            }

            state.lastId = message.id;

            var row = document.createElement('div');
            row.className = 'msg ' + message.sender;

            var text = guideCard(message) || document.createElement('span');

            if (!text.className) {
                text.className = 'text';
                text.textContent = message.body;
            }

            var time = document.createElement('span');
            time.className = 'time';
            time.textContent = formatTime(message.created_at);

            row.appendChild(text);
            row.appendChild(time);
            dom.list.appendChild(row);
        });

        dom.list.scrollTop = dom.list.scrollHeight;
    }

    /**
     * 가이드 카드. 서버가 meta 에 담아 보낸 제목과 주소로 그린다(상담원이 보냈거나 자동 추천).
     * 주소는 http(s) 만 링크로 만들고 새 탭으로 연다. 카드가 아니면 null.
     */
    function guideCard(message) {
        var meta = message.meta;

        if (!meta || meta.type !== 'guide' || !/^https?:\/\//.test(meta.url || '')) {
            return null;
        }

        var card = document.createElement('span');
        card.className = 'guide';

        if (message.sender === 'system') {
            var label = document.createElement('span');
            label.className = 'label';
            label.textContent = state.texts.guide_suggest || '';
            card.appendChild(label);
        }

        var title = document.createElement('span');
        title.className = 'title';
        title.textContent = meta.title || '';
        card.appendChild(title);

        if (meta.subtitle) {
            var sub = document.createElement('span');
            sub.className = 'sub';
            sub.textContent = meta.subtitle;
            card.appendChild(sub);
        }

        var link = document.createElement('a');
        link.href = meta.url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = state.texts.guide_open || meta.url;
        card.appendChild(link);

        return card;
    }

    function showEmpty() {
        if (dom.list.children.length === 0) {
            var note = document.createElement('div');
            note.className = 'empty';
            note.textContent = state.texts.empty || '';
            dom.list.appendChild(note);
        }
    }

    function notice(message) {
        appendMessages([{ id: state.lastId + 1, sender: 'system', body: message, created_at: '' }]);
    }

    // ------------------------------------------------------------------
    // 동작
    // ------------------------------------------------------------------

    function toggle() {
        state.open = !state.open;
        dom.panel.classList.toggle('open', state.open);
        // 열려 있으면 닫기 아이콘으로 바꿔 버블을 다시 누르면 닫힌다는 것을 보여준다.
        dom.bubble.innerHTML = icon(state.open ? 'close' : 'chat');

        if (state.open) {
            dom.input.focus();
            connectRealtime();
            startPolling();
            poll();
        } else {
            stopPolling();
        }
    }

    /**
     * 실시간 메시지 서버에 붙는다. 붙어 있는 동안에는 폴링 간격을 늘린다.
     * 연결이 끊기면 다시 시도하되, 그 사이에는 폴링이 메시지를 가져온다.
     */
    function connectRealtime() {
        if (!state.realtime || !state.realtime.url || !window.WebSocket) {
            return;
        }

        if (state.socket && (state.socket.readyState === 0 || state.socket.readyState === 1)) {
            return;
        }

        var url = state.realtime.url + '?token=' + encodeURIComponent(state.realtime.token);
        var socket;

        try {
            socket = new WebSocket(url);
        } catch (e) {
            return;
        }

        state.socket = socket;

        socket.onopen = function () {
            state.live = true;
            state.retry = 0;
            applyPollInterval();
        };

        socket.onmessage = function (event) {
            var payload;

            try {
                payload = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            if (payload.type === 'message' && payload.message) {
                appendMessages([payload.message]);
            }
        };

        socket.onclose = function () {
            state.live = false;
            state.socket = null;
            applyPollInterval();

            // 1초에서 시작해 30초까지 점점 늘려 다시 붙는다.
            state.retry = Math.min(state.retry + 1, 5);
            window.setTimeout(connectRealtime, Math.min(1000 * Math.pow(2, state.retry), 30000));
        };

        socket.onerror = function () {
            // onclose 에서 재연결을 처리한다.
        };
    }

    /**
     * 실시간으로 받고 있으면 폴링은 놓친 메시지를 줍는 역할만 한다.
     */
    function applyPollInterval() {
        if (!state.open) {
            return;
        }

        startPolling();
    }

    function startPolling() {
        stopPolling();
        state.timer = window.setInterval(poll, state.live ? POLL_INTERVAL_LIVE_MS : POLL_INTERVAL_MS);
    }

    function stopPolling() {
        if (state.timer) {
            window.clearInterval(state.timer);
            state.timer = null;
        }
    }

    function poll() {
        // 대화창이 닫혀 있거나 탭이 숨어 있으면 서버를 부르지 않는다.
        if (!state.open || document.hidden) {
            return;
        }

        get('/api/widget/poll', {
            key: widgetKey,
            visitor_key: state.visitorKey,
            after_id: state.lastId,
        }, function (res) {
            if (!res || res.result !== true) {
                return;
            }

            applyPresence(res.data.online);
            appendMessages(res.data.messages);
        });
    }

    function onSubmit(event) {
        event.preventDefault();

        var body = dom.input.value.trim();

        if (!body) {
            return;
        }

        dom.submit.disabled = true;
        dom.input.value = '';

        post('/api/widget/send', {
            key: widgetKey,
            visitor_key: state.visitorKey,
            body: body,
            name: dom.name.value,
            email: dom.email.value,
        }, function (res) {
            dom.submit.disabled = false;

            if (!res || res.result !== true) {
                notice((res && res.message) || state.texts.error || '');
                dom.input.value = body;
                return;
            }

            appendMessages(res.data.messages);
        });
    }

    function init() {
        post('/api/widget/init', {
            key: widgetKey,
            visitor_key: readStored(),
            page_url: window.location.href,
        }, function (res) {
            if (!res || res.result !== true) {
                return;
            }

            state.visitorKey = res.data.visitor_key;
            state.texts = res.data.texts || {};
            state.config = res.data.config || {};
            state.realtime = res.data.realtime || null;
            store(state.visitorKey);

            build();
            applyTexts();

            if (res.data.visitor) {
                dom.name.value = res.data.visitor.name || '';
                dom.email.value = res.data.visitor.email || '';
            }

            appendMessages(res.data.messages);
            showEmpty();
            state.ready = true;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
