/**
 * 파일 위치: assets/js/console/chat.js
 * 역할: 상담 위젯 대화 목록과 응답 패널 (폴링), 추천 가이드 보내기
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

    var STATUS_CLASS = { open: 'badge-green', closed: 'badge-gray' };
    var STATUS_LABEL = { open: '진행 중', closed: '종료' };

    var grid = null;
    var current = null;
    var lastId = 0;
    var socket = null;
    var live = false;
    var retry = 0;
    var listTimer = null;
    var messageTimer = null;
    var guideTimer = null;

    // ------------------------------------------------------------------
    // 목록
    // ------------------------------------------------------------------

    function columnDefs() {
        return [
            {
                headerName: '방문자', field: 'visitor_name', minWidth: 140,
                cellRenderer: function (params) {
                    var wrap = document.createElement('div');
                    wrap.className = 'flex h-full items-center gap-2';

                    var name = document.createElement('span');
                    name.textContent = params.value || '이름 없음';
                    wrap.appendChild(name);

                    if (params.data.unread > 0) {
                        var badge = document.createElement('span');
                        badge.className = 'badge badge-red';
                        badge.textContent = params.data.unread;
                        wrap.appendChild(badge);
                    }

                    return wrap;
                }
            },
            {
                headerName: '마지막 메시지', field: 'last_body', minWidth: 200,
                valueFormatter: function (p) { return p.value || '-'; }
            },
            {
                headerName: '상태', field: 'status', width: 100, flex: 0,
                cellRenderer: RHGrid.badgeRenderer(STATUS_CLASS, STATUS_LABEL),
                valueFormatter: function (p) { return STATUS_LABEL[p.value] || p.value; }
            },
            { headerName: '담당', field: 'agent_name', width: 110, flex: 0,
              valueFormatter: function (p) { return p.value || '-'; } },
            { headerName: '최근', field: 'last_message_at', width: 150, flex: 0,
              valueFormatter: RHGrid.dateTime }
        ];
    }

    function refreshList() {
        var params = { status: $('#chatShowClosed').is(':checked') ? '' : 'open' };

        apiGet(CHAT_URLS.list + '?' + $.param(params), function (data) {
            if (grid) {
                grid.setGridOption('rowData', data.conversations);
            }

            var $badge = $('#chatUnread');

            if (data.unread_total > 0) {
                $badge.text('미확인 ' + data.unread_total + '건').removeClass('hidden');
            } else {
                $badge.addClass('hidden');
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
    // 대화 패널
    // ------------------------------------------------------------------

    function renderHeader() {
        $('#chatVisitor').text(current.visitor_name || '이름 없음');

        var meta = [];

        if (current.visitor_email) { meta.push(current.visitor_email); }
        if (current.page_url) { meta.push(current.page_url); }

        $('#chatMeta').text(meta.join(' · ') || '연락처 없음');

        var closed = current.status !== 'open';
        $('#chatViewer').toggleClass('hidden', !current.session_id);
        $('#chatClose').prop('disabled', closed);
        $('#chatRemote').prop('disabled', closed);
        $('#chatInput').prop('disabled', closed);
    }

    function messageNode(message) {
        var row = document.createElement('div');
        var bubble = document.createElement('div');
        var time = document.createElement('div');

        bubble.className = 'inline-block max-w-[85%] whitespace-pre-wrap break-words rounded-lg px-3 py-2 text-sm';

        if (message.meta && message.meta.type === 'guide') {
            fillGuideCard(bubble, message);
        } else {
            bubble.textContent = message.body;
        }

        time.className = 'mt-1 text-[11px] text-slate-400';
        time.textContent = RHI18n.formatDate(message.created_at, 'datetime');

        if (message.sender === 'agent') {
            row.className = 'text-right';
            bubble.className += ' bg-blue-600 text-white';
        } else if (message.sender === 'system') {
            row.className = 'text-center';
            bubble.className += ' border border-amber-200 bg-amber-50 text-amber-900';
        } else {
            row.className = 'text-left';
            bubble.className += ' border border-slate-200 bg-white text-slate-800';
        }

        row.appendChild(bubble);
        row.appendChild(time);

        return row;
    }

    /**
     * 가이드 카드 메시지. 상담원이 보냈으면 '보낸 가이드', 위젯이 자동으로 붙였으면 '자동 추천됨'.
     */
    function fillGuideCard(bubble, message) {
        var meta = message.meta;
        var label = document.createElement('div');
        label.className = 'text-[11px] opacity-75';
        label.textContent = message.sender === 'system' ? '자동 추천됨' : '보낸 가이드';

        var title = document.createElement('a');
        title.className = 'block font-semibold underline';
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
            var $empty = $('<li class="text-xs text-slate-400">')
                .text(q ? '맞는 가이드가 없습니다.' : '방문자 메시지에 맞는 가이드가 없습니다.');

            if (CHAT_URLS.guideAdmin) {
                $empty.append(' ').append($('<a class="text-brand-600 hover:underline">')
                    .attr('href', CHAT_URLS.guideAdmin).attr('target', '_blank').text('가이드 관리'));
            }

            $list.append($empty);
            return;
        }

        var closed = current.status !== 'open';

        items.forEach(function (item) {
            var $li = $('<li class="flex items-center gap-2 text-sm">');
            var $link = $('<a class="min-w-0 flex-1 truncate text-slate-700 hover:underline" target="_blank" rel="noopener">')
                .attr('href', item.url).attr('title', item.subtitle || item.title).text(item.title);
            var $btn = $('<button type="button" class="btn btn-sm shrink-0">')
                .addClass(item.sent ? 'btn-ghost' : 'btn-secondary')
                .text(item.sent ? '다시 보내기' : '보내기')
                .prop('disabled', closed)
                .on('click', function () { sendGuide(item, $btn); });

            $li.append($link, $('<span class="shrink-0 text-[11px] text-slate-400">').text(item.score ? item.score + '점' : ''), $btn);
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
            pollMessages();
            refreshList();
            loadGuides();
        }).always(function () { $btn.prop('disabled', false); });
    }

    function select(row) {
        current = row;
        lastId = 0;
        $('#chatMessages').empty();
        $('#chatEmpty').addClass('hidden');
        $('#chatPanel').removeClass('hidden').addClass('flex');
        renderHeader();
        $('#chatGuideSearch').val('');
        $('#chatGuideList').empty();
        pollMessages();
        loadGuides();
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

        apiPost(CHAT_URLS.send, { conversation_id: current.id, body: body }, function () {
            pollMessages();
            refreshList();
        }, function (message) {
            showToast(message, 'error');
            $input.val(body);
        });
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
            .toggleClass('badge-green', on)
            .toggleClass('badge-gray', !on)
            .text(on ? '실시간 연결됨' : '연결 확인 중')
            .removeClass('hidden');
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
            columnDefs: columnDefs(),
            rowData: CHAT_ROWS,
            rowClass: 'cursor-pointer',
            onRowClicked: function (event) { select(event.data); }
        });

        $('#chatForm').on('submit', function (event) {
            event.preventDefault();
            send();
        });

        $('#chatInput').on('keydown', function (event) {
            if (event.key === 'Enter' && !event.shiftKey) {
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

        connectRealtime();
        restartTimers();
        refreshList();
    });
})(jQuery);
