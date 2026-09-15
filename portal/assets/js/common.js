/**
 * 파일 위치: assets/js/common.js
 * 역할: 공통 UI 함수(showToast, showConfirmModal, openModal/closeModal)와 AJAX 래퍼
 *
 * 외부 UI 프레임워크에 의존하지 않는다. 표시는 Tailwind 클래스로 처리한다.
 */
(function (window, $) {
    'use strict';

    var TOAST_STYLE = {
        success: 'bg-emerald-600',
        danger: 'bg-red-600',
        warning: 'bg-amber-500',
        info: 'bg-slate-700'
    };

    /**
     * CSRF 토큰을 포함한 기본 POST 데이터
     */
    function csrfData(data) {
        var name = $('meta[name="csrf-name"]').attr('content');
        var hash = $('meta[name="csrf-hash"]').attr('content');
        data = data || {};
        if (name && hash) {
            data[name] = hash;
        }
        return data;
    }

    /**
     * 알림 토스트
     * type: success | danger | warning | info
     */
    function showToast(message, type) {
        var $container = $('#toastContainer');
        if (!$container.length) {
            window.alert(message);
            return;
        }

        var color = TOAST_STYLE[type] || TOAST_STYLE.info;

        var $toast = $('<div>')
            .addClass('pointer-events-auto flex items-start gap-3 rounded-lg px-4 py-3 ' +
                      'text-sm text-white shadow-lg transition-all duration-200 ' +
                      'opacity-0 translate-x-4 ' + color);

        $('<div>').addClass('flex-1').text(message).appendTo($toast);
        $('<button type="button" aria-label="닫기">')
            .addClass('shrink-0 text-white/70 hover:text-white')
            .text('×')
            .on('click', function () { dismiss(); })
            .appendTo($toast);

        $container.append($toast);

        // 다음 프레임에 전환 효과를 준다.
        window.requestAnimationFrame(function () {
            $toast.removeClass('opacity-0 translate-x-4');
        });

        var timer = window.setTimeout(dismiss, 4000);

        function dismiss() {
            window.clearTimeout(timer);
            $toast.addClass('opacity-0 translate-x-4');
            window.setTimeout(function () { $toast.remove(); }, 200);
        }
    }

    /**
     * 모달 열기 / 닫기 (id 로 지정한 요소의 hidden 클래스를 토글한다)
     */
    function openModal(id) {
        $('#' + id).removeClass('hidden').addClass('flex');
    }

    function closeModal(id) {
        $('#' + id).addClass('hidden').removeClass('flex');
    }

    /**
     * 확인 모달
     */
    function showConfirmModal(title, message, onConfirm, onCancel) {
        var $modal = $('#confirmModal');
        if (!$modal.length) {
            if (window.confirm(message)) {
                if (onConfirm) { onConfirm(); }
            } else if (onCancel) {
                onCancel();
            }
            return;
        }

        $('#confirmModalTitle').text(title);
        $('#confirmModalBody').text(message);

        $('#confirmModalOk').off('click').on('click', function () {
            closeModal('confirmModal');
            if (onConfirm) { onConfirm(); }
        });

        $('#confirmModalCancel').off('click').on('click', function () {
            closeModal('confirmModal');
            if (onCancel) { onCancel(); }
        });

        openModal('confirmModal');
    }

    /**
     * 공통 AJAX 래퍼. 응답 형식 { result, message, data } 를 전제로 한다.
     */
    function apiPost(url, data, onSuccess, onError) {
        return $.ajax({
            url: url,
            type: 'POST',
            dataType: 'json',
            data: csrfData(data)
        }).done(function (res) {
            if (res && res.result) {
                if (onSuccess) { onSuccess(res.data, res.message); }
            } else {
                var msg = (res && res.message) ? res.message : '요청을 처리하지 못했습니다.';
                if (onError) { onError(msg, res); } else { showToast(msg, 'danger'); }
            }
        }).fail(function (xhr) {
            var msg = '서버와 통신하지 못했습니다.';
            if (xhr.responseJSON && xhr.responseJSON.message) {
                msg = xhr.responseJSON.message;
            }
            if (xhr.status === 401 && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.redirect) {
                window.location.href = xhr.responseJSON.data.redirect;
                return;
            }
            if (onError) { onError(msg, xhr.responseJSON); } else { showToast(msg, 'danger'); }
        });
    }

    function apiGet(url, onSuccess, onError) {
        return $.ajax({
            url: url,
            type: 'GET',
            dataType: 'json'
        }).done(function (res) {
            if (res && res.result) {
                if (onSuccess) { onSuccess(res.data, res.message); }
            } else if (onError) {
                onError(res ? res.message : '', res);
            }
        }).fail(function (xhr) {
            if (xhr.status === 401 && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.redirect) {
                window.location.href = xhr.responseJSON.data.redirect;
                return;
            }
            if (onError) { onError('서버와 통신하지 못했습니다.', xhr.responseJSON); }
        });
    }

    /**
     * 초 단위 남은 시간을 mm:ss 로 표시
     */
    function formatRemain(seconds) {
        seconds = Math.max(0, parseInt(seconds, 10) || 0);
        var m = Math.floor(seconds / 60);
        var s = seconds % 60;
        return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    $(function () {
        // data-modal-close 속성이 있는 요소와 배경 클릭으로 모달을 닫는다.
        $(document).on('click', '[data-modal-close]', function () {
            closeModal($(this).attr('data-modal-close'));
        });

        $(document).on('click', '[data-modal-backdrop]', function (e) {
            if (e.target === this) {
                closeModal($(this).attr('id'));
            }
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                $('.modal-root').not('.hidden').each(function () {
                    closeModal($(this).attr('id'));
                });
            }
        });
    });

    window.showToast = showToast;
    window.showConfirmModal = showConfirmModal;
    window.openModal = openModal;
    window.closeModal = closeModal;
    window.apiPost = apiPost;
    window.apiGet = apiGet;
    window.csrfData = csrfData;
    window.formatRemain = formatRemain;
})(window, jQuery);
