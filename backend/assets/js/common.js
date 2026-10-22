/**
 * 파일 위치: assets/js/common.js
 * 역할: 공통 UI 함수(showToast, showConfirmModal, openModal/closeModal)와 AJAX 래퍼
 *
 * 화면 방식(RH_UI_KIT, layouts/_head.html)에 따라 두 가지로 그린다. 함수 이름과 인자는 같다.
 *   tailwind  : 아직 바꾸지 않은 레이아웃. Tailwind 클래스와 자체 모달/드로어.
 *   bootstrap : Bootstrap 6 + 왔니(docs/frontend-bootstrap6-plan.md). 네이티브 <dialog class="dialog|drawer"> 와
 *               Bootstrap 의 Dialog/Drawer/Toast. Bootstrap 은 bs.js(ESM)가 window.RHBootstrap 에 넘긴다.
 *   plain     : 고객 화면(customer.css). Bootstrap 없이 토스트만 rhc-* 클래스로 그린다.
 */
(function (window, $) {
    'use strict';

    var KIT = (window.RH_UI_KIT === 'bootstrap' || window.RH_UI_KIT === 'plain') ? window.RH_UI_KIT : 'tailwind';

    var TOAST_STYLE = {
        success: 'bg-emerald-600',
        danger: 'bg-red-600',
        warning: 'bg-amber-500',
        info: 'bg-slate-700'
    };

    var TOAST_THEME = {
        success: 'theme-success',
        danger: 'theme-danger',
        warning: 'theme-warning',
        info: 'theme-primary'
    };

    /**
     * 화면 방식별 클래스. 배지·버튼을 JS 로 만드는 곳(grid.js 등)은 여기서 고른다.
     * 이름은 톤(primary, secondary, success, danger, ghost / gray, blue, amber, green, red, dark)이며,
     * 예전 이름('btn-primary', 'badge-amber')도 받는다. Bootstrap 쪽에 5.3 이름(btn-primary 등)을 쓰지 않는다.
     */
    var UI_CLASS = {
        tailwind: {
            button: {
                primary: 'btn btn-sm btn-primary',
                secondary: 'btn btn-sm btn-secondary',
                success: 'btn btn-sm btn-success',
                danger: 'btn btn-sm btn-danger',
                ghost: 'btn btn-sm btn-ghost'
            },
            badge: {
                gray: 'badge badge-gray', blue: 'badge badge-blue', amber: 'badge badge-amber',
                green: 'badge badge-green', red: 'badge badge-red', dark: 'badge badge-dark'
            },
            actions: 'flex h-full items-center gap-1'
        },
        bootstrap: {
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
        }
    };

    function toneOf(name, prefix, fallback) {
        name = String(name || '').trim().split(/\s+/)[0] || fallback;
        return name.indexOf(prefix) === 0 ? name.slice(prefix.length) : name;
    }

    var RHUi = {
        kit: KIT,
        button: function (name) {
            // 예전 Tailwind 화면은 넘긴 클래스를 그대로 덧붙인다(텍스트 색 등 추가 클래스 유지).
            if (KIT === 'tailwind' && name && !UI_CLASS.tailwind.button[name]) {
                return 'btn btn-sm ' + name;
            }
            var map = (UI_CLASS[KIT] || UI_CLASS.tailwind).button;
            return map[toneOf(name, 'btn-', 'secondary')] || map.secondary;
        },
        badge: function (name) {
            var map = (UI_CLASS[KIT] || UI_CLASS.tailwind).badge;
            return map[toneOf(name, 'badge-', 'gray')] || map.gray;
        },
        actions: function () {
            return (UI_CLASS[KIT] || UI_CLASS.tailwind).actions;
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

        if (KIT === 'bootstrap') {
            showBootstrapToast($container[0], message, type);
            return;
        }
        if (KIT === 'plain') {
            showPlainToast($container[0], message, type);
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
     * 모달 열기 / 닫기
     * tailwind : id 로 지정한 요소의 hidden 클래스를 토글한다.
     * bootstrap: <dialog class="dialog"> 를 Bootstrap Dialog 로 연다(배경 클릭·ESC 는 Bootstrap 이 처리).
     */
    function openModal(id) {
        var el = document.getElementById(id);
        if (isNativeDialog(el)) {
            withBootstrap(function (bs) { bs.Dialog.getOrCreateInstance(el).show(); });
            return;
        }
        $('#' + id).removeClass('hidden').addClass('flex');
    }

    function closeModal(id) {
        var el = document.getElementById(id);
        if (isNativeDialog(el)) {
            withBootstrap(function (bs) { bs.Dialog.getOrCreateInstance(el).hide(); });
            return;
        }
        $('#' + id).addClass('hidden').removeClass('flex');
    }

    /**
     * 드로어 열기 / 닫기 (오른쪽에서 밀려 나오는 설정 패널)
     * Bootstrap 화면: <dialog class="drawer drawer-end rh-drawer"> (예: templates/console/_widget_settings.html).
     * Tailwind 화면(전환 전): .drawer-root > .drawer-backdrop + .drawer-panel.
     */
    function openDrawer(id) {
        var $drawer = $('#' + id);
        if (isNativeDialog($drawer[0])) {
            withBootstrap(function (bs) { bs.Drawer.getOrCreateInstance($drawer[0]).show(); });
            $drawer.trigger('drawer:open');
            return;
        }
        $drawer.addClass('is-open').attr('aria-hidden', 'false');
        $('body').addClass('overflow-hidden');
        // 패널이 다 밀려 나온 뒤 첫 입력 요소에 초점을 둔다.
        window.setTimeout(function () {
            $drawer.find('.drawer-panel').trigger('focus');
        }, 200);
        $drawer.trigger('drawer:open');
    }

    function closeDrawer(id) {
        var $drawer = $('#' + id);
        if (isNativeDialog($drawer[0])) {
            withBootstrap(function (bs) { bs.Drawer.getOrCreateInstance($drawer[0]).hide(); });
            $drawer.trigger('drawer:close');
            return;
        }
        $drawer.removeClass('is-open').attr('aria-hidden', 'true');
        if (!$('.drawer-root.is-open').length) {
            $('body').removeClass('overflow-hidden');
        }
        $drawer.trigger('drawer:close');
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

        // data-drawer-close 속성이 있는 요소(닫기 버튼, 배경)로 드로어를 닫는다.
        $(document).on('click', '[data-drawer-close]', function () {
            closeDrawer($(this).attr('data-drawer-close'));
        });

        // ESC 는 맨 위의 것부터 닫는다. 드로어 위에 뜬 확인 모달이 있으면 모달만 닫는다.
        // Bootstrap 화면은 네이티브 <dialog> 가 ESC 를 처리한다.
        $(document).on('keydown', function (e) {
            if (e.key !== 'Escape' || KIT === 'bootstrap') { return; }

            var $modals = $('.modal-root').not('.hidden');
            if ($modals.length) {
                $modals.each(function () { closeModal($(this).attr('id')); });
                return;
            }

            $('.drawer-root.is-open').each(function () { closeDrawer($(this).attr('id')); });
        });
    });

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
