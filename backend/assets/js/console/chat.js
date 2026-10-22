/**
 * 파일 위치: assets/js/console/chat.js
 * 역할: 상담 위젯 대화 목록과 응답 패널 (폴링), 추천 가이드 보내기, 운영 상태 표시와 전환,
 *       중복 응대 방지(담당 표시·가져오기, 보는 중/입력 중, 새 문의 브라우저 알림 1회),
 *       매크로(입력창 / 목록은 macro-picker.js, 보낸 메시지로 만들기는 macro-editor.js),
 *       고객 휴대폰·답장 알림 메일 표시(docs/widget-ops-plan.md 3-7)
 */
(function ($) {
    'use strict';

    /**
     * 목록 폴링. 이 호출이 상담원 접속 상태(agents.last_seen_at)도 갱신하므로
     * 실시간으로 받는 중에도 멈추지 않는다. 대신 간격을 늘린다.
     */
    var LIST_INTERVAL_MS = 5000;
    var LIST_INTERVAL_LIVE_MS = 20000;

    /** 선택한 대화의 메시지 폴링. 실시간으로 받는 중에는 놓친 것만 줍는다. */
    var MESSAGE_INTERVAL_MS = 3000;
    var MESSAGE_INTERVAL_LIVE_MS = 30000;

    /** 배지 톤 이름(common.js 의 RHUi.badge 가 화면 방식에 맞는 클래스로 바꾼다) */
    var STATUS_CLASS = { open: 'green', closed: 'gray' };
    var STATUS_LABEL = { open: '진행 중', closed: '종료' };

    /** 고객 위젯에 보이는 운영 상태(Chat_hours_model::availability) */
    var AVAILABILITY_LABEL = { open: '상담 가능', away: '응대 가능 상담원 없음', closed: '운영 시간 외' };
    var AVAILABILITY_CLASS = { open: 'green', away: 'amber', closed: 'gray' };

    var grid = null;
    var current = null;
    var lastId = 0;
    var socket = null;
    var live = false;
    var retry = 0;
    var listTimer = null;
    var messageTimer = null;
    var guideTimer = null;

    /**
     * 보는 중/입력 중(docs/chat-operation-plan.md F7). 실시간 연결이 있을 때만 주고받으며 저장하지 않는다.
     * presence[대화 id][상담원 id] = { name, kind, until }
     */
    var PRESENCE_HEARTBEAT_MS = 20000;
    var PRESENCE_VIEWING_TTL_MS = 45000;
    var PRESENCE_TYPING_TTL_MS = 6000;
    var TYPING_THROTTLE_MS = 3000;
    var presence = {};
    var lastTypingAt = 0;

    /** 새 문의 알림: 대화마다 미확인이 0 이 될 때까지 한 번만 띄운다. */
    var notifications = {};
    var prevUnread = null;
    var baseTitle = document.title;
    var pendingSend = null;

    // ------------------------------------------------------------------
    // 목록
    // ------------------------------------------------------------------

    function columns() {
        return [
            {
                title: '방문자', key: 'visitor_name', width: 140, flex: 1, align: 'left',
                render: function (row, value) {
                    var wrap = document.createElement('div');
                    wrap.className = 'd-flex h-100 min-w-0 align-items-center gap-3';

                    var name = document.createElement('span');
                    name.className = 'text-truncate';
                    name.textContent = value || '이름 없음';
                    wrap.appendChild(name);

                    if (row.unread > 0) {
                        var badge = document.createElement('span');
                        badge.className = RHUi.badge('red');
                        badge.textContent = row.unread;
                        wrap.appendChild(badge);
                    }

                    return wrap;
                }
            },
            {
                title: '요청 페이지', key: 'page_url', width: 180, flex: 1, align: 'left',
                render: renderPageUrl,
                format: function (v) { return v || '-'; }
            },
            {
                title: '마지막 메시지', key: 'last_body', width: 200, flex: 1, align: 'left',
                format: function (v) { return v || '-'; }
            },
            {
                title: '상태', key: 'status', width: 100,
                render: RHGrid.badgeRenderer(STATUS_CLASS, STATUS_LABEL),
                format: function (v) { return STATUS_LABEL[v] || v; }
            },
            { title: '담당', key: 'agent_name', width: 110,
              format: function (v) { return v || '-'; } },
            { title: '최근', key: 'last_message_at', width: 150,
              format: RHGrid.dateTime }
        ];
    }

    /**
     * 방문자가 위젯을 띄운 페이지. 위젯이 페이지를 열 때마다 갱신하므로 지금 보고 있는 페이지다.
     * 주소는 고객사 사이트가 보낸 값이므로 http(s) 만 링크로 만들고 나머지는 글자로 둔다.
     */
    function renderPageUrl(row, value) {
        var wrap = document.createElement('div');
        wrap.className = 'd-flex h-100 min-w-0 align-items-center';

        if (!value) {
            wrap.textContent = '-';
            return wrap;
        }

        var parsed = null;

        try { parsed = new URL(value); } catch (e) { parsed = null; }

        var label = parsed ? parsed.host + parsed.pathname + parsed.search : value;
        var el;

        if (parsed && (parsed.protocol === 'https:' || parsed.protocol === 'http:')) {
            el = document.createElement('a');
            el.href = parsed.href;
            el.target = '_blank';
            el.rel = 'noopener noreferrer';
            el.className = 'text-truncate';
            // 링크를 눌러도 대화가 선택되지 않게 한다.
            el.addEventListener('click', function (e) { e.stopPropagation(); });
        } else {
            el = document.createElement('span');
            el.className = 'text-truncate';
        }

        el.textContent = label;
        el.title = value;
        wrap.appendChild(el);

        return wrap;
    }

    function refreshList() {
        var params = { status: $('#chatShowClosed').is(':checked') ? '' : 'open' };

        apiGet(CHAT_URLS.list + '?' + $.param(params), function (data) {
            if (grid) {
                grid.setRows(data.conversations);
            }

            checkNotifications(data.conversations);
            document.title = data.unread_total > 0 ? '(' + data.unread_total + ') ' + baseTitle : baseTitle;

            if (data.availability) {
                renderAvailability(data.availability);
            }

            var $badge = $('#chatUnread');

            if (data.unread_total > 0) {
                $badge.text('미확인 ' + data.unread_total + '건').removeClass('d-none');
            } else {
                $badge.addClass('d-none');
            }

            // 선택한 대화의 요약 정보도 함께 갱신한다.
            if (current) {
                data.conversations.forEach(function (row) {
                    if (row.id === current.id) {
                        current = row;
                        renderHeader();
                    }
                });
            }
        });
    }

    // ------------------------------------------------------------------
    // 운영 상태
    // ------------------------------------------------------------------

    /**
     * 시각은 서버가 조직 시간대로 바꾼 문구(오늘 18:00 등)로 받는다.
     */
    function renderAvailability(av) {
        var $badge = $('#chatAvailability');

        $badge.attr('class', RHUi.badge(AVAILABILITY_CLASS[av.state] || 'gray'));

        var label = AVAILABILITY_LABEL[av.state] || av.state;

        if (av.state === 'closed' && av.reason === 'holiday' && av.holiday) {
            label = '휴무 · ' + av.holiday;
        } else if (av.state === 'closed' && av.reason === 'manual') {
            label = '운영 종료(수동)';
        }

        $badge.text(label);

        var notes = [];

        if (av.state === 'closed' && av.next_open) {
            notes.push('다음 운영 ' + av.next_open);
        }

        if (av.state === 'away') {
            notes.push('고객에게 부재중 자동 응답이 나갑니다');
        }

        if (av.manual !== 'auto') {
            notes.push(av.manual_until ? av.manual_until + ' 에 운영 시간대로 돌아갑니다' : '직접 바꿀 때까지 유지합니다');
        }

        if (!av.hours_enabled && av.state !== 'closed') {
            notes.push('운영 시간 미설정');
        }

        $('#chatAvailabilityNote').text(notes.join(' · '));
        $('#chatManualState').val(av.manual);
    }

    function bindAvailability() {
        renderAvailability(CHAT_AVAILABILITY);

        $('#chatMyStatus').on('change', function () {
            var $select = $(this).prop('disabled', true);

            apiPost(CHAT_URLS.myStatus, { status: $select.val() }, function (data, message) {
                showToast(message, 'success');
                renderAvailability(data.availability);
            }).always(function () { $select.prop('disabled', false); });
        });

        $('#chatManualState').on('change', function () {
            var $select = $(this).prop('disabled', true);

            apiPost(CHAT_URLS.chatState, { state: $select.val() }, function (data, message) {
                showToast(message, 'success');
                renderAvailability(data.availability);
            }, function (message) {
                showToast(message, 'danger');
                refreshList();
            }).always(function () { $select.prop('disabled', false); });
        });
    }

    // ------------------------------------------------------------------
    // 대화 패널
    // ------------------------------------------------------------------

    function renderHeader() {
        $('#chatVisitor').text(current.visitor_name || '이름 없음');

        var meta = [];

        if (current.visitor_email) { meta.push(current.visitor_email); }
        if (current.visitor_phone) { meta.push(current.visitor_phone); }
        if (current.followup_sent_at) {
            meta.push('답장 알림 메일 ' + RHI18n.formatDate(current.followup_sent_at, 'datetime'));
        }
        if (current.page_url) { meta.push(current.page_url); }

        $('#chatMeta').text(meta.join(' · ') || '연락처 없음');

        var closed = current.status !== 'open';
        $('#chatViewer').toggleClass('d-none', !current.session_id);
        $('#chatClose').prop('disabled', closed);
        $('#chatRemote').prop('disabled', closed);
        $('#chatInput').prop('disabled', closed);

        var owner = current.assigned_agent_id;
        var mine = owner === CHAT_ME.id;
        var $assignee = $('#chatAssignee');

        if (!owner) {
            $assignee.attr('class', RHUi.badge('gray')).text('담당 없음 · 대기 중');
        } else if (mine) {
            $assignee.attr('class', RHUi.badge('blue')).text('담당: 나');
        } else {
            $assignee.attr('class', RHUi.badge('amber')).text('담당: ' + (current.agent_name || '다른 상담원'));
        }

        $('#chatTake').toggleClass('d-none', closed || !owner || mine);
        $('#chatRelease').toggleClass('d-none', closed || !owner || !(mine || CHAT_ME.role === 'admin'));
        renderPresence();
    }

    // ------------------------------------------------------------------
    // 중복 응대 방지: 담당, 보는 중/입력 중, 새 문의 알림
    // ------------------------------------------------------------------

    function renderPresence() {
        if (!current) {
            return;
        }

        var now = Date.now();
        var people = presence[current.id] || {};
        var typing = [];
        var viewing = [];

        Object.keys(people).forEach(function (agentId) {
            var p = people[agentId];

            if (p.until < now) {
                delete people[agentId];
            } else if (p.kind === 'typing') {
                typing.push(p.name);
            } else {
                viewing.push(p.name);
            }
        });

        var parts = [];

        if (typing.length) { parts.push(typing.join(', ') + ' 님이 입력 중'); }
        if (viewing.length) { parts.push(viewing.join(', ') + ' 님이 보는 중'); }

        $('#chatPresence').text(parts.join(' · '));
    }

    function sendPresence(conversationId, kind) {
        if (!live || !conversationId) {
            return;
        }

        apiPost(CHAT_URLS.presence, { conversation_id: conversationId, kind: kind }, null, function () {
            // 보는 중 표시는 보조 정보라 실패를 알리지 않는다.
        });
    }

    function onPresence(payload) {
        if (payload.agent_id === CHAT_ME.id) {
            return;
        }

        var people = presence[payload.conversation_id] = presence[payload.conversation_id] || {};

        if (payload.kind === 'leave') {
            delete people[payload.agent_id];
        } else {
            people[payload.agent_id] = {
                name: payload.agent_name,
                kind: payload.kind,
                until: Date.now() + (payload.kind === 'typing' ? PRESENCE_TYPING_TTL_MS : PRESENCE_VIEWING_TTL_MS)
            };
            // 다른 상담원이 이 대화를 열었으면 내 알림은 거둔다.
            closeNotification(payload.conversation_id);
        }

        if (current && current.id === payload.conversation_id) {
            renderPresence();
        }
    }

    function onAssignment(payload) {
        if (payload.previous_agent_id === CHAT_ME.id && payload.by_agent_id !== CHAT_ME.id && payload.assigned_agent_id) {
            showToast(payload.by_agent_name + ' 님이 대화 #' + payload.conversation_id + ' 의 담당을 가져갔습니다.', 'info');
        }

        if (payload.assigned_agent_id && payload.assigned_agent_id !== CHAT_ME.id) {
            closeNotification(payload.conversation_id);
        }

        if (current && current.id === payload.conversation_id) {
            current.assigned_agent_id = payload.assigned_agent_id;
            current.agent_name = payload.agent_name;
            renderHeader();
        }

        refreshList();
    }

    function changeAssignment(action) {
        if (!current) {
            return;
        }

        var id = current.id;

        apiPost(CHAT_URLS.assign, { conversation_id: id, action: action }, function (data, message) {
            showToast(message, 'success');

            if (current && current.id === id) {
                current.assigned_agent_id = action === 'take' ? CHAT_ME.id : null;
                current.agent_name = action === 'take' ? CHAT_ME.name : null;
                renderHeader();
            }

            refreshList();
        });
    }

    /**
     * 목록이 갱신될 때 미확인이 늘어난 대화를 찾아 브라우저 알림을 띄운다(실시간과 폴링 모두 이 길로 온다).
     * 담당이 있는 대화는 담당자에게만, 내가 '응대 종료' 이거나 알림을 끈 경우에는 띄우지 않는다.
     */
    function checkNotifications(rows) {
        var first = prevUnread === null;
        var next = {};

        rows.forEach(function (row) {
            var before = first ? row.unread : (prevUnread[row.id] || 0);
            next[row.id] = row.unread;

            if (row.unread === 0 || row.status !== 'open' || (row.assigned_agent_id && row.assigned_agent_id !== CHAT_ME.id)) {
                closeNotification(row.id);
                return;
            }

            if (row.unread > before) {
                notify(row);
            }
        });

        prevUnread = next;
    }

    function notify(row) {
        if (notifications[row.id] || !$('#chatNotify').is(':checked') || $('#chatMyStatus').val() === 'off') {
            return;
        }

        // 지금 보고 있는 대화면 알릴 필요가 없다.
        if (current && current.id === row.id && !document.hidden) {
            return;
        }

        if (!window.Notification || Notification.permission !== 'granted') {
            return;
        }

        var notice;

        try {
            notice = new Notification('새 상담 문의', {
                body: (row.visitor_name || '이름 없음') + ': ' + (row.last_body || ''),
                tag: 'rh-chat-' + row.id
            });
        } catch (e) {
            return;
        }

        notice.onclick = function () {
            window.focus();
            select(row);
            notice.close();
        };

        notifications[row.id] = notice;
    }

    function closeNotification(conversationId) {
        if (notifications[conversationId]) {
            notifications[conversationId].close();
            delete notifications[conversationId];
        }
    }

    function bindDuplicateGuard() {
        $('#chatTake').on('click', function () { changeAssignment('take'); });
        $('#chatRelease').on('click', function () { changeAssignment('release'); });

        $('#chatConflictJoin').on('click', function () { resend('join'); });
        $('#chatConflictTake').on('click', function () { resend('take'); });

        $('#chatInput').on('input', function () {
            var now = Date.now();

            if (current && now - lastTypingAt > TYPING_THROTTLE_MS) {
                lastTypingAt = now;
                sendPresence(current.id, 'typing');
            }
        });

        $('#chatNotify').on('change', function () {
            var on = this.checked;

            // 브라우저 권한은 상담원이 켤 때 한 번만 묻는다.
            if (on && window.Notification && Notification.permission === 'default') {
                Notification.requestPermission();
            } else if (on && (!window.Notification || Notification.permission === 'denied')) {
                showToast('브라우저에서 이 사이트의 알림이 막혀 있습니다. 주소창의 사이트 설정에서 허용해 주세요.', 'warning');
            }

            apiPost(CHAT_URLS.notify, { enabled: on ? 1 : 0 }, function (data, message) {
                showToast(message, 'success');
            });
        });

        // 보고 있는 동안에는 주기적으로 알리고, 탭이 숨으면 멈춘다. 표시 만료도 같은 주기로 정리한다.
        window.setInterval(function () {
            if (current && !document.hidden) {
                sendPresence(current.id, 'viewing');
            }
        }, PRESENCE_HEARTBEAT_MS);
        window.setInterval(renderPresence, 3000);
    }

    function messageNode(message) {
        var row = document.createElement('div');
        var bubble = document.createElement('div');
        var time = document.createElement('div');

        // 말풍선 모양은 wani-chat.css(.rh-msg / .rh-bubble). 보낸 사람에 따라 줄 클래스만 다르다.
        bubble.className = 'rh-bubble';

        if (message.meta && message.meta.type === 'guide') {
            fillGuideCard(bubble, message);
        } else if (message.meta && message.meta.type === 'auto_reply') {
            // 운영 시간 외 / 부재중에 위젯이 고객에게 보낸 자동 응답
            bubble.textContent = '[자동 응답] ' + message.body;
        } else if (message.meta && message.meta.type === 'greeting') {
            // 고객이 첫 메시지를 보내기 전에 본 인사말과 바로가기 버튼
            var labels = (message.meta.buttons || []).map(function (b) { return '[' + b.label + ']'; }).join(' ');
            bubble.textContent = '[인사말] ' + message.body + (labels ? '\n' + labels : '');
        } else {
            bubble.textContent = message.body;
        }

        time.className = 'rh-msg-time';
        time.textContent = RHI18n.formatDate(message.created_at, 'datetime');

        if (message.sender === 'agent') {
            row.className = 'rh-msg rh-msg-agent';
        } else if (message.sender === 'system') {
            row.className = 'rh-msg rh-msg-system';
        } else {
            row.className = 'rh-msg rh-msg-visitor';
        }

        row.appendChild(bubble);
        row.appendChild(time);

        // 상담원이 직접 쓴 글은 매크로로 저장할 수 있다(docs/macro-plan.md M4). 카드와 자동 응답은 빼고.
        if (message.sender === 'agent' && !message.meta && window.RHMacroEditor) {
            var save = document.createElement('button');
            save.type = 'button';
            save.className = 'rh-msg-save';
            save.textContent = '매크로로 저장';
            save.addEventListener('click', function () { saveAsMacro(message.body); });
            time.appendChild(save);
        }

        return row;
    }

    /**
     * 보낸 글로 매크로를 만든다. 고객 이름이 들어 있으면 변수로 바꾸라고 안내만 한다(우연히 같은 낱말일 수 있다).
     */
    function saveAsMacro(body) {
        var name = current && current.visitor_name;
        var hint = name && body.indexOf(name) >= 0
            ? '내용에 고객 이름(' + name + ')이 들어 있습니다. [고객 이름] 변수로 바꾸면 다른 고객에게도 맞게 들어갑니다.'
            : '';

        RHMacroEditor.open({ body: body, hint: hint });
    }

    /**
     * 가이드 카드 메시지. 상담원이 보냈으면 '보낸 가이드', 위젯이 자동으로 붙였으면 '자동 추천됨'.
     */
    function fillGuideCard(bubble, message) {
        var meta = message.meta;
        var label = document.createElement('div');
        label.className = 'rh-bubble-label';
        label.textContent = message.sender === 'system' ? '자동 추천됨' : '보낸 가이드';

        var title = document.createElement('a');
        title.className = 'rh-bubble-link';
        title.textContent = meta.title || meta.url;
        title.target = '_blank';
        title.rel = 'noopener';

        if (/^https?:\/\//.test(meta.url || '')) {
            title.href = meta.url;
        }

        bubble.appendChild(label);
        bubble.appendChild(title);
    }

    function appendMessages(messages) {
        if (!messages.length) {
            return;
        }

        var box = document.getElementById('chatMessages');
        var fromVisitor = false;

        messages.forEach(function (message) {
            if (message.id <= lastId) {
                return;
            }

            lastId = message.id;
            box.appendChild(messageNode(message));
            fromVisitor = fromVisitor || message.sender === 'visitor';
        });

        box.scrollTop = box.scrollHeight;

        // 방문자가 새로 말하면 추천 가이드를 다시 찾는다.
        if (fromVisitor) {
            scheduleGuides();
        }
    }

    // ------------------------------------------------------------------
    // 추천 가이드
    // ------------------------------------------------------------------

    function scheduleGuides() {
        window.clearTimeout(guideTimer);
        guideTimer = window.setTimeout(loadGuides, 300);
    }

    function loadGuides() {
        if (!current) {
            return;
        }

        var id = current.id;
        var q = $.trim($('#chatGuideSearch').val());
        var url = q
            ? CHAT_URLS.guideSearch + '?' + $.param({ q: q, conversation_id: id })
            : CHAT_URLS.guideSuggest + id;

        $('#chatGuideTitle').text(q ? '가이드 검색' : '추천 가이드');

        apiGet(url, function (data) {
            if (!current || current.id !== id) {
                return;
            }

            renderGuides(data.items, q);
        });
    }

    function renderGuides(items, q) {
        var $list = $('#chatGuideList').empty();

        if (!items.length) {
            var $empty = $('<li class="rh-guide-empty">')
                .text(q ? '맞는 가이드가 없습니다.' : '방문자 메시지에 맞는 가이드가 없습니다.');

            if (CHAT_URLS.guideAdmin) {
                $empty.append(' ').append($('<a>')
                    .attr('href', CHAT_URLS.guideAdmin).attr('target', '_blank').text('가이드'));
            }

            $list.append($empty);
            return;
        }

        var closed = current.status !== 'open';

        items.forEach(function (item) {
            var $li = $('<li class="rh-guide-item">');
            var $link = $('<a class="min-w-0 flex-grow-1 text-truncate" target="_blank" rel="noopener">')
                .attr('href', item.url).attr('title', item.subtitle || item.title).text(item.title);
            var $btn = $('<button type="button">')
                .attr('class', RHUi.button(item.sent ? 'ghost' : 'secondary') + ' flex-shrink-0')
                .text(item.sent ? '다시 보내기' : '보내기')
                .prop('disabled', closed)
                .on('click', function () { sendGuide(item, $btn); });

            $li.append($link, $('<span class="rh-guide-score">').text(item.score ? item.score + '점' : ''), $btn);
            $list.append($li);
        });
    }

    function sendGuide(item, $btn) {
        if (!current) {
            return;
        }

        $btn.prop('disabled', true);

        apiPost(CHAT_URLS.sendGuide, { conversation_id: current.id, article_id: item.id }, function (data, message) {
            showToast(message, 'success');
            notifyFollowup(data);
            pollMessages();
            refreshList();
            loadGuides();
        }).always(function () { $btn.prop('disabled', false); });
    }

    /**
     * 입력창 / 매크로 목록. 변수 값은 고를 때의 대화로 채운다.
     */
    function bindMacros() {
        if (!window.RHMacroPicker) {
            return;
        }

        RHMacroPicker.attach({
            input: document.getElementById('chatInput'),
            popup: document.getElementById('chatMacroPicker'),
            button: document.getElementById('chatMacroButton'),
            listUrl: CHAT_URLS.macroList,
            suggestUrl: CHAT_URLS.macroSuggest,
            manageUrl: CHAT_URLS.macroAdmin,
            context: function () {
                return {
                    conversationId: current ? current.id : 0,
                    values: {
                        visitor_name: (current && current.visitor_name) || CHAT_MACRO.visitorFallback,
                        agent_name: CHAT_ME.name,
                        org_name: CHAT_MACRO.orgName
                    }
                };
            }
        });
    }

    function select(row) {
        if (current && current.id !== row.id) {
            sendPresence(current.id, 'leave');
        }

        closeNotification(row.id);
        if (window.RHMacroPicker) { RHMacroPicker.reset(); }
        current = row;
        lastId = 0;
        $('#chatMessages').empty();
        $('#chatEmpty').addClass('d-none');
        $('#chatPanel').removeClass('d-none');
        renderHeader();
        $('#chatGuideSearch').val('');
        $('#chatGuideList').empty();
        pollMessages();
        loadGuides();
        sendPresence(row.id, 'viewing');
    }

    function pollMessages() {
        if (!current) {
            return;
        }

        var id = current.id;

        apiGet(CHAT_URLS.messages + id + '?after_id=' + lastId, function (data) {
            // 응답이 오는 사이 다른 대화로 옮겼으면 버린다.
            if (!current || current.id !== id) {
                return;
            }

            current.status = data.conversation.status;
            current.session_id = data.conversation.session_id;
            current.assigned_agent_id = data.conversation.assigned_agent_id;
            appendMessages(data.messages);
            renderHeader();
        });
    }

    function send() {
        var $input = $('#chatInput');
        var body = $.trim($input.val());

        if (!current || !body) {
            return;
        }

        $input.val('');
        doSend(current.id, body, '', window.RHMacroPicker ? RHMacroPicker.takeMacroId() : 0);
    }

    /**
     * mode: '' 처음 보내기, join 함께 답하기, take 담당 가져오고 보내기.
     * 다른 상담원이 담당이면 서버가 409 와 담당자 이름을 돌려주고, 여기서 확인 창을 띄운다.
     */
    function doSend(conversationId, body, mode, macroId) {
        apiPost(CHAT_URLS.send, { conversation_id: conversationId, body: body, mode: mode, macro_id: macroId || 0 }, function (data) {
            notifyFollowup(data);
            // 보냈으니 다른 상담원 화면의 '입력 중' 을 바로 거둔다.
            lastTypingAt = 0;
            sendPresence(conversationId, 'viewing');
            pollMessages();
            refreshList();
        }, function (message, res) {
            if (res && res.data && res.data.conflict) {
                pendingSend = { conversationId: conversationId, body: body, macroId: macroId };
                $('#chatConflictBody').text((res.data.agent_name || '다른 상담원') + ' 님이 이 대화를 담당하고 있습니다. '
                    + '고객이 두 사람에게 답을 받지 않도록 확인해 주세요.');
                openModal('chatConflictModal');
                $('#chatInput').val(body);
                return;
            }

            showToast(message, 'error');
            $('#chatInput').val(body);
        });
    }

    /** 고객이 자리를 비워 답장을 메일로 알렸으면 상담원에게 알려 준다(목록 갱신이 헤더 표시도 바꾼다). */
    function notifyFollowup(data) {
        if (data && data.followup) {
            showToast('고객이 자리를 비워 답장을 이메일로 알렸습니다.', 'info');
        }
    }

    function resend(mode) {
        closeModal('chatConflictModal');

        if (!pendingSend) {
            return;
        }

        var job = pendingSend;
        pendingSend = null;

        if (current && current.id === job.conversationId) {
            $('#chatInput').val('');
        }

        doSend(job.conversationId, job.body, mode, job.macroId);
    }

    // ------------------------------------------------------------------
    // 실시간 구독
    // ------------------------------------------------------------------

    /**
     * 실시간 메시지 서버에 붙는다. 끊기면 폴링이 이어받고, 잠시 뒤 다시 시도한다.
     */
    function connectRealtime() {
        if (!CHAT_REALTIME || !CHAT_REALTIME.url || !window.WebSocket) {
            return;
        }

        if (socket && (socket.readyState === 0 || socket.readyState === 1)) {
            return;
        }

        try {
            socket = new WebSocket(CHAT_REALTIME.url + '?token=' + encodeURIComponent(CHAT_REALTIME.token));
        } catch (e) {
            return;
        }

        socket.onopen = function () {
            live = true;
            retry = 0;
            restartTimers();
            setLiveBadge(true);
        };

        socket.onmessage = function (event) {
            var payload;

            try {
                payload = JSON.parse(event.data);
            } catch (e) {
                return;
            }

            if (payload.type === 'message') {
                // 열어 둔 대화면 바로 붙이고, 아니면 목록만 갱신한다.
                if (current && payload.conversation_id === current.id) {
                    appendMessages([payload.message]);
                }

                refreshList();
                return;
            }

            if (payload.type === 'conversation') {
                refreshList();
                return;
            }

            if (payload.type === 'presence') {
                onPresence(payload);
                return;
            }

            if (payload.type === 'assignment') {
                onAssignment(payload);
            }
        };

        socket.onclose = function () {
            live = false;
            socket = null;
            setLiveBadge(false);
            restartTimers();

            retry = Math.min(retry + 1, 5);
            window.setTimeout(connectRealtime, Math.min(1000 * Math.pow(2, retry), 30000));
        };

        socket.onerror = function () {
            // onclose 에서 재연결을 처리한다.
        };
    }

    function setLiveBadge(on) {
        $('#chatLive')
            .attr('class', RHUi.badge(on ? 'green' : 'gray'))
            .text(on ? '실시간 연결됨' : '연결 확인 중');
    }

    function restartTimers() {
        window.clearInterval(listTimer);
        window.clearInterval(messageTimer);

        listTimer = window.setInterval(refreshList, live ? LIST_INTERVAL_LIVE_MS : LIST_INTERVAL_MS);
        messageTimer = window.setInterval(pollMessages, live ? MESSAGE_INTERVAL_LIVE_MS : MESSAGE_INTERVAL_MS);
    }

    // ------------------------------------------------------------------

    $(function () {
        grid = RHGrid.create('chatGrid', {
            columns: columns(),
            rows: CHAT_ROWS,
            rowId: function (row) { return row.id; },
            rowClass: function () { return 'rh-clickable'; },
            onRowClicked: function (row) { select(row); }
        });

        $('#chatForm').on('submit', function (event) {
            event.preventDefault();
            send();
        });

        $('#chatInput').on('keydown', function (event) {
            // 매크로 목록이 열려 있으면 화살표·Enter·Esc 는 목록이 쓴다.
            if (window.RHMacroPicker && RHMacroPicker.handleKey(event)) {
                return;
            }

            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                send();
            }
        });

        $('#chatClose').on('click', function () {
            if (!current) {
                return;
            }

            showConfirmModal('대화 종료', '이 대화를 종료할까요? 방문자는 계속 새 대화를 시작할 수 있습니다.', function () {
                apiPost(CHAT_URLS.close, { conversation_id: current.id }, function (data, message) {
                    showToast(message, 'success');
                    current.status = 'closed';
                    renderHeader();
                    refreshList();
                });
            });
        });

        $('#chatRemote').on('click', function () {
            if (!current) {
                return;
            }

            showConfirmModal('원격지원 시작',
                '이 방문자에게 원격지원 코드를 발급하고 대화에 안내를 남깁니다.',
                function () {
                    apiPost(CHAT_URLS.remote, { conversation_id: current.id }, function (data, message) {
                        showToast(message + ' 코드 ' + data.code, 'success');
                        current.session_id = data.session_id;
                        renderHeader();
                        pollMessages();
                    });
                });
        });

        $('#chatViewer').on('click', function () {
            if (current && current.session_id) {
                window.open(CHAT_URLS.viewer + current.session_id, '_blank');
            }
        });

        $('#chatShowClosed').on('change', refreshList);

        $('#chatGuideSearch').on('input', scheduleGuides);

        bindAvailability();
        bindDuplicateGuard();
        bindMacros();

        connectRealtime();
        restartTimers();
        refreshList();
    });
})(jQuery);
