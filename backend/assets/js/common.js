/**
 * 파일 위치: backend/assets/js/common.js
 * 역할: 공통 UI 함수(showToast, showConfirmModal, openModal/closeModal, openDrawer/closeDrawer)와 AJAX 래퍼
 *
 * 화면 방식(RH_UI_KIT, layouts/_head.html)에 따라 그린다. 함수 이름과 인자는 같다.
 *   bootstrap : Bootstrap 6 + 왔니(docs/frontend-bootstrap6-plan.md). 네이티브 <dialog class="dialog|drawer"> 와
 *               Bootstrap 의 Dialog/Drawer/Toast. Bootstrap 은 bs.js(ESM)가 window.RHBootstrap 에 넘긴다.
 *   plain     : 고객 화면(customer.css). Bootstrap 없이 토스트만 rhc-* 클래스로 그린다.
 *               공개 가이드('guide', guide-themes.css)도 이 방식으로 다룬다.
 */
(function (window, $) {
    'use strict';

    var KIT = (window.RH_UI_KIT === 'plain' || window.RH_UI_KIT === 'guide') ? 'plain' : 'bootstrap';

    var TOAST_THEME = {
        success: 'theme-success',
        danger: 'theme-danger',
        warning: 'theme-warning',
        info: 'theme-primary'
    };

    /**
     * 배지·버튼을 JS 로 만드는 곳(grid.js 등)이 쓰는 Bootstrap 6 클래스.
     * 이름은 톤(버튼 primary, secondary, success, danger, ghost / 배지 gray, blue, amber, green, red, dark)이다.
     * 앞에 'btn-' / 'badge-' 가 붙은 예전 이름도 톤으로 읽는다.
     */
    var UI_CLASS = {
        button: {
            primary: 'btn-solid theme-primary btn-sm',
            secondary: 'btn-outline theme-secondary btn-sm',
            success: 'btn-solid theme-success btn-sm',
            danger: 'btn-outline theme-danger btn-sm',
            ghost: 'btn-text theme-secondary btn-sm'
        },
        badge: {
            gray: 'badge badge-subtle theme-secondary', blue: 'badge badge-subtle theme-primary',
            amber: 'badge badge-subtle theme-warning', green: 'badge badge-subtle theme-success',
            red: 'badge badge-subtle theme-danger', dark: 'badge rh-badge-dark'
        },
        actions: 'rh-grid-actions'
    };

    function toneOf(name, prefix, fallback) {
        name = String(name || '').trim().split(/\s+/)[0] || fallback;
        return name.indexOf(prefix) === 0 ? name.slice(prefix.length) : name;
    }

    var RHUi = {
        kit: KIT,
        button: function (name) {
            return UI_CLASS.button[toneOf(name, 'btn-', 'secondary')] || UI_CLASS.button.secondary;
        },
        badge: function (name) {
            return UI_CLASS.badge[toneOf(name, 'badge-', 'gray')] || UI_CLASS.badge.gray;
        },
        actions: function () {
            return UI_CLASS.actions;
        }
    };

    /**
     * Bootstrap 이 준비되면 fn(RHBootstrap) 을 부른다. bs.js 는 모듈이라 일반 스크립트보다 늦게 실행된다.
     */
    function withBootstrap(fn) {
        if (window.RHBootstrap) {
            fn(window.RHBootstrap);
            return;
        }
        document.addEventListener('rh:bootstrap-ready', function () { fn(window.RHBootstrap); }, { once: true });
    }

    function isNativeDialog(el) {
        return !!el && el.tagName === 'DIALOG';
    }

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
        // 예전 호출의 'error' 는 'danger' 와 같다.
        if (type === 'error') { type = 'danger'; }
        var $container = $('#toastContainer');
        if (!$container.length) {
            window.alert(message);
            return;
        }

        if (KIT === 'plain') {
            showPlainToast($container[0], message, type);
        } else {
            showBootstrapToast($container[0], message, type);
        }
    }

    /**
     * Bootstrap 토스트. 닫히면 요소를 지운다(Toast 는 숨겨도 DOM 에 남긴다).
     */
    function showBootstrapToast(container, message, type) {
        var toast = document.createElement('div');
        toast.className = 'toast ' + (TOAST_THEME[type] || TOAST_THEME.info);
        toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');

        var row = document.createElement('div');
        row.className = 'd-flex align-items-start';

        var body = document.createElement('div');
        body.className = 'toast-body flex-grow-1';
        body.textContent = message;

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close me-3 mt-3';
        close.setAttribute('data-bs-dismiss', 'toast');
        close.setAttribute('aria-label', '닫기');

        row.appendChild(body);
        row.appendChild(close);
        toast.appendChild(row);
        container.appendChild(toast);

        toast.addEventListener('hidden.bs.toast', function () { toast.remove(); });
        withBootstrap(function (bs) {
            bs.Toast.getOrCreateInstance(toast, { delay: 4000 }).show();
        });
    }

    /**
     * 고객 화면 토스트(customer.css). 4초 뒤 사라진다.
     */
    function showPlainToast(container, message, type) {
        var toast = document.createElement('div');
        toast.className = 'rhc-toast' + (TOAST_THEME[type] ? ' rhc-toast-' + type : '');
        toast.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        toast.textContent = message;
        container.appendChild(toast);
        window.setTimeout(function () {
            toast.className += ' is-hiding';
            window.setTimeout(function () { toast.remove(); }, 200);
        }, 4000);
    }

    /**
     * 모달 열기 / 닫기: <dialog class="dialog"> 를 Bootstrap Dialog 로 연다(배경 클릭·ESC 는 Bootstrap 이 처리).
     */
    function openModal(id) {
        var el = document.getElementById(id);
        if (isNativeDialog(el)) {
            withBootstrap(function (bs) { bs.Dialog.getOrCreateInstance(el).show(); });
        }
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (isNativeDialog(el)) {
            withBootstrap(function (bs) { bs.Dialog.getOrCreateInstance(el).hide(); });
        }
    }

    /**
     * 드로어 열기 / 닫기 (왔니 방식의 오른쪽 패널)
     * 마크업: <dialog class="drawer drawer-end rh-drawer"> (예: templates/console/_widget_settings.html).
     * 열고 닫을 때 drawer:open / drawer:close jQuery 이벤트가 난다. 닫기 버튼(data-bs-dismiss)·ESC·바탕 클릭으로
     * 닫아도 나도록 아래에서 Bootstrap 의 show/hidden.bs.drawer 이벤트에 잇는다.
     */
    function openDrawer(id) {
        var $drawer = $('#' + id);
        if (!isNativeDialog($drawer[0])) { return; }
        withBootstrap(function (bs) { bs.Drawer.getOrCreateInstance($drawer[0]).show(); });
    }

    function closeDrawer(id) {
        var $drawer = $('#' + id);
        if (!isNativeDialog($drawer[0])) { return; }
        withBootstrap(function (bs) { bs.Drawer.getOrCreateInstance($drawer[0]).hide(); });
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

    document.addEventListener('show.bs.drawer', function (e) { $(e.target).trigger('drawer:open'); });
    document.addEventListener('hidden.bs.drawer', function (e) { $(e.target).trigger('drawer:close'); });

    window.RHUi = RHUi;
    window.withBootstrap = withBootstrap;
    window.showToast = showToast;
    window.showConfirmModal = showConfirmModal;
    window.openModal = openModal;
    window.closeModal = closeModal;
    window.openDrawer = openDrawer;
    window.closeDrawer = closeDrawer;
    window.apiPost = apiPost;
    window.apiGet = apiGet;
    window.csrfData = csrfData;
    window.formatRemain = formatRemain;
})(window, jQuery);
