# 기술 검증 보고서 (Spike)

- 최초 작성: 2026-09-15 (UltraVNC 기반)
- 개정: 2026-09-15 자체 원격제어 프로토콜로 전환하면서 전면 재작성
- 검증 환경: Ubuntu 24.04 컨테이너(도커), Node.js 20, nginx 1.24, Python 3.12 에이전트 모의 구현

결과는 세 가지로 구분해 적었습니다.

- **검증 완료**: 이 환경에서 실제로 실행해 확인함
- **소스/문서 확인**: 공식 문서나 소스로 동작을 확인함(윈도우 실기 실행은 아직 못 함)
- **미검증**: 윈도우 실기, 스토어 계정 등 이 환경에서 확인할 수 없는 항목

---

## 요약

| 번호 | 항목 | 상태 |
|---|---|---|
| A-1 | 자체 프로토콜 왕복(화면 프레임 + 입력) | **검증 완료** |
| A-2 | 중계 서버 세션 짝짓기와 토큰 검증 | **검증 완료** |
| A-3 | 타일 단위 변경 감지 전송 | **검증 완료** |
| A-4 | TLS 구간 암호화 | **검증 완료** |
| A-5 | 1회용 토큰 재사용 차단 | **검증 완료** |
| B-1 | GDI 화면 캡처 | 소스/문서 확인 |
| B-2 | DXGI Desktop Duplication 과 자동 폴백 | 소스/문서 확인 |
| B-3 | SendInput 입력 주입 | 소스/문서 확인 |
| B-4 | JPEG 인코딩(WPF JpegBitmapEncoder) | 소스/문서 확인 |
| C-1 | MSIX(runFullTrust) 에서 캡처/입력 동작 | 미검증 |
| C-2 | UAC 창 제어 한계 | 미검증(제약은 확정) |
| C-3 | 백신 오진 | 미검증 |
| C-4 | Microsoft Store 정책 | 미검증(요건 정리) |

---

## A. 이 환경에서 검증한 것

윈도우 없이도 프로토콜과 서버 경로를 확인하려고, 실제 런처(C#)와 **같은 프레임 형식**을 쓰는
파이썬 에이전트 모의 구현(`docker/agentsim/agent_sim.py`)과 뷰어 프로브(`scripts/viewer-probe.py`)를
만들어 `scripts/e2e-test.sh` 로 자동 점검했습니다.

### A-1 · A-3. 화면 프레임과 입력 (검증 완료)

```
1. 뷰어 접속: wss://relay:8443/viewer
2. 중계 서버 연결됨
3. 제어 메시지: {"type":"ready","session_id":"17","role":"viewer"}
3. 제어 메시지: {"type":"peer_connected","role":"agent"}
4. 전체 화면 요청 전송
5. 화면 정보 수신: 1280x800, 모니터 1개
6. 첫 타일 수신: (0,0) 128x128 JPEG 1556바이트, 디코딩 128x128
7. 첫 프레임 완료: 타일 70개, 인코딩 22ms
8. 입력 이벤트 전송 (마우스 이동/클릭, 키 A, 화질 변경)

   수신 프레임 3개, 타일 80개, 77.9KB
```

에이전트 쪽에서 받은 입력:

```
[agent] 입력 수신: mouse_move {'x': 640, 'y': 400}
[agent] 입력 수신: mouse_button {'x': 640, 'y': 400, 'button': 0, 'down': True}
[agent] 입력 수신: key {'down': True, 'vk': 65, 'extended': False}
[agent] 입력 수신: set_quality {'quality': 60, 'fps': 8, 'scale': 100}
```

**타일 변경 감지가 실제로 동작합니다.** 첫 프레임은 전체 70타일(1280x800 기준),
이후 프레임은 움직이는 영역만 보내 3프레임 합계가 80타일에 그쳤습니다.

### A-2. 중계 서버 (검증 완료)

- 에이전트와 뷰어가 각자 토큰으로 접속하면 포털 `/api/relay/auth` 검증 후 같은 세션으로 짝지어집니다.
- 짝이 맞기 전에 보낸 프레임은 버려집니다.
- 한쪽이 끊기면 상대에게 `peer_disconnected` 제어 메시지가 갑니다.

### A-4. TLS (검증 완료)

`wss://` 한 경로만 외부에 열립니다. nginx 가 TLS 를 종료하고 루프백의 Node 서버로 넘깁니다.
이전 구조에 있던 평문 TCP 포트(5501)가 사라졌습니다.

### A-5. 토큰 (검증 완료)

| 검사 | 결과 |
|---|---|
| 잘못된 뷰어 토큰 | 접속 거절(4401) |
| 사용한 뷰어 토큰 재사용 | 접속 거절 |
| 사용한 6자리 코드 재사용 | 거절 |
| IP 당 코드 5회 실패 | 10분 차단 |

### 이 과정에서 찾은 설계 결함

**뷰어가 붙기 전에 보낸 화면 정보는 사라진다.**
에이전트가 접속 직후 한 번만 `SCREEN_INFO` 를 보내면, 나중에 접속한 뷰어는 화면 크기를 모른 채
타일만 받습니다(좌표 변환이 불가능해 입력도 못 보냄). 양쪽 구현 모두
**`peer_connected` 를 받을 때마다 `SCREEN_INFO` 를 다시 보내도록** 고쳤습니다.

---

## B. 윈도우 구현 (소스/문서 확인, 실기 검증 필요)

### B-1. GDI 화면 캡처

`CreateDIBSection` 으로 top-down 32비트 버퍼를 만들고 `BitBlt(SRCCOPY | CAPTUREBLT)` 로
가상 데스크톱 전체를 한 번에 복사합니다.

- `CAPTUREBLT` 를 넣어야 레이어드(반투명) 창이 포함됩니다.
- 음수 높이로 DIB 를 만들면 상하 반전 처리가 필요 없습니다.
- 다중 모니터는 `SM_XVIRTUALSCREEN` 계열 지표로 가상 데스크톱 전체를 잡습니다.
- 해상도 변경은 매 프레임 `ScreenGeometry.Current()` 와 비교해 감지합니다.

구현: `launcher/RemoteHelp.App/Services/Engine/GdiScreenCapturer.cs`

### B-2. DXGI Desktop Duplication 과 폴백

`Vortice.Windows` 로 D3D11 장치를 만들고 `IDXGIOutput1.DuplicateOutput` 을 씁니다.
**생성에 실패하면 예외를 밖으로 내지 않고 GDI 로 조용히 전환합니다.**

폴백이 필요한 경우가 실제로 많습니다.

| 상황 | 처리 |
|---|---|
| 모니터가 2개 이상 | 출력별 복제를 합쳐야 하므로 1차 범위에서는 GDI |
| 원격 데스크톱 세션 | DuplicateOutput 실패 → GDI |
| 일부 가상 머신 / 드라이버 문제 | 생성 실패 → GDI |
| `AcquireNextFrame` 타임아웃 | 화면 변화 없음. 직전 프레임 재사용 |

환경변수 `REMOTEHELP_CAPTURE=gdi|dxgi|auto` 로 강제할 수 있습니다(진단용).

구현: `DxgiScreenCapturer.cs`, `ScreenCapturerFactory.cs`

### B-3. 입력 주입

`SendInput` 으로 마우스와 키보드를 주입합니다.

- 마우스 절대 좌표는 가상 데스크톱을 **0~65535 로 정규화**해야 하며
  `MOUSEEVENTF_VIRTUALDESK` 플래그가 필요합니다(다중 모니터).
- 방향키, 오른쪽 Ctrl/Alt, Insert 계열은 `KEYEVENTF_EXTENDEDKEY` 가 필요합니다.
  브라우저 `KeyboardEvent.code` → VK 매핑에 확장 여부를 함께 담았습니다(`keymap.js`).
- 휠은 `WHEEL_DELTA`(120) 단위입니다.

**Ctrl+Alt+Del 은 SAS(보안 주의 시퀀스)라 `SendInput` 으로 전달되지 않습니다.**
현재는 요청을 로그로 남기고 동작하지 않음을 명시했습니다. 필요하면 서비스 권한 구성요소가 필요한데,
그것은 "백그라운드 상주/서비스 설치 금지" 원칙과 충돌하므로 1차 범위에서 제외합니다.

구현: `InputInjector.cs`

### B-4. JPEG 인코딩

WPF 의 `JpegBitmapEncoder` 를 씁니다. `System.Drawing.Common` 패키지가 필요 없고
.NET 8 데스크톱 런타임에 이미 포함되어 있습니다.

- `BitmapSource.Create(..., PixelFormats.Bgr32, ...)` 후 `Freeze()` 하면 백그라운드 스레드에서 안전합니다.
- 타일마다 인코더를 새로 만드는 비용이 있습니다. 1920x1080 전체 갱신(135타일)에서
  얼마나 걸리는지는 실기 측정이 필요합니다. 느리면 WIC 직접 호출로 바꿉니다.

구현: `TileEncoder.cs`

---

## C. 윈도우 실기가 필요한 항목

### C-1. MSIX(runFullTrust) 에서 캡처와 입력

`Package.appxmanifest` 에 `runFullTrust` 를 선언했습니다. 확인할 것:

- 패키지 설치 상태에서 `BitBlt` / `SendInput` 이 제한 없이 동작하는지
- DXGI 장치 생성이 되는지
- `launcher/scripts/sideload-test.ps1` 로 스토어 설치와 같은 조건 재현

**이전 구조와 달리 외부 실행 파일을 띄우지 않으므로**, 자식 프로세스 관리(Job Object)나
읽기 전용 설치 폴더 문제는 사라졌습니다.

### C-2. UAC / 관리자 권한 화면 (제약은 확정)

비관리자 권한 프로세스는 **보안 데스크톱(UAC 동의 창)과 더 높은 무결성 수준의 창을
캡처하거나 입력을 보낼 수 없습니다.** 윈도우 UIPI 제약이며 우회 대상이 아닙니다.

권고: 1차 범위에서는 "관리자 권한으로 다시 실행" 옵션을 넣지 않고,
UAC 창이 뜨면 상담원이 고객에게 구두로 안내합니다.

### C-3. 백신 오진

화면 캡처 + 입력 주입 + 외부 통신 조합은 오진 대상이 되기 쉽습니다.
Microsoft Defender, V3, 알약 등에서 확인이 필요합니다.
스토어 배포본은 Microsoft 서명이 들어가 가능성이 낮아지지만 보장되지는 않습니다.

### C-4. Microsoft Store 정책

`docs/store-submission.md` 참조. GPL 구성요소가 사라져 소스 공개 의무 항목은 없어졌고,
남은 쟁점은 `runFullTrust` 사유 설명과 원격제어 앱의 사용자 고지·동의 흐름입니다.

---

## 이전 구조(UltraVNC)에서 확인했던 내용

VNC 기반 구조는 폐기했지만, 당시 확인한 사실은 참고로 남깁니다.

- noVNC 의 `repeaterID` 옵션에는 `ID:` 접두어를 붙이면 안 된다(내부에서 붙임)
- uvncrepeater 의 Mode 2 ID 는 양의 10진 정수여야 한다(`strtol` 파싱)
- VNC 인증(DES) 비밀번호는 정확히 8자여야 하고, ultravnc.ini 에는 고정키로 암호화된 8바이트로 저장된다
- winvnc 설정 경로는 `-config` 로 지정할 수 있다
- nginx `auth_request` 서브요청은 원 요청의 쿼리 인자를 물려받지 않는다
- **TLS 1.3 세션 티켓 때문에 `select()` + 타임아웃 소켓 조합은 오동작한다**
  (현재 구조에서도 유효한 교훈. `ClientWebSocket` 은 이 문제가 없다)
