/**
 * 파일 위치: assets/js/admin/guide-edit.js
 * 역할: 가이드 편집 화면의 본문 블록 편집기(글/이미지/유튜브)와 저장
 *
 * 블록은 [{type:'text',text}, {type:'image',src,caption}, {type:'video',url|youtube_id,caption}] 로 보낸다.
 * 서버(Guide_model::clean_article)가 같은 검사를 다시 하므로 여기서는 입력 편의만 맡는다.
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

    function toolbar(type) {
        var $bar = $('<div class="mb-2 flex items-center gap-1">');
        $bar.append($('<span class="mr-auto text-xs font-semibold text-slate-500">').text(TYPE_LABEL[type]));
        $bar.append('<button type="button" class="btn btn-sm btn-ghost" data-move="-1" title="위로">↑</button>');
        $bar.append('<button type="button" class="btn btn-sm btn-ghost" data-move="1" title="아래로">↓</button>');
        $bar.append('<button type="button" class="btn btn-sm btn-ghost text-red-600" data-remove>삭제</button>');

        return $bar;
    }

    function textBlock(block) {
        var $el = $('<div class="guide-block rounded-lg border border-slate-200 p-3" data-type="text">').append(toolbar('text'));
        $el.append($('<textarea class="form-input" rows="5" data-field="text" maxlength="5000">').val(block.text || ''));

        return $el;
    }

    function imageBlock(block) {
        var $el = $('<div class="guide-block rounded-lg border border-slate-200 p-3" data-type="image">').append(toolbar('image'));
        var $src = $('<input type="hidden" data-field="src">').val(block.src || '');
        var $drop = $('<div class="image-drop">');
        var $img = $('<img class="max-h-48 max-w-full rounded" alt="">');
        var $hint = $('<span class="image-drop-hint">').text('이미지를 여기로 끌어다 놓거나 눌러서 선택하세요');

        if (block.src) {
            $img.attr('src', GUIDE_URLS.assetBase + block.src);
        } else {
            $img.addClass('hidden');
        }

        $drop.append($img, $hint);
        $el.append($src, $drop);
        $el.append($('<input class="form-input mt-2" data-field="caption" maxlength="200" placeholder="설명 (선택)">').val(block.caption || ''));

        // Dropzone 은 DOM 에 붙은 뒤에 만든다(addBlock 에서 호출).
        $el.data('setup', function () {
            RHImageDrop.create($drop.get(0), {
                url: GUIDE_URLS.image,
                paramName: 'image',
                maxMb: 2,
                onSuccess: function (data) {
                    $src.val(data.src);
                    $img.attr('src', data.url).removeClass('hidden');
                    dirty = true;
                }
            });
        });

        return $el;
    }

    function videoBlock(block) {
        var $el = $('<div class="guide-block rounded-lg border border-slate-200 p-3" data-type="video">').append(toolbar('video'));
        var start = block.youtube_id ? 'https://www.youtube.com/watch?v=' + block.youtube_id : (block.url || '');
        var $url = $('<input class="form-input font-mono text-sm" data-field="url" placeholder="https://www.youtube.com/watch?v=... 또는 https://youtu.be/...">').val(start);
        var $state = $('<div class="form-help">');

        function update() {
            var id = youtubeId($url.val());

            if (!$.trim($url.val())) {
                $state.removeClass('text-red-600').text('유튜브 영상 주소를 붙여 넣으세요. (watch, youtu.be, shorts 주소)');
            } else if (id) {
                $state.removeClass('text-red-600').text('영상 ' + id + ' 을(를) 넣습니다. 고객 화면에서는 누를 때 재생됩니다.');
            } else {
                $state.addClass('text-red-600').text('유튜브 주소를 알아보지 못했습니다.');
            }
        }

        $url.on('input', update);
        update();

        $el.append($url, $state);
        $el.append($('<input class="form-input mt-2" data-field="caption" maxlength="200" placeholder="설명 (선택)">').val(block.caption || ''));

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
