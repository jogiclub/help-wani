/**
 * 파일 위치: assets/js/console/history.js
 * 역할: 상담 이력 그리드
 */
(function ($) {
    'use strict';

    var STATUS_CLASS = {
        connected: 'badge-green',
        ended: 'badge-dark',
        expired: 'badge-gray',
        canceled: 'badge-gray',
        issued: 'badge-gray',
        verified: 'badge-blue',
        waiting: 'badge-amber'
    };

    /** 초를 "12분 34초" 로 */
    function formatDuration(seconds) {
        seconds = parseInt(seconds, 10) || 0;

        if (seconds <= 0) {
            return '-';
        }

        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return m > 0 ? (m + '분 ' + s + '초') : (s + '초');
    }

    $(function () {
        RHGrid.create('historyGrid', {
            columnDefs: [
                { headerName: '일시', field: 'created_at', width: 160, flex: 0, valueFormatter: RHGrid.dateTime },
                { headerName: '코드', field: 'code', width: 100, flex: 0, cellClass: 'font-mono' },
                { headerName: '상담원', field: 'agent_name', width: 120, flex: 0 },
                { headerName: '고객 PC', field: 'pc_name', minWidth: 150,
                  valueFormatter: function (p) { return p.value || '-'; } },
                { headerName: '고객 IP', field: 'customer_ip', width: 140, flex: 0,
                  valueFormatter: function (p) { return p.value || '-'; } },
                {
                    headerName: '상태', field: 'status', width: 110, flex: 0,
                    cellRenderer: function (params) {
                        var span = document.createElement('span');
                        span.className = 'badge ' + (STATUS_CLASS[params.value] || 'badge-gray');
                        span.textContent = params.data.status_label || params.value;
                        return span;
                    },
                    valueFormatter: function (p) { return p.data.status_label || p.value; }
                },
                {
                    headerName: '소요시간', field: 'duration', width: 110, flex: 0,
                    filter: 'agNumberColumnFilter',
                    valueFormatter: function (p) { return formatDuration(p.value); }
                },
                {
                    headerName: '만족도', field: 'survey_score', width: 100, flex: 0,
                    filter: 'agNumberColumnFilter',
                    cellRenderer: function (params) {
                        if (!params.value) { return '-'; }

                        var span = document.createElement('span');
                        span.className = 'text-amber-500';
                        span.textContent = '★'.repeat(params.value);
                        return span;
                    }
                },
                {
                    headerName: '상세', width: 90, flex: 0, sortable: false, filter: false, pinned: 'right',
                    cellRenderer: RHGrid.buttonsRenderer([
                        {
                            label: '보기', className: 'btn-secondary',
                            onClick: function (row) {
                                window.location.href = HISTORY_VIEWER_URL + row.id;
                            }
                        }
                    ])
                }
            ],
            rowData: HISTORY_ROWS,
            overlayNoRowsTemplate: '<span class="text-slate-400">검색 결과가 없습니다.</span>'
        });
    });
})(jQuery);
