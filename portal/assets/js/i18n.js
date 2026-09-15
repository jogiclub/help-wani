/**
 * 파일 위치: assets/js/i18n.js
 * 역할: 화면 문구 번역과 조직 타임존 기준 날짜 표시
 *
 * 사전은 assets/lang/{locale}.json 이며 PHP 와 같은 파일을 쓴다.
 * 페이지에서 RH_I18N = { locale, intl, timezone, messages } 를 먼저 정의해 두면
 * 추가 요청 없이 바로 동작한다.
 */
(function (window, document) {
    'use strict';

    var config = window.RH_I18N || {};
    var messages = config.messages || {};
    var locale = config.locale || 'ko';
    var intlLocale = config.intl || 'ko-KR';
    var timezone = config.timezone || 'Asia/Seoul';

    /**
     * 번역 문구를 돌려준다. 사전에 없으면 키를 그대로 돌려준다.
     * params 로 {org} 같은 자리표시자를 채운다.
     */
    function t(key, params) {
        var text = Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : key;

        if (params) {
            Object.keys(params).forEach(function (name) {
                text = text.split('{' + name + '}').join(params[name]);
            });
        }

        return text;
    }

    /**
     * UTC 문자열(또는 Date)을 조직 타임존과 언어로 표시한다.
     * style: 'datetime' | 'date' | 'time' | 'short'
     */
    function formatDate(value, style) {
        if (!value) {
            return '-';
        }

        var date = (value instanceof Date) ? value : parseUtc(value);

        if (!date || isNaN(date.getTime())) {
            return '-';
        }

        var options = { timeZone: timezone };

        switch (style) {
            case 'date':
                Object.assign(options, { year: 'numeric', month: '2-digit', day: '2-digit' });
                break;
            case 'time':
                Object.assign(options, { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
                break;
            case 'short':
                Object.assign(options, { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false });
                break;
            default:
                Object.assign(options, {
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hour12: false
                });
        }

        try {
            return new Intl.DateTimeFormat(intlLocale, options).format(date);
        } catch (e) {
            return date.toISOString();
        }
    }

    /**
     * 서버는 UTC 로 'YYYY-MM-DD HH:MM:SS' 를 내려준다.
     * 브라우저가 이를 지역 시각으로 오해하지 않도록 명시적으로 UTC 로 해석한다.
     */
    function parseUtc(value) {
        if (typeof value !== 'string') {
            return new Date(value);
        }

        var iso = value.trim().replace(' ', 'T');

        if (!/[zZ]|[+-]\d{2}:?\d{2}$/.test(iso)) {
            iso += 'Z';
        }

        return new Date(iso);
    }

    /**
     * data-i18n 속성이 있는 요소를 번역한다.
     *   <span data-i18n="survey.submit"></span>
     *   <input data-i18n-placeholder="survey.comment_placeholder">
     */
    function apply(root) {
        var scope = root || document;

        scope.querySelectorAll('[data-i18n]').forEach(function (el) {
            el.textContent = t(el.getAttribute('data-i18n'), readParams(el));
        });

        scope.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
            el.setAttribute('placeholder', t(el.getAttribute('data-i18n-placeholder'), readParams(el)));
        });

        scope.querySelectorAll('[data-i18n-title]').forEach(function (el) {
            el.setAttribute('title', t(el.getAttribute('data-i18n-title'), readParams(el)));
        });

        // data-i18n-date 속성이 있으면 조직 타임존으로 다시 그린다.
        scope.querySelectorAll('[data-i18n-date]').forEach(function (el) {
            el.textContent = formatDate(el.getAttribute('data-i18n-date'),
                el.getAttribute('data-i18n-date-style') || 'datetime');
        });
    }

    /** data-i18n-params='{"org":"데모"}' 형태의 치환값 */
    function readParams(el) {
        var raw = el.getAttribute('data-i18n-params');

        if (!raw) {
            return null;
        }

        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.documentElement.setAttribute('lang', locale);
        apply(document);
    });

    window.RHI18n = {
        t: t,
        apply: apply,
        formatDate: formatDate,
        parseUtc: parseUtc,
        locale: locale,
        intlLocale: intlLocale,
        timezone: timezone
    };

    // 짧게 쓰기 위한 별칭
    window.__ = t;
})(window, document);
