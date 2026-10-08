# 런처(RemoteHelp.exe) Windows 빌드 작업 지시서

> 대상: Windows PC 에서 고객용 원격접속 실행 파일을 처음 빌드하는 담당자
> 상태: **이 저장소의 런처는 아직 한 번도 Windows 에서 빌드된 적이 없습니다.**
> (개발 환경이 리눅스/WSL 이라 코드 작성까지만 했습니다.) 아래 "5. 예상 문제"는 실제로 막힐 가능성이
> 높은 지점을 코드에서 미리 찾아 적은 것이며, 빌드해 보며 확인이 필요합니다.

---

## 0. 먼저 알아둘 것

| 항목 | 내용 |
|---|---|
| 산출물 | `RemoteHelp.exe` 한 개 (자체 포함 단일 파일, 약 70~90MB 예상) |
| 기술 | .NET 8 WPF, x64 전용, Windows 10 1809(10.0.17763) 이상 |
| 원격제어 | 자체 프로토콜(VNC 아님). 외부 실행 파일을 동봉하지 않음 |
| 배포 경로 | 1차는 Microsoft Store(MSIX). 이 문서의 exe 는 **직접 다운로드용**이며 `DIRECT_DOWNLOAD_ENABLED=false` 로 꺼져 있음 |
| 조직 코드 | exe 파일 이름 `RemoteHelp_{조직코드}.exe` 에서 읽음. 포털이 내려줄 때 이름을 붙여 줌 |

exe 는 두 가지 용도가 있습니다.

1. **내부 시험용** — 서명 없이 빌드해 내 PC/시험 PC 에서 동작 확인. 지금 단계에서 필요한 것.
2. **고객 배포용** — OV 코드 서명 인증서로 서명 필요(`docs/code-signing.md`). 서명 없으면
   SmartScreen 경고와 백신 차단이 발생합니다.

---

## 1. 작업 PC 준비

### 1.1 요구 사양

- Windows 10 / 11 x64 (가상머신도 가능하나 화면 캡처·DXGI 확인은 **실제 PC 권장**)
- 디스크 여유 10GB 이상
- 인터넷 연결 (NuGet 패키지 복원)

### 1.2 설치 목록

| 도구 | 용도 | 비고 |
|---|---|---|
| Git for Windows | 저장소 받기 | |
| .NET 8 SDK (x64) | 빌드 | Runtime 이 아니라 **SDK**. `dotnet --version` 이 8.x |
| Visual Studio 2022 17.9 이상 (Community 가능) | 솔루션 열기, MSIX 패키징 | 워크로드: **".NET 데스크톱 개발"**, MSIX 패키징도 할 거면 **"유니버설 Windows 플랫폼 개발"** 추가 |
| Windows SDK 10.0.19041 이상 | `signtool.exe`, WACK | VS 워크로드에 포함되거나 별도 설치 |

> exe 만 만든다면 .NET 8 SDK + Git 만으로 충분합니다. Visual Studio 는 디버깅과 MSIX 용입니다.

### 1.3 설치 확인

PowerShell 에서:

```powershell
dotnet --version        # 8.x.x
dotnet --list-sdks
git --version
```

대상 프레임워크가 `net8.0-windows10.0.19041.0` 이므로 SDK 가 Windows 대상 팩을 자동으로 받습니다.
오프라인 환경이면 첫 빌드가 실패하니 인터넷을 연결하세요.

---

## 2. 소스 받기

```powershell
cd C:\work
git clone <저장소 주소> help-wani
cd help-wani\launcher
```

> 현재 작업 중인 내용이 커밋되지 않았다면(저장소 상태가 수정 중) 담당 개발자에게 브랜치/커밋을 확인하세요.

폴더 구조:

```
launcher/
  RemoteHelp.sln
  RemoteHelp.App/          WPF 앱 + 원격제어 엔진 (exe 의 본체)
    Services/Engine/       화면 캡처(GDI/DXGI), 입력 주입, 타일 인코딩, 릴레이 접속
    Services/              포털 API, 세션 관리, 조직 코드 해석
    Assets/                (비어 있음 - app.ico 필요, 3.1 참고)
  RemoteHelp.Package/      MSIX 패키징 프로젝트 (Store 용)
  scripts/build-exe.ps1    직접 배포용 단일 exe 빌드 스크립트
  scripts/sideload-test.ps1  MSIX 시험 설치 스크립트
```

---

## 3. 빌드 전에 반드시 처리할 것

### 3.1 앱 아이콘 만들기 (현재 빌드가 여기서 실패합니다)

`RemoteHelp.App.csproj` 가 `Assets\app.ico` 를 참조하지만 **`Assets` 폴더가 비어 있습니다.**
아이콘이 없으면 빌드 오류가 납니다.

- 256x256 PNG 로고를 준비해 `.ico` 로 변환합니다(16/32/48/256 크기가 한 파일에 들어가야 함).
- 저장 위치: `launcher\RemoteHelp.App\Assets\app.ico`
- 급하면 임시 아이콘으로 빌드하고 나중에 교체해도 됩니다.

### 3.2 포털 주소 고정 (현재 자리표시자)

`Services/AppInfo.cs` 의 기본값이 `https://portal.example.com/` 입니다.
이대로 빌드하면 실제 포털과 통신하지 못합니다.

| 목적 | 방법 |
|---|---|
| 내 PC 에서 시험 | 빌드는 그대로 두고 실행 전에 환경변수 지정: `$env:REMOTEHELP_PORTAL="http://<개발서버IP>:8099/"` |
| 배포용 | `AppInfo.cs` 의 기본 문자열을 **실제 운영 포털 주소(https, 끝에 `/`)** 로 교체 후 빌드 |

> 환경변수 방식은 시험용입니다. 고객 PC 에는 환경변수를 줄 수 없으므로 배포용은 반드시 코드에 고정합니다.
> 주소를 바꾸면 커밋 전에 담당 개발자와 확인하세요(운영 주소가 저장소에 들어가도 되는지).

시험 서버가 http(TLS 없음)이면 중계 서버(`wss`) 연결이 막힐 수 있습니다. 포털이 내려주는
`relay_ws_url`(`PortalApiClient.VerifyResult`)을 그대로 쓰므로 **포털 `.env` 의 중계 서버 주소**가
시험 PC 에서 접근 가능한지 먼저 확인하세요.

### 3.3 버전 번호

`RemoteHelp.App.csproj` 의 `<Version>1.0.0</Version>`. 릴리스마다 올립니다.
포털 `.env` 의 `LAUNCHER_MIN_VERSION` 보다 낮으면 런처가 실행을 거부할 수 있으니 함께 확인합니다.

---

## 4. 빌드

### 4.1 방법 A — 스크립트로 한 번에 (권장)

```powershell
cd C:\work\help-wani\launcher\scripts

# 시험용 (서명 없음)
powershell -ExecutionPolicy Bypass -File .\build-exe.ps1

# 배포용 (서명 포함)
powershell -ExecutionPolicy Bypass -File .\build-exe.ps1 -SignPfx C:\keys\company.pfx -SignPassword "<비밀번호>"
```

스크립트가 하는 일:

1. `dotnet publish -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true
   -p:IncludeNativeLibrariesForSelfExtract=true -p:EnableCompressionInSingleFile=true -p:DebugType=none`
2. (선택) `signtool sign /fd SHA256 /tr <타임스탬프> /td SHA256` 로 서명
3. 산출물을 `portal\assets\downloads\RemoteHelp.exe` 로 복사하고 SHA-256 출력

산출 위치: `launcher\RemoteHelp.App\bin\Release\publish\RemoteHelp.exe`

> 인증서 비밀번호를 명령 기록에 남기지 않도록 주의하세요. `.pfx` 는 저장소에 넣지 않습니다(`.gitignore` 에 포함됨).

### 4.2 방법 B — 명령 직접 실행

```powershell
cd C:\work\help-wani\launcher
dotnet restore RemoteHelp.App\RemoteHelp.App.csproj
dotnet publish RemoteHelp.App\RemoteHelp.App.csproj -c Release -r win-x64 --self-contained true `
  -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true `
  -p:EnableCompressionInSingleFile=true -p:DebugType=none -o .\publish
```

### 4.3 방법 C — Visual Studio

`launcher\RemoteHelp.sln` 열기 → 구성 `Release | x64` → `RemoteHelp.App` 우클릭 → 게시.
디버깅(F5)은 이 방법이 편합니다.

> 솔루션에는 `RemoteHelp.Package`(MSIX)도 들어 있습니다. VS 에 "유니버설 Windows 플랫폼" 워크로드가
> 없으면 이 프로젝트가 로드 실패로 표시됩니다. exe 만 만들 때는 무시하고 `RemoteHelp.App` 만 빌드하세요.

### 4.4 빌드 성공 판정

- 오류 0, `RemoteHelp.exe` 생성
- 파일 크기 대략 수십 MB (자체 포함이므로 1MB 대면 자체 포함이 적용되지 않은 것)
- 다른 폴더로 복사해 실행해도 .NET 설치 없이 뜸

---

## 5. 예상 문제 (코드 확인 기준, 실제 빌드로 검증 필요)

| 증상 | 원인 추정 | 조치 |
|---|---|---|
| `app.ico` 를 찾을 수 없음 | 3.1 미처리 | 아이콘 배치 |
| `Vortice.*` 관련 컴파일 오류 | 패키지 3.6.2 의 API 차이. DXGI 코드(`DxgiScreenCapturer.cs`)는 Windows 에서 컴파일된 적이 없음 | 오류 메시지의 API 이름을 담당 개발자에게 전달. **임시 우회**: DXGI 캡처러 제외 후 GDI 만으로 빌드 가능(`ScreenCapturerFactory` 수정 필요, 담당자 확인) |
| NuGet 복원 실패 | 프록시/방화벽 | `nuget.org` 접근 허용 |
| exe 는 되는데 실행 즉시 종료 | 로그 확인 | 5.1 참고 |
| 서명 시 `signtool.exe` 없음 | Windows SDK 미설치 | SDK 설치 |
| 백신이 exe 를 삭제/격리 | 서명 없는 화면 캡처+입력 주입 프로그램은 탐지 대상 | 시험 PC 에서만 예외 등록. 배포는 서명 필수 |
| `sideload-test.ps1` 의 "ultravnc 확인" 안내 | **옛 문구.** VNC 를 쓰지 않음 | 무시 (스크립트 문구 정리 필요, 담당자에게 알림) |

### 5.1 로그 위치

`Services/AppLogger.cs` 가 기록합니다. 위치는 해당 파일에서 확인하세요(이 문서 작성 시 미확인).

---

## 6. 동작 시험 (Windows 실기)

시험에는 **개발 서버(포털+중계)가 켜져 있어야** 합니다. 서버 측은 `CLAUDE.md` 5장 참고
(`docker compose up -d --build`).

### 6.1 준비

1. 시험 PC 에서 개발 서버 주소로 접속 가능한지 브라우저로 확인 (`http://<서버>:8099/demo`)
2. 빌드한 exe 를 이름 변경: `RemoteHelp_demo.exe` (조직 코드 `demo`)
3. 환경변수(시험용 빌드인 경우): `$env:REMOTEHELP_PORTAL="http://<서버>:8099/"`

### 6.2 체크리스트

| # | 항목 | 기대 결과 | 결과 |
|---|---|---|---|
| 1 | `RemoteHelp_demo.exe` 실행 | 시작 화면에 조직 이름 표시 | |
| 2 | 파일 이름을 `RemoteHelp.exe` 로 바꿔 실행 | 조직 코드를 못 찾아 안내/입력 화면 | |
| 3 | 상담원 콘솔(`/login`) 에서 대기열 확인 | 고객 접속 후 대기열에 나타남 | |
| 4 | 고객 PC 에서 접속 코드 입력 | 연결 성공, 오버레이/트레이 표시 | |
| 5 | 상담원 뷰어에 고객 화면 표시 | 지연 체감 없이 갱신 | |
| 6 | 상담원이 마우스/키보드 조작 | 고객 PC 에서 실제 동작 | |
| 7 | 한글 입력, 해상도 다른 모니터, 다중 모니터 | 좌표/문자가 어긋나지 않음 | |
| 8 | 관리자 권한 창(작업 관리자, UAC) 조작 | 제한 동작 확인(권한 상승 필요 여부 기록) | |
| 9 | 고객이 종료 | 세션 종료, 상담원 화면에 종료 반영 | |
| 10 | 상담원이 종료 | 런처가 종료되고 만족도 조사 페이지 안내 | |
| 11 | GDI / DXGI 전환 | 기본 DXGI 시도 후 불가 시 GDI 자동 전환, 로그에 기록 | |
| 12 | 네트워크 단절/복구 | 재연결 또는 오류 안내 | |

문제가 있으면 **스크린샷, 로그, 재현 순서, Windows 버전/그래픽 카드**를 함께 보고하세요.

---

## 7. 포털에 올리기 (고객 다운로드 연결)

1. `build-exe.ps1` 이 `portal\assets\downloads\RemoteHelp.exe` 로 복사합니다. 이 파일은
   `.gitignore` 되어 있어 **커밋되지 않습니다.** 운영 서버에는 별도로 올려야 합니다.
2. 직접 다운로드는 기본 **꺼져 있습니다.** 켜려면 포털 `.env` 에서
   `DIRECT_DOWNLOAD_ENABLED=true` — **서명된 exe 를 올린 뒤에만** 켭니다.
3. 포털의 `Download::launcher()` 가 조직별 파일 이름 `RemoteHelp_{조직코드}.exe` 로 내려줍니다.
   (같은 파일을 이름만 바꿔 전송하므로 조직별로 따로 빌드하지 않습니다.)
4. 고객 페이지(`/<조직코드>`)에서 다운로드 버튼과 SHA-256 이 보이는지 확인합니다.

---

## 8. (선택) Microsoft Store 용 MSIX

Store 가 1차 배포 경로입니다. exe 와 별개로 진행하며 상세는 `docs/store-submission.md`.

- 선행 조건: Partner Center 계정, `Package.appxmanifest` 의 Identity 를 예약값으로 교체,
  `RemoteHelp.Package\Images\` 로고 에셋 7종(**현재 비어 있음**)
- 시험 설치: **관리자 PowerShell** 에서 `launcher\scripts\sideload-test.ps1 -Build`
  (자체 서명 인증서를 만들어 `LocalMachine\TrustedPeople` 에 등록하므로 **시험 PC 에서만** 실행)
- 시험 후 제거: `Get-AppxPackage *RemoteHelp* | Remove-AppxPackage`,
  등록한 시험 인증서도 삭제
- 제출 전 WACK 통과 필요

---

## 9. 작업 완료 보고 양식

작업이 끝나면 아래를 채워 전달하세요 (`CLAUDE.md` 1장 규칙).

```
[완료 항목]
- 빌드 환경: Windows ___ / .NET SDK ___ / VS ___
- 빌드 결과: 성공/실패, exe 크기 ___MB, SHA-256 ___
- 서명 여부: 서명함(인증서 ___) / 안 함
- 시험 체크리스트(6.2) 통과 항목: ___ / 12

[미완료 항목]
-

[확인 필요 사항]
- 빌드 중 수정한 소스(있다면 파일과 이유)
- 실패한 시험 항목과 증상(스크린샷/로그 첨부)
- 시험에 쓴 계정·데이터를 원래대로 되돌렸는지
```

> 빌드 중 소스를 고쳤다면 파일 맨 위 위치/역할 주석 규칙을 지키고, 임의로 커밋하지 말고 변경 내용을 보고하세요.
