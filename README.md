# RemoteHelp (help-wani)

기업이 자기 고객의 Windows PC 를 원격으로 지원하는 B2B 원격지원 서비스입니다.
UltraVNC(SC 역방향 접속) + UltraVNC Repeater + noVNC 구성입니다.

```
[고객 PC] 런처 -> TLS 5501 -> nginx stream -> uvncrepeater(5500)
                                                    |
[상담원] 브라우저 noVNC -> wss /ws -> websockify -> uvncrepeater(5900)
                                 (접속 전 포털 API 로 1회용 토큰 검증)
```

## 저장소 구성

| 경로 | 내용 |
|---|---|
| `portal/` | CodeIgniter 3 포털 (고객 페이지, 상담원 콘솔, 관리자, API) |
| `server/` | 중계 서버 설치 스크립트, nginx/systemd 설정, uvncrepeater 소스 |
| `launcher/` | 고객 런처 (.NET 8 WPF) + MSIX 패키징 프로젝트 + 동봉 UltraVNC 바이너리 |
| `docker/` | 개발용 컨테이너 정의 (web, relay, vnctest) |
| `sql/` | 스키마와 개발용 시드 데이터 |
| `scripts/` | 검증 도구와 빌드 스크립트 (`e2e-test.sh`, `rfb-probe.py`, `fetch-vendor.sh`, `build-css.sh`) |
| `docs/` | Phase 0 검증 보고서, 스토어 제출 준비 문서 |

## 개발 환경 실행

```bash
cp .env.example .env    # 값 수정 (DB 비밀번호, ENCRYPTION_KEY 등)
./scripts/fetch-vendor.sh
docker compose up -d --build
```

| 주소 | 용도 |
|---|---|
| http://localhost:8099/login | 상담원 콘솔 (admin@demo.local / Test1234!admin) |
| http://localhost:8099/demo | 고객 접속 페이지 |
| https://localhost:8443/healthz | 중계 서버 상태 확인 |
| localhost:5501 | 런처 TLS 터널 |

`.env` 의 `ENCRYPTION_KEY` 는 64자리 16진수여야 합니다(`openssl rand -hex 32`).

중계 서버는 개발용 자체 서명 인증서를 사용하므로, 브라우저에서 원격 화면을 열기 전에
https://localhost:8443/healthz 를 한 번 방문해 인증서를 허용해야 wss 연결이 됩니다.

## 원격지원 엔진 (UltraVNC)

런처가 실행하는 UltraVNC 1.8.3.0 (x64) 바이너리가 `launcher/vendor/ultravnc/` 에 포함되어 있으므로
따로 준비할 필요가 없습니다. 무결성 확인과 재배치는 아래와 같습니다.

```bash
cd launcher/vendor/ultravnc && sha256sum -c SHA256SUMS   # 무결성 확인
./scripts/fetch-ultravnc.sh                              # 공식 배포본에서 다시 받기
```

GPL-3.0 구성요소이므로 배포 전에 대응 소스를 공개해야 합니다.
자세한 내용은 `launcher/vendor/README.md` 와 `launcher/THIRD_PARTY_NOTICES.md` 를 보세요.

## 화면 스타일 (Tailwind CSS)

포털 화면은 Tailwind CSS v4 로 작성합니다. Node.js 없이 독립 실행 CLI 로 빌드합니다.

```bash
./scripts/build-css.sh           # portal/assets/css/app.css 생성
./scripts/build-css.sh --watch   # 뷰를 수정하며 작업할 때
```

- 입력: `portal/assets/css/tailwind.src.css` (색상 토큰과 `.btn` / `.card` / `.table` 등 공통 클래스 정의)
- 출력: `portal/assets/css/app.css` (저장소에 포함하므로 빌드 없이도 화면은 정상 동작)
- 뷰나 JS 에 **새 Tailwind 클래스를 추가하면 반드시 다시 빌드**해야 반영됩니다.

알림과 모달은 외부 UI 프레임워크 없이 `assets/js/common.js` 의
`showToast(message, type)` / `showConfirmModal(title, message, onConfirm, onCancel)` /
`openModal(id)` / `closeModal(id)` 로 처리합니다.

## 동작 검증

포털 API 부터 실제 화면 데이터 수신까지 한 번에 점검합니다.

```bash
./scripts/e2e-test.sh
```

점검 항목: 로그인 → 코드 발급 → 런처 코드 검증 → 코드 재사용 차단 → 고객 PC 모의 접속 →
뷰어 토큰 발급 → 잘못된 토큰 차단 → 실제 RFB 세션 수립 → 토큰 재사용 차단 → 상담원 종료 전파

브라우저 없이 뷰어 쪽 프로토콜만 확인하려면:

```bash
python3 scripts/rfb-probe.py --host localhost --port 8443 \
    --ws-token <토큰> --repeater-id <ID> --password <8자리>
```

## 진행 상황

| Phase | 내용 | 상태 |
|---|---|---|
| 0 | 기술 검증 | 프로토콜/서버 구간 검증 완료, 윈도우 실기 항목 미검증 (`docs/spike-report.md`) |
| 1 | 중계 서버 | 완료 (도커 검증 완료, `server/install.sh` 는 실서버 미적용) |
| 2 | 웹 포털 | 완료 (스키마, API, 콘솔, 고객 페이지, 관리자) |
| 3 | 고객 런처 | 코드 작성 완료 + UltraVNC 1.8.3.0 동봉, 윈도우 빌드/실행 미검증 |
| 4 | MSIX 패키징 / 스토어 제출 | 매니페스트와 제출 문서 준비, 실제 제출 미진행 |
| 5 | 통합 테스트 | 서버 구간 시나리오 자동화 완료, 윈도우 실기 시나리오 미수행 |

## 다음에 필요한 것

1. 윈도우 개발 PC (런처 빌드, `docs/spike-report.md` 의 S-1/S-2/S-3/S-6/S-7/S-8 실기 확인)
2. 실서버와 도메인, Let's Encrypt 인증서 (`server/README.md`)
3. Partner Center 계정과 앱 이름 예약 (`docs/store-submission.md`)
4. GPL 대응 소스 공개 URL 확보 (`./scripts/fetch-ultravnc.sh --with-source` 로 스냅샷 생성)
5. 발주자 결정 사항: 서비스명/도메인, 중계 서버 사양, 사업자 인증 방식, 요금제,
   약관/개인정보처리방침 작성 주체, GPL 구성요소 법률 검토
