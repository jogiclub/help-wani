/**
 * 파일 위치: assets/js/console/queue.js
 * 역할: 세션 코드 발급과 대기열 상태 폴링
 */
(function ($) {
    'use strict';

    var POLL_INTERVAL = 3000;
    var codeExpiresAt = null;

    var BADGE_CLASS = {
        issued: 'badge-gray',
        verified: 'badge-blue',
        waiting: 'badge-amber',
        connected: 'badge-green',
        ended: 'badge-dark',
        expired: 'badge-gray',
        canceled: 'badge-gray'
    };

    function renderRows(sessions) {
        var $tbody = $('#sessionTbody');
        $tbody.empty();

        if (!sessions.length) {
            $tbody.append('<tr><td colspan="7" class="py-8 text-center text-slate-400">' +
                '진행 중인 상담이 없습니다.</td></tr>');
            return;
        }

        sessions.forEach(function (s) {
            var $tr = $('<tr>');

            $tr.append($('<td>').addClass('font-mono font-semibold').text(s.code));
            $tr.append($('<td>').append(
                $('<span>').addClass('badge ' + (BADGE_CLASS[s.status] || 'badge-gray')).text(s.status_label)
            ));
            $tr.append($('<td>').text(s.pc_name || '-'));
            $tr.append($('<td>').addClass('text-xs text-slate-500').text(s.customer_ip || '-'));
            $tr.append($('<td>').text(s.agent_name));
            $tr.append($('<td>').text(s.status === 'issued' ? formatRemain(s.remain_sec) : '-'));

            var $actions = $('<td>').addClass('whitespace-nowrap');

            if (s.status === 'waiting' || s.status === 'connected') {
                $('<a>').addClass('btn btn-sm btn-success mr-1')
                    .attr('href', CONSOLE_URLS.viewer + s.id)
                    .text('원격 시작')
                    .appendTo($actions);
            }

            if (['ended', 'expired', 'canceled'].indexOf(s.status) === -1) {
                $('<button type="button">').addClass('btn btn-sm btn-secondary')
                    .text('종료')
                    .on('click', function () { endSession(s.id); })
                    .appendTo($actions);
            }

            $tr.append($actions);
            $tbody.append($tr);
        });
    }

    function endSession(id) {
        showConfirmModal('세션 종료', '이 세션을 종료할까요? 고객 PC 의 원격 연결이 즉시 해제됩니다.', function () {
            apiPost(CONSOLE_URLS.end, { session_id: id }, function (data, message) {
                showToast(message, 'success');
                loadSessions();
            });
        });
    }

    function loadSessions() {
        apiGet(CONSOLE_URLS.list, function (data) {
            renderRows(data.sessions || []);
        });
    }

    function tickCode() {
        if (!codeExpiresAt) { return; }

        var remain = Math.floor((codeExpiresAt - Date.now()) / 1000);
        $('#codeRemain').text(formatRemain(remain));

        if (remain <= 0) {
            codeExpiresAt = null;
            $('#codeRemain').removeClass('badge-gray').addClass('badge-red').text('만료');
        }
    }

    $(function () {
        $('#btnCreate').on('click', function () {
            var $btn = $(this).prop('disabled', true);

            apiPost(CONSOLE_URLS.create, {}, function (data, message) {
                $('#codeCard').removeClass('hidden');
                $('#codeValue').text(data.code);
                $('#customerUrl').text(data.customer_url);
                $('#codeRemain').removeClass('badge-red').addClass('badge-gray');
                codeExpiresAt = Date.now() + (data.expires_in * 1000);
                tickCode();
                showToast(message, 'success');
                loadSessions();
            }).always(function () { $btn.prop('disabled', false); });
        });

        loadSessions();
        setInterval(loadSessions, POLL_INTERVAL);
        setInterval(tickCode, 1000);
    });
})(jQuery);
