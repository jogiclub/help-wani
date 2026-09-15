/**
 * 파일 위치: assets/js/console/viewer.js
 * 역할: 자체 프로토콜로 고객 화면을 받아 캔버스에 그리고, 마우스/키보드 입력을 전송
 *
 * 프로토콜: docs/protocol.md
 */
(function (window, $) {
    'use strict';

    var P = window.RHProtocol;
    var MAX_RECONNECT = 3;
    var MOUSE_MOVE_INTERVAL = 40;   // 마우스 이동은 25fps 로 제한

    var ws = null;
    var canvas = null;
    var ctx = null;
    var cursorCanvas = null;
    var cursorCtx = null;
    var remoteCursor = null;
    var screenSize = { width: 0, height: 0 };
    var reconnectCount = 0;
    var manualDisconnect = false;
    var inputEnabled = true;
    var lastMouseSent = 0;
    var stats = { frames: 0, tiles: 0, bytes: 0, lastFrameAt: 0 };

    // ------------------------------------------------------------------
    // 화면 표시
    // ------------------------------------------------------------------

    function setStatus(text, kind) {
        var cls = {
            connecting: 'badge-amber',
            connected: 'badge-green',
            error: 'badge-red',
            ended: 'badge-dark'
        }[kind] || 'badge-gray';

        $('#viewerStatus').removeClass().addClass('badge ml-1 ' + cls).text(text);
    }

    function setMessage(text) {
        $('#viewerMessage').text(text);
    }

    function logEvent(event, detail) {
        window.apiPost(VIEWER_CONFIG.logUrl, {
            session_id: VIEWER_CONFIG.sessionId,
            event: event,
            detail: detail || ''
        });
    }

    function resizeCanvas(width, height) {
        screenSize.width = width;
        screenSize.height = height;

        canvas.width = width;
        canvas.height = height;
        cursorCanvas.width = width;
        cursorCanvas.height = height;

        ctx.fillStyle = '#000';
        ctx.fillRect(0, 0, width, height);

        setMessage('고객 화면 ' + width + ' x ' + height);
    }

    /**
     * 타일 하나를 캔버스에 그린다.
     * createImageBitmap 은 비동기지만 타일끼리 겹치지 않으므로 순서를 보장할 필요가 없다.
     */
    function drawTile(tile) {
        var mime = tile.format === 2 ? 'image/png' : 'image/jpeg';
        // 원본 버퍼의 뷰이므로 그대로 Blob 에 넘기면 안 된다(뒤 프레임이 덮어쓸 수 있다).
        var blob = new Blob([tile.data.slice()], { type: mime });

        window.createImageBitmap(blob).then(function (bitmap) {
            ctx.drawImage(bitmap, tile.x, tile.y);
            bitmap.close();
        }).catch(function () {
            // 손상된 타일은 버린다. 다음 프레임에서 다시 온다.
        });
    }

    /**
     * 고객 PC 의 마우스 위치를 겹쳐 그린다. 화면 타일을 건드리지 않도록 별도 캔버스를 쓴다.
     */
    function drawRemoteCursor() {
        if (!remoteCursor || !cursorCtx) { return; }

        cursorCtx.clearRect(0, 0, cursorCanvas.width, cursorCanvas.height);

        var x = remoteCursor.x;
        var y = remoteCursor.y;

        cursorCtx.beginPath();
        cursorCtx.moveTo(x, y);
        cursorCtx.lineTo(x, y + 17);
        cursorCtx.lineTo(x + 4.5, y + 13);
        cursorCtx.lineTo(x + 7.5, y + 19);
        cursorCtx.lineTo(x + 10, y + 18);
        cursorCtx.lineTo(x + 7, y + 12);
        cursorCtx.lineTo(x + 12, y + 12);
        cursorCtx.closePath();

        cursorCtx.fillStyle = '#ffffff';
        cursorCtx.strokeStyle = '#000000';
        cursorCtx.lineWidth = 1.2;
        cursorCtx.fill();
        cursorCtx.stroke();
    }

    // ------------------------------------------------------------------
    // 좌표 변환과 입력 전송
    // ------------------------------------------------------------------

    /**
     * 브라우저 좌표를 고객 화면 절대 좌표로 바꾼다.
     */
    function toScreenPoint(event) {
        var rect = canvas.getBoundingClientRect();
        var x = (event.clientX - rect.left) * (screenSize.width / rect.width);
        var y = (event.clientY - rect.top) * (screenSize.height / rect.height);

        return {
            x: Math.max(0, Math.min(screenSize.width - 1, Math.round(x))),
            y: Math.max(0, Math.min(screenSize.height - 1, Math.round(y)))
        };
    }

    function send(buffer) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(buffer);
        }
    }

    function bindInput() {
        var $canvas = $(canvas);

        $canvas.on('mousemove', function (e) {
            if (!inputEnabled) { return; }

            var now = Date.now();
            if (now - lastMouseSent < MOUSE_MOVE_INTERVAL) { return; }
            lastMouseSent = now;

            var p = toScreenPoint(e);
            send(P.encode.mouseMove(p.x, p.y));
        });

        $canvas.on('mousedown mouseup', function (e) {
            if (!inputEnabled) { return; }
            e.preventDefault();

            var p = toScreenPoint(e);
            var button = e.button === 2 ? 1 : (e.button === 1 ? 2 : 0);
            send(P.encode.mouseButton(p.x, p.y, button, e.type === 'mousedown'));
        });

        $canvas.on('contextmenu', function (e) {
            e.preventDefault();   // 오른쪽 클릭은 고객 PC 로 보낸다
        });

        canvas.addEventListener('wheel', function (e) {
            if (!inputEnabled) { return; }
            e.preventDefault();

            var p = toScreenPoint(e);
            // 윈도우 휠 단위(WHEEL_DELTA=120)에 맞춘다.
            var delta = e.deltaY > 0 ? -120 : 120;
            send(P.encode.mouseWheel(p.x, p.y, delta));
        }, { passive: false });

        // 키보드는 캔버스가 포커스를 가진 동안에만 처리한다.
        $canvas.on('keydown keyup', function (e) {
            if (!inputEnabled) { return; }

            var mapped = window.RHKeymap.toVk(e.originalEvent);

            if (!mapped) { return; }

            e.preventDefault();
            send(P.encode.key(e.type === 'keydown', mapped.vk, mapped.extended));
        });

        $canvas.on('mouseenter', function () { canvas.focus(); });
    }

    // ------------------------------------------------------------------
    // 연결
    // ------------------------------------------------------------------

    function connect() {
        manualDisconnect = false;

        window.apiPost(VIEWER_CONFIG.tokenUrl, { session_id: VIEWER_CONFIG.sessionId }, function (data) {
            openSocket(data);
        }, function (message) {
            window.showToast(message, 'warning');
            setMessage(message);
        });
    }

    function openSocket(data) {
        closeSocket();

        setStatus('연결 중', 'connecting');
        setMessage('고객 PC 에 연결하는 중입니다...');

        ws = new WebSocket(data.ws_url + '?token=' + encodeURIComponent(data.token));
        ws.binaryType = 'arraybuffer';

        ws.onopen = function () {
            reconnectCount = 0;
            setStatus('연결됨', 'connected');
            setMessage('고객 PC 에이전트를 기다리는 중입니다...');
        };

        ws.onmessage = function (event) {
            if (typeof event.data === 'string') {
                handleControl(JSON.parse(event.data));
                return;
            }

            stats.bytes += event.data.byteLength;
            handleBinary(P.decode(event.data));
        };

        ws.onclose = function (e) {
            handleClose(e.code, e.reason);
        };

        ws.onerror = function () {
            setStatus('오류', 'error');
        };
    }

    function handleControl(msg) {
        switch (msg.type) {
            case 'ready':
                setMessage('중계 서버에 연결되었습니다.');
                break;

            case 'peer_connected':
                setStatus('원격 중', 'connected');
                setMessage('고객 화면을 받는 중입니다.');
                window.showToast('고객 PC 에 연결되었습니다.', 'success');
                logEvent('viewer_connected', '');
                send(P.encode.requestFullFrame());
                inputEnabled = true;
                break;

            case 'peer_disconnected':
                setStatus('고객 연결 끊김', 'error');
                setMessage('고객 PC 연결이 끊어졌습니다. 재접속을 기다립니다.');
                window.showToast('고객 PC 연결이 끊어졌습니다.', 'warning');
                logEvent('viewer_peer_lost', '');
                inputEnabled = false;
                break;

            case 'error':
                window.showToast(msg.message || '중계 서버 오류', 'danger');
                setMessage(msg.message || '중계 서버 오류');
                break;
        }
    }

    function handleBinary(frame) {
        switch (frame.type) {
            case P.SERVER.SCREEN_INFO:
                resizeCanvas(frame.width, frame.height);
                break;

            case P.SERVER.TILE:
                stats.tiles++;
                drawTile(frame);
                break;

            case P.SERVER.FRAME_END:
                stats.frames++;
                stats.lastFrameAt = Date.now();
                break;

            case P.SERVER.CURSOR_POS:
                remoteCursor = { x: frame.x, y: frame.y };
                drawRemoteCursor();
                break;

            case P.SERVER.CLIPBOARD:
                if (navigator.clipboard && frame.text) {
                    navigator.clipboard.writeText(frame.text).catch(function () {
                        // 권한이 없으면 무시한다.
                    });
                }
                break;

            case P.SERVER.DISPLAY_CHANGED:
                setMessage('고객 PC 화면 구성이 바뀌었습니다. 다시 받는 중...');
                send(P.encode.requestFullFrame());
                break;
        }
    }

    function handleClose(code, reason) {
        inputEnabled = false;

        if (manualDisconnect) {
            setStatus('종료', 'ended');
            setMessage('원격 연결을 종료했습니다.');
            logEvent('viewer_disconnected', 'manual');
            return;
        }

        logEvent('viewer_disconnected', 'code=' + code);

        // 인증 실패는 재시도해도 소용없다.
        if (code === 4401 || code === 4403) {
            setStatus('인증 실패', 'error');
            setMessage('접속 인증에 실패했습니다. 세션 상태를 확인해 주세요.');
            window.showToast('접속 인증에 실패했습니다.', 'danger');
            logEvent('viewer_auth_failed', 'code=' + code);
            return;
        }

        setStatus('연결 끊김', 'error');

        if (reconnectCount < MAX_RECONNECT) {
            reconnectCount++;
            setMessage('연결이 끊어져 재연결합니다. (' + reconnectCount + '/' + MAX_RECONNECT + ')');
            window.showToast('연결이 끊어졌습니다. 재연결 ' + reconnectCount + '회차', 'warning');
            logEvent('viewer_reconnecting', String(reconnectCount));
            setTimeout(connect, 2000);
        } else {
            setMessage('재연결에 실패했습니다. 고객에게 프로그램 상태를 확인해 주세요. ' + (reason || ''));
            window.showToast('재연결에 실패했습니다.', 'danger');
        }
    }

    function closeSocket() {
        if (ws) {
            try { ws.close(); } catch (e) { /* 이미 닫힘 */ }
            ws = null;
        }
    }

    // ------------------------------------------------------------------
    // 툴바
    // ------------------------------------------------------------------

    function bindToolbar() {
        $('#btnConnect').on('click', connect);

        $('#btnFit').on('click', function () {
            $(canvas).toggleClass('object-contain w-full h-full');
            window.showToast('화면 맞춤을 전환했습니다.', 'info');
        });

        $('#btnCad').on('click', function () {
            send(P.encode.ctrlAltDel());
            logEvent('viewer_ctrl_alt_del', '');
            window.showToast('Ctrl+Alt+Del 을 보냈습니다.', 'info');
        });

        $('#btnClipboard').on('click', function () {
            window.openModal('clipboardModal');
        });

        $('#btnClipboardSend').on('click', function () {
            var text = $('#clipboardText').val();
            send(P.encode.clipboard(text));
            logEvent('viewer_clipboard', String(text.length) + '자');
            window.showToast('클립보드를 보냈습니다.', 'success');
            window.closeModal('clipboardModal');
        });

        $('#btnFullscreen').on('click', function () {
            var el = document.getElementById('screenWrap');
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else if (el.requestFullscreen) {
                el.requestFullscreen();
            }
        });

        $('#selQuality').on('change', function () {
            var parts = $(this).val().split(',');
            send(P.encode.setQuality(parseInt(parts[0], 10), parseInt(parts[1], 10), parseInt(parts[2], 10)));
            logEvent('viewer_quality_changed', $(this).val());
            window.showToast('화질 설정을 보냈습니다.', 'info');
        });

        $('#btnEnd').on('click', function () {
            window.showConfirmModal('원격 종료', '원격지원을 종료할까요? 고객 PC 의 연결이 즉시 해제됩니다.', function () {
                manualDisconnect = true;
                closeSocket();

                window.apiPost(VIEWER_CONFIG.endUrl, { session_id: VIEWER_CONFIG.sessionId }, function (d, message) {
                    window.showToast(message, 'success');
                    setTimeout(function () { window.location.href = VIEWER_CONFIG.queueUrl; }, 1000);
                });
            });
        });

        $('#btnNote').on('click', function () {
            var content = $('#noteContent').val().trim();

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

                var $list = $('#noteList').empty();
                (data.notes || []).forEach(function (n) {
                    $('<div class="border-l-2 border-brand-500 pl-3">')
                        .append($('<div class="text-xs text-slate-400">')
                            .text(n.agent_name + ' · ' + window.RHI18n.formatDate(n.created_at)))
                        .append($('<div class="text-sm text-slate-700">').text(n.content))
                        .appendTo($list);
                });
            });
        });
    }

    // ------------------------------------------------------------------

    $(function () {
        canvas = document.getElementById('screen');
        ctx = canvas.getContext('2d', { alpha: false });
        canvas.tabIndex = 0;   // 키보드 포커스를 받을 수 있게 한다

        cursorCanvas = document.getElementById('cursorLayer');
        cursorCtx = cursorCanvas.getContext('2d');

        // 커서 레이어는 화면 캔버스와 같은 크기로 겹쳐 둔다.
        new ResizeObserver(function () {
            cursorCanvas.style.width = canvas.clientWidth + 'px';
            cursorCanvas.style.height = canvas.clientHeight + 'px';
        }).observe(canvas);

        bindInput();
        bindToolbar();

        // 상태 표시 갱신
        setInterval(function () {
            if (stats.lastFrameAt && Date.now() - stats.lastFrameAt < 3000) {
                $('#viewerStats').text(
                    stats.frames + '프레임 · ' + Math.round(stats.bytes / 1024) + 'KB 수신'
                );
            }
        }, 1000);

        // 세션 상태 확인 (고객이 종료한 경우 감지)
        setInterval(function () {
            window.apiGet(VIEWER_CONFIG.detailUrl, function (data) {
                if (['ended', 'expired', 'canceled'].indexOf(data.status) !== -1 && !manualDisconnect) {
                    manualDisconnect = true;
                    closeSocket();
                    setStatus(data.status_label, 'ended');
                    setMessage('세션이 종료되었습니다. (' + (data.end_reason || '') + ')');
                }
            });
        }, 5000);
    });

    window.RHViewer = { connect: connect };
})(window, jQuery);
