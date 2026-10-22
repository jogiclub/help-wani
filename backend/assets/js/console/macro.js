/**
 * 파일 위치: assets/js/console/macro.js
 * 역할: 매크로 관리 화면 — 목록(wgrid, 범위 탭·검색·90일 미사용), 즐겨찾기 켜기/끄기와 끌어서 순서 바꾸기,
 *       개인으로 복사, 여러 개 삭제, CSV 파일 등록(Dropzone)
 *
 * 편집 드로어는 macro-editor.js(RHMacroEditor), 검색 순위와 변수 표시는 macro-common.js(RHMacro). (docs/macro-plan.md)
 * 화면은 Bootstrap 6 + 왔니. 배지·버튼은 RHUi 톤 이름으로 고르고, 숨기기는 d-none 이다.
 * 매크로 이름·내용은 상담원이 쓴 글이므로 textContent 로만 그린다(CLAUDE.md 3.17).
 */
(function ($) {
    'use strict';

    var UNUSED_DAYS = 90;
    var SCOPE_LABEL = { org: '공용', personal: '개인' };
    var SCOPE_TONE = { org: 'blue', personal: 'gray' };

    var conf = window.MACRO_EDITOR || {};
    var rows = MACRO_ROWS;
    var scope = 'all';
    var grid = null;

    function utcTime(value) {
        return value ? Date.parse(String(value).replace(' ', 'T') + 'Z') : 0;
    }

    function isUnused(row) {
        var limit = Date.now() - UNUSED_DAYS * 86400000;
        return (row.last_used_at ? utcTime(row.last_used_at) : utcTime(row.created_at)) < limit;
    }

    // ------------------------------------------------------------------
    // 목록
    // ------------------------------------------------------------------

    function filtered() {
        var q = $('#macroSearch').val();
        var unused = $('#macroUnused').is(':checked');
        var out = [];

        rows.forEach(function (row) {
            if (scope === 'org' && row.scope !== 'org') { return; }
            if (scope === 'personal' && row.scope !== 'personal') { return; }
            if (unused && !isUnused(row)) { return; }

            var r = RHMacro.rank(row, q);

            if (r >= 0) {
                out.push({ row: row, rank: r });
            }
        });

        // 검색어가 있으면 맞는 정도 순, 없으면 서버 순서(개인 → 공용, 최근 사용 순)를 그대로 둔다.
        if (q) {
            out.sort(function (a, b) { return a.rank - b.rank; });
        }

        return out.map(function (item) { return item.row; });
    }

    function favoriteButton(row) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'rh-macro-star' + (row.favorite ? ' is-on' : '');
        btn.textContent = row.favorite ? '★' : '☆';
        btn.title = row.favorite ? '즐겨찾기에서 빼기' : '즐겨찾기에 넣기';
        btn.addEventListener('click', function (event) {
            event.stopPropagation();
            toggleFavorite(row);
        });
        return btn;
    }

    function columns() {
        return [
            { key: '_fav', title: '★', width: 50, sortable: false, filterable: false,
              render: function (row) { return favoriteButton(row); } },
            { key: 'name', title: '이름', width: 200, tooltip: true, cellClass: 'fw-medium fg-1' },
            { key: 'body', title: '내용', flex: 1, width: 260, tooltip: true,
              format: function (v) { return RHMacro.label(v, conf.variables).replace(/\s+/g, ' '); } },
            { key: 'scope', title: '범위', width: 80, render: RHGrid.badgeRenderer(SCOPE_TONE, SCOPE_LABEL) },
            { key: 'created_by_name', title: '만든 사람', width: 110, format: function (v) { return v || '-'; } },
            { key: 'use_count', title: '사용', width: 80 },
            { key: 'last_used_at', title: '최근 사용', width: 130, format: RHGrid.shortDateTime },
            { key: 'updated_at', title: '수정', width: 130, format: RHGrid.shortDateTime },
            { key: '_actions', title: '', width: 240, sortable: false, filterable: false, pinned: 'right',
              render: RHGrid.buttonsRenderer([
                  { label: '고치기', className: 'secondary', show: function (row) { return row.can_edit; },
                    onClick: function (row) { RHMacroEditor.open({ macro: row }); } },
                  { label: '개인으로 복사', className: 'secondary', show: function (row) { return row.scope === 'org'; },
                    onClick: copyToPersonal },
                  { label: '삭제', className: 'danger', show: function (row) { return row.can_edit; },
                    onClick: function (row) { removeMacros([row]); } }
              ]) }
        ];
    }

    function render() {
        var favoriteTab = scope === 'favorite';

        $('#macroGridCard').toggleClass('d-none', favoriteTab);
        $('#macroFavCard').toggleClass('d-none', !favoriteTab);
        $('#macroSearch, #macroUnused, #btnMacroDelete').prop('disabled', favoriteTab);

        if (favoriteTab) {
            renderFavorites();
        } else {
            grid.setRows(filtered());
        }
    }

    function reload() {
        apiGet(MACRO_URLS.list, function (data) {
            rows = data.macros;
            render();
        });
    }

    // ------------------------------------------------------------------
    // 즐겨찾기
    // ------------------------------------------------------------------

    function toggleFavorite(row) {
        var on = !row.favorite;

        apiPost(MACRO_URLS.favorite, { id: row.id, on: on ? 1 : 0 }, function () {
            reload();
        });
    }

    function favoriteRows() {
        return rows.filter(function (row) { return row.favorite; })
                   .sort(function (a, b) { return a.fav_order - b.fav_order; });
    }

    function renderFavorites() {
        var list = document.getElementById('macroFavList');
        var favs = favoriteRows();

        list.innerHTML = '';
        $('#macroFavEmpty').toggleClass('d-none', favs.length > 0);
        $('#macroFavCount').text(favs.length + ' / ' + conf.maxFavorites);

        favs.forEach(function (row) {
            var li = document.createElement('li');
            li.className = 'rh-macro-fav d-flex align-items-center gap-4 px-5 py-3 fs-sm';
            li.draggable = true;
            li.dataset.id = row.id;

            var handle = document.createElement('span');
            handle.className = 'rh-macro-handle';
            handle.textContent = '⋮⋮';

            var name = document.createElement('span');
            name.className = 'rh-macro-fav-name flex-shrink-0 text-truncate fw-medium fg-1';
            name.textContent = row.name;

            var body = document.createElement('span');
            body.className = 'min-w-0 flex-grow-1 text-truncate fg-3';
            body.textContent = RHMacro.label(row.body, conf.variables).replace(/\s+/g, ' ');

            var badge = document.createElement('span');
            badge.className = RHUi.badge(SCOPE_TONE[row.scope]);
            badge.textContent = SCOPE_LABEL[row.scope];

            var star = favoriteButton(row);

            li.appendChild(handle);
            li.appendChild(name);
            li.appendChild(body);
            li.appendChild(badge);
            li.appendChild(star);
            list.appendChild(li);
        });
    }

    /** 끌어서 놓으면 그 자리에 끼우고 바로 순서를 저장한다. */
    function bindFavoriteDrag() {
        var list = document.getElementById('macroFavList');
        var dragging = null;

        list.addEventListener('dragstart', function (event) {
            dragging = event.target.closest('li');
            if (dragging) {
                dragging.classList.add('opacity-50');
                event.dataTransfer.effectAllowed = 'move';
            }
        });

        list.addEventListener('dragover', function (event) {
            if (!dragging) { return; }
            event.preventDefault();

            var over = event.target.closest('li');
            if (!over || over === dragging) { return; }

            var box = over.getBoundingClientRect();
            var after = event.clientY > box.top + box.height / 2;
            list.insertBefore(dragging, after ? over.nextSibling : over);
        });

        list.addEventListener('dragend', function () {
            if (!dragging) { return; }
            dragging.classList.remove('opacity-50');
            dragging = null;

            var ids = Array.prototype.map.call(list.querySelectorAll('li'), function (li) { return Number(li.dataset.id); });

            apiPost(MACRO_URLS.favoriteOrder, { ids: ids }, function () {
                ids.forEach(function (id, index) {
                    rows.forEach(function (row) { if (row.id === id) { row.fav_order = index; } });
                });
            });
        });
    }

    // ------------------------------------------------------------------
    // 복사, 삭제
    // ------------------------------------------------------------------

    function copyToPersonal(row) {
        apiPost(MACRO_URLS.copy, { id: row.id }, function (data, message) {
            showToast(message, 'success');
            reload();
        });
    }

    function removeMacros(targets) {
        var editable = targets.filter(function (row) { return row.can_edit; });

        if (!editable.length) {
            showToast(targets.length ? '고를 수 있는 매크로 중 지울 권한이 있는 것이 없습니다.' : '지울 매크로를 골라 주세요.', 'warning');
            return;
        }

        var hasOrg = editable.some(function (row) { return row.scope === 'org'; });
        var text = editable.length === 1 ? '"' + editable[0].name + '" 매크로를 지울까요?' : editable.length + '개 매크로를 지울까요?';

        showConfirmModal('매크로 삭제', text + (hasOrg ? ' 공용 매크로는 모든 상담원의 목록과 즐겨찾기에서 사라집니다.' : ''), function () {
            apiPost(MACRO_URLS.remove, { ids: editable.map(function (row) { return row.id; }) }, function (data, message) {
                showToast(message, 'success');
                reload();
            });
        });
    }

    // ------------------------------------------------------------------
    // CSV 등록
    // ------------------------------------------------------------------

    function showImportResult(res) {
        var $box = $('#macroImportResult').empty().removeClass('d-none');
        var errors = (res && res.data && res.data.errors) || [];

        $('<p class="mb-0 fw-semibold">').text(res.message || '').appendTo($box);

        if (errors.length) {
            var $ul = $('<ul class="mt-1 mb-0 ps-5">').appendTo($box);
            errors.slice(0, 100).forEach(function (e) {
                $('<li>').text((e.row > 0 ? e.row + '행 ' : '') + (e.field ? '[' + e.field + '] ' : '') + e.message).appendTo($ul);
            });
            if (errors.length > 100) {
                $('<p class="mt-1 mb-0">').text('… 외 ' + (errors.length - 100) + '건').appendTo($box);
            }
        }
    }

    function bindImport() {
        $('#btnMacroImport').on('click', function () {
            $('#macroImportResult').addClass('d-none').empty();
            openModal('macroImportModal');
        });

        RHImageDrop.create('#macroImportDrop', {
            url: MACRO_URLS.importFile,
            paramName: 'file',
            maxMb: MACRO_IMPORT_MB,
            accept: '.csv,text/csv',
            params: function () {
                return {
                    scope: $('input[name="macroImportScope"]:checked').val(),
                    mode: $('input[name="macroImportMode"]:checked').val()
                };
            },
            invalidTypeText: 'CSV 파일만 올릴 수 있습니다. 엑셀에서 "CSV UTF-8" 로 저장해 주세요.',
            tooBigText: '파일은 ' + (MACRO_IMPORT_MB) + 'MB 이하로 올려 주세요.',
            onSuccess: function (data, message) {
                showToast(message, 'success');
                showImportResult({ message: message });
                reload();
            },
            onError: showImportResult
        });
    }

    // ------------------------------------------------------------------

    $(function () {
        grid = RHGrid.create('macroGrid', {
            columns: columns(),
            rows: filtered(),
            rowId: function (row) { return row.id; },
            checkboxSelection: true,
            noRowsText: '매크로가 없습니다. [새 매크로] 로 만들어 보세요.',
            onRowClicked: function (row) {
                if (row.can_edit) {
                    RHMacroEditor.open({ macro: row });
                }
            }
        });

        $('#macroTabs').on('click', 'button', function () {
            scope = $(this).data('scope');
            $('#macroTabs button').removeClass('active').attr('aria-selected', 'false');
            $(this).addClass('active').attr('aria-selected', 'true');
            render();
        });

        $('#macroSearch').on('input', render);
        $('#macroUnused').on('change', render);

        $('#btnMacroNew').on('click', function () {
            RHMacroEditor.open({ scope: scope === 'org' ? 'org' : 'personal' });
        });

        $('#btnMacroDelete').on('click', function () {
            removeMacros(grid.grid.getSelectedRows());
        });

        RHMacroEditor.onSaved(reload);

        bindFavoriteDrag();
        bindImport();
    });
})(jQuery);
