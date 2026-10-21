<!--
  파일 위치: docs/request-launcher-domain.md
  역할: 런처 시험 빌드의 포털 주소를 https://help.wani.im 으로 바꾸는 작업요청서 (포털 세션 -> 런처 세션)
-->

# 작업요청서: 런처 시험 빌드 포털 주소를 https://help.wani.im 으로 변경

- 요청일: 2026-10-21
- 요청 세션: 포털/서버 작업 세션(원격접속 연결 실패)
- 받는 쪽: 런처(.NET) 작업 세션(help_remote)

## 배경

시험 서버 도메인 `help.wani.im` 이 **Cloudflare 프록시(주황 구름)** 로 바뀌었고, 포털은 Cloudflare Tunnel
(cloudflared 컨테이너)로 받는다. 그래서 다음과 같다.

- `https://help.wani.im/` : 동작(터널 연결 후). Cloudflare 정식 인증서라 인증서 예외가 필요 없다.
- `http://help.wani.im:8099/` : **동작하지 않는다.** Cloudflare 가 8099 포트를 넘기지 않는다.
- `http://118.36.197.219:8099/` : 지금처럼 동작한다(고객 IP 는 알 수 없음).
- relay(`/agent`) : 그대로 `wss://118.36.197.219:8443/agent` (자체 서명). 도메인으로 바꾸지 않는다.

## 바꿀 곳

| 파일 | 지금 | 바꿀 값 |
|---|---|---|
| `launcher/RemoteHelp.App/Services/AppInfo.cs` (TEST_BUILD) | `http://118.36.197.219:8099/` | `https://help.wani.im/` |
| `launcher/RemoteHelp.Viewer/AppInfo.cs` `DefaultPortal` | `http://118.36.197.219:8099/` | `https://help.wani.im/` |
| `launcher/RemoteHelp.Viewer/AppInfo.cs` `TestTrustedHost` | `118.36.197.219` | 그대로 둔다(포털은 정식 인증서, 예외는 IP relay 에만 필요) |
| `launcher/RemoteHelp.App/Services/Engine/RelayClient.cs` 인증서 예외 | 호스트가 `118.36.197.219` 일 때만 | 그대로 둔다 |
| `launcher/scripts/build-exe.ps1` 안내 문구 | `http://118.36.197.219:8099/demo/download` | `https://help.wani.im/demo/download` |

## 순서

1. 서버: Cloudflare Tunnel 연결 후 `https://help.wani.im/` 이 열리는지 확인
   (포털 세션이 확인 후 알린다)
2. 런처: 위 표대로 고쳐 시험 빌드 배포
3. 확인: 새 런처로 코드 입력 -> 원격 화면 연결까지 성공, 포털 `verify_attempts.ip` 에 실제 공인 IP 가 찍히는지

relay 주소는 바꾸지 않으므로 기존 런처와 새 런처가 함께 동작한다.

## 참고: 이번에 서버 쪽에서 바뀐 것

- 상담원 뷰어(브라우저)는 포털 경유 `ws://<서버>:8099/relay-viewer` 로 relay 에 붙는다(커밋 206477e).
  런처 `/agent` 경로는 그대로 8443 직결이다.
- 오류 응답의 빈 `data` 가 `[]` 대신 `{}` 로 나간다(커밋 5b3f83d). 이전에는 429 차단 응답 등이
  런처에서 "서버 응답을 해석하지 못했습니다" 로 보였다. 런처도 `data` 를 못 읽을 때 `message` 를
  보여 주도록 방어 코드를 두면 좋다.

## 완료 후 회신

배포한 exe 해시, 다운로드 응답, 실제 연결 결과를 이 문서 아래에 덧붙이거나 포털 세션에 알려 준다.
