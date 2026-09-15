/**
 * 파일 위치: assets/js/console/viewer.js
 * 역할: noVNC 연결 및 툴바 제어
 *
 * 주의: noVNC RFB 는 옵션 repeaterID 에 "ID:" 접두어 없이 숫자만 전달해야 한다.
 *       접두어는 noVNC 내부(core/rfb.js)에서 붙인다.
 */
import RFB from '/assets/vendor/novnc/core/rfb.js';

const MAX_RECONNECT = 3;

let rfb = null;
let reconnectCount = 0;
let lastCredentials = null;
let manualDisconnect = false;

function setStatus(text, type) {
    const $badge = jQuery('#viewerStatus');
    $badge.removeClass().addClass('badge ms-1 text-bg-' + (type || 'secondary')).text(text);
}

function setMessage(text) {
    jQuery('#viewerMessage').text(text);
}

/**
 * 뷰어 이벤트를 세션 로그로 남긴다.
 */
function logEvent(event, detail) {
    window.apiPost(VIEWER_CONFIG.logUrl, {
        session_id: VIEWER_CONFIG.sessionId,
        event: event,
        detail: detail || ''
    });
}

/**
 * 1회용 토큰을 받아 noVNC 를 연결한다.
 */
function connect() {
    manualDisconnect = false;

    window.apiPost(VIEWER_CONFIG.tokenUrl, { session_id: VIEWER_CONFIG.sessionId }, function (data) {
        lastCredentials = data;
        openRfb(data);
    }, function (message) {
        window.showToast(message, 'warning');
        setMessage(message);
    });
}

function openRfb(data) {
    if (rfb) {
        try { rfb.disconnect(); } catch (e) { /* 이미 끊긴 경우 무시 */ }
        rfb = null;
    }

    const url = data.ws_url + '?token=' + encodeURIComponent(data.token);
    setMessage('연결 중입니다...');
    setStatus('연결 중', 'warning');

    rfb = new RFB(document.getElementById('screen'), url, {
        repeaterID: data.repeater_id,
        credentials: { password: data.vnc_password }
    });

    rfb.scaleViewport = true;
    rfb.clipViewport = false;
    rfb.resizeSession = false;

    rfb.addEventListener('connect', function () {
        reconnectCount = 0;
        setStatus('원격 중', 'success');
        setMessage('고객 화면에 연결되었습니다.');
        window.showToast('고객 화면에 연결되었습니다.', 'success');
        logEvent('viewer_connected', '');
    });

    rfb.addEventListener('disconnect', function (e) {
        const clean = e.detail && e.detail.clean;
        setStatus('연결 끊김', 'dark');

        if (manualDisconnect) {
            setMessage('원격 연결을 종료했습니다.');
            logEvent('viewer_disconnected', 'manual');
            return;
        }

        logEvent('viewer_disconnected', clean ? 'clean' : 'unexpected');

        if (reconnectCount < MAX_RECONNECT) {
            reconnectCount++;
            setMessage('연결이 끊어져 재연결합니다. (' + reconnectCount + '/' + MAX_RECONNECT + ')');
            window.showToast('연결이 끊어졌습니다. 재연결 ' + reconnectCount + '회차', 'warning');
            logEvent('viewer_reconnecting', String(reconnectCount));
            setTimeout(connect, 2000);
        } else {
            setMessage('재연결에 실패했습니다. 고객에게 런처 상태를 확인해 주세요.');
            window.showToast('재연결에 실패했습니다.', 'danger');
        }
    });

    rfb.addEventListener('credentialsrequired', function () {
        setMessage('VNC 인증에 실패했습니다.');
        window.showToast('VNC 인증에 실패했습니다.', 'danger');
        logEvent('viewer_auth_failed', 'credentialsrequired');
        manualDisconnect = true;
        try { rfb.disconnect(); } catch (e) { /* 무시 */ }
    });

    rfb.addEventListener('securityfailure', function (e) {
        const reason = (e.detail && e.detail.reason) ? e.detail.reason : '';
        setMessage('보안 협상에 실패했습니다. ' + reason);
        window.showToast('보안 협상에 실패했습니다.', 'danger');
        logEvent('viewer_auth_failed', reason);
    });
}

jQuery(function ($) {
    $('#btnConnect').on('click', connect);

    $('#btnFit').on('click', function () {
        if (!rfb) { return; }
        rfb.scaleViewport = !rfb.scaleViewport;
        window.showToast(rfb.scaleViewport ? '화면 맞춤을 켰습니다.' : '원본 크기로 표시합니다.', 'info');
    });

    $('#btnCad').on('click', function () {
        if (!rfb) { return; }
        rfb.sendCtrlAltDel();
        logEvent('viewer_ctrl_alt_del', '');
    });

    $('#btnClipboard').on('click', function () {
        bootstrap.Modal.getOrCreateInstance(document.getElementById('clipboardModal')).show();
    });

    $('#btnClipboardSend').on('click', function () {
        if (!rfb) {
            window.showToast('먼저 원격에 연결해 주세요.', 'warning');
            return;
        }
        const text = $('#clipboardText').val();
        rfb.clipboardPasteFrom(text);
        logEvent('viewer_clipboard', String(text.length) + '자');
        window.showToast('클립보드를 보냈습니다.', 'success');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('clipboardModal')).hide();
    });

    $('#btnFullscreen').on('click', function () {
        const el = document.getElementById('screen');
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else if (el.requestFullscreen) {
            el.requestFullscreen();
        }
    });

    $('#btnEnd').on('click', function () {
        window.showConfirmModal('원격 종료', '원격지원을 종료할까요? 고객 PC 의 연결이 즉시 해제됩니다.', function () {
            manualDisconnect = true;
            if (rfb) {
                try { rfb.disconnect(); } catch (e) { /* 무시 */ }
            }
            window.apiPost(VIEWER_CONFIG.endUrl, { session_id: VIEWER_CONFIG.sessionId }, function (data, message) {
                window.showToast(message, 'success');
                setTimeout(function () { window.location.href = VIEWER_CONFIG.queueUrl; }, 1000);
            });
        });
    });

    $('#btnNote').on('click', function () {
        const content = $('#noteContent').val().trim();
        if (!content) {
            window.showToast('메모 내용을 입력해 주세요.', 'warning');
            return;
        }
        window.apiPost(VIEWER_CONFIG.noteUrl, {
            session_id: VIEWER_CONFIG.sessionId,
            content: content
        }, function (data, message) {
            $('#noteContent').val('');
            window.showToast(message, 'success');
            const $list = $('#noteList').empty();
            (data.notes || []).forEach(function (n) {
                $('<div class="note-item ps-2 mb-2">')
                    .append($('<div class="small text-muted">').text(n.agent_name + ' · ' + n.created_at))
                    .append($('<div>').text(n.content))
                    .appendTo($list);
            });
        });
    });

    // 고객 측 상태 변화를 주기적으로 확인한다.
    setInterval(function () {
        window.apiGet(VIEWER_CONFIG.detailUrl, function (data) {
            if (['ended', 'expired', 'canceled'].indexOf(data.status) !== -1 && !manualDisconnect) {
                manualDisconnect = true;
                if (rfb) {
                    try { rfb.disconnect(); } catch (e) { /* 무시 */ }
                }
                setStatus(data.status_label, 'dark');
                setMessage('세션이 종료되었습니다. (' + (data.end_reason || '') + ')');
            }
        });
    }, 5000);
});
