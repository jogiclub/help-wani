/**
 * 파일 위치: assets/js/operator/organizations.js
 * 역할: 조직 가입 관리 그리드와 승인/반려/중지 처리
 *
 * Bootstrap 6 화면(layouts/operator.html). 조직 수정은 드로어(openDrawer), 사유 입력은 다이얼로그(openModal).
 */
(function ($) {
    'use strict';

    var STATUS_CLASS = {
        pending: 'amber',
        active: 'green',
        rejected: 'red',
        suspended: 'dark'
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

    /**
     * 행을 클릭하면 조직 정보를 드로어에 채워 연다.
     */
    function openOrgModal(row) {
        $('#orgModalId').val(row.id);
        $('#orgModalName').val(row.name);
        $('#orgModalCode').val(row.org_code);
        $('#orgModalBizNo').val(row.biz_no || '');
        $('#orgModalPhone').val(row.phone || '');
        $('#orgModalPlan').val(row.plan || '');
        $('#orgModalCountry').val(row.country || 'KR');
        $('#orgModalLocale').val(row.locale || 'ko');
        $('#orgModalTimezone').val(row.timezone || 'Asia/Seoul');

        $('#orgModalStatus')
            .attr('class', RHUi.badge(STATUS_CLASS[row.status] || 'gray'))
            .text(STATUS_LABEL[row.status] || row.status);

        $('#orgModalOwner').text(row.owner_name ? (row.owner_name + ' <' + row.owner_email + '>') : '-');
        $('#orgModalAgents').text(row.agent_count + '명');
        $('#orgModalSessions').text(row.session_count + '건');
        $('#orgModalCreated').text(RHI18n.formatDate(row.created_at));
        $('#orgModalApproved').text(row.approved_at ? RHI18n.formatDate(row.approved_at) : '-');
        $('#orgModalReason').text(row.status_reason || '-');
        $('#orgModalVisit').attr('href', ORG_BASE_URL + row.org_code);

        updateModalTzPreview();
        openDrawer('orgDrawer');
    }

    /** 선택한 시간대의 현재 시각 미리보기 */
    function updateModalTzPreview() {
        try {
            $('#orgModalTzPreview').text(new Intl.DateTimeFormat(RHI18n.intlLocale, {
                timeZone: $('#orgModalTimezone').val(), dateStyle: 'medium', timeStyle: 'short'
            }).format(new Date()));
        } catch (e) {
            $('#orgModalTzPreview').text('-');
        }
    }

    function saveOrg() {
        apiPost(ORG_URLS.save, {
            org_id:   $('#orgModalId').val(),
            name:     $('#orgModalName').val(),
            org_code: $('#orgModalCode').val(),
            biz_no:   $('#orgModalBizNo').val(),
            phone:    $('#orgModalPhone').val(),
            plan:     $('#orgModalPlan').val(),
            country:  $('#orgModalCountry').val(),
            locale:   $('#orgModalLocale').val(),
            timezone: $('#orgModalTimezone').val()
        }, function (data, message) {
            closeDrawer('orgDrawer');
            showToast(message, 'success');
            setTimeout(function () { window.location.reload(); }, 800);
        });
    }

    $(function () {
        // 국가를 바꾸면 언어와 시간대 기본값을 따라 바꾼다.
        $('#orgModalCountry').on('change', function () {
            var opt = $(this).find('option:selected');
            $('#orgModalLocale').val(opt.data('locale'));
            $('#orgModalTimezone').val(opt.data('timezone'));
            updateModalTzPreview();
        });

        $('#orgModalTimezone').on('change', updateModalTzPreview);
        $('#orgModalSave').on('click', saveOrg);

        var columns = [
            {
                title: '조직명', key: 'name', width: 160, flex: 1, align: 'left',
                render: function (row, value) {
                    var wrap = document.createElement('div');
                    wrap.className = 'rh-cell-stack';

                    var name = document.createElement('div');
                    name.className = 'text-truncate fw-medium';
                    name.textContent = value;

                    var code = document.createElement('div');
                    code.className = 'text-truncate font-monospace rh-cell-sub';
                    code.textContent = ORG_BASE_URL + row.org_code;

                    wrap.appendChild(name);
                    wrap.appendChild(code);
                    return wrap;
                }
            },
            {
                title: '상태', key: 'status', width: 110,
                render: RHGrid.badgeRenderer(STATUS_CLASS, STATUS_LABEL),
                format: function (v) { return STATUS_LABEL[v] || v; }
            },
            {
                title: '담당자', key: 'owner_name', width: 150, flex: 1, align: 'left',
                render: function (row, value) {
                    if (!value) { return '-'; }

                    var wrap = document.createElement('div');
                    wrap.className = 'rh-cell-stack';

                    var name = document.createElement('div');
                    name.className = 'text-truncate';
                    name.textContent = value;

                    var email = document.createElement('div');
                    email.className = 'text-truncate rh-cell-sub';
                    email.textContent = row.owner_email;

                    wrap.appendChild(name);
                    wrap.appendChild(email);
                    return wrap;
                }
            },
            { title: '사업자번호', key: 'biz_no', width: 130 },
            { title: '연락처', key: 'phone', width: 130 },
            { title: '상담원', key: 'agent_count', width: 90, type: 'number' },
            { title: '세션', key: 'session_count', width: 90, type: 'number' },
            { title: '신청일', key: 'created_at', width: 130, format: RHGrid.shortDateTime },
            { title: '승인일', key: 'approved_at', width: 130, format: RHGrid.shortDateTime },
            {
                title: '사유', key: 'status_reason', width: 140, flex: 1, align: 'left', tooltip: true,
                format: function (v) { return v || '-'; }
            },
            {
                title: '처리', key: '_actions', width: 170, sortable: false, pinned: 'right',
                render: RHGrid.buttonsRenderer([
                    {
                        label: '승인', className: 'primary',
                        show: function (row) { return row.status === 'pending'; },
                        onClick: function (row) {
                            showConfirmModal('조직 승인',
                                row.name + ' 조직을 승인할까요? 승인하면 담당자가 바로 로그인할 수 있습니다.',
                                function () { post(ORG_URLS.approve, { org_id: row.id }); });
                        }
                    },
                    {
                        label: '반려', className: 'secondary',
                        show: function (row) { return row.status === 'pending'; },
                        onClick: function (row) {
                            askReason('가입 반려',
                                row.name + ' 조직의 신청을 반려합니다. 사유는 담당자 로그인 화면에 표시됩니다.',
                                function (reason) { post(ORG_URLS.reject, { org_id: row.id, reason: reason }); });
                        }
                    },
                    {
                        label: '이용 중지', className: 'secondary',
                        show: function (row) { return row.status === 'active' && row.org_code !== 'system'; },
                        onClick: function (row) {
                            askReason('이용 중지',
                                row.name + ' 조직의 이용을 중지합니다. 소속 상담원이 모두 로그인할 수 없게 됩니다.',
                                function (reason) { post(ORG_URLS.suspend, { org_id: row.id, reason: reason }); });
                        }
                    },
                    {
                        label: '다시 활성화', className: 'primary',
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
            columns: columns,
            rows: ORG_ROWS,
            rowId: function (row) { return row.id; },
            rowClass: function () { return 'rh-clickable'; },
            onRowClicked: function (row) {
                openOrgModal(row);
            }
        });
    });
})(jQuery);
