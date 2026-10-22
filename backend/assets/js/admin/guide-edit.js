/**
 * 파일 위치: assets/js/admin/guide-edit.js
 * 역할: 가이드 편집 화면의 본문 블록 편집기(글/이미지/유튜브)와 저장
 *
 * 블록은 [{type:'text',text}, {type:'image',src,caption}, {type:'video',url|youtube_id,caption}] 로 보낸다.
 * 서버(Guide_model::clean_article)가 같은 검사를 다시 하므로 여기서는 입력 편의만 맡는다.
 *
 * Bootstrap 6 화면(layouts/main.html). 블록 버튼 클래스는 RHUi 톤 이름으로 고른다(common.js).
 */
(function ($) {
    'use strict';

    var MAX_BLOCKS = 50;
    var TYPE_LABEL = { text: '글', image: '이미지', video: '유튜브 동영상' };

    var articleId = GUIDE_ARTICLE.id;
    var dirty = false;

    /**
     * 화면에서 영상 id 를 미리 보여주기 위한 것. 최종 판단은 서버(Guide_model::youtube_id)가 한다.
     */
    function youtubeId(url) {
        url = $.trim(url || '');

        if (/^[A-Za-z0-9_-]{11}$/.test(url)) {
            return url;
        }

        var m = url.match(/^https?:\/\/(?:www\.|m\.)?(?:youtube\.com\/(?:watch\?(?:.*&)?v=|shorts\/|embed\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/);

        return m ? m[1] : '';
    }

    // ------------------------------------------------------------------
    // 블록
    // ------------------------------------------------------------------

    /** 아이콘(Bootstrap Icons)을 앞에 둔 버튼. 글자가 없으면 title 을 aria-label 로도 쓴다. */
    function toolButton(tone, icon, text, title) {
        var $btn = $('<button type="button">').addClass(RHUi.button(tone))
            .append($('<i aria-hidden="true">').addClass('bi bi-' + icon));

        if (text) {
            $btn.append(document.createTextNode(text));
        }
        if (title) {
            $btn.attr({ title: title, 'aria-label': title });
        }

        return $btn;
    }

    function toolbar(type) {
        var $bar = $('<div class="d-flex align-items-center gap-1 mb-3">');
        $bar.append($('<span class="me-auto fs-xs fw-semibold fg-3">').text(TYPE_LABEL[type]));
        $bar.append(toolButton('ghost', 'arrow-up', '', '위로').attr('data-move', '-1'));
        $bar.append(toolButton('ghost', 'arrow-down', '', '아래로').attr('data-move', '1'));
        $bar.append(toolButton('danger', 'trash', '삭제').attr('data-remove', ''));

        return $bar;
    }

    function textBlock(block) {
        var $el = $('<div class="guide-block rh-guide-block" data-type="text">').append(toolbar('text'));
        $el.append($('<textarea class="form-control" rows="5" data-field="text" maxlength="5000" aria-label="글">').val(block.text || ''));

        return $el;
    }

    function imageBlock(block) {
        var $el = $('<div class="guide-block rh-guide-block" data-type="image">').append(toolbar('image'));
        var $src = $('<input type="hidden" data-field="src">').val(block.src || '');
        var $drop = $('<div class="image-drop">');
        var $img = $('<img class="rh-guide-block-img rounded-3" alt="">');
        var $hint = $('<span class="image-drop-hint">').text('이미지를 여기로 끌어다 놓거나 눌러서 선택하세요');

        if (block.src) {
            // 외부 https 이미지(guide.json 으로 옮겨 온 것)는 주소 그대로 보여준다.
            $img.attr('src', /^https:\/\//i.test(block.src) ? block.src : GUIDE_URLS.assetBase + block.src)
                .attr('referrerpolicy', 'no-referrer');
        } else {
            $img.addClass('d-none');
        }

        $drop.append($img, $hint);
        $el.append($src, $drop);
        $el.append($('<input class="form-control mt-3" data-field="caption" maxlength="200" placeholder="설명 (선택)" aria-label="설명">').val(block.caption || ''));

        // Dropzone 은 DOM 에 붙은 뒤에 만든다(addBlock 에서 호출).
        $el.data('setup', function () {
            RHImageDrop.create($drop.get(0), {
                url: GUIDE_URLS.image,
                paramName: 'image',
                maxMb: 2,
                onSuccess: function (data) {
                    $src.val(data.src);
                    $img.attr('src', data.url).removeClass('d-none');
                    dirty = true;
                }
            });
        });

        return $el;
    }

    function videoBlock(block) {
        var $el = $('<div class="guide-block rh-guide-block" data-type="video">').append(toolbar('video'));
        var start = block.youtube_id ? 'https://www.youtube.com/watch?v=' + block.youtube_id : (block.url || '');
        var $url = $('<input class="form-control font-monospace fs-sm" data-field="url" aria-label="유튜브 주소" placeholder="https://www.youtube.com/watch?v=... 또는 https://youtu.be/...">').val(start);
        var $state = $('<div class="form-text">');

        function update() {
            var id = youtubeId($url.val());

            if (!$.trim($url.val())) {
                $state.removeClass('fg-danger').text('유튜브 영상 주소를 붙여 넣으세요. (watch, youtu.be, shorts 주소)');
            } else if (id) {
                $state.removeClass('fg-danger').text('영상 ' + id + ' 을(를) 넣습니다. 고객 화면에서는 누를 때 재생됩니다.');
            } else {
                $state.addClass('fg-danger').text('유튜브 주소를 알아보지 못했습니다.');
            }
        }

        $url.on('input', update);
        update();

        $el.append($url, $state);
        $el.append($('<input class="form-control mt-3" data-field="caption" maxlength="200" placeholder="설명 (선택)" aria-label="설명">').val(block.caption || ''));

        return $el;
    }

    function addBlock(block) {
        var $list = $('#blockList');

        if ($list.children().length >= MAX_BLOCKS) {
            showToast('블록은 ' + MAX_BLOCKS + '개까지 넣을 수 있습니다.', 'warning');
            return;
        }

        var builders = { text: textBlock, image: imageBlock, video: videoBlock };
        var $el = (builders[block.type] || textBlock)(block);

        $list.append($el);

        if ($el.data('setup')) {
            $el.data('setup')();
        }
    }

    function collectBlocks() {
        var blocks = [];

        $('#blockList .guide-block').each(function () {
            var $b = $(this);
            var type = $b.attr('data-type');
            var field = function (name) { return $b.find('[data-field="' + name + '"]').val() || ''; };

            if (type === 'text') {
                blocks.push({ type: 'text', text: field('text') });
            } else if (type === 'image') {
                blocks.push({ type: 'image', src: field('src'), caption: field('caption') });
            } else if (type === 'video') {
                blocks.push({ type: 'video', url: field('url'), caption: field('caption') });
            }
        });

        return blocks;
    }

    // ------------------------------------------------------------------
    // 저장
    // ------------------------------------------------------------------

    function save() {
        var $btn = $('#btnSaveGuide').prop('disabled', true);

        apiPost(GUIDE_URLS.save, {
            id: articleId,
            title: $('#guideTitle').val(),
            subtitle: $('#guideSubtitle').val(),
            slug: $('#guideSlug').val(),
            keywords: $('#guideKeywords').val(),
            status: $('input[name="guideStatus"]:checked').val(),
            category_id: $('#guideCategory').val(),
            sort_order: $('#guideSort').val(),
            is_feature: $('#guideIsFeature').is(':checked') ? 1 : 0,
            feature_date: $('#guideFeatureDate').val(),
            feature_label: $('#guideFeatureLabel').val(),
            blocks: JSON.stringify(collectBlocks())
        }, function (data, message) {
            dirty = false;
            showToast(message, data.warnings && data.warnings.length ? 'warning' : 'success');

            (data.warnings || []).forEach(function (text) { showToast(text, 'warning'); });

            // 새 글이면 편집 주소로 옮겨 미리보기와 다음 저장이 같은 글을 가리키게 한다.
            if (!articleId) {
                window.location.href = data.edit_url;
                return;
            }

            $('#guidePublicLink').attr('href', data.public_url).text(data.public_url);
        }).always(function () { $btn.prop('disabled', false); });
    }

    // ------------------------------------------------------------------

    $(function () {
        (GUIDE_ARTICLE.blocks || []).forEach(addBlock);

        $('[data-add-block]').on('click', function () {
            addBlock({ type: $(this).attr('data-add-block') });
            dirty = true;
        });

        $('#blockList').on('click', '[data-remove]', function () {
            $(this).closest('.guide-block').remove();
            dirty = true;
        });

        $('#blockList').on('click', '[data-move]', function () {
            var $block = $(this).closest('.guide-block');

            if ($(this).attr('data-move') === '-1') {
                $block.prev().before($block);
            } else {
                $block.next().after($block);
            }

            dirty = true;
        });

        $(document).on('input change', 'input, textarea, select', function () { dirty = true; });

        $('#btnSaveGuide').on('click', save);

        // 새 기능 표시를 켰을 때만 출시일과 버전을 고친다.
        function syncFeatureFields() {
            var on = $('#guideIsFeature').is(':checked');
            $('#featureFields').toggleClass('opacity-50', !on).find('input').prop('disabled', !on);
        }

        syncFeatureFields();
        $('#guideIsFeature').on('change', syncFeatureFields);

        // Ctrl+S 로도 저장한다.
        $(document).on('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                event.preventDefault();
                save();
            }
        });

        window.addEventListener('beforeunload', function (event) {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    });
})(jQuery);
