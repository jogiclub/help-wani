/**
 * 파일 위치: assets/js/operator/audit.js
 * 역할: 감사 로그 그리드
 */
(function ($) {
    'use strict';

    // 행위별 표시 문구와 색
    var ACTION_LABEL = {
        login: '로그인',
        login_failed: '로그인 실패',
        logout: '로그아웃',
        org_signup: '조직 가입 신청',
        org_approved: '조직 승인',
        org_rejected: '조직 반려',
        org_suspended: '조직 이용 중지',
        org_restored: '조직 재활성화',
        org_update: '조직 정보 수정',
        agent_create: '상담원 추가',
        agent_update: '상담원 수정',
        agent_toggle: '상담원 사용 여부 변경',
        session_create: '세션 생성',
        session_end: '세션 종료',
        viewer_token: '원격 접속 토큰 발급',
        launcher_download: '런처 다운로드',
        operator_access_denied: '운영자 화면 접근 거부'
    };

    var ACTION_CLASS = {
        org_approved: 'badge-green',
        org_restored: 'badge-green',
        org_rejected: 'badge-red',
        org_suspended: 'badge-dark',
        login_failed: 'badge-red',
        operator_access_denied: 'badge-red',
        org_signup: 'badge-amber'
    };

    $(function () {
        RHGrid.create('auditGrid', {
            columnDefs: [
                { headerName: '일시', field: 'created_at', width: 160, flex: 0 },
                {
                    headerName: '행위', field: 'action', width: 180, flex: 0,
                    cellRenderer: RHGrid.badgeRenderer(ACTION_CLASS, ACTION_LABEL),
                    valueFormatter: function (p) { return ACTION_LABEL[p.value] || p.value; }
                },
                {
                    headerName: '조직', field: 'org_name', minWidth: 140,
                    valueFormatter: function (p) { return p.value || '-'; }
                },
                {
                    headerName: '수행자', field: 'agent_name', minWidth: 140,
                    cellRenderer: function (params) {
                        if (!params.value || params.value === '-') { return '-'; }

                        var wrap = document.createElement('div');
                        wrap.className = 'leading-tight';

                        var name = document.createElement('div');
                        name.textContent = params.value;

                        var email = document.createElement('div');
                        email.className = 'text-xs text-slate-400';
                        email.textContent = params.data.agent_email;

                        wrap.appendChild(name);
                        wrap.appendChild(email);
                        return wrap;
                    }
                },
                {
                    headerName: '내용', field: 'detail', minWidth: 200,
                    tooltipField: 'detail',
                    valueFormatter: function (p) { return p.value || '-'; }
                },
                { headerName: 'IP', field: 'ip', width: 130, flex: 0 }
            ],
            rowData: AUDIT_ROWS,
            tooltipShowDelay: 300
        });
    });
})(jQuery);
