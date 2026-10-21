/**
 * 파일 위치: assets/js/guide.js
 * 역할: 공개 가이드 화면의 유튜브 동영상을 누를 때 불러오기, 문서형 테마의 목차 열고 닫기
 *
 * 페이지를 여는 것만으로 유튜브에 요청이 가지 않도록, 누른 뒤에 iframe 을 만든다.
 * 주소는 개인정보 보호 강화 모드(youtube-nocookie.com/embed/)로만 만든다.
 * 유튜브는 임베드 요청에 Referer 를 요구하므로 strict-origin-when-cross-origin 을 쓴다.
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

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.guide-video');

        if (!button) {
            return;
        }

        var id = button.getAttribute('data-youtube-id') || '';

        if (!/^[A-Za-z0-9_-]{11}$/.test(id)) {
            return;
        }

        var frame = document.createElement('iframe');
        frame.className = 'aspect-video w-full rounded-lg';
        frame.title = button.textContent.trim();
        frame.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
        // src 보다 먼저 정해야 첫 요청에 적용된다.
        frame.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        frame.setAttribute('allowfullscreen', '');
        frame.src = 'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0';

        button.replaceWith(frame);
    });
})();
