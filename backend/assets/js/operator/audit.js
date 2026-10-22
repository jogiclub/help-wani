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
            columns: [
                { title: '일시', key: 'created_at', width: 160, format: RHGrid.dateTime },
                {
                    title: '행위', key: 'action', width: 180,
                    render: RHGrid.badgeRenderer(ACTION_CLASS, ACTION_LABEL),
                    format: function (v) { return ACTION_LABEL[v] || v; }
                },
                {
                    title: '조직', key: 'org_name', width: 140, flex: 1,
                    format: function (v) { return v || '-'; }
                },
                {
                    title: '수행자', key: 'agent_name', width: 140, flex: 1, align: 'left',
                    render: function (row, value) {
                        if (!value || value === '-') { return '-'; }

                        var wrap = document.createElement('div');
                        wrap.className = 'min-w-0 leading-tight';

                        var name = document.createElement('div');
                        name.className = 'truncate';
                        name.textContent = value;

                        var email = document.createElement('div');
                        email.className = 'truncate text-xs text-slate-400';
                        email.textContent = row.agent_email;

                        wrap.appendChild(name);
                        wrap.appendChild(email);
                        return wrap;
                    }
                },
                {
                    title: '내용', key: 'detail', width: 200, flex: 1, align: 'left', tooltip: true,
                    format: function (v) { return v || '-'; }
                },
                { title: 'IP', key: 'ip', width: 130 }
            ],
            rows: AUDIT_ROWS,
            rowId: function (row) { return row.id; }
        });
    });
})(jQuery);
