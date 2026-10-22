/**
 * 파일 위치: assets/js/console/protocol.js
 * 역할: RemoteHelp 원격제어 프로토콜 v1 의 프레임 인코딩/디코딩 (docs/protocol.md)
 */
(function (window) {
    'use strict';

    // 에이전트 -> 뷰어
    var S = {
        SCREEN_INFO: 0x01,
        TILE: 0x02,
        FRAME_END: 0x03,
        CURSOR_POS: 0x04,
        CLIPBOARD: 0x05,
        PONG: 0x06,
        DISPLAY_CHANGED: 0x07
    };

    // 뷰어 -> 에이전트
    var C = {
        MOUSE_MOVE: 0x10,
        MOUSE_BUTTON: 0x11,
        MOUSE_WHEEL: 0x12,
        KEY: 0x13,
        CLIPBOARD_SET: 0x14,
        CTRL_ALT_DEL: 0x15,
        SET_QUALITY: 0x16,
        REQUEST_FULL_FRAME: 0x17,
        PING: 0x18
    };

    /**
     * 에이전트가 보낸 바이너리 프레임을 해석한다.
     * 화면 데이터(TILE)는 복사를 피하려고 원본 버퍼의 뷰를 그대로 돌려준다.
     */
    function decode(buffer) {
        var view = new DataView(buffer);
        var type = view.getUint8(0);

        switch (type) {
            case S.SCREEN_INFO: {
                var monitorCount = view.getUint8(5);
                var monitors = [];
                var off = 6;
                for (var i = 0; i < monitorCount; i++) {
                    monitors.push({
                        x: view.getInt16(off),
                        y: view.getInt16(off + 2),
                        w: view.getUint16(off + 4),
                        h: view.getUint16(off + 6)
                    });
                    off += 8;
                }
                return {
                    type: type,
                    width: view.getUint16(1),
                    height: view.getUint16(3),
                    monitors: monitors
                };
            }

            case S.TILE: {
                var len = view.getUint32(14);
                return {
                    type: type,
                    seq: view.getUint32(1),
                    x: view.getUint16(5),
                    y: view.getUint16(7),
                    w: view.getUint16(9),
                    h: view.getUint16(11),
                    format: view.getUint8(13),
                    data: new Uint8Array(buffer, 18, len)
                };
            }

            case S.FRAME_END:
                return {
                    type: type,
                    seq: view.getUint32(1),
                    tiles: view.getUint16(5),
                    elapsedMs: view.getUint16(7)
                };

            case S.CURSOR_POS:
                return { type: type, x: view.getUint16(1), y: view.getUint16(3) };

            case S.CLIPBOARD: {
                var clipLen = view.getUint32(1);
                var bytes = new Uint8Array(buffer, 5, clipLen);
                return { type: type, text: new TextDecoder('utf-8').decode(bytes) };
            }

            case S.PONG:
                return { type: type, echo: view.getUint32(1) };

            case S.DISPLAY_CHANGED:
                return { type: type };

            default:
                return { type: type, unknown: true };
        }
    }

    function frame(type, byteLength) {
        var buf = new ArrayBuffer(1 + byteLength);
        var view = new DataView(buf);
        view.setUint8(0, type);
        return { buffer: buf, view: view };
    }

    var encode = {
        mouseMove: function (x, y) {
            var f = frame(C.MOUSE_MOVE, 4);
            f.view.setUint16(1, x);
            f.view.setUint16(3, y);
            return f.buffer;
        },

        mouseButton: function (x, y, button, down) {
            var f = frame(C.MOUSE_BUTTON, 6);
            f.view.setUint16(1, x);
            f.view.setUint16(3, y);
            f.view.setUint8(5, button);
            f.view.setUint8(6, down ? 1 : 0);
            return f.buffer;
        },

        mouseWheel: function (x, y, delta) {
            var f = frame(C.MOUSE_WHEEL, 6);
            f.view.setUint16(1, x);
            f.view.setUint16(3, y);
            f.view.setInt16(5, delta);
            return f.buffer;
        },

        key: function (down, vk, extended) {
            var f = frame(C.KEY, 4);
            f.view.setUint8(1, down ? 1 : 0);
            f.view.setUint16(2, vk);
            f.view.setUint8(4, extended ? 1 : 0);
            return f.buffer;
        },

        clipboard: function (text) {
            var bytes = new TextEncoder().encode(text);
            var buf = new ArrayBuffer(5 + bytes.length);
            var view = new DataView(buf);
            view.setUint8(0, C.CLIPBOARD_SET);
            view.setUint32(1, bytes.length);
            new Uint8Array(buf, 5).set(bytes);
            return buf;
        },

        ctrlAltDel: function () {
            return frame(C.CTRL_ALT_DEL, 0).buffer;
        },

        setQuality: function (jpegQuality, maxFps, scalePercent) {
            var f = frame(C.SET_QUALITY, 3);
            f.view.setUint8(1, jpegQuality);
            f.view.setUint8(2, maxFps);
            f.view.setUint8(3, scalePercent);
            return f.buffer;
        },

        requestFullFrame: function () {
            return frame(C.REQUEST_FULL_FRAME, 0).buffer;
        },

        ping: function (echo) {
            var f = frame(C.PING, 4);
            f.view.setUint32(1, echo);
            return f.buffer;
        }
    };

    window.RHProtocol = { SERVER: S, CLIENT: C, decode: decode, encode: encode };
})(window);
