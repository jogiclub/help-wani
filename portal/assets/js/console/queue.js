/**
 * 파일 위치: assets/js/console/queue.js
 * 역할: 세션 코드 발급과 대기열 그리드 갱신
 */
(function ($) {
    'use strict';

    var POLL_INTERVAL = 3000;
    var codeExpiresAt = null;
    var grid = null;

    var STATUS_CLASS = {
        issued: 'badge-gray',
        verified: 'badge-blue',
        waiting: 'badge-amber',
        connected: 'badge-green',
        ended: 'badge-dark',
        expired: 'badge-gray',
        canceled: 'badge-gray'
    };

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
            if (grid) {
                // 갱신마다 그리드를 다시 만들지 않고 행 데이터만 바꾼다(정렬/필터 유지).
                grid.setGridOption('rowData', data.sessions || []);
            }
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
        grid = RHGrid.create('sessionGrid', {
            columnDefs: [
                {
                    headerName: '코드', field: 'code', width: 110, flex: 0,
                    cellClass: 'font-mono font-semibold'
                },
                {
                    headerName: '상태', field: 'status', width: 120, flex: 0,
                    // 표시 문구는 서버가 내려준 status_label 을 쓴다.
                    cellRenderer: function (params) {
                        var span = document.createElement('span');
                        span.className = 'badge ' + (STATUS_CLASS[params.value] || 'badge-gray');
                        span.textContent = params.data.status_label || params.value;
                        return span;
                    },
                    valueFormatter: function (p) { return p.data.status_label || p.value; }
                },
                { headerName: '고객 PC', field: 'pc_name', minWidth: 150,
                  valueFormatter: function (p) { return p.value || '-'; } },
                { headerName: '고객 IP', field: 'customer_ip', width: 140, flex: 0,
                  valueFormatter: function (p) { return p.value || '-'; } },
                { headerName: '담당', field: 'agent_name', width: 110, flex: 0 },
                {
                    headerName: '남은 시간', field: 'remain_sec', width: 110, flex: 0,
                    valueFormatter: function (p) {
                        return p.data.status === 'issued' ? formatRemain(p.value) : '-';
                    }
                },
                { headerName: '시작', field: 'started_at', width: 130, flex: 0,
                  valueFormatter: RHGrid.shortDateTime },
                {
                    headerName: '작업', width: 160, flex: 0, sortable: false, filter: false, pinned: 'right',
                    cellRenderer: RHGrid.buttonsRenderer([
                        {
                            label: '원격 시작', className: 'btn-success',
                            show: function (row) {
                                return row.status === 'waiting' || row.status === 'connected';
                            },
                            onClick: function (row) {
                                window.location.href = CONSOLE_URLS.viewer + row.id;
                            }
                        },
                        {
                            label: '종료', className: 'btn-secondary',
                            show: function (row) {
                                return ['ended', 'expired', 'canceled'].indexOf(row.status) === -1;
                            },
                            onClick: function (row) { endSession(row.id); }
                        }
                    ])
                }
            ],
            rowData: [],
            getRowId: function (params) { return String(params.data.id); },
            overlayNoRowsTemplate: '<span class="text-slate-400">진행 중인 상담이 없습니다.</span>'
        });

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
