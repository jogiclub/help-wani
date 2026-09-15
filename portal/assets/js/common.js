/**
 * 파일 위치: assets/js/common.js
 * 역할: 공통 UI 함수(showToast, showConfirmModal)와 AJAX 래퍼
 */
(function (window, $) {
    'use strict';

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
        type = type || 'info';
        var $container = $('#toastContainer');
        if (!$container.length) {
            window.alert(message);
            return;
        }

        var $toast = $(
            '<div class="toast align-items-center text-bg-' + type + ' border-0" role="alert">' +
            '  <div class="d-flex">' +
            '    <div class="toast-body"></div>' +
            '    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '  </div>' +
            '</div>'
        );
        $toast.find('.toast-body').text(message);
        $container.append($toast);

        var toast = new bootstrap.Toast($toast[0], { delay: 4000 });
        toast.show();
        $toast.on('hidden.bs.toast', function () { $toast.remove(); });
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

        var modal = bootstrap.Modal.getOrCreateInstance($modal[0]);
        var confirmed = false;

        $('#confirmModalOk').off('click').on('click', function () {
            confirmed = true;
            modal.hide();
            if (onConfirm) { onConfirm(); }
        });

        $('#confirmModalCancel').off('click').on('click', function () {
            modal.hide();
        });

        $modal.off('hidden.bs.modal').on('hidden.bs.modal', function () {
            if (!confirmed && onCancel) { onCancel(); }
        });

        modal.show();
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

    window.showToast = showToast;
    window.showConfirmModal = showConfirmModal;
    window.apiPost = apiPost;
    window.apiGet = apiGet;
    window.csrfData = csrfData;
    window.formatRemain = formatRemain;
})(window, jQuery);
