/**
 * 파일 위치: assets/js/console/keymap.js
 * 역할: 브라우저 KeyboardEvent.code 를 윈도우 가상 키 코드(VK)로 변환
 */
(function (window) {
    'use strict';

    // 확장 키(오른쪽 Alt/Ctrl, 방향키, Insert 계열 등)는 SendInput 에서 확장 플래그가 필요하다.
    var EXTENDED = {
        ControlRight: true, AltRight: true,
        ArrowUp: true, ArrowDown: true, ArrowLeft: true, ArrowRight: true,
        Insert: true, Delete: true, Home: true, End: true, PageUp: true, PageDown: true,
        NumpadEnter: true, NumpadDivide: true, PrintScreen: true
    };

    var MAP = {
        // 제어
        Backspace: 0x08, Tab: 0x09, Enter: 0x0D, NumpadEnter: 0x0D,
        ShiftLeft: 0xA0, ShiftRight: 0xA1,
        ControlLeft: 0xA2, ControlRight: 0xA3,
        AltLeft: 0xA4, AltRight: 0xA5,
        Pause: 0x13, CapsLock: 0x14, Escape: 0x1B, Space: 0x20,
        PageUp: 0x21, PageDown: 0x22, End: 0x23, Home: 0x24,
        ArrowLeft: 0x25, ArrowUp: 0x26, ArrowRight: 0x27, ArrowDown: 0x28,
        PrintScreen: 0x2C, Insert: 0x2D, Delete: 0x2E,
        MetaLeft: 0x5B, MetaRight: 0x5C, ContextMenu: 0x5D,
        NumLock: 0x90, ScrollLock: 0x91,

        // 숫자열
        Digit0: 0x30, Digit1: 0x31, Digit2: 0x32, Digit3: 0x33, Digit4: 0x34,
        Digit5: 0x35, Digit6: 0x36, Digit7: 0x37, Digit8: 0x38, Digit9: 0x39,

        // 기호
        Semicolon: 0xBA, Equal: 0xBB, Comma: 0xBC, Minus: 0xBD, Period: 0xBE,
        Slash: 0xBF, Backquote: 0xC0, BracketLeft: 0xDB, Backslash: 0xDC,
        BracketRight: 0xDD, Quote: 0xDE, IntlBackslash: 0xE2,

        // 숫자 키패드
        Numpad0: 0x60, Numpad1: 0x61, Numpad2: 0x62, Numpad3: 0x63, Numpad4: 0x64,
        Numpad5: 0x65, Numpad6: 0x66, Numpad7: 0x67, Numpad8: 0x68, Numpad9: 0x69,
        NumpadMultiply: 0x6A, NumpadAdd: 0x6B, NumpadSubtract: 0x6D,
        NumpadDecimal: 0x6E, NumpadDivide: 0x6F,

        // 한글 입력 관련
        Lang1: 0x15,   // 한/영 (VK_HANGUL)
        Lang2: 0x19    // 한자 (VK_HANJA)
    };

    // 알파벳
    for (var i = 0; i < 26; i++) {
        MAP['Key' + String.fromCharCode(65 + i)] = 65 + i;
    }

    // 기능키
    for (var f = 1; f <= 24; f++) {
        MAP['F' + f] = 0x70 + (f - 1);
    }

    /**
     * KeyboardEvent 를 { vk, extended } 로 바꾼다. 매핑이 없으면 null.
     */
    function toVk(event) {
        var vk = MAP[event.code];

        if (vk === undefined) {
            return null;
        }

        return { vk: vk, extended: !!EXTENDED[event.code] };
    }

    window.RHKeymap = { toVk: toVk, MAP: MAP };
})(window);
