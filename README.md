# RemoteHelp (help-wani)

기업이 자기 고객의 Windows PC 를 원격으로 지원하는 B2B 원격지원 서비스입니다.
원격제어 엔진과 프로토콜을 직접 구현합니다. **VNC 를 쓰지 않습니다.**

```
[고객 PC 런처(.NET 8)]  --wss /agent--+
   화면 캡처 + 입력 주입              |--> nginx(TLS) --> Node 중계 서버
                                      |                        |
[상담원 브라우저(캔버스 뷰어)] --wss /viewer--+          포털 API (토큰 검증)
```

외부에 열리는 포트는 포털 443 과 중계 서버 443 뿐입니다.

## 저장소 구성

| 경로 | 내용 |
|---|---|
| `portal/` | CodeIgniter 3 포털 (고객 페이지, 상담원 콘솔, 관리자, API) |
| `relay/` | Node.js 중계 서버 (세션 짝짓기와 바이너리 중계) |
| `launcher/` | 고객 런처 (.NET 8 WPF) + 원격제어 엔진 + MSIX 패키징 |
| `docker/` | 개발용 컨테이너 정의 (web, relay, agentsim) |
| `server/` | 중계 서버 설치 스크립트와 nginx/systemd 설정 |
| `sql/` | 스키마, 마이그레이션, 개발용 시드 데이터 |
| `scripts/` | 검증 도구와 빌드 스크립트 |
| `docs/` | 프로토콜 명세, 기술 검증 보고서, 스토어 제출 준비 문서 |

## 개발 환경 실행

```bash
cp .env.example .env    # 값 수정 (DB 비밀번호, ENCRYPTION_KEY 등)
docker compose up -d --build
```

| 주소 | 용도 |
|---|---|
| http://localhost:8099/login | 상담원 콘솔 (admin@demo.local / Test1234!admin) |
| http://localhost:8099/demo | 고객 접속 페이지 |
| https://localhost:8443/healthz | 중계 서버 상태 |

`.env` 의 `ENCRYPTION_KEY` 는 64자리 16진수여야 합니다(`openssl rand -hex 32`).

중계 서버는 개발용 자체 서명 인증서를 쓰므로, 브라우저에서 원격 화면을 열기 전에
https://localhost:8443/healthz 를 한 번 방문해 인증서를 허용해야 wss 연결이 됩니다.

## 프로토콜

`docs/protocol.md` 에 전체 명세가 있습니다. 요약하면,

- WebSocket 하나로 통일. **텍스트 = 제어(JSON), 바이너리 = 화면/입력**
- 화면은 **128x128 타일**로 나눠 바뀐 타일만 JPEG 으로 전송
- 좌표는 고객 PC 가상 데스크톱 기준 절대 픽셀, 정수는 빅엔디안
- 중계 서버는 바이너리를 해석하지 않으므로 프로토콜이 바뀌어도 고칠 필요가 없다

## 동작 검증

포털 API 부터 실제 화면 수신까지 한 번에 점검합니다.

```bash
./scripts/e2e-test.sh
```

점검 항목: 로그인 → 코드 발급 → 런처 검증 → 코드 재사용 차단 → 에이전트 접속 →
뷰어 토큰 발급 → 잘못된 토큰 차단 → **실제 화면 프레임 수신과 입력 전송** →
토큰 재사용 차단 → 상담원 종료 전파

브라우저 없이 뷰어 쪽만 확인하려면:

```bash
python3 scripts/viewer-probe.py --url wss://localhost:8443/viewer \
    --token <1회용 뷰어 토큰> --insecure --frames 3
```

## 화면 스타일 (Tailwind CSS)

```bash
./scripts/build-css.sh           # portal/assets/css/app.css 생성
./scripts/build-css.sh --watch   # 뷰를 수정하며 작업할 때
```

- 입력: `portal/assets/css/tailwind.src.css`
- 출력: `portal/assets/css/app.css` (저장소에 포함하므로 빌드 없이도 화면은 동작)
- 뷰나 JS 에 **새 Tailwind 클래스를 추가하면 반드시 다시 빌드**해야 합니다.

알림과 모달은 외부 UI 프레임워크 없이 `assets/js/common.js` 의
`showToast` / `showConfirmModal` / `openModal` / `closeModal` 로 처리합니다.

## 진행 상황

| Phase | 내용 | 상태 |
|---|---|---|
| 0 | 기술 검증 | 프로토콜·중계·서버 구간 검증 완료, 윈도우 실기 미검증 (`docs/spike-report.md`) |
| 1 | 중계 서버 | 완료 (도커 검증 완료, `server/install.sh` 는 실서버 미적용) |
| 2 | 웹 포털 | 완료 (스키마, API, 콘솔, 고객 페이지, 관리자) |
| 3 | 고객 런처 + 원격제어 엔진 | 코드 작성 완료, 윈도우 빌드/실행 미검증 |
| 4 | MSIX 패키징 / 스토어 제출 | 매니페스트와 제출 문서 준비, 실제 제출 미진행 |
| 5 | 통합 테스트 | 서버 구간 시나리오 자동화 완료, 윈도우 실기 시나리오 미수행 |

## 다음에 필요한 것

1. 윈도우 개발 PC — 런처 빌드와 `docs/spike-report.md` 의 B/C 항목 실기 확인
2. 실서버와 도메인, Let's Encrypt 인증서 (`server/README.md`)
3. Partner Center 계정과 앱 이름 예약 (`docs/store-submission.md`)
4. 발주자 결정 사항: 서비스명/도메인, 중계 서버 사양, 사업자 인증 방식, 요금제,
   약관·개인정보처리방침 작성 주체

## 라이선스 참고

원격제어 엔진을 직접 구현하므로 GPL 구성요소가 없습니다.
사용 중인 제3자 구성요소는 모두 허용적 라이선스이며 `launcher/THIRD_PARTY_NOTICES.md` 에 정리되어 있습니다.
