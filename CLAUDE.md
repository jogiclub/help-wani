# RemoteHelp 프로젝트 지침

이 파일은 이 저장소에서 작업할 때 지켜야 할 규칙을 모아 둔 곳입니다.
**새 규칙이 정해지면 반드시 여기에 추가합니다.**

---

## 1. 작업 방식

- **항상 한국어로 답변합니다.** 최종 보고뿐 아니라 작업 중 진행 안내, 중간 설명, 질문, 커밋 메시지까지 모두 한국어입니다.
  긴 작업이나 오류 대응 중에도 영어로 바꾸지 않습니다(코드, 명령어, 로그 원문은 그대로 둡니다).
- 코드 설명에 이모티콘을 쓰지 않습니다.
- 모든 소스 파일 **맨 위에 위치와 역할 주석**을 답니다.

  ```php
  /**
   * 파일 위치: application/controllers/Example.php
   * 역할: 예제 데이터 조회 및 처리
   */
  ```

- 가이드 문서와 테스트 코드는 **요청받았을 때만** 작성합니다.
- 외부 도구의 옵션, 명령행 인자, 설정 문법은 **추측하지 않습니다.**
  해당 버전의 공식 문서나 소스로 확인하고, 확인 결과를 함께 보고합니다.
- 비밀값(API 키, 서명키, DB 비밀번호)은 코드에 넣지 않고 `.env` 로 분리하며 `.gitignore` 에 포함합니다.
- 작업 단위가 끝나면 **완료 항목 / 미완료 항목 / 확인 필요 사항**을 정리해 보고합니다.
- 실제 데이터를 테스트에 썼다면 원래 값으로 되돌리고 그 사실을 알립니다.

---

## 2. 구조

```
[고객 PC 런처(.NET 8)]  --wss /agent--+
   화면 캡처 + 입력 주입              |--> nginx(TLS) --> Node 중계 서버
                                      |                        |
[상담원 브라우저(캔버스 뷰어)] --wss /viewer--+          포털 API (토큰 검증)
```

| 경로 | 내용 |
|---|---|
| `portal/` | CodeIgniter 3 포털 |
| `relay/` | Node.js 중계 서버(원격 화면 중계) |
| `realtime/` | Node.js 상담 실시간 메시지 서버(realtime-msg) |
| `launcher/` | .NET 8 WPF 런처 + 원격제어 엔진 |
| `docker/` | 개발용 컨테이너 |
| `server/` | 중계 서버 설치 스크립트 |
| `sql/` | 스키마, 마이그레이션, 시드 |
| `scripts/` | 검증/빌드 스크립트 |
| `docs/` | 프로토콜, 검증 보고서, 제출 문서, 작업요청서 |

**원격제어는 자체 프로토콜입니다. VNC 를 쓰지 않습니다.** 명세는 `docs/protocol.md`.

---

## 3. 반드시 지킬 규칙

어기면 조용히 깨지는 것들입니다. `./scripts/check-conventions.sh` 가 자동으로 검사합니다.

### 3.1 Tailwind — 클래스를 추가하면 다시 빌드

뷰나 JS 에 **새 Tailwind 클래스를 쓰면 CSS 를 다시 만들어야** 화면에 반영됩니다.

```bash
./scripts/build-css.sh
```

- 입력 `portal/assets/css/tailwind.src.css`, 출력 `portal/assets/css/app.css`(저장소에 포함)
- 새 색상 단계가 필요하면 `@theme` 에 먼저 추가합니다. 없는 단계를 `@apply` 하면 빌드가 실패합니다.
- 공통 컴포넌트는 `.btn` `.card` `.form-input` `.badge` `.table` `.icon-tile` 을 조합해 씁니다.

### 3.2 다국어 — 세 사전을 함께 고친다

문구는 `portal/assets/lang/{ko,en,ja}.json` 에 있고 PHP 와 JS 가 같은 파일을 씁니다.

- **키를 추가하면 세 파일 모두에 넣습니다.** 빠지면 그 언어에서 키 문자열이 그대로 보입니다.
- 서버: `lang_text('key', ['org' => '이름'])`
- 화면: `__('key')` 또는 `data-i18n="key"` 속성

언어 결정 우선순위

| 화면 | 적용 언어 |
|---|---|
| 고객 접속 페이지, 만족도 조사 | **조직 설정** (그 조직의 고객이 보는 화면) |
| 소개 페이지, 상담원 콘솔 | 방문자 선택(`rh_locale` 쿠키) → 조직 설정 → 한국어 |

### 3.3 아이콘 — 목록에 넣어야 나온다

[Material Symbols](https://fonts.google.com/icons) 를 **서브셋으로** 받습니다(전체 315KB → 약 5.5KB).

- 아이콘을 추가하면 **`Home::landing_icons()` 목록에도 넣습니다.** 빠지면 아이콘 대신 영문 글자가 보입니다.
- 아이콘 폰트는 `icon_names` 를 넘긴 화면에서만 불러옵니다.

### 3.4 시간 — UTC 로 저장하고 표시할 때만 변환

조직마다 시간대가 다르므로 저장 기준은 UTC 입니다.

```
MySQL  --default-time-zone=+00:00
PHP    date.timezone = UTC
표시   서버 to_timezone($utc, $tz) / 화면 RHI18n.formatDate(utc)
```

- 새 화면에서 날짜를 그릴 때 **원본을 그대로 출력하지 않습니다.** 반드시 변환 함수를 거칩니다.
- 운영 서버에서 두 설정이 어긋나면 시각이 통째로 밀립니다.

### 3.5 목록은 AG Grid, 기본 100개

- 공통 설정은 `portal/assets/js/grid.js` 의 `RHGrid.create()` 를 씁니다.
- 컨트롤러에서 `'use_grid' => TRUE` 를 넘겨야 그리드 라이브러리를 불러옵니다.
- 날짜 열은 `RHGrid.dateTime` / `RHGrid.shortDateTime` 을 씁니다(조직 시간대 적용).
- Enterprise 기능(`agSetColumnFilter` 등)은 쓰지 않습니다. Community 판입니다.

### 3.6 메뉴와 권한 — 사용자와 운영자는 분리

| 레이아웃 | 대상 | 메뉴 |
|---|---|---|
| `layouts/main.php` | 조직 소속 사용자 | 원격지원(대기열+이력 통합) / 상담 대화 / (관리자) 상담원 관리 · 조직 정보 · 가이드 관리. 조직 관리 통계(`/admin`)는 왼쪽 위 서비스 이름, 상담 위젯 설정은 상담 대화의 설정 드로어 |
| `layouts/operator.php` | 플랫폼 운영자 | 조직 가입 관리 / 감사 로그 |
| `layouts/public.php` | 방문자 | 소개 페이지 |
| `layouts/customer.php` | 고객 | 접속 안내, 만족도 조사, 공개 가이드(`/{조직코드}/guide`) |

| 권한 | 범위 | 판별 |
|---|---|---|
| 상담원 | 자기 조직 상담 | `agents.role = 'agent'` |
| 조직 관리자 | 자기 조직 설정 | `agents.role = 'admin'` |
| 플랫폼 운영자 | 전체 조직 승인 | `agents.is_super = 1` |

운영자 전용 화면은 `Operator_Controller`, 조직 관리자 전용 화면은 `Admin_Controller` 를 상속합니다.
권한 거부처럼 응답을 보내고 바로 끝낼 때는 `api_response()` 뒤에 `$this->output->_display(); exit;` 로 씁니다.
`exit` 만 하면 CI 가 출력을 내보내지 않아 본문이 빈 채로 나갑니다.

### 3.7 API 와 UI 공통 규약

- 모든 API 응답은 `{ "result": true|false, "message": "", "data": {} }` 형식입니다. `api_response()` 를 씁니다.
- 알림은 `showToast(message, type)`, 확인은 `showConfirmModal(title, message, onConfirm, onCancel)`,
  모달은 `openModal(id)` / `closeModal(id)` 를 씁니다. 외부 UI 프레임워크를 쓰지 않습니다.
- 설정처럼 화면 옆에서 여는 패널은 드로어 `openDrawer(id)` / `closeDrawer(id)` 를 씁니다.
  마크업은 `.drawer-root > .drawer-backdrop + .drawer-panel(.drawer-header / .drawer-body / .drawer-footer)`,
  닫기 요소에 `data-drawer-close="id"`. 예: `views/console/_widget_settings.php`(상담 대화 > 설정)
- **JS 에서 API 주소를 상대 경로로 적지 않습니다.** 뷰에서 `base_url()` 로 만든 절대 주소를
  `XXX_URLS` 전역에 담아 넘깁니다. 상대 경로는 보는 화면의 깊이에 따라 `/console/console/...` 로 어긋납니다.
- 관리 행위는 `audit_logs`, 세션 이벤트는 `session_logs` 에 남깁니다.
- 사용자 입력으로 상태를 바꿀 때는 **허용 목록 검사**를 합니다(언어, 시간대, 국가, 상태값 등).

### 3.8 라우팅 순서

`$route['(:any)']` 는 조직 코드를 받는 규칙이라 **반드시 맨 아래**에 둡니다.
새 최상위 경로를 추가할 때는 그보다 위에 넣습니다.

### 3.9 상담 위젯 — 고객사 사이트 안에서 도는 코드

`portal/assets/js/widget.js` 는 **우리 화면이 아니라 고객사 웹사이트에 삽입**됩니다.
포털의 자산을 쓸 수 없고, 써도 고객사 스타일과 충돌합니다.

- **Tailwind(`app.css`), `common.js`, `i18n.js`, jQuery 를 쓰지 않습니다.** 의존성 없이 혼자 돕니다.
- 화면은 `attachShadow` 안에 그리고 CSS 도 그 안에 넣습니다. 고객사 CSS 와 섞이면 안 됩니다.
- 문구는 위젯이 사전을 읽지 않고 **서버가 `/api/widget/init` 응답에 담아 내려줍니다.**
  언어는 조직 설정을 따릅니다(고객이 보는 화면, 3.2 표).
- 위젯 API 는 외부 도메인에서 호출되므로 `csrf_exclude_uris` 에 `api/widget/.*` 를 넣고,
  대신 **Origin 허용 도메인 검사와 방문자 토큰 대조**로 막습니다.

자세한 설계는 `docs/widget-plan.md`.

### 3.10 용어 — 원격과 상담을 섞지 않는다

두 기능이 한 포털 안에 있으므로 **화면 문구에서 반드시 구분**합니다. 섞으면 사용자가 목록을 잘못 읽습니다.

| 용어 | 가리키는 것 | 테이블 | 화면 이름 |
|---|---|---|---|
| **원격지원**(줄여서 원격) | 고객 PC 화면을 보고 조작하는 것 | `sessions` | 원격지원(진행 중+이력) / 원격 메모 |
| **상담** | 위젯을 통한 글 대화 | `conversations` | 상담 대화 / 상담 위젯 |
| **상담원** | 사람. 원격과 상담을 모두 맡는다 | `agents` | 상담원 관리 / 상담원 콘솔 |

- 원격 세션을 세는 곳에 "상담 건수", "상담 이력" 이라고 쓰지 않습니다. "원격지원 건수", "원격 이력" 입니다.
- 위젯 대화를 가리키며 "원격 대화" 라고 쓰지 않습니다.
- "상담원" 은 사람을 가리키므로 두 기능 어디서나 그대로 씁니다.

### 3.11 상담 실시간 — 저장은 포털, 전달은 realtime-msg

상담 메시지는 **포털이 DB 에 저장한 뒤** `realtime-msg` 컨테이너가 구독자에게 밀어 줍니다.
중계 서버(`relay/`)와는 다른 서버입니다. 원격 화면은 `relay`, 상담 글은 `realtime-msg` 입니다.

```
위젯 / 콘솔  --ws  /ws?token=-->  realtime-msg   (구독만)
포털         --POST /publish--->  realtime-msg   (발행)
realtime-msg --GET /api/realtime/auth--> 포털    (토큰 검증)
```

- **메시지를 웹소켓으로 받지 않습니다.** 전송은 기존 HTTP API 로만 합니다. 저장 경로가 하나여야
  순서와 권한 검사가 어긋나지 않습니다.
- 새 메시지를 저장하는 곳을 만들면 **발행도 함께 넣습니다.** 빠뜨리면 상대 화면이 폴링 간격만큼 늦습니다.
- 구독 토큰은 포털이 HMAC 으로 서명합니다(`REALTIME_TOKEN_SECRET`). 방문자는 자기 대화(`conv:{id}`),
  상담원은 자기 조직(`org:{id}`) 만 구독합니다.
- 실시간이 끊겨도 화면이 멈추면 안 됩니다. **폴링을 제거하지 말고 간격만 늘립니다.**
- 컨트롤러 클래스 이름을 라이브러리와 같게 짓지 않습니다. `Realtime` 라이브러리가 있으므로
  검증 API 컨트롤러는 `Realtime_auth` 입니다. 같은 이름이면 CI3 가 라이브러리를 읽지 못합니다.

### 3.12 고객 IP — Cloudflare Tunnel 로만 알 수 있다

Docker Desktop 은 포트로 들어온 연결의 원래 IP 를 지웁니다(모두 게이트웨이 IP 로 보임).
고객 IP 는 `cloudflared` 가 넘기는 `CF-Connecting-IP` 를 Apache `mod_remoteip` 가 복원해서 얻습니다.

```
고객 --https--> Cloudflare --터널--> cloudflared(10.250.88.5) --> web  (CF-Connecting-IP 복원)
고객 --http :8099--> web                                                (게이트웨이 IP, 헤더 무시)
```

- **신뢰 주소는 cloudflared 고정 주소 하나만** 둡니다(`apache-vhost.conf` 의 `RemoteIPInternalProxy`
  = `docker-compose.yml` 의 `ipv4_address`). 대역으로 넓히면 8099 직접 요청이 tunnel 망 게이트웨이로
  들어와 IP 를 위조할 수 있습니다.
- `X-Forwarded-For` 는 쓰지 않습니다. Cloudflare 가 고객이 보낸 값 뒤에 덧붙이므로 맨 앞 값을 꾸밀 수 있습니다.
- 브라우저용 웹소켓 주소는 고정하지 않고 접속한 주소로 만듭니다. https 화면은 다른 포트로 붙을 수 없으므로
  포털 경유 경로(`/relay-viewer`, `/realtime-ws`)를 씁니다(`relay_viewer_ws_url()`, `Realtime::ws_url()`).

### 3.13 고객에게 보내는 주소 — `customer_url()` 로 만든다

상담원은 localhost 나 내부 IP 로 콘솔을 열기도 하므로, 고객에게 전달하는 주소를 `base_url()` 로 만들면
그 주소가 그대로 고객에게 나갑니다.

- 고객 접속 주소(`/{조직코드}`)와 만족도 조사 주소는 **`customer_url($org->org_code)`** 로 만듭니다.
  고객사 사이트에 붙이는 위젯 스니펫의 `widget.js` 주소도 같습니다(`Organization_model::widget_snippet()`).
- 기준은 `.env` 의 `PUBLIC_BASE_URL`(시험 서버 `https://help.wani.im/`), 비어 있으면 접속한 주소입니다.
- 고객 접속 페이지 상단에는 조직 로고(`logo_path`), 소개(`intro`, 500자), 대표 전화가 보입니다.
  조직 관리자가 헤더의 `조직 정보` 에서 고칩니다. 로고는 PNG/JPG/WEBP 1MB 이하, SVG 는 받지 않습니다.
- 로고 폴더 `portal/assets/uploads/logos/` 는 웹 서버(www-data)가 쓸 수 있어야 합니다. 올린 파일은 git 에 넣지 않습니다.

### 3.14 이미지 선택 — Dropzone.js 로만

포털에서 이미지를 고르는 곳은 모두 **Dropzone.js**(끌어다 놓기 + 클릭 선택)를 씁니다.

- 공통 모듈 `portal/assets/js/image-drop.js` 의 `RHImageDrop.create(selector, { url, paramName, onSuccess })` 를 씁니다.
  CSRF 토큰, 허용 형식(PNG/JPG/WEBP), 크기 제한, 한국어 오류 문구, `{ result, message, data }` 처리가 들어 있습니다.
- 컨트롤러에서 **`'use_dropzone' => TRUE`** 를 넘겨야 라이브러리를 불러옵니다(`use_grid` 와 같은 방식).
- 이미지가 아닌 파일(guide.json)도 같은 모듈로 받습니다. `accept`, `maxMb`, `params`, `invalidTypeText`, `tooBigText` 옵션을 씁니다.
- 서버 검사는 `check_uploaded_image($file, $max_bytes)`(app_helper) 하나를 씁니다(로고, 가이드 이미지 공통).
- `<input type="file">` 을 직접 쓰지 않습니다. 서버 검사(내용 기준 형식 판별, SVG 거부)는 그대로 둡니다.
- 버전은 `dropzone@6.3.5` 의 `dist/dropzone-min.js`(window.Dropzone). `dist/dropzone.js` 와 cdnjs 판은 CommonJS 라
  `<script>` 로 넣으면 동작하지 않습니다. 기본 CSS 대신 `tailwind.src.css` 의 `.image-drop` 으로 그립니다.

### 3.15 가이드 — 원본은 DB, guide.json 은 주고받는 형식

조직 관리자가 `가이드 관리`(`/admin/guide`)에서 만들고 고객은 `/{조직코드}/guide` 에서 봅니다.
상담 중에는 **AI 없이 키워드 매칭**(`Guide_matcher`, 색인 `guide_terms`)으로 추천합니다. 설계는 `docs/guide-plan.md`.

- **원본은 DB 입니다.** guide.json 은 내려받기/올리기(백업, 이관, 일괄 편집) 형식이며 서버에 파일로 두지 않습니다.
  화면 저장과 파일 가져오기는 같은 검사(`Guide_model::clean_article`)를 거칩니다. 검사를 따로 만들지 않습니다.
- **글을 바꾸는 곳은 `Guide_model::save_article()` / `set_status()` 를 거칩니다.** 색인을 함께 다시 만듭니다.
  DB 를 직접 고치면 추천에 반영되지 않습니다(관리 화면의 [색인 다시 만들기] 로 복구).
- 본문은 블록(`text` / `image` / `video`)이며 **HTML 을 받지 않습니다.** 공개 뷰(`views/guide/`)는 모든 값을
  `html_escape()` 로, 글 블록은 `guide_text_html()` 로 내보냅니다.
- 유튜브는 영상 id(11자)만 저장하고 **`youtube-nocookie.com/embed/{id}`** 로만 그립니다. 누르기 전에는 유튜브에
  요청하지 않고, iframe 에 `referrerpolicy="strict-origin-when-cross-origin"` 을 둡니다(유튜브가 Referer 를 요구).
- 이미지는 `assets/uploads/guides/{org_id}/` 에 둡니다. 이 조직 폴더에 실제로 있는 파일만 블록에 받으며,
  웹 서버(www-data)가 쓸 수 있어야 하고 git 에 넣지 않습니다.
- 가이드 카드는 `messages.meta`(`{type:'guide', article_id, title, subtitle, url}`)에 담고, 본문에도 제목과 주소를 넣습니다.
  **카드를 저장하면 발행도 합니다(3.11).** 주소는 `Guide_model::public_url()`(= `customer_url`) 로 만듭니다(3.13).
- 위젯 자동 추천은 조직 설정 `guide_autosuggest`(기본 꺼짐)일 때만, 같은 글 1번, 대화당 3번까지입니다.
- 매칭 가중치, 기준 점수, 조사/불용어는 `config/guide_match.php` 한 곳에서 고칩니다.

---

## 4. 검증

```bash
./scripts/check-conventions.sh   # 이 문서의 규칙을 자동 점검
./scripts/check-conventions.sh --live   # 실행 중인 개발 서버까지 점검
./scripts/e2e-test.sh            # 포털 -> 중계 -> 화면 수신까지 전 구간
./scripts/build-css.sh           # Tailwind 빌드
```

작업을 마치기 전에 `check-conventions.sh` 를 통과시킵니다.
서버나 프로토콜을 건드렸다면 `e2e-test.sh` 도 돌립니다.

### 자동 실행

`.claude/settings.json` 에 훅이 걸려 있어, **파일을 고칠 때마다 규칙 검사가 자동으로 돕니다.**

```
portal/ relay/ launcher/ scripts/ docker/ sql/ server/ 아래 파일을 수정
  -> scripts/hooks/post-edit-check.py 실행
  -> 위반이 있을 때만 결과를 알림 (통과하면 조용함)
```

검사에 0.4초쯤 걸리며 작업을 막지는 않습니다. 잠시 꺼야 하면 `/hooks` 에서 끌 수 있습니다.

---

## 5. 개발 환경

```bash
cp .env.example .env
docker compose up -d --build
```

| 주소 | 용도 |
|---|---|
| http://localhost:8099/ | 소개 페이지 |
| http://localhost:8099/login | 상담원 콘솔 (admin@demo.local / Test1234!admin) |
| http://localhost:8099/operator | 조직 가입 승인 (operator@demo.local / Test1234!admin) |
| http://localhost:8099/demo | 고객 접속 페이지 |
| https://localhost:8443/healthz | 중계 서버 상태 |
| http://localhost:8091/healthz | 상담 실시간 메시지 서버 상태 |
| https://help.wani.im/ | 시험 서버 외부 주소(Cloudflare Tunnel `remotehelp-help` -> `http://web:80`, `.env` 에 토큰과 `COMPOSE_PROFILES=tunnel`). http 는 Apache 가 https 로 넘긴다 |

윈도우 런처는 이 환경에서 빌드할 수 없습니다. 코드 작성까지만 하고 실기 검증은 별도로 표시합니다.

---

## 6. 현재 결정 사항

| 항목 | 결정 |
|---|---|
| 배포 경로 | **운영은 1차 Microsoft Store(MSIX) 단독.** 직접 다운로드는 `DIRECT_DOWNLOAD_ENABLED` 로 켜고 끈다(운영 기본 false). 개발 `.env` 는 true 이며 `portal/assets/downloads/RemoteHelp.exe` 를 내려준다. 켜지면 고객 페이지의 주 버튼이 다운로드로 바뀐다 |
| 런처 빌드 후 배포 | **런처 exe 를 빌드하면 항상 시험 서버에 배포한다(시험 서버 한정, 사용자 승인).** `launcher/scripts/build-exe.ps1 -TestBuild` 가 `portal/assets/downloads/RemoteHelp.exe` 로 복사하고, 그것이 `http://118.36.197.219:8099/demo/download` 로 내려간다(`.env` 의 `DIRECT_DOWNLOAD_ENABLED=true`). 시험 빌드에는 인증서 예외가 들어 있으므로 서명·운영 배포 금지. 배포 후 해시와 다운로드 응답을 확인해 보고한다 |
| 코드 서명 | Store 는 Microsoft 가 서명하므로 불필요. 직접 배포 시 OV 인증서 필요(`docs/code-signing.md`) |
| 요금 | 연 220,000원 / 상담원 계정 5개. `Home::index()` 의 `$pricing` 한 곳에서 관리 |
| 화면 캡처 | GDI 우선, 가능하면 DXGI 자동 전환 |
| 중계 서버 | Node.js + ws |
| 시험 서버 뷰어 연결 | **relay 가 자체 서명 인증서라 상담원 브라우저는 포털 경유로 붙는다.** `.env` 의 `RELAY_VIEWER_WS_URL=auto` 이면 접속한 주소 기준 `ws(s)://<호스트>/relay-viewer`, web(Apache)이 relay 인증서(`relay-ca` 볼륨)로 검증한 wss 로 넘긴다. 런처(`/agent`)는 그대로 8443 직결. 운영은 공인 인증서 + `wss://<도메인>/viewer` |
| 시험 서버 외부 주소 | **`https://help.wani.im` 은 Cloudflare Tunnel(cloudflared 컨테이너)로 받는다.** 고객 IP 를 얻는 유일한 경로이며 https 인증서도 해결된다. `help.wani.im` 은 Cloudflare 프록시라 `:8099` 로는 접속되지 않는다. 런처 relay 접속은 IP(`118.36.197.219:8443`) 유지 |
| 상담 위젯 | **별도 사이트가 아니라 이 포털 안의 기능.** 조직·상담원 체계를 그대로 쓴다 (`docs/widget-plan.md`) |
| 상담 실시간 | **`realtime-msg` 컨테이너(Node + ws)로 전달.** 폴링은 연결이 끊겼을 때의 보조 수단으로 남긴다 |
| 가이드 | **DB 저장 + guide.json 내려받기/올리기.** 편집은 조직 관리자만, 상담원은 콘솔에서 검색·보내기. 추천은 키워드 매칭(AI 토큰 없음). 조직당 한 언어 (`docs/guide-plan.md`) |

---

## 7. 이 지침을 갱신하는 방법

새 규칙이 생기거나 결정이 바뀌면 **이 파일을 먼저 고칩니다.**

- 조용히 깨질 수 있는 규칙은 3장에 넣고, **`scripts/check-conventions.py` 에 검사 함수도 함께 추가합니다.**
  검사를 붙이면 훅이 자동으로 돌려 주므로 사람이 기억할 필요가 없어집니다.
- 검사를 추가할 때는 일부러 규칙을 어겨 보고 실제로 잡히는지 확인합니다.
- 결정 사항이 바뀌면 6장의 표를 고칩니다.
- 검사로 자동화할 수 없는 규칙만 문서로 남깁니다.

### 검사 함수 추가 예시

```python
# scripts/check-conventions.py
def check_new_rule():
    if 위반_조건:
        fail('무엇이 잘못됐는지', '어떻게 고치는지')
    else:
        ok('무엇을 확인했는지')

# main() 에 호출을 추가한다
```
