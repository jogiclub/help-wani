/**
 * 파일 위치: assets/js/grid.js
 * 역할: AG Grid 공통 설정 (한국어 문구, 기본 100개씩 페이징, 공통 컬럼 기본값)
 *
 * AG Grid v36 Community. 테마는 CSS 파일 없이 Theming API 를 쓴다.
 */
(function (window) {
    'use strict';

    var DEFAULT_PAGE_SIZE = 100;
    var PAGE_SIZE_OPTIONS = [100, 200, 500, 1000];

    /** AG Grid 화면 문구 한국어 */
    var LOCALE = {
        // 페이징
        page: '페이지',
        to: '~',
        of: '/',
        more: '이상',
        firstPage: '처음',
        previousPage: '이전',
        nextPage: '다음',
        lastPage: '마지막',
        pageSizeSelectorLabel: '쪽당 행 수',
        ariaPageSizeSelectorLabel: '쪽당 행 수',

        // 상태
        noRowsToShow: '표시할 내용이 없습니다.',
        loadingOoo: '불러오는 중...',
        totalRows: '전체',
        totalAndFilteredRows: '표시',

        // 필터
        searchOoo: '검색...',
        filterOoo: '필터...',
        applyFilter: '적용',
        resetFilter: '초기화',
        clearFilter: '지우기',
        cancelFilter: '취소',
        blanks: '(빈 값)',
        selectAll: '(전체 선택)',
        equals: '같음',
        notEqual: '같지 않음',
        contains: '포함',
        notContains: '포함하지 않음',
        startsWith: '시작 문자',
        endsWith: '끝 문자',
        blank: '빈 값',
        notBlank: '빈 값 아님',
        lessThan: '작음',
        greaterThan: '큼',
        inRange: '범위',
        andCondition: '그리고',
        orCondition: '또는',

        // 메뉴
        columns: '열',
        filters: '필터',
        pinColumn: '열 고정',
        pinLeft: '왼쪽 고정',
        pinRight: '오른쪽 고정',
        noPin: '고정 해제',
        autosizeThisColumn: '이 열 너비 맞춤',
        autosizeAllColumns: '모든 열 너비 맞춤',
        resetColumns: '열 초기화',
        sortAscending: '오름차순',
        sortDescending: '내림차순',
        sortUnSort: '정렬 해제'
    };

    /**
     * 공통 옵션을 씌워 그리드를 만든다.
     *
     * @param {string|HTMLElement} target  컨테이너 (id 문자열 또는 요소)
     * @param {object} options             AG Grid 옵션 (columnDefs, rowData 등)
     * @returns {object} grid API
     */
    function create(target, options) {
        var el = (typeof target === 'string') ? document.getElementById(target) : target;

        if (!el) {
            return null;
        }

        var merged = Object.assign({
            theme: window.agGrid.themeQuartz,
            localeText: LOCALE,

            // 기본 100개씩
            pagination: true,
            paginationPageSize: DEFAULT_PAGE_SIZE,
            paginationPageSizeSelector: PAGE_SIZE_OPTIONS,

            defaultColDef: {
                sortable: true,
                resizable: true,
                filter: true,
                minWidth: 90,
                flex: 1
            },

            // 행 높이를 포털 표 밀도와 맞춘다.
            rowHeight: 40,
            headerHeight: 40,
            animateRows: false,
            suppressCellFocus: true,

            // 컬럼 너비가 남으면 채운다.
            autoSizeStrategy: { type: 'fitGridWidth' }
        }, options || {});

        return window.agGrid.createGrid(el, merged);
    }

    /**
     * UTC 로 내려온 값을 조직 타임존의 'MM-DD HH:mm' 으로 보여준다.
     */
    function shortDateTime(params) {
        return window.RHI18n ? window.RHI18n.formatDate(params.value, 'short') : (params.value || '-');
    }

    /**
     * UTC 로 내려온 값을 조직 타임존의 전체 일시로 보여준다.
     */
    function dateTime(params) {
        return window.RHI18n ? window.RHI18n.formatDate(params.value, 'datetime') : (params.value || '-');
    }

    /**
     * 배지 형태로 보여주는 셀 렌더러를 만든다.
     * classMap: { 값: 'badge-green' }
     * labelMap: { 값: '표시문구' }
     */
    function badgeRenderer(classMap, labelMap) {
        return function (params) {
            if (params.value === null || params.value === undefined) {
                return '-';
            }

            var cls = (classMap && classMap[params.value]) || 'badge-gray';
            var label = (labelMap && labelMap[params.value]) || params.value;
            var span = document.createElement('span');

            span.className = 'badge ' + cls;
            span.textContent = label;
            return span;
        };
    }

    /**
     * 버튼 여러 개를 담는 셀 렌더러를 만든다.
     * buttons: [{ label, className, onClick(rowData), show(rowData) }]
     */
    function buttonsRenderer(buttons) {
        return function (params) {
            var wrap = document.createElement('div');
            wrap.className = 'flex h-full items-center gap-1';

            buttons.forEach(function (def) {
                if (def.show && !def.show(params.data)) {
                    return;
                }

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm ' + (def.className || 'btn-secondary');
                btn.textContent = def.label;
                btn.addEventListener('click', function () { def.onClick(params.data); });
                wrap.appendChild(btn);
            });

            return wrap;
        };
    }

    window.RHGrid = {
        create: create,
        shortDateTime: shortDateTime,
        dateTime: dateTime,
        badgeRenderer: badgeRenderer,
        buttonsRenderer: buttonsRenderer,
        DEFAULT_PAGE_SIZE: DEFAULT_PAGE_SIZE,
        LOCALE: LOCALE
    };
})(window);
