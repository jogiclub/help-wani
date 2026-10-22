/**
 * 파일 위치: assets/js/console/queue.js
 * 역할: 원격지원 화면 - 코드 발급, 검색 조건에 맞는 원격 세션 목록 3초 갱신
 *       (예전 원격 대기열과 원격 이력을 합쳤다)
 */
(function ($) {
    'use strict';

    var POLL_INTERVAL = 3000;
    var codeExpiresAt = null;
    var grid = null;
    var loading = false;
    var requestSeq = 0;
    var ACTIVE = ['issued', 'verified', 'waiting', 'connected'];

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
                loadSessions(true);
            });
        });
    }

    /** 검색 조건을 쿼리 문자열로. 빈 값은 뺀다. */
    function filterQuery() {
        return $('#filterForm').serializeArray()
            .filter(function (f) { return f.value !== ''; })
            .map(function (f) { return encodeURIComponent(f.name) + '=' + encodeURIComponent(f.value); })
            .join('&');
    }

    /**
     * force: 검색 조건을 바꿨을 때. 진행 중인 요청이 있어도 새로 보내고, 늦게 온 예전 응답은 버린다.
     */
    function loadSessions(force) {
        // 폴링은 응답이 늦을 때 요청이 쌓이지 않게 한다.
        if (loading && !force) { return; }
        loading = true;

        var seq = ++requestSeq;
        var query = filterQuery();

        apiGet(CONSOLE_URLS.list + (query ? '?' + query : ''), function (data) {
            if (seq !== requestSeq) { return; }
            if (grid) {
                // 갱신마다 그리드를 다시 만들지 않고 행 데이터만 바꾼다(정렬/필터/페이지/스크롤 유지).
                grid.setRows(data.sessions || []);
            }
        }).always(function () {
            if (seq === requestSeq) { loading = false; }
        });
    }

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
            columns: [
                { title: '일시', key: 'created_at', width: 150,
                  format: RHGrid.shortDateTime },
                { title: '코드', key: 'code', width: 100, cellClass: 'font-mono font-semibold' },
                {
                    title: '상태', key: 'status', width: 130,
                    // 표시 문구는 서버가 내려준 status_label 을 쓴다.
                    render: function (row, value) {
                        var span = document.createElement('span');
                        span.className = 'badge ' + (STATUS_CLASS[value] || 'badge-gray');
                        span.textContent = row.status_label || value;
                        return span;
                    },
                    format: function (v, row) { return row.status_label || v; }
                },
                { title: '고객 PC', key: 'pc_name', width: 150, flex: 1,
                  format: function (v) { return v || '-'; } },
                { title: '고객 IP', key: 'customer_ip', width: 140,
                  format: function (v) { return v || '-'; } },
                { title: '담당', key: 'agent_name', width: 110 },
                {
                    // 코드 발급 중이면 남은 시간, 끝난 세션이면 소요시간
                    title: '시간', key: 'duration', width: 110, sortable: false, filterable: false,
                    format: function (v, row) {
                        if (row.status === 'issued') { return '남음 ' + formatRemain(row.remain_sec); }
                        return formatDuration(v);
                    }
                },
                {
                    title: '만족도', key: 'survey_score', width: 100, type: 'number', align: 'center',
                    render: function (row, value) {
                        if (!value) { return '-'; }

                        var span = document.createElement('span');
                        span.className = 'text-amber-500';
                        span.textContent = '★'.repeat(value);
                        return span;
                    }
                },
                {
                    title: '작업', key: '_actions', width: 160, sortable: false, pinned: 'right',
                    render: RHGrid.buttonsRenderer([
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
                            show: function (row) { return ACTIVE.indexOf(row.status) !== -1; },
                            onClick: function (row) { endSession(row.id); }
                        },
                        {
                            label: '보기', className: 'btn-secondary',
                            show: function (row) { return ACTIVE.indexOf(row.status) === -1; },
                            onClick: function (row) {
                                window.location.href = CONSOLE_URLS.viewer + row.id;
                            }
                        }
                    ])
                }
            ],
            rows: [],
            sort: [{ key: 'created_at', dir: 'desc' }],
            rowId: function (row) { return row.id; },
            noRowsText: '조건에 맞는 원격지원이 없습니다.'
        });

        $('#btnCreate').on('click', function () {
            var $btn = $(this).prop('disabled', true);

            apiPost(CONSOLE_URLS.create, {}, function (data, message) {
                $('#codeCard').removeClass('hidden');
                $('#codeValue').text(data.code);
                $('#customerUrl').text(data.customer_url).attr('href', data.customer_url);
                $('#codeRemain').removeClass('badge-red').addClass('badge-gray');
                codeExpiresAt = Date.now() + (data.expires_in * 1000);
                tickCode();
                showToast(message, 'success');
                loadSessions(true);
            }).always(function () { $btn.prop('disabled', false); });
        });

        // 고객에게 보낼 주소 복사. http 로 연 화면은 clipboard API 가 없어 선택 후 복사로 대신한다.
        $('#btnCopyUrl').on('click', function () {
            var url = $('#customerUrl').attr('href');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(function () { showToast('주소를 복사했습니다.', 'success'); });
                return;
            }
            var $tmp = $('<textarea>').val(url).appendTo('body').trigger('select');
            document.execCommand('copy');
            $tmp.remove();
            showToast('주소를 복사했습니다.', 'success');
        });

        // 검색 조건은 주소에도 남겨 새로고침하거나 링크를 공유해도 같은 목록이 보이게 한다.
        $('#filterForm').on('submit', function (e) {
            e.preventDefault();
            var query = filterQuery();
            window.history.replaceState(null, '', window.location.pathname + (query ? '?' + query : ''));
            loadSessions(true);
        });

        $('#btnReset').on('click', function () {
            $('#filterForm')[0].reset();
            $('#filterForm').find('input, select').val('');
            $('#filterForm').trigger('submit');
        });

        loadSessions();
        setInterval(function () { loadSessions(false); }, POLL_INTERVAL);
        setInterval(tickCode, 1000);
    });
})(jQuery);
