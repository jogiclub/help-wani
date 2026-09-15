# RemoteHelp 원격제어 프로토콜 v1

자체 구현 프로토콜입니다. VNC/RFB 를 쓰지 않습니다.

## 1. 전송 계층

모든 통신은 **WebSocket over TLS(wss)** 하나로 통일합니다.

```
[고객 PC 에이전트]  --wss--> [중계 서버] <--wss--  [상담원 브라우저]
   wss://relay/agent?token=...        wss://relay/viewer?token=...
```

- **텍스트 프레임** = 중계 서버와 주고받는 제어 메시지(JSON)
- **바이너리 프레임** = 화면/입력 데이터. 중계 서버는 내용을 해석하지 않고 그대로 전달합니다.

이 분리 덕분에 중계 서버는 프로토콜 버전이 올라가도 바꿀 필요가 없습니다.

## 2. 접속과 인증

| 역할 | 경로 | 토큰 |
|---|---|---|
| 에이전트(고객 PC) | `/agent?token=<agent_token>` | `/api/launcher/verify` 응답으로 발급. 세션당 1개 |
| 뷰어(상담원) | `/viewer?token=<viewer_token>` | `/console/api/session/viewer-token` 으로 발급. 1회용, 60초 |

중계 서버는 접속 시 포털 `/api/relay/auth?role=agent|viewer&token=...` 를 호출해 검증하고,
응답의 `session_id` 로 두 연결을 짝지읍니다. 검증 실패면 즉시 닫습니다(코드 4401).

### 제어 메시지 (텍스트 JSON)

중계 서버 → 양쪽

```json
{"type":"ready",            "session_id":123, "role":"agent"}
{"type":"peer_connected",   "role":"viewer"}
{"type":"peer_disconnected","role":"viewer"}
{"type":"error",            "message":"..."}
```

종료 코드

| 코드 | 의미 |
|---|---|
| 4401 | 토큰 검증 실패 |
| 4403 | 세션 상태가 접속 불가 |
| 4409 | 같은 역할이 이미 접속해 있음 |
| 4410 | 상대가 끊어져 세션 종료 |

## 3. 바이너리 프레임 형식

- 한 WebSocket 메시지 = 한 프레임
- 첫 1바이트가 프레임 종류, 이후는 종류별 본문
- 정수는 모두 **빅엔디안**(브라우저 `DataView` 기본값과 일치)
- 좌표는 고객 PC 가상 데스크톱 기준 절대 픽셀

### 3.1 에이전트 → 뷰어

| 종류 | 이름 | 본문 |
|---|---|---|
| `0x01` | SCREEN_INFO | `w:u16` `h:u16` `monitors:u8` 그리고 모니터마다 `x:i16` `y:i16` `w:u16` `h:u16` |
| `0x02` | TILE | `seq:u32` `x:u16` `y:u16` `w:u16` `h:u16` `format:u8` `len:u32` `data:bytes` |
| `0x03` | FRAME_END | `seq:u32` `tiles:u16` `elapsedMs:u16` |
| `0x04` | CURSOR_POS | `x:u16` `y:u16` |
| `0x05` | CLIPBOARD | `len:u32` `utf8:bytes` |
| `0x06` | PONG | `echo:u32` |
| `0x07` | DISPLAY_CHANGED | 없음. 뷰어는 SCREEN_INFO 를 기다린 뒤 전체 화면을 다시 요청한다 |

`format` 값: `1` = JPEG, `2` = PNG

### 3.2 뷰어 → 에이전트

| 종류 | 이름 | 본문 |
|---|---|---|
| `0x10` | MOUSE_MOVE | `x:u16` `y:u16` |
| `0x11` | MOUSE_BUTTON | `x:u16` `y:u16` `button:u8` `down:u8` |
| `0x12` | MOUSE_WHEEL | `x:u16` `y:u16` `delta:i16` |
| `0x13` | KEY | `down:u8` `vk:u16` `extended:u8` |
| `0x14` | CLIPBOARD_SET | `len:u32` `utf8:bytes` |
| `0x15` | CTRL_ALT_DEL | 없음 |
| `0x16` | SET_QUALITY | `jpegQuality:u8(10~95)` `maxFps:u8(1~30)` `scalePercent:u8(25~100)` |
| `0x17` | REQUEST_FULL_FRAME | 없음 |
| `0x18` | PING | `echo:u32` |

`button` 값: `0` 왼쪽, `1` 오른쪽, `2` 가운데
`vk` 는 윈도우 가상 키 코드입니다. 브라우저 `KeyboardEvent.code` 를 뷰어가 변환해 보냅니다.

## 4. 화면 전송 방식

전체 화면을 **128x128 타일**로 나누고, 프레임마다 타일별 해시를 계산해
**바뀐 타일만** JPEG 으로 보냅니다.

```
1. 화면 캡처 (GDI BitBlt, 가능하면 DXGI Desktop Duplication)
2. 타일별 FNV-1a 해시 계산
3. 직전 프레임과 해시가 다른 타일만 인코딩
4. TILE 프레임들 전송 후 FRAME_END
```

- 첫 프레임과 `REQUEST_FULL_FRAME` 수신 시에는 전체 타일을 보냅니다.
- 기본값: JPEG 품질 70, 최대 10fps, 배율 100%
- 정지 화면에서는 바뀐 타일이 없어 트래픽이 사실상 0 입니다.

### 대역폭 어림값

| 상황 | 대략 |
|---|---|
| 정지 화면 | 0 ~ 5 KB/s (하트비트만) |
| 문서 작업, 타이핑 | 50 ~ 300 KB/s |
| 창 이동, 스크롤 | 1 ~ 3 MB/s |
| 전체 화면 갱신 1회 (1920x1080, 품질 70) | 약 300 ~ 600 KB |

## 5. 보안

- 전 구간 TLS. 평문 경로 없음
- 에이전트 토큰은 세션당 1개, 뷰어 토큰은 1회용 60초
- 중계 서버는 세션 짝이 맞는 경우에만 바이트를 전달하며 내용을 저장하지 않음
- 화면 데이터는 디스크에 기록하지 않음

## 6. VNC 대비 달라진 점

| 항목 | 이전(UltraVNC) | 현재 |
|---|---|---|
| 고객 PC 실행 파일 | winvnc.exe 동봉(GPL) | 런처 자체 구현. 외부 실행 파일 없음 |
| 중계 | uvncrepeater + websockify | Node.js 중계 서버 하나 |
| 상담원 뷰어 | noVNC | 자체 캔버스 뷰어 |
| 포트 | 5501(TLS), 443(wss) | 443(wss) 하나 |
| 라이선스 의무 | GPL 소스 공개 | 없음 |
