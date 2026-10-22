/**
 * 파일 위치: assets/js/image-drop.js
 * 역할: 포털의 모든 이미지 선택/업로드 공통 모듈 (Dropzone.js 6 래퍼)
 *
 * 이미지 업로드는 반드시 이 모듈을 거친다(CLAUDE.md 3.14). 파일 입력 태그를 화면에 직접 두지 않는다.
 * 컨트롤러에서 'use_dropzone' => TRUE 를 넘겨야 Dropzone 라이브러리를 불러온다.
 *
 * 사용 예
 *   RHImageDrop.create('#logoDrop', {
 *       url: ORG_URLS.logoUpload,      // 절대 주소 (CLAUDE.md 3.7)
 *       paramName: 'logo',
 *       onSuccess: function (data, message) { ... }
 *   });
 *
 * 응답은 공통 규약 { result, message, data } 를 따른다. 오류 문구는 서버 message 를 그대로 보여준다.
 *
 * 선택 옵션
 *   maxMb           최대 크기(MiB, 기본 1)
 *   accept          허용 형식(기본 PNG/JPG/WEBP). guide.json 올리기는 '.json,application/json'
 *   params          함께 보낼 값. 객체 또는 객체를 돌려주는 함수 (예: { mode: 'merge' })
 *   invalidTypeText 형식이 틀렸을 때 문구
 *   tooBigText      크기가 넘쳤을 때 문구
 *   onError         실패 응답을 받을 함수 (res) — 서버가 data 에 오류 목록을 담아 줄 때(매크로 CSV 등록). 알림은 그대로 띄운다
 */
(function (window, $) {
    'use strict';

    /** 서버가 받는 형식과 같아야 한다(Admin::api_logo_upload) */
    var DEFAULT_ACCEPT = 'image/png,image/jpeg,image/webp';

    /** Dropzone 오류 값(문자열 또는 파싱된 JSON)에서 보여줄 문구를 꺼낸다 */
    function errorText(value) {
        if (value && typeof value === 'object' && value.message) {
            return value.message;
        }
        return typeof value === 'string' && value !== '' ? value : '이미지를 올리지 못했습니다.';
    }

    function create(selector, options) {
        var el = typeof selector === 'string' ? document.querySelector(selector) : selector;

        if (!el || !window.Dropzone) {
            return null;
        }

        options = options || {};
        var maxMb = options.maxMb || 1;

        var dz = new window.Dropzone(el, {
            url: options.url,
            paramName: options.paramName || 'file',
            maxFiles: null,
            maxFilesize: maxMb,           // MiB 단위 (서버 RH_LOGO_MAX_BYTES 와 같게)
            filesizeBase: 1024,
            acceptedFiles: options.accept || DEFAULT_ACCEPT,
            // 미리보기는 화면이 직접 그린다. Dropzone 기본 미리보기와 CSS 는 쓰지 않는다.
            disablePreviews: true,
            createImageThumbnails: false,
            // CSRF 토큰을 폼 필드로 함께 보낸다(common.js 의 csrfData 와 같은 값).
            params: function () {
                var extra = typeof options.params === 'function' ? options.params() : (options.params || {});
                return window.csrfData($.extend({}, extra));
            },
            dictInvalidFileType: options.invalidTypeText || 'PNG, JPG, WEBP 이미지만 올릴 수 있습니다.',
            dictFileTooBig: options.tooBigText || ('이미지는 ' + maxMb + 'MB 이하로 올려 주세요.'),
            dictResponseError: '서버와 통신하지 못했습니다. ({{statusCode}})'
        });

        dz.on('sending', function () {
            el.classList.add('is-uploading');
        });

        dz.on('success', function (file, res) {
            if (res && res.result) {
                if (options.onSuccess) { options.onSuccess(res.data, res.message); }
            } else {
                showToast(errorText(res), 'danger');
                if (options.onError) { options.onError(res); }
            }
        });

        dz.on('error', function (file, message) {
            showToast(errorText(message), 'danger');
            // 서버가 4xx 와 함께 JSON 을 돌려주면 Dropzone 이 파싱한 객체가 온다.
            if (options.onError && message && typeof message === 'object') { options.onError(message); }
        });

        // 한 번 올린 파일은 목록에서 비워 다음 선택을 막지 않게 한다.
        dz.on('complete', function (file) {
            el.classList.remove('is-uploading');
            dz.removeFile(file);
        });

        return dz;
    }

    window.RHImageDrop = { create: create };
})(window, jQuery);
