/**
 * 파일 위치: assets/js/admin/guide.js
 * 역할: 가이드 관리 목록(wgrid, 새 기능만 거르기), 외부 이미지 저장, 매칭 시험, 분류/동의어 관리, guide.json 올리기,
 *       공개 페이지 테마와 구성(새 기능 모음, 동영상 모음)
 */
(function ($) {
    'use strict';

    var STATUS_CLASS = { published: 'badge-green', draft: 'badge-gray' };
    var STATUS_LABEL = { published: '공개', draft: '임시' };

    var grid = null;

    /** 가져오기가 끝났으면 모달을 닫을 때 새 목록을 불러온다 */
    var importDone = false;

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 800);
    }

    // ------------------------------------------------------------------
    // 목록
    // ------------------------------------------------------------------

    function titleRenderer(row, value) {
        var wrap = document.createElement('div');
        wrap.className = 'flex min-w-0 flex-col justify-center leading-tight';

        var link = document.createElement('a');
        link.className = 'truncate font-medium text-slate-800 hover:text-brand-600 hover:underline';
        link.href = GUIDE_URLS.edit + row.id;
        link.textContent = value;
        wrap.appendChild(link);

        if (row.subtitle) {
            var sub = document.createElement('span');
            sub.className = 'truncate text-xs text-slate-400';
            sub.textContent = row.subtitle;
            wrap.appendChild(sub);
        }

        return wrap;
    }

    /** 새 기능 칸: 배지 + 출시일(조직 시간대 날짜) + 버전 */
    function featureRenderer(row) {
        if (!row.is_feature) {
            return '-';
        }

        var wrap = document.createElement('div');
        wrap.className = 'flex min-w-0 flex-col justify-center leading-tight';

        var badge = document.createElement('span');
        badge.className = 'badge badge-blue w-fit';
        badge.textContent = '새 기능' + (row.feature_label ? ' ' + row.feature_label : '');
        wrap.appendChild(badge);

        var date = document.createElement('span');
        date.className = 'mt-0.5 text-xs text-slate-400';
        date.textContent = row.feature_date || '공개 전';
        wrap.appendChild(date);

        return wrap;
    }

    /** 위쪽 "새 기능만" 거르기를 반영한 행 */
    function visibleRows() {
        return $('#filterFeature').is(':checked')
            ? GUIDE_ROWS.filter(function (row) { return row.is_feature; })
            : GUIDE_ROWS;
    }

    function removeArticle(row) {
        showConfirmModal('가이드 삭제',
            '"' + row.title + '" 가이드를 삭제할까요? 들어 있는 이미지도 함께 지워지고 되돌릴 수 없습니다.',
            function () {
                apiPost(GUIDE_URLS.remove, { id: row.id }, function (data, message) {
                    showToast(message, 'success');
                    GUIDE_ROWS = GUIDE_ROWS.filter(function (r) { return r !== row; });
                    grid.removeRow(row);
                });
            });
    }

    function toggleStatus(row) {
        var next = row.status === 'published' ? 'draft' : 'published';

        apiPost(GUIDE_URLS.status, { id: row.id, status: next }, function (data, message) {
            showToast(message, 'success');
            row.status = data.status;
            grid.refresh();
        });
    }

    function createGrid() {
        // 행 높이를 부제목에 맞춰 키웠다. wgrid 셀은 세로 가운데 정렬이라 한 줄짜리 칸도 그대로 맞는다.
        grid = RHGrid.create('guideGrid', {
            columns: [
                { title: '분류', key: 'category_name', width: 120,
                  format: function (v) { return v || '분류 없음'; } },
                { title: '제목', key: 'title', width: 220, flex: 1, align: 'left', render: titleRenderer },
                { title: '키워드', key: 'keywords', width: 140, flex: 1,
                  format: function (v) { return v || '-'; } },
                {
                    title: '상태', key: 'status', width: 90,
                    render: RHGrid.badgeRenderer(STATUS_CLASS, STATUS_LABEL),
                    format: function (v) { return STATUS_LABEL[v] || v; }
                },
                { title: '새 기능', key: 'feature_date', width: 120, render: featureRenderer,
                  format: function (v, row) { return row.is_feature ? '새 기능 ' + (v || '') : ''; } },
                { title: '조회', key: 'view_count', width: 80, type: 'number', align: 'center' },
                { title: '수정', key: 'updated_at', width: 120,
                  format: RHGrid.shortDateTime },
                {
                    title: '관리', key: '_actions', width: 230, sortable: false, pinned: 'right',
                    render: RHGrid.buttonsRenderer([
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
            rows: visibleRows(),
            rowHeight: 52,
            rowId: function (row) { return row.id; },
            noRowsText: '아직 가이드가 없습니다. [새 가이드] 로 만들거나 guide.json 을 올려 주세요.'
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
    // 공개 페이지 구성 (새 기능 모음, 동영상 모음)
    // ------------------------------------------------------------------

    /** 상위 옵션이 꺼져 있으면 하위 항목을 흐리게 하고 고를 수 없게 한다 */
    function syncOptionGroups() {
        [['feature', '#optFeatureEnabled'], ['video', '#optVideoEnabled']].forEach(function (pair) {
            var on = $(pair[1]).is(':checked');
            $('[data-option-group="' + pair[0] + '"]').toggleClass('opacity-50', !on)
                .find('input, select').prop('disabled', !on);
        });
    }

    function savePageOptions() {
        var $btn = $('#btnSavePageOptions').prop('disabled', true);

        apiPost(GUIDE_URLS.pageOptionsSave, {
            feature_enabled: $('#optFeatureEnabled').is(':checked') ? 1 : 0,
            feature_new_days: $('#optFeatureNewDays').val(),
            feature_banner: $('#optFeatureBanner').is(':checked') ? 1 : 0,
            video_enabled: $('#optVideoEnabled').is(':checked') ? 1 : 0,
            video_sort: $('#optVideoSort').val(),
            video_filter: $('#optVideoFilter').is(':checked') ? 1 : 0
        }, function (data, message) {
            showToast(message, 'success');
        }).always(function () { $btn.prop('disabled', false); });
    }

    function setupPageOptions() {
        syncOptionGroups();
        $('#optFeatureEnabled, #optVideoEnabled').on('change', syncOptionGroups);

        $('#btnSavePageOptions').on('click', function () {
            // 켰는데 모을 것이 없으면 고객 화면에 빈 페이지가 보이므로 한 번 묻는다.
            var empty = [];

            if ($('#optFeatureEnabled').is(':checked') && Number($('#featureCount').text()) === 0) {
                empty.push('새 기능 모음(새 기능으로 표시된 공개 가이드 0건)');
            }
            if ($('#optVideoEnabled').is(':checked') && Number($('#videoCount').text()) === 0) {
                empty.push('동영상 모음(공개 가이드의 동영상 0개)');
            }

            if (!empty.length) {
                savePageOptions();
                return;
            }

            showConfirmModal('빈 모음 페이지',
                empty.join(', ') + ' 은 고객 화면에 빈 페이지로 보입니다. 그래도 저장할까요?',
                savePageOptions);
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

        $('#btnLocalizeImages').on('click', function () {
            var $btn = $(this);

            showConfirmModal('외부 이미지를 본 서버로 저장',
                '가이드에 들어 있는 외부 이미지(https 주소)를 받아 우리 조직 폴더에 저장하고 글의 주소를 바꿉니다. '
                + '이미지가 많으면 시간이 걸릴 수 있습니다. 진행할까요?',
                function () {
                    var label = $btn.text();
                    $btn.prop('disabled', true).text('저장하는 중...');

                    apiPost(GUIDE_URLS.localizeImages, {}, function (data, message) {
                        if (data.failed_count > 0) {
                            // 실패 사유는 앞의 몇 개만 알림에 붙이고 전체(최대 20개)는 콘솔에 남긴다.
                            var reasons = data.failed.slice(0, 3).map(function (f) { return f.reason; }).join(' / ');
                            showToast(message + ' (' + reasons + ')', 'warning');
                            if (window.console) { console.warn('받지 못한 외부 이미지', data.failed); }
                        } else {
                            showToast(message, 'success');
                        }
                    }).always(function () { $btn.prop('disabled', false).text(label); });
                });
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

        $('#filterFeature').on('change', function () { grid.setRows(visibleRows()); });

        setupPageOptions();

        $('#btnReindex').on('click', function () {
            apiPost(GUIDE_URLS.reindex, {}, function (data, message) {
                showToast(message, 'success');
            });
        });
    });
})(jQuery);
