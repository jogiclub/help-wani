/**
 * 파일 위치: portal/assets/js/widget.js
 * 역할: 고객사 웹사이트에 삽입되는 상담 위젯 (버블, 대화창, 폴링, 운영 상태·답변 속도 표시, 전화 상담 버튼,
 *       인사말과 바로가기 버튼, 연락처 카드, 팔로업 메일의 대화 이어 가기)
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
    // 팔로업 메일의 이어 가기 주소로 열린 고객 접속 페이지가 넣어 준다(docs/widget-ops-plan.md 3-5).
    var resumeToken = script.getAttribute('data-resume') || '';
    var openOnStart = script.getAttribute('data-open') === '1';
    var origin = new URL(script.src, window.location.href).origin;

    if (!widgetKey) {
        return;
    }

    /**
     * 버블 아이콘. 포털의 다른 아이콘과 같은 Material Symbols Outlined(Apache-2.0) 의 공식 SVG 경로를 그대로 옮겼다.
     * 출처: npm @material-symbols/svg-400@0.47.5 outlined/chat.svg, outlined/close.svg, outlined/call.svg
     * 위젯은 고객사 페이지 안에서 돌아 아이콘 폰트를 불러올 수 없으므로 SVG 로 넣는다(CLAUDE.md 3.9).
     */
    var ICONS = {
        chat:  'M240-399h313v-60H240v60Zm0-130h480v-60H240v60Zm0-130h480v-60H240v60ZM80-80v-740q0-24 18-42t42-18h680q24 0 42 18t18 42v520q0 24-18 42t-42 18H240L80-80Zm134-220h606v-520H140v600l74-80Zm-74 0v-520 520Z',
        close: 'm249-207-42-42 231-231-231-231 42-42 231 231 231-231 42 42-231 231 231 231-42 42-231-231-231 231Z',
        call:  'M795-120q-116 0-236.5-56T335-335Q232-438 176-558.5T120-795q0-19.29 12.86-32.14Q145.71-840 165-840h140q14 0 24 10t14 25l26.93 125.64Q372-665 369.5-653.5t-10.73 19.73L259-533q26 44 55 82t64 72q37 38 78 69.5t86 55.5l95-98q10-11 23.15-15 13.15-4 25.85-2l119 26q15 4 25 16.04 10 12.05 10 26.96v135q0 19.29-12.86 32.14Q814.29-120 795-120ZM229-588l81-82-23-110H180q2 42 13.5 88.5T229-588Zm369 363q41 19 89 31t93 14v-107l-103-21-79 83ZM229-588Zm369 363Z'
    };

    function icon(name, size) {
        size = size || 28;
        return '<svg viewBox="0 -960 960 960" width="' + size + '" height="' + size + '" fill="currentColor" aria-hidden="true">'
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
        availability: null,
        visitor: {},
        contact: null,
        greeting: null,
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
            '.status{display:inline-flex;align-items:center;gap:5px;margin-top:6px;font-size:11px;padding:2px 8px;border-radius:10px;background:rgba(255,255,255,.2)}',
            '.dot{width:7px;height:7px;border-radius:4px;background:#cbd5e1}',
            '.status.open .dot{background:#4ade80}',
            '.status.away .dot{background:#facc15}',
            '.head .hours{margin-top:6px;font-size:11px}',
            '.offhours{display:none;padding:14px 16px;border-top:1px solid #e2e8f0;background:#fff;font-size:13px;line-height:1.5;color:#475569}',
            '.offhours.show{display:block}',
            '.head .reply{margin-top:4px;font-size:11px;opacity:.9}',
            '.phone{display:flex;align-items:center;gap:8px;padding:8px 12px;border-top:1px solid #e2e8f0;background:#fff;font-size:12px}',
            '.phone a{display:inline-flex;align-items:center;gap:6px;color:' + color + ';font-weight:600;text-decoration:none}',
            '.phone button{margin-left:auto;border:1px solid #e2e8f0;background:#fff;border-radius:6px;padding:3px 8px;font-size:11px;color:#475569;cursor:pointer}',
            '.hidden{display:none !important}',
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
            '.msg .from{font-size:11px;color:#64748b;margin-bottom:3px}',
            '.links{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;max-width:85%}',
            '.links a{display:inline-block;padding:5px 10px;border:1px solid ' + color + ';border-radius:7px;background:#fff;color:' + color + ';font-size:12px;text-decoration:none}',
            '.note{text-align:center;font-size:11px;color:#64748b;margin:0 0 10px}',
            '.contact{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:10px;font-size:13px;color:#0f172a}',
            '.contact .ct-title{margin:0 0 4px;font-weight:600}',
            '.contact .ct-notice{margin:0 0 8px;font-size:11px;line-height:1.45;color:#64748b;white-space:pre-wrap}',
            '.contact label{display:block;font-size:11px;color:#475569;margin:6px 0 3px}',
            '.contact input,.contact select{width:100%;padding:7px 9px;border:1px solid #e2e8f0;border-radius:7px;font-size:13px;background:#fff;color:#0f172a}',
            '.contact .ct-phone{display:flex;gap:6px}',
            '.contact .ct-phone select{width:auto;flex:none}',
            '.contact .ct-error{margin:6px 0 0;font-size:11px;color:#dc2626;min-height:0}',
            '.contact .ct-actions{display:flex;justify-content:flex-end;gap:6px;margin-top:10px}',
            '.contact .ct-actions button{border:0;border-radius:7px;padding:6px 12px;font-size:12px;cursor:pointer}',
            '.contact .ct-skip{background:#f1f5f9;color:#475569}',
            '.contact .ct-submit{background:' + color + ';color:#fff}',
            '.contact button:disabled{opacity:.5;cursor:default}',
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
            '    <h3></h3><p></p>',
            '    <span class="status"><span class="dot"></span><span class="label"></span></span>',
            '    <p class="hours"></p>',
            '    <p class="reply hidden"></p>',
            '  </div>',
            '  <div class="list"></div>',
            '  <div class="offhours"></div>',
            '  <div class="phone hidden"><a></a><button type="button"></button></div>',
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
        dom.statusLabel = root.querySelector('.status .label');
        dom.hours = root.querySelector('.head .hours');
        // 상태 점의 클래스(open/away/closed)와 겹치지 않게 안내 상자는 offhours 로 부른다.
        dom.offhours = root.querySelector('.offhours');
        dom.reply = root.querySelector('.head .reply');
        dom.phone = root.querySelector('.phone');
        dom.phoneLink = root.querySelector('.phone a');
        dom.phoneCopy = root.querySelector('.phone button');
        dom.list = root.querySelector('.list');
        dom.form = root.querySelector('.form');
        dom.input = root.querySelector('.form textarea');
        dom.submit = root.querySelector('.form button');

        dom.bubble.addEventListener('click', toggle);
        dom.phoneCopy.addEventListener('click', copyPhone);
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
        dom.input.placeholder = state.texts.placeholder || '';
        dom.submit.textContent = state.texts.send || '';
        applyAvailability(state.availability);
    }

    /**
     * 운영 상태 표시. 서버가 정한 값(open/away/closed)과 문구를 그대로 그린다(docs/chat-operation-plan.md F5).
     * 예전 서버처럼 availability 가 없으면 online 값만으로 그린다.
     */
    function applyAvailability(availability) {
        var av = availability || { state: state.config.online ? 'open' : 'away', accepting: true };
        var texts = state.texts;

        state.availability = av;
        dom.status.className = 'status ' + av.state;
        dom.statusLabel.textContent = texts['status_' + av.state] || (av.state === 'open' ? texts.online : texts.offline) || '';

        var hours = state.config.hours ? (texts.hours_label || '') + ' ' + state.config.hours : '';
        var next = av.state === 'closed' && av.next_open ? (texts.next_open || '{next}').replace('{next}', av.next_open) : '';

        dom.hours.textContent = [hours, next].filter(Boolean).join(' · ');
        dom.hours.classList.toggle('hidden', !dom.hours.textContent);

        // '안내만' / '숨기기' 의 운영 시간 외에는 입력칸 대신 안내를 보여 준다.
        var accepting = av.accepting !== false;

        dom.form.classList.toggle('hidden', !accepting);
        dom.offhours.classList.toggle('show', !accepting);

        // 운영 시간과 다음 운영 시각은 머리에 이미 보이므로 안내 문구만 둔다.
        dom.offhours.textContent = accepting ? '' : (texts.closed_info || '');

        // 답변 속도는 서버가 상담 가능할 때만 문구로 보낸다(F6).
        dom.reply.textContent = av.reply || '';
        dom.reply.classList.toggle('hidden', !av.reply);

        applyPhone(av.phone || '');
    }

    /**
     * 전화 상담 버튼(F8). 휴대폰은 바로 걸리고, PC 는 번호를 보여 주고 복사할 수 있게 한다.
     */
    function applyPhone(phone) {
        dom.phone.classList.toggle('hidden', !phone);

        if (!phone) {
            return;
        }

        dom.phoneLink.innerHTML = icon('call', 16);
        dom.phoneLink.appendChild(document.createTextNode((state.texts.phone_label || '') + ' ' + phone));
        dom.phoneLink.href = 'tel:' + phone.replace(/[^0-9+]/g, '');
        dom.phoneCopy.textContent = state.texts.phone_copy || '';

        var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
        var canCopy = !!(navigator.clipboard && window.isSecureContext);
        dom.phoneCopy.classList.toggle('hidden', touch || !canCopy);
        dom.phoneCopy.setAttribute('data-phone', phone);
    }

    function copyPhone() {
        var phone = dom.phoneCopy.getAttribute('data-phone') || '';

        navigator.clipboard.writeText(phone).then(function () {
            dom.phoneCopy.textContent = state.texts.phone_copied || '';
            window.setTimeout(function () { dom.phoneCopy.textContent = state.texts.phone_copy || ''; }, 2000);
        }).catch(function () {});
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

            // 첫 메시지와 함께 저장된 인사말이 오면 화면에만 그려 둔 인사말 자리에 바꿔 넣는다.
            if (message.meta && message.meta.type === 'greeting') {
                var stored = greetingRow(message.body, message.meta.buttons, message.created_at);

                if (dom.liveGreeting) {
                    dom.list.replaceChild(stored, dom.liveGreeting);
                    dom.liveGreeting = null;
                } else {
                    dom.list.appendChild(stored);
                }
                return;
            }

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

    // ------------------------------------------------------------------
    // 인사말 (docs/widget-ops-plan.md 2장)
    // ------------------------------------------------------------------

    /**
     * 인사말 말풍선. 조직이 상담원처럼 먼저 말을 거는 모양이다. 글자는 textContent 로만 넣는다.
     * 버튼 주소는 서버가 https 만 남겨 보내지만 여기서도 한 번 더 확인한다.
     */
    function greetingRow(body, buttons, createdAt) {
        var row = document.createElement('div');
        row.className = 'msg agent';

        var from = document.createElement('span');
        from.className = 'from';
        from.textContent = state.config.org_name || '';
        row.appendChild(from);

        var text = document.createElement('span');
        text.className = 'text';
        text.textContent = body;
        row.appendChild(text);

        var links = (buttons || []).filter(function (item) {
            return item && item.label && /^https:\/\//.test(item.url || '');
        });

        if (links.length) {
            var box = document.createElement('span');
            box.className = 'links';

            links.forEach(function (item) {
                var a = document.createElement('a');
                a.href = item.url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = item.label;
                box.appendChild(a);
            });

            row.appendChild(box);
        }

        if (createdAt) {
            var time = document.createElement('span');
            time.className = 'time';
            time.textContent = formatTime(createdAt);
            row.appendChild(time);
        }

        return row;
    }

    /** 대화를 시작하기 전에는 인사말을 화면에만 그린다. 저장은 첫 메시지 때 서버가 한다. */
    function renderLiveGreeting() {
        if (!state.greeting || !state.greeting.body) {
            return;
        }

        dom.liveGreeting = greetingRow(state.greeting.body, state.greeting.buttons, '');
        dom.list.insertBefore(dom.liveGreeting, dom.list.firstChild);
    }

    // ------------------------------------------------------------------
    // 연락처 카드 (docs/widget-ops-plan.md 3-3)
    // ------------------------------------------------------------------

    /**
     * 서버가 정한 연락처 상태대로 카드를 띄우고 입력창을 잠근다.
     * required 면 카드를 내기 전에는 메시지를 보낼 수 없다(서버도 막는다).
     */
    function applyContact(contact) {
        state.contact = contact || null;

        var required = !!(contact && contact.required);

        dom.input.disabled = required;
        dom.input.placeholder = required ? (state.texts.contact_locked || '') : (state.texts.placeholder || '');
        dom.submit.disabled = required;

        if (contact && contact.ask) {
            showContactCard();
        } else if (dom.contactCard) {
            dom.contactCard.remove();
            dom.contactCard = null;
        }
    }

    function showContactCard() {
        var contact = state.contact;

        if (dom.contactCard || !contact || !contact.fields || !contact.fields.length) {
            return;
        }

        var texts = state.texts;
        var card = document.createElement('div');
        card.className = 'contact';

        var title = document.createElement('p');
        title.className = 'ct-title';
        title.textContent = texts.contact_title || '';
        card.appendChild(title);

        var notice = document.createElement('p');
        notice.className = 'ct-notice';
        notice.textContent = contact.notice || '';
        card.appendChild(notice);

        var inputs = {};

        contact.fields.forEach(function (field) {
            var label = document.createElement('label');
            label.textContent = texts[field] || field;
            card.appendChild(label);

            var input = document.createElement('input');
            input.value = (state.visitor && state.visitor[field]) || '';

            if (field === 'email') {
                input.type = 'email';
                input.autocomplete = 'email';
            } else if (field === 'phone') {
                input.type = 'tel';
                input.autocomplete = 'tel-national';
                input.placeholder = '010-0000-0000';
            } else {
                input.type = 'text';
                input.autocomplete = 'name';
                input.maxLength = 60;
            }

            if (field === 'phone') {
                var line = document.createElement('div');
                line.className = 'ct-phone';

                var select = document.createElement('select');
                (contact.countries || []).forEach(function (country) {
                    var option = document.createElement('option');
                    option.value = country.code;
                    option.textContent = country.code + ' ' + country.dial;
                    option.selected = country.code === contact.country;
                    select.appendChild(option);
                });

                inputs.phone_country = select;
                line.appendChild(select);
                line.appendChild(input);
                card.appendChild(line);
            } else {
                card.appendChild(input);
            }

            inputs[field] = input;
        });

        var error = document.createElement('p');
        error.className = 'ct-error';
        card.appendChild(error);

        var actions = document.createElement('div');
        actions.className = 'ct-actions';

        var buttons = [];

        if (!contact.required) {
            var skip = document.createElement('button');
            skip.type = 'button';
            skip.className = 'ct-skip';
            skip.textContent = texts.contact_skip || '';
            skip.addEventListener('click', function () { sendContact({ skip: 1 }, buttons, error); });
            actions.appendChild(skip);
            buttons.push(skip);
        }

        var submit = document.createElement('button');
        submit.type = 'button';
        submit.className = 'ct-submit';
        submit.textContent = texts.contact_submit || '';
        submit.addEventListener('click', function () {
            var values = {};

            Object.keys(inputs).forEach(function (key) { values[key] = inputs[key].value; });
            sendContact(values, buttons, error);
        });
        actions.appendChild(submit);
        buttons.push(submit);

        card.appendChild(actions);

        dom.contactCard = card;
        dom.list.appendChild(card);
        dom.list.scrollTop = dom.list.scrollHeight;
    }

    function sendContact(values, buttons, error) {
        buttons.forEach(function (b) { b.disabled = true; });
        error.textContent = '';

        values.key = widgetKey;
        values.visitor_key = state.visitorKey;

        post('/api/widget/contact', values, function (res) {
            buttons.forEach(function (b) { b.disabled = false; });

            if (!res || res.result !== true) {
                error.textContent = (res && res.message) || state.texts.error || '';
                return;
            }

            state.visitor = res.data.visitor || state.visitor;

            if (dom.contactCard) {
                dom.contactCard.remove();
                dom.contactCard = null;
            }

            if (res.message) {
                localNote(res.message);
            }

            applyContact(res.data.contact);

            if (!dom.input.disabled) {
                dom.input.focus();
            }
        });
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

    /**
     * 저장하지 않는 안내 한 줄(오류, 연락처 저장됨). 메시지 번호(lastId)를 건드리지 않는다.
     * 예전에는 가짜 번호를 붙여 넣어 바로 다음에 온 진짜 메시지를 건너뛸 수 있었다.
     */
    function localNote(message) {
        var placeholder = dom.list.querySelector('.empty');

        if (placeholder) {
            placeholder.remove();
        }

        var note = document.createElement('p');
        note.className = 'note';
        note.textContent = message;
        dom.list.appendChild(note);
        dom.list.scrollTop = dom.list.scrollHeight;
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

            applyAvailability(res.data.availability || { state: res.data.online ? 'open' : 'away', accepting: true });
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
        }, function (res) {
            dom.submit.disabled = false;

            if (!res || res.result !== true) {
                localNote((res && res.message) || state.texts.error || '');
                dom.input.value = body;

                // 연락처를 먼저 받아야 하는 조직이면 카드를 띄운다.
                if (res && res.data && res.data.contact) {
                    applyContact(res.data.contact);
                }
                return;
            }

            appendMessages(res.data.messages);

            if (res.data.availability) {
                applyAvailability(res.data.availability);
            }

            if (res.data.contact) {
                applyContact(res.data.contact);
            }
        });
    }

    function init() {
        post('/api/widget/init', {
            key: widgetKey,
            visitor_key: readStored(),
            resume: resumeToken,
            page_url: window.location.href,
        }, function (res) {
            // 운영 시간 외 '숨기기' 이고 대화 중이 아니면 버블을 그리지 않는다.
            if (!res || res.result !== true || res.data.hidden) {
                return;
            }

            state.visitorKey = res.data.visitor_key;
            state.texts = res.data.texts || {};
            state.config = res.data.config || {};
            state.realtime = res.data.realtime || null;
            state.availability = res.data.availability || null;
            store(state.visitorKey);

            state.visitor = res.data.visitor || {};
            state.greeting = res.data.greeting || null;

            build();
            applyTexts();

            appendMessages(res.data.messages);
            renderLiveGreeting();

            if (!state.greeting) {
                showEmpty();
            }

            applyContact(res.data.contact);
            state.ready = true;

            if (openOnStart && !state.open) {
                toggle();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
