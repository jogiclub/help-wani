/**
 * 파일 위치: assets/js/admin/guide.js
 * 역할: 가이드 관리 목록(AG Grid), 매칭 시험, 분류/동의어 관리, guide.json 올리기
 */
(function ($) {
    'use strict';

    var STATUS_CLASS = { published: 'badge-green', draft: 'badge-gray' };
    var STATUS_LABEL = { published: '공개', draft: '임시' };

    var grid = null;

    /**
     * 행 높이를 부제목에 맞춰 키웠으므로 한 줄짜리 칸은 세로 가운데에 둔다.
     * Tailwind 유틸리티는 레이어 안에 있어 AG Grid 기본 스타일을 이기지 못하므로 인라인 스타일로 준다.
     * flex 로 맞추면 말줄임(…)이 사라지므로 줄 높이로 맞춘다(행 52px - 테두리).
     */
    var CENTER = { lineHeight: '50px' };

    /** 가져오기가 끝났으면 모달을 닫을 때 새 목록을 불러온다 */
    var importDone = false;

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 800);
    }

    // ------------------------------------------------------------------
    // 목록
    // ------------------------------------------------------------------

    function titleRenderer(params) {
        var wrap = document.createElement('div');
        wrap.className = 'flex h-full flex-col justify-center leading-tight';

        var link = document.createElement('a');
        link.className = 'truncate font-medium text-slate-800 hover:text-brand-600 hover:underline';
        link.href = GUIDE_URLS.edit + params.data.id;
        link.textContent = params.value;
        wrap.appendChild(link);

        if (params.data.subtitle) {
            var sub = document.createElement('span');
            sub.className = 'truncate text-xs text-slate-400';
            sub.textContent = params.data.subtitle;
            wrap.appendChild(sub);
        }

        return wrap;
    }

    function removeArticle(row) {
        showConfirmModal('가이드 삭제',
            '"' + row.title + '" 가이드를 삭제할까요? 들어 있는 이미지도 함께 지워지고 되돌릴 수 없습니다.',
            function () {
                apiPost(GUIDE_URLS.remove, { id: row.id }, function (data, message) {
                    showToast(message, 'success');
                    grid.applyTransaction({ remove: [row] });
                });
            });
    }

    function toggleStatus(row) {
        var next = row.status === 'published' ? 'draft' : 'published';

        apiPost(GUIDE_URLS.status, { id: row.id, status: next }, function (data, message) {
            showToast(message, 'success');
            row.status = data.status;
            grid.applyTransaction({ update: [row] });
        });
    }

    function createGrid() {
        grid = RHGrid.create('guideGrid', {
            columnDefs: [
                { headerName: '분류', field: 'category_name', width: 120, flex: 0, cellStyle: CENTER,
                  valueFormatter: function (p) { return p.value || '분류 없음'; } },
                { headerName: '제목', field: 'title', minWidth: 220, cellRenderer: titleRenderer },
                { headerName: '키워드', field: 'keywords', minWidth: 140, cellStyle: CENTER,
                  valueFormatter: function (p) { return p.value || '-'; } },
                {
                    headerName: '상태', field: 'status', width: 90, flex: 0, cellStyle: CENTER,
                    cellRenderer: RHGrid.badgeRenderer(STATUS_CLASS, STATUS_LABEL),
                    valueFormatter: function (p) { return STATUS_LABEL[p.value] || p.value; }
                },
                { headerName: '조회', field: 'view_count', width: 80, flex: 0, cellStyle: CENTER,
                  filter: 'agNumberColumnFilter' },
                { headerName: '수정', field: 'updated_at', width: 120, flex: 0, cellStyle: CENTER,
                  valueFormatter: RHGrid.shortDateTime },
                {
                    headerName: '관리', width: 230, minWidth: 230, flex: 0, sortable: false, filter: false, pinned: 'right',
                    cellRenderer: RHGrid.buttonsRenderer([
                        {
                            label: '보기', className: 'btn-secondary',
                            show: function (row) { return row.status === 'published'; },
                            onClick: function (row) { window.open(row.public_url, '_blank', 'noopener'); }
                        },
                        {
                            label: '공개/임시', className: 'btn-secondary',
                            onClick: toggleStatus
                        },
                        {
                            label: '삭제', className: 'btn-ghost text-red-600',
                            onClick: removeArticle
                        }
                    ])
                }
            ],
            rowData: GUIDE_ROWS,
            rowHeight: 52,
            getRowId: function (p) { return String(p.data.id); },
            overlayNoRowsTemplate: '<span class="text-slate-400">아직 가이드가 없습니다. [새 가이드] 로 만들거나 guide.json 을 올려 주세요.</span>'
        });
    }

    // ------------------------------------------------------------------
    // 매칭 시험
    // ------------------------------------------------------------------

    function runMatchTest(event) {
        event.preventDefault();

        var q = $.trim($('#matchQuery').val());

        if (!q) {
            return;
        }

        apiGet(GUIDE_URLS.matchTest + '?q=' + encodeURIComponent(q), function (data) {
            $('#matchTerms').text('찾은 낱말: ' + (data.terms.length ? data.terms.join(', ') : '없음')).removeClass('hidden');

            var $list = $('#matchResults').empty();

            if (!data.results.length) {
                $list.append($('<li class="text-slate-400">').text('맞는 가이드가 없습니다. 키워드나 동의어를 추가해 보세요.'));
                return;
            }

            data.results.forEach(function (item) {
                var where = [];

                if (item.score >= data.min.widget) { where.push('위젯'); }
                if (item.score >= data.min.console) { where.push('콘솔'); }

                var $li = $('<li class="flex items-center gap-2 rounded border border-slate-200 px-3 py-2">');
                $li.append($('<a class="min-w-0 flex-1 truncate hover:underline">')
                    .attr('href', GUIDE_URLS.edit + item.id).text(item.title));
                $li.append($('<span class="badge badge-blue shrink-0">').text(item.score + '점'));
                $li.append($('<span class="shrink-0 text-xs text-slate-400">')
                    .text(where.length ? where.join('·') + ' 추천' : '검색만'));
                $list.append($li);
            });
        });
    }

    // ------------------------------------------------------------------
    // 분류
    // ------------------------------------------------------------------

    function renderCategories() {
        var $list = $('#categoryList').empty();

        if (!GUIDE_CATEGORIES.length) {
            $list.append($('<p class="text-sm text-slate-400">').text('분류가 없습니다.'));
            return;
        }

        GUIDE_CATEGORIES.forEach(function (category) {
            var $row = $('<div class="flex gap-2">');
            var $name = $('<input class="form-input flex-1" maxlength="60">').val(category.name);
            var $sort = $('<input class="form-input w-20" type="number" title="정렬 순서">').val(category.sort_order);

            var $save = $('<button type="button" class="btn btn-sm btn-secondary shrink-0">저장</button>').on('click', function () {
                apiPost(GUIDE_URLS.categorySave, { id: category.id, name: $name.val(), sort_order: $sort.val() },
                    function (data, message) {
                        showToast(message, 'success');
                        reloadSoon();
                    });
            });

            var $remove = $('<button type="button" class="btn btn-sm btn-ghost shrink-0 text-red-600">삭제</button>').on('click', function () {
                showConfirmModal('분류 삭제', '"' + category.name + '" 분류를 삭제할까요? 그 분류의 가이드는 분류 없음으로 남습니다.', function () {
                    apiPost(GUIDE_URLS.categoryDelete, { id: category.id }, function (data, message) {
                        showToast(message, 'success');
                        reloadSoon();
                    });
                });
            });

            $list.append($row.append($name, $sort, $save, $remove));
        });
    }

    // ------------------------------------------------------------------
    // guide.json 올리기
    // ------------------------------------------------------------------

    function showImportResult(data, message) {
        var $box = $('#importResult').empty().removeClass('hidden');

        $box.append($('<p class="font-semibold text-slate-700">').text(message));

        (data.skipped || []).forEach(function (item) {
            $box.append($('<p class="text-red-600">').text('건너뜀 ' + (item.slug || '-') + ': ' + item.reason));
        });

        (data.warnings || []).forEach(function (text) {
            $box.append($('<p class="text-amber-700">').text(text));
        });

        showToast(message, 'success');
        importDone = true;
    }

    function setupImport() {
        RHImageDrop.create('#importDrop', {
            url: GUIDE_URLS.importFile,
            paramName: 'guide',
            maxMb: 2,
            accept: '.json,application/json',
            params: function () { return { mode: $('input[name="importMode"]:checked').val() }; },
            invalidTypeText: 'guide.json(JSON) 파일만 올릴 수 있습니다.',
            tooBigText: '파일은 2MB 이하로 올려 주세요.',
            onSuccess: showImportResult
        });

        // 결과를 읽고 닫으면(닫기 버튼 또는 바탕) 새 목록을 보여준다.
        $('#importModal').on('click', function (event) {
            if (importDone && (event.target === this || $(event.target).is('[data-modal-close]'))) {
                reloadSoon();
            }
        });

        // 전체 교체는 되돌릴 수 없으므로 고를 때 한 번 더 묻는다.
        $('input[name="importMode"][value="replace"]').on('change', function () {
            showConfirmModal('전체 교체',
                '파일을 올리는 순간 지금 있는 가이드, 분류, 동의어가 모두 지워집니다. 먼저 guide.json 을 내려받아 두세요. 전체 교체로 올릴까요?',
                function () {},
                function () { $('input[name="importMode"][value="merge"]').prop('checked', true); });
        });
    }

    // ------------------------------------------------------------------

    $(function () {
        createGrid();
        setupImport();

        $('#matchForm').on('submit', runMatchTest);

        $('#btnCategories').on('click', function () {
            renderCategories();
            openModal('categoryModal');
        });

        $('#btnAddCategory').on('click', function () {
            apiPost(GUIDE_URLS.categorySave, {
                name: $('#newCategoryName').val(),
                sort_order: $('#newCategorySort').val()
            }, function (data, message) {
                showToast(message, 'success');
                reloadSoon();
            });
        });

        $('#btnSynonyms').on('click', function () { openModal('synonymModal'); });

        $('#btnSaveSynonyms').on('click', function () {
            apiPost(GUIDE_URLS.synonymsSave, { synonyms: $('#synonymText').val() }, function (data, message) {
                showToast(message, 'success');
                closeModal('synonymModal');
            });
        });

        $('#btnImport').on('click', function () {
            $('#importResult').addClass('hidden').empty();
            openModal('importModal');
        });

        $('#btnSaveTheme').on('click', function () {
            var $btn = $(this).prop('disabled', true);
            var theme = $('input[name="guideTheme"]:checked').val();

            apiPost(GUIDE_URLS.themeSave, { theme: theme }, function (data, message) {
                showToast(message, 'success');
                // '사용 중' 표시를 고른 테마로 옮긴다.
                $('[data-theme-current]').remove();
                $('input[name="guideTheme"][value="' + data.theme + '"]').closest('label').find('.font-semibold')
                    .append(' <span class="badge badge-green ml-1" data-theme-current>사용 중</span>');
            }).always(function () { $btn.prop('disabled', false); });
        });

        $('#btnReindex').on('click', function () {
            apiPost(GUIDE_URLS.reindex, {}, function (data, message) {
                showToast(message, 'success');
            });
        });
    });
})(jQuery);
