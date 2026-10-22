/**
 * 파일 위치: assets/js/guide.js
 * 역할: 공개 가이드 화면의 문서형 테마 목차 열고 닫기와 목차 너비 조절(Split.js)
 *
 * 유튜브 동영상은 views/guide/_article.php 가 처음부터 iframe 으로 그린다.
 */
(function () {
    'use strict';

    /**
     * 문서형 테마: 좁은 화면에서 머리말의 목차 버튼(data-gt-toc="목차 id")으로 왼쪽 목차를 연다.
     * 목차 밖을 누르면 닫는다.
     */
    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('[data-gt-toc]');
        var opened = document.querySelector('.gt-docs-side.is-open');

        if (toggle) {
            var toc = document.getElementById(toggle.getAttribute('data-gt-toc'));

            if (toc) {
                toc.classList.toggle('is-open');
                toggle.setAttribute('aria-expanded', toc.classList.contains('is-open') ? 'true' : 'false');
            }
            return;
        }

        if (opened && !event.target.closest('.gt-docs-side')) {
            opened.classList.remove('is-open');
        }
    });

    /**
     * 문서형 테마: 넓은 화면에서 목차와 본문 사이를 Split.js 로 끌어 너비를 바꾼다.
     * 좁은 화면(991px 이하)은 목차가 서랍이 되므로 풀고, 다시 넓어지면 건다.
     * Split.js 는 비율(%)로 나누므로, 창 크기가 바뀌면 고른 목차 너비(px)를 다시 비율로 바꿔 유지한다.
     * 고른 너비는 이 브라우저에만 기억한다. 사이 막대를 두 번 누르면 처음 너비로 돌아간다.
     */
    function initDocsSplit() {
        var shell = document.querySelector('[data-gt-split]');

        if (!shell || typeof window.Split !== 'function' || !window.matchMedia) {
            return;
        }

        var side = shell.querySelector('.gt-docs-side');
        var main = shell.querySelector('.gt-docs-main');
        var wide = window.matchMedia('(min-width: 992px)');
        var STORE_KEY = 'gt-docs-side-width';
        var DEFAULT_PX = 264;
        var MIN_PX = 200;
        var MAX_PX = 520;
        var KEY_STEP = 16;
        var GUTTER = 8;
        var split = null;
        var gutterEl = null;
        var widthPx = savedWidth();

        function savedWidth() {
            try {
                var px = parseInt(window.localStorage.getItem(STORE_KEY), 10);
                return px >= MIN_PX && px <= MAX_PX ? px : DEFAULT_PX;
            } catch (e) {
                return DEFAULT_PX;
            }
        }

        function remember() {
            widthPx = Math.round(side.getBoundingClientRect().width);
            gutterEl.setAttribute('aria-valuenow', String(widthPx));

            try {
                window.localStorage.setItem(STORE_KEY, String(widthPx));
            } catch (e) { /* 저장을 못 해도 동작에는 지장이 없다 */ }
        }

        // Split.js 는 양 끝 요소의 너비를 calc(비율% - 막대 절반) 으로 주므로 그만큼 더해서 비율을 만든다.
        function sizesFor(px) {
            var pct = Math.min(90, (px + GUTTER / 2) / shell.clientWidth * 100);
            return [pct, 100 - pct];
        }

        function setSideWidth(px) {
            split.setSizes(sizesFor(Math.max(MIN_PX, Math.min(MAX_PX, px))));
            remember();
        }

        function makeGutter() {
            var gutter = document.createElement('div');

            gutter.className = 'gt-docs-gutter';
            gutter.setAttribute('role', 'separator');
            gutter.setAttribute('aria-orientation', 'vertical');
            gutter.setAttribute('aria-controls', side.id);
            gutter.setAttribute('aria-label', shell.getAttribute('data-gt-split-label') || '');
            gutter.setAttribute('aria-valuemin', String(MIN_PX));
            gutter.setAttribute('aria-valuemax', String(MAX_PX));
            gutter.tabIndex = 0;

            gutter.addEventListener('dblclick', function () { setSideWidth(DEFAULT_PX); });
            gutter.addEventListener('keydown', function (event) {
                var target = {
                    ArrowLeft: widthPx - KEY_STEP,
                    ArrowRight: widthPx + KEY_STEP,
                    Home: MIN_PX,
                    End: MAX_PX
                }[event.key];

                if (target === undefined) { return; }

                event.preventDefault();
                setSideWidth(target);
            });

            gutterEl = gutter;
            return gutter;
        }

        function attach() {
            if (split) { return; }

            shell.classList.add('is-split');
            split = window.Split([side, main], {
                sizes: sizesFor(widthPx),
                minSize: [MIN_PX, 360],
                maxSize: [MAX_PX, Infinity],
                gutterSize: GUTTER,
                snapOffset: 0,
                gutter: makeGutter,
                onDragEnd: remember
            });
            gutterEl.setAttribute('aria-valuenow', String(widthPx));
        }

        function detach() {
            if (!split) { return; }

            split.destroy();
            split = null;
            gutterEl = null;
            shell.classList.remove('is-split');
        }

        function apply() {
            if (wide.matches) { attach(); } else { detach(); }
        }

        var resizeFrame = 0;

        window.addEventListener('resize', function () {
            if (!split || resizeFrame) { return; }

            resizeFrame = window.requestAnimationFrame(function () {
                resizeFrame = 0;
                if (split) { split.setSizes(sizesFor(widthPx)); }
            });
        });

        if (wide.addEventListener) {
            wide.addEventListener('change', apply);
        } else if (wide.addListener) {
            wide.addListener(apply);
        }

        apply();
    }

    initDocsSplit();
})();
