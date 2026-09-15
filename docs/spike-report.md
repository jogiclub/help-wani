# Phase 0 기술 검증 보고서 (Spike)

- 작성일: 2026-09-15
- 검증 환경: Ubuntu 24.04 컨테이너(도커), nginx 1.24, uvncrepeater-ac 1.0.0, websockify(Ubuntu 패키지), noVNC 1.7.0
- 소스 확인 기준: UltraVNC `main` 브랜치(2026-09-13 커밋 기준), noVNC v1.7.0 태그, uvncrepeater-ac master

검증 결과는 세 가지로 구분해 적었습니다.

- **검증 완료**: 이 환경에서 실제로 실행해 확인함
- **소스 확인**: 해당 버전의 공식 소스에서 동작을 확인함(윈도우 실기 실행은 아직 하지 못함)
- **미검증**: 윈도우 실기, 스토어 계정 등 이 환경에서 확인할 수 없는 항목

---

## 요약

| 번호 | 항목 | 상태 | 비고 |
|---|---|---|---|
| S-1 | winvnc 역방향 접속 + 리피터 ID 인자 | 소스 확인 | `-autoreconnect -id:NNN -connect host::port` |
| S-2 | winvnc 설정 파일 경로 | 소스 확인 | `-config <경로>` 로 명시 지정 가능. MSIX 제약 회피 |
| S-3 | 세션별 VNC 비밀번호 | 소스 확인 + 프로토콜 검증 완료 | 정확히 8자. ini 에는 DES 암호화된 8바이트로 저장 |
| S-4 | 리피터 + websockify + noVNC 연결 | **검증 완료** | 실제 화면 데이터 수신까지 확인 |
| S-5 | TLS 터널 구간 암호화 | **검증 완료** | 패킷 캡처에서 평문 미검출 |
| S-6 | MSIX(runFullTrust) 에서 winvnc 실행 | 미검증 | 바이너리는 동봉 완료(1.8.3.0 x64), 윈도우 실기 필요 |
| S-7 | UAC / 관리자 권한 화면 제어 | 미검증(제약은 확정) | 비관리자 실행 시 UAC 화면 제어 불가 |
| S-8 | 백신 오진 | 미검증 | 윈도우 실기 + 국내 백신 필요 |
| S-9 | Microsoft Store 정책 | 미검증(요건 정리) | `docs/store-submission.md` 참조 |

---

## S-1. winvnc 역방향 접속과 리피터 ID 지정

`winvnc/winvnc/winvnc.h` 에 정의된 명령행 상수를 그대로 확인했습니다.

```c
const char winvncConnect[]        = "-connect";
const char winvncAutoReconnect[]  = "-autoreconnect";
const char winvncStopReconnect[]  = "-stopreconnect";
const char winvncReconnectId[]    = "-id:";
const char winvncRepeater[]       = "-repeater";
const char winvncConfig[]         = "-config";
const char winvncUsageText[] =
  "winvnc [-sc_prompt] [-sc_exit] [-id:????] [-stopreconnect]"
  "[-autoreconnect[ ID:????]] [-connect host[:display]] [-connect host[::port]]"
  "[-repeater host[:port]][-run]\n";
```

`-connect` 인자 파싱(`winvnc.cpp`)에서 **콜론 한 개는 디스플레이 번호, 두 개는 포트 번호**로 해석합니다.
즉 `127.0.0.1::5901` 은 포트 5901, `127.0.0.1:1` 은 디스플레이 1(=5901)입니다.

### 런처가 사용할 명령행

```
winvnc.exe -config "%LOCALAPPDATA%\RemoteHelp\sessions\<세션>\ultravnc.ini" -autoreconnect -id:452405243 -connect 127.0.0.1::51234
```

- `-id:` 값은 **양의 10진 정수**여야 합니다. 리피터 쪽 `parseId()` 가 `strtol(&IdCode[3], NULL, 10)` 으로 해석하고,
  0 이나 파싱 실패는 연결을 끊습니다. 포털은 9자리 난수(100000000~999999999)를 발급합니다.
- `-autoreconnect` 를 붙이면 끊겼을 때 런처 개입 없이 재접속을 시도합니다. 종료 시에는 프로세스를 종료하므로
  `-stopreconnect` 를 따로 보낼 필요는 없습니다(Job Object 로 강제 종료).

### 남은 확인 사항(윈도우 실기)

- `-connect` 는 이미 실행 중인 winvnc 인스턴스에 메시지를 보내는 경로와, 새로 띄우는 경로가 함께 있습니다.
  런처는 항상 자기 세션 폴더의 winvnc 를 새로 띄우므로 기존 인스턴스가 없어야 합니다.
  기존에 UltraVNC 가 설치/서비스 실행 중인 PC 에서의 동작은 실기 확인이 필요합니다.

---

## S-2. 설정 파일(ultravnc.ini) 경로

`winvnc.cpp` 의 설정 경로 결정 로직을 확인했습니다. 우선순위는 다음과 같습니다.

1. `-config <경로>` 가 주어지면 그 경로를 그대로 사용 (명령행 선처리 루프에서 먼저 해석)
2. 포터블 모드(`IsPortableMode(winvncFolder)`)이면 실행 폴더
3. 관리자/서비스 실행이면 `%PROGRAMDATA%\UltraVNC\ultravnc.ini`
4. 비관리자면 `%LOCALAPPDATA%\UltraVNC\ultravnc.ini` → 없으면 ProgramData 로 폴백

**결론: `-config` 로 세션별 경로를 명시한다.** MSIX 설치 폴더가 읽기 전용이라는 제약과 무관해지고,
머신 전역(ProgramData) 설정을 건드리지 않아 기존 UltraVNC 설치와도 충돌하지 않습니다.

```
%LOCALAPPDATA%\RemoteHelp\sessions\<세션GUID>\ultravnc.ini
```

세션 종료 시 폴더째 삭제합니다.

---

## S-3. 세션별 VNC 비밀번호

### 확인한 사실

- `common/inifile.cpp` 의 `ReadPassword()/WritePassword()` 는 `GetPrivateProfileStruct/WritePrivateProfileStruct`
  로 **8바이트 구조체**를 다룹니다. 즉 ini 의 `passwd` 값은 평문이 아니라 8바이트 이진값입니다.
- `setpasswd/setpasswd/setpasswd.cpp` 의 `vncEncryptPasswd()` 는 고정키
  `{23,82,107,6,35,78,88,7}` 로 DES-ECB 암호화합니다(VNC 계열 공통 d3des, 바이트별 비트 순서 반전).
- noVNC(`core/rfb.js` → `core/crypto/des.js`)의 VNC 인증도 DES 8바이트 키를 그대로 요구하므로
  **비밀번호는 정확히 8자**여야 합니다. 짧으면 키 구성이 깨지고, 길면 잘립니다.

### 런처 구현 방침

1. 포털이 세션마다 8자 비밀번호를 발급한다(`random_vnc_password()`, 혼동 문자 제외).
2. 런처는 고정키 DES-ECB 로 8바이트를 만든 뒤 `WritePrivateProfileStructA`(kernel32) 로 ini 에 기록한다.
   직접 체크섬 형식을 흉내 내지 않고 Win32 API 를 그대로 쓴다.
3. 동봉된 `setpasswd.exe` 는 경로를 ProgramData 로 고정하므로 사용하지 않는다.

### 프로토콜 측 검증(완료)

8자 비밀번호로 리피터를 거쳐 VNC 인증이 통과하고 화면 데이터까지 수신되는 것을 확인했습니다(S-4 참조).
ini 기록 부분만 윈도우 실기 확인이 남아 있습니다.

---

## S-4. 리피터 + websockify + noVNC 연결 (검증 완료)

### 사용한 구성

- uvncrepeater-ac 1.0.0 (`server/repeater/`), Mode 2 전용, 5500/5900 모두 127.0.0.1 바인딩
- websockify `127.0.0.1:6080 → 127.0.0.1:5900`
- nginx `stream 5501(TLS) → 127.0.0.1:5500`, `https /ws → 127.0.0.1:6080` (+ `auth_request` 토큰 검증)
- 고객 PC 대역: `docker/vnctest`(Xvfb + x11vnc + 런처 모의 터널 `sc_shim.py`)

### 결과

```
0. websockify 경로로 접속: wss://localhost:8443/ws
1. 리피터 프로토콜 버전: RFB 000.000
2. 리피터 ID 전송: 498620884
3. 고객 VNC 서버 버전: RFB 003.008
4. 서버가 제시한 보안 방식: [2]
5. VNC 인증 성공
6. ServerInit: 1280x800, 데스크톱 이름 '75d3d2a10245:1'
7. FramebufferUpdate 수신: 사각형 1개
8. 첫 사각형 1280x800 (인코딩 0), 픽셀 4096000바이트 수신
```

(`scripts/rfb-probe.py` 실행 결과. 마우스/키보드 입력은 브라우저에서 별도 확인이 필요합니다.)

### 이 과정에서 확인한 중요한 사실 세 가지

**1) noVNC 의 `repeaterID` 에는 `ID:` 접두어를 붙이면 안 된다**

작업요청서 예시(`repeaterID: 'ID:' + repeaterId`)는 잘못되었습니다. noVNC 가 내부에서 붙입니다.

```javascript
// noVNC 1.7.0 core/rfb.js
if (isRepeater) {
    let repeaterID = "ID:" + this._repeaterID;   // 접두어를 여기서 붙인다
    while (repeaterID.length < 250) { repeaterID += "\0"; }
```

`assets/js/console/viewer.js` 는 숫자만 넘기도록 구현했습니다.

**2) auth_request 서브요청은 원 요청의 쿼리 인자를 물려받지 않는다**

`/ws?token=...` 에 대해 `auth_request` 안에서 `$arg_token` 을 쓰면 **빈 값**이 전달됩니다.
`$request_uri` 에서 직접 뽑아 변수에 담아야 합니다.

```nginx
map $request_uri $relay_token {
    default "";
    "~[?&]token=(?<captured_token>[a-fA-F0-9]{64})" $captured_token;
}
```

또한 `proxy_pass` 의 URI 에 변수를 쓰면 nginx 가 호스트를 런타임에 해석하려 해
`no resolver defined` 오류가 납니다. `upstream` 블록 이름을 쓰면 해결됩니다.

**3) 리피터는 서버측 핸드셰이크 배너를 먼저 읽어 보관한다**

`readPeerHandShake()` 가 서버 연결 직후 최대 5초 동안 배너를 읽어 두었다가, 뷰어가 붙을 때
"kickstart" 로 흘려보냅니다. 따라서 고객측(winvnc)이 연결 직후 RFB 버전 문자열을 보내지 않으면
뷰어는 아무것도 받지 못합니다. 런처의 터널 구현이 이 구간에서 데이터를 지연시키면 안 됩니다.

---

## S-5. TLS 터널 구간 암호화 (검증 완료)

`tcpdump -i any port 5501` 로 약 4MB(화면 1프레임 포함)를 캡처한 뒤 문자열 검색했습니다.

| 검색어 | 결과 |
|---|---|
| `RFB 003` (RFB 버전 문자열) | 0건 |
| `ID:<리피터ID>` | 0건 |

TLS 1.3 으로 협상되었고(`[shim] TLS 연결됨 (TLSv1.3)`), 고객-서버 구간에 평문이 노출되지 않습니다.

### 런처 구현 시 반드시 지킬 점 (실제로 겪은 문제)

검증용 터널을 `select()` + 타임아웃 소켓으로 구현했더니 **10초마다 연결이 끊겼습니다.**
원인은 TLS 1.3 의 NewSessionTicket 레코드였습니다. 서버가 핸드셰이크 직후 티켓을 보내면
소켓은 읽기 가능 상태가 되지만 애플리케이션 데이터는 없어서, 타임아웃이 걸린 `recv()` 가
그대로 블록되다가 타임아웃 예외로 끝납니다.

C# `SslStream` 도 같은 함정이 있습니다. **`ReadTimeout` 을 걸어 두고 폴링하지 말고,
방향별로 `CopyToAsync`(또는 전용 스레드)를 사용해야 합니다.** 유휴 감지는 하트비트로 합니다.

---

## S-6. MSIX(runFullTrust) 에서 동봉 winvnc 실행 — 미검증

윈도우 실기가 필요합니다. 설계상 유의점만 정리합니다.

- 패키지 설치 폴더(`%ProgramFiles%\WindowsApps\...`)는 읽기 전용입니다. winvnc 실행 자체는 가능하지만
  같은 폴더에 ini 를 쓸 수 없으므로 S-2 의 `-config` 방식이 필수입니다.
- winvnc 는 자식 프로세스를 만들 수 있으므로 **Job Object(`JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE`)** 로 묶어
  런처가 비정상 종료해도 남지 않게 합니다.
- 확인 방법: 자체 서명 인증서로 MSIX 를 만들어 사이드로드(`launcher/scripts/sideload-test.ps1`)한 뒤
  스토어 설치와 동일한 조건에서 실행합니다.

### 동봉한 바이너리 (2026-09-15 배치)

공식 배포본 UltraVNC 1.8.3.0 의 x64 파일 중 아래 6개만 포함했습니다.
`winvnc.exe` 의 정적 임포트를 확인한 결과 모두 윈도우 시스템 DLL 이었고,
UltraVNC 자체 DLL 은 기능을 켰을 때 `LoadLibrary` 로 불러옵니다.
바이너리 문자열에서 참조가 확인된 `vnchooks` / `logging` / `ddengine64` / `authSSP` 를 포함했고,
MS-Logon(ldapauth 계열), DSM 플러그인, 가상 디스플레이 드라이버는 사용하지 않으므로 제외했습니다.

| 파일 | 크기 |
|---|---|
| winvnc.exe | 3.67 MB |
| vnchooks.dll | 0.47 MB |
| logging.dll | 0.48 MB |
| authSSP.dll | 0.52 MB |
| ddengine64.dll | 0.32 MB |
| logmessages.dll | 0.01 MB |

출처와 체크섬은 `launcher/vendor/README.md`, 재배치는 `scripts/fetch-ultravnc.sh` 입니다.

## S-7. UAC / 관리자 권한 프로그램 제어 — 제약은 확정, 실기 미검증

비관리자 권한으로 실행한 VNC 서버는 **보안 데스크톱(UAC 동의 창)과 더 높은 무결성 수준의 창을
캡처하거나 입력을 보낼 수 없습니다.** 이는 윈도우의 UIPI 제약이며 우회 대상이 아닙니다.

권고: 1차 범위에서는 "관리자 권한으로 다시 실행" 옵션을 넣지 않고, UAC 창이 뜨면
상담원이 고객에게 구두로 안내하도록 합니다. 필요성이 확인되면 2차에서 검토합니다.

## S-8. 백신 오진 — 미검증

원격제어 엔진은 오진 대상이 되기 쉽습니다. 실기 확인 항목:

- Microsoft Defender(실시간 보호), V3, 알약 등 국내 백신
- 오진 시 대응: Microsoft Security Intelligence 오탐 신고, 각 백신사 오탐 신고 창구
- 스토어 배포본은 Microsoft 서명이 들어가므로 오진 가능성이 낮아지지만 보장되지는 않습니다.

## S-9. Microsoft Store 정책 — 요건 정리(제출은 미진행)

`docs/store-submission.md` 에 제출 준비물과 `runFullTrust` 사유서 초안을 정리했습니다.
핵심 쟁점은 세 가지입니다.

1. `runFullTrust` 제한 기능 사용 사유 설명
2. 원격제어 앱에 요구되는 사용자 고지와 동의 흐름(앱이 항상 표시되는 상태창을 유지하는지)
3. GPL-3.0 구성요소(UltraVNC) 동봉에 따른 소스 제공 의무

---

## 설계 변경 권고 (작업요청서 대비)

| 항목 | 작업요청서 | 변경 권고 | 이유 |
|---|---|---|---|
| noVNC `repeaterID` | `'ID:' + repeaterId` | 숫자만 전달 | noVNC 가 접두어를 붙임 |
| 리피터 ID 형식 | 명시 없음 | 양의 10진 정수 | `parseId()` 가 `strtol` 로 해석 |
| VNC 비밀번호 | "임의 비밀번호" | 정확히 8자 | VNC 인증 DES 키 길이 |
| ini 경로 | LocalAppData 복사 등 | `-config` 로 명시 | 공식 지원 경로, 전역 설정 미오염 |
| 런처 터널 | 명시 없음 | 방향별 비동기 복사 | TLS 1.3 세션 티켓 문제 |
| nginx auth | `auth_request` + `$arg_token` | `$request_uri` 에서 추출 | 서브요청은 쿼리 미상속 |
