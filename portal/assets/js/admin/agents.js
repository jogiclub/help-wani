/**
 * 파일 위치: assets/js/admin/agents.js
 * 역할: 상담원 목록 그리드와 추가/수정/사용 여부 변경
 */
(function ($) {
    'use strict';

    function openAgentModal(agent) {
        $('#agentId').val(agent ? agent.id : '');
        $('#agentName').val(agent ? agent.name : '');
        $('#agentEmail').val(agent ? agent.email : '');
        $('#agentPhone').val(agent ? agent.phone : '');
        $('#agentRole').val(agent ? agent.role : 'agent');
        $('#agentPassword').val('');
        $('#agentModalTitle').text(agent ? '상담원 수정' : '상담원 추가');
        openModal('agentModal');
    }

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 800);
    }

    $(function () {
        RHGrid.create('agentGrid', {
            columnDefs: [
                { headerName: '이름', field: 'name', minWidth: 120, cellClass: 'font-medium text-slate-800' },
                { headerName: '이메일', field: 'email', minWidth: 180 },
                { headerName: '전화', field: 'phone', width: 140, flex: 0,
                  valueFormatter: function (p) { return p.value || '-'; } },
                {
                    headerName: '권한', field: 'role', width: 110, flex: 0,
                    cellRenderer: RHGrid.badgeRenderer(
                        { admin: 'badge-blue', agent: 'badge-gray' },
                        { admin: '관리자', agent: '상담원' }),
                    valueFormatter: function (p) { return p.value === 'admin' ? '관리자' : '상담원'; }
                },
                {
                    headerName: '상태', field: 'is_active', width: 100, flex: 0,
                    cellRenderer: RHGrid.badgeRenderer(
                        { 1: 'badge-green', 0: 'badge-gray' },
                        { 1: '사용', 0: '중지' }),
                    valueFormatter: function (p) { return p.value ? '사용' : '중지'; }
                },
                {
                    headerName: '최근 로그인', field: 'last_login_at', width: 160, flex: 0,
                    valueFormatter: function (p) { return p.value || '-'; }
                },
                {
                    headerName: '관리', width: 150, flex: 0, sortable: false, filter: false, pinned: 'right',
                    cellRenderer: RHGrid.buttonsRenderer([
                        {
                            label: '수정', className: 'btn-secondary',
                            onClick: function (row) { openAgentModal(row); }
                        },
                        {
                            label: '사용/중지', className: 'btn-secondary',
                            onClick: function (row) {
                                showConfirmModal('상태 변경',
                                    row.name + ' 상담원의 사용 여부를 변경할까요?',
                                    function () {
                                        apiPost(AGENT_URLS.toggle, { id: row.id }, function (data, message) {
                                            showToast(message, 'success');
                                            reloadSoon();
                                        });
                                    });
                            }
                        }
                    ])
                }
            ],
            rowData: AGENT_ROWS,
            overlayNoRowsTemplate: '<span class="text-slate-400">등록된 상담원이 없습니다.</span>'
        });

        $('#btnAdd').on('click', function () { openAgentModal(null); });

        $('#btnSaveAgent').on('click', function () {
            apiPost(AGENT_URLS.save, {
                id: $('#agentId').val(),
                name: $('#agentName').val(),
                email: $('#agentEmail').val(),
                phone: $('#agentPhone').val(),
                role: $('#agentRole').val(),
                password: $('#agentPassword').val()
            }, function (data, message) {
                showToast(message, 'success');
                reloadSoon();
            });
        });
    });
})(jQuery);
