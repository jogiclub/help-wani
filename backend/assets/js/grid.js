/**
 * 파일 위치: assets/js/grid.js
 * 역할: wgrid 공통 설정 (기본 20개씩 페이징, 한 쪽 개수 localStorage 기억, 필터 행, 남는 너비 채우기, 공통 셀 렌더러)
 *
 * wgrid 0.1.0 코어(MIT, assets/vendor/wgrid/). Pro 모듈은 라이선스 키가 필요하므로 쓰지 않는다.
 * 컬럼은 wgrid 형식({ key, title, width, format, render, pinned })으로 쓰고, 이 파일이 더하는 키는 셋이다.
 *   flex: 1    남는 너비를 나눠 갖는 컬럼(없으면 width 고정). 그리드 너비가 바뀔 때마다 다시 나눈다.
 *   tooltip    true 면 셀 글자 전체를 title 로 띄운다(말줄임된 긴 글).
 *   cellClass  셀 글자에 붙일 클래스(Tailwind 유틸리티). render 가 없을 때만 쓴다.
 * key 는 컬럼 사이에서 유일해야 한다. 버튼 칸처럼 값이 없는 컬럼도 '_actions' 같은 key 를 준다.
 */
(function (window) {
    'use strict';

    var DEFAULT_PAGE_SIZE = 20;
    var PAGE_SIZE_OPTIONS = [20, 50, 100, 200, 500, 1000];
    var ROW_HEIGHT = 40;
    // 한 쪽 개수는 그리드(컨테이너 id)마다 이 브라우저에 기억한다. 키: rh-grid-page-size:{id}
    var PAGE_SIZE_STORAGE_PREFIX = 'rh-grid-page-size:';
    var MIN_WIDTH = 90;

    /**
     * 공통 옵션을 씌워 그리드를 만든다.
     *
     * @param {string|HTMLElement} target  컨테이너 (id 문자열 또는 요소, 높이가 있어야 한다)
     * @param {object} options             wgrid 옵션 + noRowsText, onRowClicked(row, event)
     * @returns {object|null} { grid, setRows, removeRow, refresh }
     */
    function create(target, options) {
        var el = (typeof target === 'string') ? document.getElementById(target) : target;

        if (!el) {
            return null;
        }

        var opts = Object.assign({}, options || {});
        var flex = {};
        var onRowClicked = opts.onRowClicked;
        var noRowsText = opts.noRowsText;

        delete opts.onRowClicked;
        delete opts.noRowsText;

        opts.columns = (opts.columns || []).map(function (col) {
            return prepareColumn(col, flex);
        });

        var storageKey = el.id ? PAGE_SIZE_STORAGE_PREFIX + el.id : '';
        var pageSize = loadPageSize(storageKey);

        var grid = window.wgrid.createGrid(el, Object.assign({
            rowHeight: ROW_HEIGHT,
            headerHeight: ROW_HEIGHT,
            filterRow: true,
            pagination: { pageSize: pageSize, pageSizes: PAGE_SIZE_OPTIONS },
            locale: noRowsText ? { noRows: noRowsText } : undefined
        }, opts));

        if (storageKey) {
            grid.on('paginationChanged', function (state) {
                if (state.pageSize !== pageSize) {
                    pageSize = state.pageSize;
                    savePageSize(storageKey, pageSize);
                }
            });
        }

        if (onRowClicked) {
            grid.on('rowClicked', function (event) {
                // 셀 안의 버튼과 링크는 자기 동작만 한다.
                if (event.event.target.closest('button, a')) {
                    return;
                }
                onRowClicked(event.row, event.event);
            });
        }

        fitFlexColumns(el, grid, flex);

        return wrap(el, grid, opts.rows || []);
    }

    /**
     * 기억해 둔 한 쪽 개수를 읽는다. 없거나 선택지에 없는 값이면 기본값.
     * 사생활 보호 창처럼 저장소를 못 쓰는 환경에서도 그리드는 떠야 하므로 오류는 삼킨다.
     */
    function loadPageSize(key) {
        var value = 0;

        if (key) {
            try {
                value = parseInt(window.localStorage.getItem(key), 10);
            } catch (e) {
                value = 0;
            }
        }
        return PAGE_SIZE_OPTIONS.indexOf(value) >= 0 ? value : DEFAULT_PAGE_SIZE;
    }

    function savePageSize(key, value) {
        try {
            window.localStorage.setItem(key, String(value));
        } catch (e) {
            // 저장하지 못해도 이번 화면에서는 고른 개수가 그대로 쓰인다.
        }
    }

    /**
     * 공통 컬럼 기본값을 씌우고 이 파일 전용 키(flex, tooltip)를 처리한다.
     * flex 컬럼은 flex[key] = 최소 너비 로 모은다.
     */
    function prepareColumn(col, flex) {
        var out = Object.assign({}, col);
        var width = out.width || out.minWidth || 120;

        out.width = width;
        // flex 컬럼의 width 는 "이보다 좁아지지 않는 너비" 다. 넘치면 가로 스크롤이 생긴다.
        out.minWidth = out.flex ? (out.minWidth || width) : Math.min(out.minWidth || MIN_WIDTH, width);

        if (out.flex) {
            flex[out.key] = out.minWidth;
        }
        delete out.flex;

        // 버튼만 담는 칸은 정렬·필터 대상이 아니다.
        if (out.sortable === false && out.filterable === undefined) {
            out.filterable = false;
        }

        if ((out.tooltip || out.cellClass) && !out.render) {
            var format = out.format;
            var tooltip = !!out.tooltip;
            var cellClass = out.cellClass || '';

            out.render = function (row, value) {
                var span = document.createElement('span');
                var text = format ? format(value, row) : (value === null || value === undefined ? '' : String(value));

                span.className = ('wgrid-cell-text ' + cellClass).trim();
                span.textContent = text;
                if (tooltip) {
                    span.title = text;
                }
                return span;
            };
        }
        delete out.tooltip;
        delete out.cellClass;

        return out;
    }

    /**
     * flex 컬럼이 남는 너비를 나눠 갖게 한다. 그리드 너비가 바뀌면 다시 나눈다.
     * wgrid 의 fitColumns() 는 고정 너비 컬럼까지 비율로 늘리므로 직접 계산한다.
     */
    function fitFlexColumns(el, grid, flex) {
        var keys = Object.keys(flex);

        if (keys.length === 0) {
            return;
        }

        var lastWidth = -1;

        function fit() {
            var viewport = el.querySelector('.wgrid-viewport');
            var width = viewport ? viewport.clientWidth : 0;

            if (width <= 0 || width === lastWidth) {
                return;
            }
            lastWidth = width;

            var fixed = 0;

            grid.getVisibleColumns().forEach(function (col) {
                if (!(col.key in flex)) {
                    fixed += col.width;
                }
            });

            // 최소 너비에 비례해 나눈다. 모자라면 최소 너비를 지키고 가로 스크롤에 맡긴다.
            var minSum = keys.reduce(function (sum, key) { return sum + flex[key]; }, 0);
            var room = Math.max(width - fixed, minSum);

            keys.forEach(function (key) {
                grid.setColumnWidth(key, Math.floor(room * flex[key] / minSum));
            });
        }

        fit();

        if (typeof ResizeObserver !== 'undefined') {
            var observer = new ResizeObserver(fit);
            observer.observe(el);
            grid.on('destroy', function () { observer.disconnect(); });
        }
    }

    /**
     * 화면에서 자주 쓰는 동작만 감싼다. 원래 그리드는 .grid 로 쓴다.
     */
    function wrap(el, grid, initialRows) {
        var rows = initialRows.slice();

        /**
         * 행을 바꾸되 보던 페이지와 스크롤 위치는 그대로 둔다(주기 갱신 화면용).
         * wgrid 의 setRows 는 1쪽 맨 위로 돌아가기 때문이다.
         */
        function setRows(next) {
            var viewport = el.querySelector('.wgrid-viewport');
            var page = grid.getPage();
            var top = viewport ? viewport.scrollTop : 0;
            var left = viewport ? viewport.scrollLeft : 0;

            rows = (next || []).slice();
            grid.setRows(rows);

            if (page > 1) {
                grid.setPage(Math.min(page, Math.max(1, grid.getPageCount())));
            }
            if (viewport) {
                viewport.scrollTop = top;
                viewport.scrollLeft = left;
            }
        }

        return {
            grid: grid,
            setRows: setRows,
            /** 행 하나를 뺀다(삭제 후) */
            removeRow: function (row) {
                setRows(rows.filter(function (r) { return r !== row; }));
            },
            /** 행 객체를 고친 뒤 화면에 반영한다 */
            refresh: function () { grid.refresh(); }
        };
    }

    /**
     * UTC 로 내려온 값을 조직 타임존의 'MM-DD HH:mm' 으로 보여준다. (컬럼의 format 으로 쓴다)
     */
    function shortDateTime(value) {
        return window.RHI18n ? window.RHI18n.formatDate(value, 'short') : (value || '-');
    }

    /**
     * UTC 로 내려온 값을 조직 타임존의 전체 일시로 보여준다. (컬럼의 format 으로 쓴다)
     */
    function dateTime(value) {
        return window.RHI18n ? window.RHI18n.formatDate(value, 'datetime') : (value || '-');
    }

    /**
     * 배지 형태로 보여주는 render 함수를 만든다.
     * classMap: { 값: 'badge-green' } (또는 톤 이름 'green')
     * labelMap: { 값: '표시문구' }
     */
    function badgeRenderer(classMap, labelMap) {
        return function (row, value) {
            if (value === null || value === undefined) {
                return '-';
            }

            var cls = (classMap && classMap[value]) || 'badge-gray';
            var label = (labelMap && labelMap[value]) || value;
            var span = document.createElement('span');

            // 화면 방식(Tailwind / Bootstrap 6)에 맞는 클래스는 common.js 의 RHUi 가 고른다.
            span.className = window.RHUi ? window.RHUi.badge(cls) : 'badge ' + cls;
            span.textContent = label;
            return span;
        };
    }

    /**
     * 버튼 여러 개를 담는 render 함수를 만든다.
     * buttons: [{ label, className, onClick(row), show(row) }]
     */
    function buttonsRenderer(buttons) {
        return function (row) {
            var wrapEl = document.createElement('div');
            wrapEl.className = window.RHUi ? window.RHUi.actions() : 'flex h-full items-center gap-1';

            buttons.forEach(function (def) {
                if (def.show && !def.show(row)) {
                    return;
                }

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = window.RHUi ? window.RHUi.button(def.className || 'btn-secondary')
                                            : 'btn btn-sm ' + (def.className || 'btn-secondary');
                btn.textContent = def.label;
                btn.addEventListener('click', function (event) {
                    // 행 클릭(상세 모달 열기)까지 번지지 않게 막는다.
                    event.stopPropagation();
                    def.onClick(row);
                });
                wrapEl.appendChild(btn);
            });

            return wrapEl;
        };
    }

    window.RHGrid = {
        create: create,
        shortDateTime: shortDateTime,
        dateTime: dateTime,
        badgeRenderer: badgeRenderer,
        buttonsRenderer: buttonsRenderer,
        DEFAULT_PAGE_SIZE: DEFAULT_PAGE_SIZE
    };
})(window);
