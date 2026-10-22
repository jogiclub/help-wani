/**
 * 파일 위치: assets/js/admin/agents.js
 * 역할: 상담원 목록 그리드와 추가/수정/사용 여부 변경
 *
 * Bootstrap 6 화면(layouts/main.html). 추가/수정은 드로어(openDrawer), 사용/중지 확인은 showConfirmModal.
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
        openDrawer('agentDrawer');
    }

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 800);
    }

    $(function () {
        RHGrid.create('agentGrid', {
            columns: [
                { title: '이름', key: 'name', width: 120, flex: 1, align: 'left', cellClass: 'fw-medium fg-1' },
                { title: '이메일', key: 'email', width: 180, flex: 1, align: 'left' },
                { title: '전화', key: 'phone', width: 140,
                  format: function (v) { return v || '-'; } },
                {
                    title: '권한', key: 'role', width: 110,
                    render: RHGrid.badgeRenderer(
                        { admin: 'blue', agent: 'gray' },
                        { admin: '관리자', agent: '상담원' }),
                    format: function (v) { return v === 'admin' ? '관리자' : '상담원'; }
                },
                {
                    title: '상태', key: 'is_active', width: 100,
                    render: RHGrid.badgeRenderer(
                        { 1: 'green', 0: 'gray' },
                        { 1: '사용', 0: '중지' }),
                    format: function (v) { return Number(v) ? '사용' : '중지'; }
                },
                {
                    title: '최근 로그인', key: 'last_login_at', width: 160,
                    format: RHGrid.dateTime
                },
                {
                    title: '관리', key: '_actions', width: 150, sortable: false, pinned: 'right',
                    render: RHGrid.buttonsRenderer([
                        {
                            label: '수정', className: 'secondary',
                            onClick: function (row) { openAgentModal(row); }
                        },
                        {
                            label: '사용/중지', className: 'secondary',
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
            rows: AGENT_ROWS,
            rowId: function (row) { return row.id; },
            noRowsText: '등록된 상담원이 없습니다.'
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
