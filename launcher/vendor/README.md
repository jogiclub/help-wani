# UltraVNC 바이너리

**별도로 올릴 필요 없습니다.** 런처가 쓰는 파일이 `ultravnc/` 에 이미 포함되어 있습니다.

## 포함된 파일 (UltraVNC 1.8.3.0, x64)

| 파일 | 크기 | 역할 |
|---|---|---|
| `winvnc.exe` | 3.67 MB | 원격지원 엔진 본체 |
| `vnchooks.dll` | 0.47 MB | 화면 변경 감지 훅 |
| `logging.dll` | 0.48 MB | 로깅 |
| `logmessages.dll` | 0.01 MB | 이벤트 로그 메시지 |
| `authSSP.dll` | 0.52 MB | 인증 보조 |
| `ddengine64.dll` | 0.32 MB | 화면 캡처 엔진 |

`SHA256SUMS` 로 무결성을 확인할 수 있습니다.

```bash
cd launcher/vendor/ultravnc && sha256sum -c SHA256SUMS
```

## 출처

- 배포본: https://uvnc.eu/download/1800/UltraVNC_1830.zip (uvnc.com 공식 다운로드 페이지 경유)
- zip SHA-256: `11163a0b9b86321bf6403eb0cf84b81f7230ce32e6291377e1fe5beb987f7e4f`
- 대응 소스: https://github.com/ultravnc/UltraVNC commit `33cee1a22e84e395c2ed09ad94fb4ad0b2f9c94b` ("1.8.3.0")

다시 받거나 버전을 올리려면:

```bash
./scripts/fetch-ultravnc.sh                # 바이너리 배치 + 체크섬 검증
./scripts/fetch-ultravnc.sh --with-source  # GPL 대응 소스 스냅샷까지
```

## 제외한 것과 이유

| 제외 | 이유 |
|---|---|
| `ldapauth*.dll`, `workgrpdomnt4.dll`, `authadmin.dll` | MS-Logon 기능용. 런처는 `MSLogonRequired=0` 으로 쓰지 않음 |
| `SecureVNCPlugin64.dsm` (4.3 MB) | DSM 암호화 플러그인. 런처가 자체 TLS 터널을 쓰므로 불필요 |
| `UVncVirtualDisplay64/` | 커널 드라이버. MSIX 로 설치할 수 없음 |
| `vncviewer.exe`, `repeater.exe` | 고객 PC 에 필요 없음 |
| `languages/*.dll` | 한국어 리소스가 없고, 기본 언어로 동작함 |
| x86 전체 | 패키지 플랫폼이 x64 |

`winvnc.exe` 의 정적 임포트는 모두 윈도우 시스템 DLL 이며, 위 목록의 DLL 은
필요할 때 `LoadLibrary` 로 불러옵니다. 제외한 DLL 들은 해당 기능을 켰을 때만 참조됩니다.

## 빌드 시 동작

`RemoteHelp.App.csproj` 가 이 폴더의 파일을 빌드 출력의 `ultravnc\` 로 복사하며,
런처는 `AppContext.BaseDirectory\ultravnc\winvnc.exe` 를 실행합니다.

## GPL 의무

UltraVNC 는 GPL-3.0-or-later 입니다. 배포 전에 대응 소스를 공개 URL 에 올리고
`launcher/THIRD_PARTY_NOTICES.md` 의 주소를 실제 값으로 바꿔야 합니다.
