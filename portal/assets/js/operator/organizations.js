/**
 * 파일 위치: assets/js/operator/organizations.js
 * 역할: 조직 가입 관리 그리드와 승인/반려/중지 처리
 */
(function ($) {
    'use strict';

    var STATUS_CLASS = {
        pending: 'badge-amber',
        active: 'badge-green',
        rejected: 'badge-red',
        suspended: 'badge-dark'
    };

    var STATUS_LABEL = {
        pending: '승인 대기',
        active: '사용 중',
        rejected: '반려',
        suspended: '이용 중지'
    };

    function post(url, data) {
        apiPost(url, data, function (res, message) {
            showToast(message, 'success');
            setTimeout(function () { window.location.reload(); }, 800);
        });
    }

    /**
     * 사유가 필요한 작업은 모달로 입력받는다.
     */
    function askReason(title, desc, onConfirm) {
        $('#reasonModalTitle').text(title);
        $('#reasonModalDesc').text(desc);
        $('#reasonText').val('');
        openModal('reasonModal');

        $('#reasonModalOk').off('click').on('click', function () {
            var reason = $('#reasonText').val().trim();

            if (!reason) {
                showToast('사유를 입력해 주세요.', 'warning');
                return;
            }

            closeModal('reasonModal');
            onConfirm(reason);
        });
    }

    $(function () {
        var columnDefs = [
            {
                headerName: '조직명', field: 'name', minWidth: 160,
                cellRenderer: function (params) {
                    var wrap = document.createElement('div');
                    wrap.className = 'leading-tight';

                    var name = document.createElement('div');
                    name.className = 'font-medium text-slate-800';
                    name.textContent = params.value;

                    var code = document.createElement('div');
                    code.className = 'font-mono text-xs text-slate-400';
                    code.textContent = ORG_BASE_URL + params.data.org_code;

                    wrap.appendChild(name);
                    wrap.appendChild(code);
                    return wrap;
                }
            },
            {
                headerName: '상태', field: 'status', width: 110, flex: 0,
                cellRenderer: RHGrid.badgeRenderer(STATUS_CLASS, STATUS_LABEL),
                valueFormatter: function (p) { return STATUS_LABEL[p.value] || p.value; }
            },
            {
                headerName: '담당자', field: 'owner_name', minWidth: 150,
                cellRenderer: function (params) {
                    if (!params.value) { return '-'; }

                    var wrap = document.createElement('div');
                    wrap.className = 'leading-tight';

                    var name = document.createElement('div');
                    name.textContent = params.value;

                    var email = document.createElement('div');
                    email.className = 'text-xs text-slate-400';
                    email.textContent = params.data.owner_email;

                    wrap.appendChild(name);
                    wrap.appendChild(email);
                    return wrap;
                }
            },
            { headerName: '사업자번호', field: 'biz_no', width: 130, flex: 0 },
            { headerName: '연락처', field: 'phone', width: 130, flex: 0 },
            {
                headerName: '상담원', field: 'agent_count', width: 90, flex: 0,
                type: 'numericColumn', filter: 'agNumberColumnFilter'
            },
            {
                headerName: '세션', field: 'session_count', width: 90, flex: 0,
                type: 'numericColumn', filter: 'agNumberColumnFilter'
            },
            { headerName: '신청일', field: 'created_at', width: 130, flex: 0, valueFormatter: RHGrid.shortDateTime },
            { headerName: '승인일', field: 'approved_at', width: 130, flex: 0, valueFormatter: RHGrid.shortDateTime },
            {
                headerName: '사유', field: 'status_reason', minWidth: 140,
                tooltipField: 'status_reason',
                valueFormatter: function (p) { return p.value || '-'; }
            },
            {
                headerName: '처리', width: 170, flex: 0, sortable: false, filter: false, pinned: 'right',
                cellRenderer: RHGrid.buttonsRenderer([
                    {
                        label: '승인', className: 'btn-primary',
                        show: function (row) { return row.status === 'pending'; },
                        onClick: function (row) {
                            showConfirmModal('조직 승인',
                                row.name + ' 조직을 승인할까요? 승인하면 담당자가 바로 로그인할 수 있습니다.',
                                function () { post(ORG_URLS.approve, { org_id: row.id }); });
                        }
                    },
                    {
                        label: '반려', className: 'btn-secondary',
                        show: function (row) { return row.status === 'pending'; },
                        onClick: function (row) {
                            askReason('가입 반려',
                                row.name + ' 조직의 신청을 반려합니다. 사유는 담당자 로그인 화면에 표시됩니다.',
                                function (reason) { post(ORG_URLS.reject, { org_id: row.id, reason: reason }); });
                        }
                    },
                    {
                        label: '이용 중지', className: 'btn-secondary',
                        show: function (row) { return row.status === 'active' && row.org_code !== 'system'; },
                        onClick: function (row) {
                            askReason('이용 중지',
                                row.name + ' 조직의 이용을 중지합니다. 소속 상담원이 모두 로그인할 수 없게 됩니다.',
                                function (reason) { post(ORG_URLS.suspend, { org_id: row.id, reason: reason }); });
                        }
                    },
                    {
                        label: '다시 활성화', className: 'btn-primary',
                        show: function (row) { return row.status === 'rejected' || row.status === 'suspended'; },
                        onClick: function (row) {
                            showConfirmModal('다시 활성화', row.name + ' 조직을 다시 활성화할까요?',
                                function () { post(ORG_URLS.restore, { org_id: row.id }); });
                        }
                    }
                ])
            }
        ];

        RHGrid.create('orgGrid', {
            columnDefs: columnDefs,
            rowData: ORG_ROWS,
            tooltipShowDelay: 300
        });
    });
})(jQuery);
