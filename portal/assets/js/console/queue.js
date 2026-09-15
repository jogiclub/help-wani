/**
 * 파일 위치: assets/js/console/queue.js
 * 역할: 세션 코드 발급과 대기열 상태 폴링
 */
(function ($) {
    'use strict';

    var POLL_INTERVAL = 3000;
    var codeExpiresAt = null;

    function statusBadge(status) {
        var map = {
            issued: 'secondary',
            verified: 'info',
            waiting: 'warning',
            connected: 'success',
            ended: 'dark',
            expired: 'light',
            canceled: 'light'
        };
        return map[status] || 'secondary';
    }

    function renderRows(sessions) {
        var $tbody = $('#sessionTbody');
        $tbody.empty();

        if (!sessions.length) {
            $tbody.append('<tr><td colspan="7" class="text-center text-muted py-4">진행 중인 상담이 없습니다.</td></tr>');
            return;
        }

        sessions.forEach(function (s) {
            var $tr = $('<tr>');
            $tr.append($('<td class="fw-bold">').text(s.code));
            $tr.append($('<td>').html('<span class="badge text-bg-' + statusBadge(s.status) + '"></span>')
                .find('.badge').text(s.status_label).end());
            $tr.append($('<td>').text(s.pc_name || '-'));
            $tr.append($('<td class="small text-muted">').text(s.customer_ip || '-'));
            $tr.append($('<td>').text(s.agent_name));
            $tr.append($('<td>').text(s.status === 'issued' ? formatRemain(s.remain_sec) : '-'));

            var $actions = $('<td>');

            if (s.status === 'waiting' || s.status === 'connected') {
                $('<a class="btn btn-sm btn-success me-1">원격 시작</a>')
                    .attr('href', CONSOLE_URLS.viewer + s.id)
                    .appendTo($actions);
            }

            if (['ended', 'expired', 'canceled'].indexOf(s.status) === -1) {
                $('<button class="btn btn-sm btn-outline-danger">종료</button>')
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
            $('#codeRemain').removeClass('text-bg-secondary').addClass('text-bg-danger').text('만료');
        }
    }

    $(function () {
        $('#btnCreate').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            apiPost(CONSOLE_URLS.create, {}, function (data, message) {
                $('#codeCard').removeClass('d-none');
                $('#codeValue').text(data.code);
                $('#customerUrl').text(data.customer_url);
                $('#codeRemain').removeClass('text-bg-danger').addClass('text-bg-secondary');
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
