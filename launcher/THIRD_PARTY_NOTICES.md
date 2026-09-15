# 제3자 소프트웨어 고지

RemoteHelp 는 원격제어 엔진을 직접 구현합니다. **UltraVNC 등 GPL 구성요소를 포함하지 않으며,
외부 실행 파일을 동봉하거나 내려받지 않습니다.**

## 고객 런처 (launcher/)

| 구성요소 | 라이선스 | 용도 |
|---|---|---|
| .NET 8 (Microsoft.NETCore.App, WindowsDesktop) | MIT | 런타임 |
| Vortice.Windows (Vortice.Direct3D11, Vortice.DXGI) | MIT | DXGI Desktop Duplication 화면 캡처 |

화면 캡처(GDI/DXGI), JPEG 인코딩(WPF `JpegBitmapEncoder`), 입력 주입(`SendInput`),
웹소켓 통신(`ClientWebSocket`)은 모두 윈도우와 .NET 기본 기능을 씁니다.

## 중계 서버 (relay/)

| 구성요소 | 라이선스 | 용도 |
|---|---|---|
| Node.js | MIT | 런타임 |
| ws | MIT | 웹소켓 서버 |
| nginx | BSD 2-Clause | TLS 종료와 프록시 |

## 웹 포털 (portal/)

| 구성요소 | 라이선스 | 용도 |
|---|---|---|
| CodeIgniter 3 | MIT | 웹 프레임워크 |
| jQuery | MIT | AJAX 및 DOM 처리 |
| Tailwind CSS | MIT | 화면 스타일 |

상담원 화면 뷰어는 자체 구현(`portal/assets/js/console/`)이며 외부 뷰어 라이브러리를 쓰지 않습니다.

## 검증 도구 (docker/agentsim, scripts/)

개발 검증용으로만 쓰이며 배포본에는 포함되지 않습니다.

| 구성요소 | 라이선스 |
|---|---|
| websockets (Python) | BSD 3-Clause |
| Pillow | MIT-CMU |
