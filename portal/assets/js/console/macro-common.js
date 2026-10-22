/**
 * 파일 위치: assets/js/console/macro-common.js
 * 역할: 매크로 공통 함수 — 변수 치환, 이름/내용 검색 순위(한글 초성 검색 포함), 변수 토큰의 화면 표시
 *
 * 관리 화면(macro.js)과 상담 대화 입력창 / 목록(macro-picker.js)이 함께 쓴다. (docs/macro-plan.md)
 */
(function (window) {
    'use strict';

    var CHO = ['ㄱ', 'ㄲ', 'ㄴ', 'ㄷ', 'ㄸ', 'ㄹ', 'ㅁ', 'ㅂ', 'ㅃ', 'ㅅ', 'ㅆ', 'ㅇ', 'ㅈ', 'ㅉ', 'ㅊ', 'ㅋ', 'ㅌ', 'ㅍ', 'ㅎ'];

    /** 한글 음절을 초성으로 바꾼다. 나머지 글자는 그대로 둔다. ('환불 안내' -> 'ㅎㅂ ㅇㄴ') */
    function choseong(text) {
        var out = '';

        for (var i = 0; i < text.length; i++) {
            var code = text.charCodeAt(i);
            out += (code >= 0xAC00 && code <= 0xD7A3) ? CHO[Math.floor((code - 0xAC00) / 588)] : text.charAt(i);
        }

        return out;
    }

    function isChoseongOnly(q) {
        return /^[ㄱ-ㅎ\s]+$/.test(q);
    }

    /**
     * 검색어에 대한 순위. 작을수록 위. 맞지 않으면 -1.
     * 0 이름이 검색어로 시작, 1 이름에 포함, 2 이름 초성에 포함, 3 키워드에 포함, 4 내용에 포함
     */
    function rank(macro, q) {
        q = (q || '').toLowerCase().trim();

        if (!q) {
            return 0;
        }

        var name = macro.name.toLowerCase();

        if (name.indexOf(q) === 0) { return 0; }
        if (name.indexOf(q) > 0) { return 1; }
        if (isChoseongOnly(q) && choseong(name).replace(/\s+/g, '').indexOf(q.replace(/\s+/g, '')) >= 0) { return 2; }
        if ((macro.keywords || '').toLowerCase().indexOf(q) >= 0) { return 3; }
        if (macro.body.toLowerCase().indexOf(q) >= 0) { return 4; }

        return -1;
    }

    /**
     * 내용의 {변수} 를 값으로 바꾼다. values 에 없는 변수는 그대로 둔다.
     */
    function fill(body, values) {
        return String(body).replace(/\{([a-z_]+)\}/g, function (all, key) {
            return Object.prototype.hasOwnProperty.call(values, key) && values[key] !== null && values[key] !== undefined
                ? String(values[key]) : all;
        });
    }

    /**
     * 화면에 보일 때 {visitor_name} 을 {고객 이름} 으로 바꿔 보여준다(저장 값은 영문 토큰 그대로).
     */
    function label(body, variables) {
        return String(body).replace(/\{([a-z_]+)\}/g, function (all, key) {
            return variables && variables[key] ? '{' + variables[key] + '}' : all;
        });
    }

    window.RHMacro = {
        choseong: choseong,
        rank: rank,
        fill: fill,
        label: label
    };
})(window);
