# RemoteHelp 프로젝트 지침

이 파일은 이 저장소에서 작업할 때 지켜야 할 규칙을 모아 둔 곳입니다.
**새 규칙이 정해지면 반드시 여기에 추가합니다.**

---

## 1. 작업 방식

- **항상 한국어로 답변합니다.** 최종 보고뿐 아니라 작업 중 진행 안내, 중간 설명, 질문, 커밋 메시지까지 모두 한국어입니다.
  긴 작업이나 오류 대응 중에도 영어로 바꾸지 않습니다(코드, 명령어, 로그 원문은 그대로 둡니다).
- 코드 설명에 이모티콘을 쓰지 않습니다.
- 모든 소스 파일 **맨 위에 위치와 역할 주석**을 답니다(템플릿은 `{#- ... -#}` 주석).

  ```python
  """
  파일 위치: backend/app/routers/example.py
  역할: 예제 데이터 조회 및 처리
  """
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
   화면 캡처 + 입력 주입              |--> nginx(TLS) --> Node 중계 서버(relay)
                                      |                        |
[상담원 브라우저(캔버스 뷰어)] --wss /viewer--+          포털 API (토큰 검증)

[브라우저 / 런처] --http--> web(Apache: /assets, 웹소켓 경유, 고객 IP) --> api(FastAPI 포털) --> MySQL
```

| 경로 | 내용 |
|---|---|
| `backend/` | FastAPI 포털(화면·API, Jinja2 템플릿 `backend/app/templates/`) |
| `backend/assets/` | 정적 파일(JS, CSS, 사전, 아이콘, 업로드). 주소는 `/assets/...`. Apache 가 직접 내주고 FastAPI 도 같은 폴더를 붙인다 |
| `relay/` | Node.js 중계 서버(원격 화면 중계) |
| `realtime/` | Node.js 상담 실시간 메시지 서버(realtime-msg) |
| `launcher/` | .NET 8 WPF 런처 + 원격제어 엔진 |
| `docker/` | 컨테이너(`web` = PHP 없는 앞단 Apache, `api` = FastAPI) |
| `server/` | 중계 서버 설치 스크립트 |
| `sql/` | 스키마, 마이그레이션, 시드 |
| `scripts/` | 검증/빌드 스크립트 |
| `docs/` | 프로토콜, 검증 보고서, 제출 문서, 작업요청서 |

**원격제어는 자체 프로토콜입니다. VNC 를 쓰지 않습니다.** 명세는 `docs/protocol.md`.
포털은 2026-10-22 에 CodeIgniter 3(PHP)에서 FastAPI 로 옮기고 PHP 를 지웠습니다(`docs/fastapi-migration-plan.md`).

---

## 3. 반드시 지킬 규칙

어기면 조용히 깨지는 것들입니다. `./scripts/check-conventions.sh` 가 자동으로 검사합니다.

### 3.1 Tailwind — 클래스를 추가하면 다시 빌드

템플릿이나 JS 에 **새 Tailwind 클래스를 쓰면 CSS 를 다시 만들어야** 화면에 반영됩니다.

```bash
./scripts/build-css.sh
```

- 입력 `backend/assets/css/tailwind.src.css`, 출력 `backend/assets/css/app.css`(저장소에 포함)
- 클래스는 `backend/app`(템플릿과 파이썬 코드)과 `backend/assets/js` 에서만 모읍니다(`source(none)` + `@source`). 데이터 폴더 `backend/app/core/data` 는 뺍니다.
- 새 색상 단계가 필요하면 `@theme` 에 먼저 추가합니다. 없는 단계를 `@apply` 하면 빌드가 실패합니다.
- 공통 컴포넌트는 `.btn` `.card` `.form-input` `.badge` `.table` `.icon-tile` 을 조합해 씁니다.
- 템플릿에서 JS/CSS 를 넣을 때는 **`asset_url('assets/...')`** 를 씁니다(파일 수정 시각을 `?v=` 로 붙임). `base_url()` 로 넣으면
  Cloudflare 가 예전 파일을 몇 시간 동안 내주어, 고친 화면이 반영되지 않습니다(검사가 잡습니다).

### 3.2 다국어 — 세 사전을 함께 고친다

문구는 `backend/assets/lang/{ko,en,ja}.json` 에 있고 서버와 JS 가 같은 파일을 씁니다.

- **키를 추가하면 세 파일 모두에 넣습니다.** 빠지면 그 언어에서 키 문자열이 그대로 보입니다.
- 서버: `lang_text('key', {'org': '이름'})`(파이썬, 템플릿 모두)
- 화면: `__('key')` 또는 `data-i18n="key"` 속성

언어 결정 우선순위

| 화면 | 적용 언어 |
|---|---|
| 고객 접속 페이지, 만족도 조사 | **조직 설정** (그 조직의 고객이 보는 화면, `use_org_locale()`) |
| 소개 페이지, 상담원 콘솔 | 방문자 선택(`rh_locale` 쿠키) → 조직 설정 → 한국어 |

### 3.3 아이콘 — 목록에 넣어야 나온다

[Material Symbols](https://fonts.google.com/icons) 를 **서브셋으로** 받습니다(전체 315KB → 약 5.5KB).

- 아이콘을 추가하면 **`routers/home.py` 의 `landing_icons()` 목록에도 넣습니다.** 빠지면 아이콘 대신 영문 글자가 보입니다.
- 아이콘 폰트는 `icon_names` 를 넘긴 화면에서만 불러옵니다.

### 3.4 시간 — UTC 로 저장하고 표시할 때만 변환

조직마다 시간대가 다르므로 저장 기준은 UTC 입니다.

```
MySQL    --default-time-zone=+00:00
FastAPI  DB 세션 SET time_zone = '+00:00' (backend/app/db.py), 지금 시각은 utc_str()
표시     서버 to_timezone(utc, tz) / 화면 RHI18n.formatDate(utc)
```

- 새 화면에서 날짜를 그릴 때 **원본을 그대로 출력하지 않습니다.** 반드시 변환 함수를 거칩니다.
- 운영 서버에서 두 설정이 어긋나면 시각이 통째로 밀립니다.

### 3.5 목록은 wgrid, 기본 20개

- 그리드는 **wgrid 0.1.0 코어(MIT)** 입니다. npm 배포 전이라 `backend/assets/vendor/wgrid/0.1.0/` 에
  `wgrid.global.js`(window.wgrid), `wgrid.css` 를 둡니다. wgrid 저장소에서 `npm run build` 한 `dist/` 의 같은 파일을 복사합니다.
- 공통 설정은 `backend/assets/js/grid.js` 의 `RHGrid.create()` 를 씁니다. 돌려주는 값은 `{ grid, setRows, removeRow, refresh }` 이며,
  주기 갱신은 `setRows()`(페이지·스크롤 유지)로 합니다. wgrid 의 `grid.setRows` 를 바로 부르면 1쪽 맨 위로 돌아갑니다.
- 컬럼은 wgrid 형식(`key`, `title`, `width`, `format(value,row)`, `render(row,value)`, `pinned`)입니다. `key` 는 유일해야 하므로
  버튼 칸에도 `_actions` 같은 key 를 줍니다. `grid.js` 가 더하는 키: `flex: 1`(남는 너비 채우기), `tooltip: true`, `cellClass`.
- 라우터가 `render()` 데이터에 **`'use_grid': True`** 를 넘겨야 그리드 라이브러리를 불러옵니다(검사가 잡습니다).
- 한 쪽 개수는 기본 20개, 고를 수 있는 값은 20 / 50 / 100 / 200 / 500 / 1000 입니다(`grid.js` 의 `PAGE_SIZE_OPTIONS`).
  고른 값은 그리드 컨테이너 id 별로 localStorage(`rh-grid-page-size:{id}`)에 기억합니다. 컨테이너에 id 가 있어야 합니다.
- 그리드 컨테이너에 높이를 주지 않으면 wgrid 가 한 쪽 행 수만큼 늘어납니다(가이드 관리가 이 방식). 화면 안에서 스크롤시키려면 `height` 를 줍니다.
- 날짜 열은 `format: RHGrid.dateTime` / `RHGrid.shortDateTime` 을 씁니다(조직 시간대 적용).
- **Pro 모듈(`wgridPro`)은 쓰지 않습니다.** 운영 도메인에서 라이선스 키가 필요합니다. 포털 색은 `tailwind.src.css` 끝의 `body .wgrid` 변수로 맞춥니다
  (wgrid.css 는 레이어 밖 스타일이라 `@layer` 안에서 덮어쓰면 집니다).

### 3.6 메뉴와 권한 — 사용자와 운영자는 분리

| 레이아웃 | 대상 | 메뉴 |
|---|---|---|
| `layouts/main.html` | 조직 소속 사용자 | (관리자) 가이드 / 상담 대화 / 원격지원(대기열+이력 통합) / (관리자) 상담원 관리 · 조직 정보. 조직 관리 통계(`/admin`)는 왼쪽 위 서비스 이름, 상담 위젯 설정은 상담 대화의 설정 드로어, 매크로(`/console/macro`)는 상담 대화의 [매크로] 버튼 |
| `layouts/operator.html` | 플랫폼 운영자 | 조직 가입 관리 / 감사 로그 |
| `layouts/public.html` | 방문자 | 소개 페이지 |
| `layouts/customer.html` | 고객 | 접속 안내, 만족도 조사, 공개 가이드(`/{조직코드}/guide`, `layouts/guide.html`) |

| 권한 | 범위 | 판별 | 의존성 |
|---|---|---|---|
| 상담원 | 자기 조직 상담 | `agents.role = 'agent'` | `Depends(current_agent)` |
| 조직 관리자 | 자기 조직 설정 | `agents.role = 'admin'` | `Depends(require_admin)` |
| 플랫폼 운영자 | 전체 조직 승인 | `agents.is_super = 1` | `Depends(require_operator)` |

조직 관리 경로(`routers/admin.py`, `routers/admin_guide.py`)는 모두 `require_admin`, 운영자 경로(`routers/operator.py`)는 모두 `require_operator` 입니다(검사가 잡습니다).
권한 거부처럼 응답을 보내고 바로 끝낼 때는 `raise Halt(api_response(...))` 를 씁니다.

### 3.7 API 와 UI 공통 규약

- 모든 API 응답은 `{ "result": true|false, "message": "", "data": {} }` 형식입니다. `api_response()` 를 씁니다(빈 data 는 `{}`).
- 알림은 `showToast(message, type)`, 확인은 `showConfirmModal(title, message, onConfirm, onCancel)`,
  모달은 `openModal(id)` / `closeModal(id)` 를 씁니다. 외부 UI 프레임워크를 쓰지 않습니다.
- 설정처럼 화면 옆에서 여는 패널은 드로어 `openDrawer(id)` / `closeDrawer(id)` 를 씁니다.
  마크업은 `.drawer-root > .drawer-backdrop + .drawer-panel(.drawer-header / .drawer-body / .drawer-footer)`,
  닫기 요소에 `data-drawer-close="id"`. 예: `templates/console/_widget_settings.html`(상담 대화 > 설정)
- **JS 에서 API 주소를 상대 경로로 적지 않습니다.** 템플릿에서 `base_url()` 로 만든 절대 주소를
  `XXX_URLS` 전역에 `|js` 로 담아 넘깁니다. 상대 경로는 보는 화면의 깊이에 따라 `/console/console/...` 로 어긋납니다.
- 관리 행위는 `audit_logs`(`log_model.audit`), 세션 이벤트는 `session_logs`(`log_model.add`) 에 남깁니다.
- 사용자 입력으로 상태를 바꿀 때는 **허용 목록 검사**를 합니다(언어, 시간대, 국가, 상태값 등).

### 3.8 라우팅 순서

조직 코드 하나만 받는 `/{org_code}`(`customer.org_router`)는 **`main.py` 에서 반드시 맨 마지막**에 등록합니다.
새 최상위 경로를 추가할 때는 그보다 위에 등록합니다(검사가 잡습니다).

### 3.9 상담 위젯 — 고객사 사이트 안에서 도는 코드

`backend/assets/js/widget.js` 는 **우리 화면이 아니라 고객사 웹사이트에 삽입**됩니다.
포털의 자산을 쓸 수 없고, 써도 고객사 스타일과 충돌합니다.

- **Tailwind(`app.css`), `common.js`, `i18n.js`, jQuery 를 쓰지 않습니다.** 의존성 없이 혼자 돕니다.
- 화면은 `attachShadow` 안에 그리고 CSS 도 그 안에 넣습니다. 고객사 CSS 와 섞이면 안 됩니다.
- 문구는 위젯이 사전을 읽지 않고 **서버가 `/api/widget/init` 응답에 담아 내려줍니다.**
  언어는 조직 설정을 따릅니다(고객이 보는 화면, 3.2 표).
- 위젯 API 는 외부 도메인에서 호출되므로 `core/csrf.py` 의 `EXCLUDE_URIS` 에 `api/widget/.*` 를 넣고,
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
realtime-msg --GET /api/realtime/auth--> 포털    (토큰 검증, http://web 을 거쳐 FastAPI)
```

- **메시지를 웹소켓으로 받지 않습니다.** 전송은 기존 HTTP API 로만 합니다. 저장 경로가 하나여야
  순서와 권한 검사가 어긋나지 않습니다.
- 새 메시지를 저장하는 곳을 만들면 **발행도 함께 넣습니다**(`libraries/realtime.py` 의 `publish_*`). 빠뜨리면 상대 화면이 폴링 간격만큼 늦습니다.
- 구독 토큰은 포털이 HMAC 으로 서명합니다(`REALTIME_TOKEN_SECRET`, 2시간). 방문자는 자기 대화(`conv:{id}`),
  상담원은 자기 조직(`org:{id}`) 만 구독합니다. 토큰이 거절되면 realtime-msg 는 연결을 연 뒤 4401 로 닫습니다.
  위젯은 `ready` 를 받아야 붙은 것으로 보고, 4401 이면 `/api/widget/poll?realtime=1` 로 새 토큰을 받아 다시 붙습니다.
- 실시간이 끊겨도 화면이 멈추면 안 됩니다. **폴링을 제거하지 말고 간격만 늘립니다.**

### 3.12 고객 IP — Cloudflare Tunnel 로만 알 수 있다

Docker Desktop 은 포트로 들어온 연결의 원래 IP 를 지웁니다(모두 게이트웨이 IP 로 보임).
고객 IP 는 `cloudflared` 가 넘기는 `CF-Connecting-IP` 를 앞단 Apache `mod_remoteip` 가 복원해서 얻습니다.

```
고객 --https--> Cloudflare --터널--> cloudflared(10.250.88.5) --> web  (CF-Connecting-IP 복원)
고객 --http :8099--> web                                                (게이트웨이 IP, 헤더 무시)
web(10.250.88.2) --X-RemoteHelp-Client-IP(set)--> api(10.250.88.3)      (FastAPI 는 web 주소에서 온 것만 믿음)
```

- **신뢰 주소는 cloudflared 고정 주소 하나만** 둡니다(`docker/web/apache-vhost.conf` 의 `RemoteIPInternalProxy`
  = `docker-compose.yml` 의 `ipv4_address`). 대역으로 넓히면 8099 직접 요청이 tunnel 망 게이트웨이로
  들어와 IP 를 위조할 수 있습니다.
- Apache 는 복원한 주소를 `X-RemoteHelp-Client-IP` 에 **덮어써(set)** 넘기고, FastAPI 는 web 의 tunnel 고정 주소에서 온 이 머리글만 믿습니다
  (`core/request.py` 의 `TRUSTED_PROXY_IP`, `client_ip()`). 고객 IP 는 `client_ip()` 하나로 얻습니다.
- `X-Forwarded-For` 는 쓰지 않습니다. Cloudflare 가 고객이 보낸 값 뒤에 덧붙이므로 맨 앞 값을 꾸밀 수 있습니다
  (Apache `ProxyAddHeaders Off`, uvicorn `--no-proxy-headers`, 검사가 잡습니다).
- 브라우저용 웹소켓 주소는 고정하지 않고 접속한 주소로 만듭니다. https 화면은 다른 포트로 붙을 수 없으므로
  포털 경유 경로(`/relay-viewer`, `/realtime-ws`)를 씁니다(`relay_viewer_ws_url()`, `realtime.ws_url()`). 이 두 경로와 `/assets` 는 Apache 가 맡습니다.

### 3.13 고객에게 보내는 주소 — `customer_url()` 로 만든다

상담원은 localhost 나 내부 IP 로 콘솔을 열기도 하므로, 고객에게 전달하는 주소를 `base_url()` 로 만들면
그 주소가 그대로 고객에게 나갑니다.

- 고객 접속 주소(`/{조직코드}`)와 만족도 조사 주소는 **`customer_url(org.org_code)`** 로 만듭니다(검사가 잡습니다).
  고객사 사이트에 붙이는 위젯 스니펫의 `widget.js` 주소도 같습니다(`organization_model.widget_snippet()`).
- 기준은 `.env` 의 `PUBLIC_BASE_URL`(시험 서버 `https://help.wani.im/`), 비어 있으면 접속한 주소입니다.
- 고객 접속 페이지 상단에는 조직 로고(`logo_path`), 소개(`intro`, 500자), 대표 전화가 보입니다.
  조직 관리자가 헤더의 `조직 정보` 에서 고칩니다. 로고는 PNG/JPG/WEBP 1MB 이하, SVG 는 받지 않습니다.
- 로고 폴더 `backend/assets/uploads/logos/` 는 api 컨테이너(www-data)가 쓸 수 있어야 합니다. 올린 파일은 git 에 넣지 않습니다.

### 3.14 이미지 선택 — Dropzone.js 로만

포털에서 이미지를 고르는 곳은 모두 **Dropzone.js**(끌어다 놓기 + 클릭 선택)를 씁니다.

- 공통 모듈 `backend/assets/js/image-drop.js` 의 `RHImageDrop.create(selector, { url, paramName, onSuccess })` 를 씁니다.
  CSRF 토큰, 허용 형식(PNG/JPG/WEBP), 크기 제한, 한국어 오류 문구, `{ result, message, data }` 처리가 들어 있습니다.
- 라우터가 `render()` 데이터에 **`'use_dropzone': True`** 를 넘겨야 라이브러리를 불러옵니다(`use_grid` 와 같은 방식, 검사가 잡습니다).
- 이미지가 아닌 파일(guide.json, 매크로 CSV)도 같은 모듈로 받습니다. `accept`, `maxMb`, `params`, `invalidTypeText`, `tooBigText` 옵션을 씁니다.
- 서버 검사는 `helpers/app.py` 의 `check_uploaded_image(upload, max_bytes)` 하나를 씁니다(로고, 가이드 이미지 공통).
  형식 판별 `image_file_ext()` 는 파일 내용으로 PNG/JPG/WEBP 만 받습니다(libmagic 5.40 규칙 + getimagesize 와 같은 판정).
- `<input type="file">` 을 직접 쓰지 않습니다(검사가 잡습니다). 서버 검사(내용 기준 형식 판별, SVG 거부)는 그대로 둡니다.
- 업로드 파일의 실제 경로는 `guide_model.asset_file('assets/...')` 로 얻습니다(api 컨테이너는 `backend/assets` 를 `/srv/assets` 로 붙입니다).
- 버전은 `dropzone@6.3.5` 의 `dist/dropzone-min.js`(window.Dropzone). `dist/dropzone.js` 와 cdnjs 판은 CommonJS 라
  `<script>` 로 넣으면 동작하지 않습니다. 기본 CSS 대신 `tailwind.src.css` 의 `.image-drop` 으로 그립니다.

### 3.15 가이드 — 원본은 DB, guide.json 은 주고받는 형식

조직 관리자가 `가이드`(`/admin/guide`)에서 만들고 고객은 `/{조직코드}/guide` 에서 봅니다.
상담 중에는 **AI 없이 키워드 매칭**(`libraries/guide_matcher.py`, 색인 `guide_terms`)으로 추천합니다. 설계는 `docs/guide-plan.md`.

- **원본은 DB 입니다.** guide.json 은 내려받기/올리기(백업, 이관, 일괄 편집) 형식이며 서버에 파일로 두지 않습니다.
  화면 저장과 파일 가져오기는 같은 검사(`guide_model.clean_article`)를 거칩니다. 검사를 따로 만들지 않습니다.
- **글을 바꾸는 곳은 `guide_model.save_article()` / `set_status()` 를 거칩니다.** 색인을 함께 다시 만듭니다.
  가이드 표(`guide_articles/categories/synonyms/terms`)는 `guide_model` 만 고칩니다(검사가 잡습니다).
  DB 를 직접 고치면 추천에 반영되지 않습니다(관리 화면의 [색인 다시 만들기] 로 복구).
- 본문은 블록(`text` / `image` / `video`)이며 **HTML 을 받지 않습니다.** 공개 템플릿(`templates/guide/`)은 Jinja 자동 이스케이프를 끄지 않고(`|safe` 금지),
  글 블록은 `guide_text_html()` 로 내보냅니다.
- 유튜브는 영상 id(11자)만 저장하고 **`youtube-nocookie.com/embed/{id}`** 로만 그립니다. 공개 화면에서 처음부터
  iframe 으로 보입니다(`loading="lazy"`). iframe 에 `referrerpolicy="strict-origin-when-cross-origin"` 을 둡니다(유튜브가 Referer 를 요구).
  **iframe 은 `templates/guide/_video.html` 한 곳에서만 만듭니다**(본문과 동영상 모음이 함께 씀, 검사가 잡습니다).
- 이미지는 `assets/uploads/guides/{org_id}/` 에 둡니다. 블록에는 이 조직 폴더에 실제로 있는 파일이나
  **외부 https 주소**(다른 사이트 가이드를 guide.json 으로 옮겨 올 때)만 받고, http·`data:`·`javascript:` 는 막습니다.
  화면 주소는 `guide_image_url()` 로 만들며 외부 이미지에는 Referer 를 보내지 않습니다(`referrerpolicy="no-referrer"`).
  업로드 폴더는 api 컨테이너(www-data)가 쓸 수 있어야 하고 git 에 넣지 않습니다.
- 가이드 화면의 [외부 이미지를 본 서버로 저장] 은 글의 외부 https 이미지를 받아 조직 폴더에 저장하고 블록 주소를 바꿉니다
  (`guide_model.localize_external_images()`). 서버가 대신 요청하므로 **`fetch_external_image()` 만 거칩니다**:
  https 만, 공인 IPv4 만(사설·예약 대역 거부), 확인한 IP 로 고정 접속(인증서·SNI 는 원래 호스트 이름), 리다이렉트는 3번까지 직접 따라가며 매번 다시 검사,
  2MB 상한, 내용 검사는 올린 이미지와 같은 `image_file_ext()`. 받지 못한 이미지는 외부 주소 그대로 둡니다.
  다른 파일에서 `http.client`·`httpx`·`socket` 등으로 외부에 요청하면 검사가 잡습니다(내부 발행 `realtime.py`, 메일 `mailer.py` 는 예외).
- 주소(slug)는 영문 소문자, 숫자, 하이픈, 밑줄 60자까지이며 숫자만으로는 만들 수 없습니다(번호 접속과 겹침).
- 가이드 카드는 `messages.meta`(`{type:'guide', article_id, title, subtitle, url}`)에 담고, 본문에도 제목과 주소를 넣습니다.
  **카드를 저장하면 발행도 합니다(3.11).** 주소는 `guide_model.public_url()`(= `customer_url`) 로 만듭니다(3.13).
- 위젯 자동 추천은 조직 설정 `guide_autosuggest`(기본 꺼짐)일 때만, 같은 글 1번, 대화당 3번까지입니다.
- 매칭 가중치, 기준 점수, 조사/불용어는 `settings/guide_match.py` 한 곳에서 고칩니다.
- 공개 화면은 조직이 고른 **테마**(`organizations.guide_theme`)로 그립니다. 테마 목록은 `guide_model.themes()` 한 곳,
  템플릿은 `templates/guide/themes/{키}.html`, 모양은 `assets/css/guide-themes.css`(Tailwind 빌드 밖의 일반 CSS)입니다.
  테마를 더하면 목록과 템플릿을 함께 만듭니다(검사가 누락을 잡습니다). 화면 데이터는 `guide_model.page_data()` 하나로 만들고,
  본문 블록은 모든 테마가 `templates/guide/_article.html` 을 씁니다. `?theme=` 미리보기는 그 조직 관리자 세션에서만 받습니다.
- 가이드 관리를 처음 열 때 글이 없으면 샘플 10개(`settings/guide_samples.py`, 이미지 `assets/img/guide-samples/`)를
  **임시 상태로** 만듭니다. 조직마다 한 번이며(`organizations.guide_seeded_at`), 다 지워도 다시 생기지 않습니다.
  샘플 동영상을 바꿀 때는 유튜브 oEmbed 로 존재와 퍼가기 허용을 확인합니다.
- 공개 가이드의 **새 기능 모음**(`/{조직코드}/guide/features`)과 **동영상 모음**(`/guide/videos`)은 가이드 관리의
  "공개 페이지 구성" 으로 켤 때만 보입니다(기본 꺼짐, 꺼져 있으면 404, 그 조직 관리자 세션은 미리보기 가능). 설계는 `docs/guide-collections-plan.md`.
  - 옵션 목록과 허용값은 `guide_model.page_options()` 한 곳입니다(`organizations.guide_feature_*`, `guide_video_*`).
  - 모음 주소는 `routers/guide.py` 에서 글 주소(`/{org_code}/guide/{slug}`)보다 먼저 등록하고 **`guide_model.RESERVED_SLUGS` 에도 넣어** 글 주소로 쓸 수 없게 합니다(검사가 잡습니다).
  - 새 기능은 글마다 표시합니다(`is_feature`, 출시일 `feature_at` 은 조직 시간대 그날 00:00 의 UTC, 비우면 첫 공개 시각 `published_at`).
    `published_at` 은 `save_article()` / `set_status()` 가 처음 공개할 때만 찍습니다.
  - 동영상 모음은 따로 저장하지 않고 공개 글의 video 블록에서 그때그때 모읍니다(`guide_model.public_videos()`).
  - 테마 템플릿은 모두 `_tabs`(탭)와 `_collection`(모음 본문)을 부릅니다(검사가 잡습니다). 모음 모양은 `guide-themes.css` 의 `guide-*` 규칙입니다.

### 3.16 상담 운영 상태 — availability() 한 곳에서 판단

고객에게 "상담 가능 / 자리 비움 / 운영 시간 외" 를 보여 주는 판단은 **`chat_hours_model.availability(org)` 하나**로 합니다.
위젯 상태 표시, 부재중 자동 응답, 부재 알림 메일, 콘솔 상단 배지가 모두 이 값을 씁니다. 설계는 `docs/chat-operation-plan.md`.

```
운영 중?   = 조직 수동 상태(지금 운영/지금 종료)가 있으면 그 값, 없으면 (휴일 아님 AND 운영 시간 안)
state      = 운영 중이 아니면 closed / 운영 중이고 응대 가능 상담원이 있으면 open / 없으면 away
응대 가능  = agents.chat_status = 'available' AND 최근 콘솔 접속(last_seen_at)
```

- `has_available_agent()` 를 라우터에서 바로 부르지 않습니다(검사가 잡습니다).
- **운영 시간(`chat_hours`)과 휴일(`chat_holidays`)은 UTC 가 아니라 조직 시간대의 벽시계 값**(자정부터 분, 로컬 날짜)입니다.
  3.4 의 유일한 예외이며, 판정할 때만 지금 시각을 조직 시간대로 바꿔 비교합니다. 밖으로 내보내는 시각(`next_open_at`, `chat_manual_until`)은 UTC 입니다.
- 수동 상태는 자동 판정이 같은 값이 되는 시각(`chat_manual_until`)에 '자동' 으로 돌아갑니다. 되돌리는 것을 잊어도 다음 날 닫힌 채로 남지 않습니다.
- 부재중 자동 응답(`widget._auto_reply`)은 시스템 메시지(`meta.type = 'auto_reply'`)로 저장하며, `send()` 가 발행할 메시지를 읽기 전에 넣어 함께 발행합니다(3.11).
  같은 상황에서는 대화당 한 번(`conversations.auto_reply_at/kind`)입니다.
- 운영 시간 외 '안내만' / '숨기기' 는 **서버도 새 문의를 막습니다**(409). 이미 대화 중인 방문자는 계속 쓸 수 있습니다.
- 공휴일 후보는 `backend/app/settings/holidays.py`(국가별, 매년 직접 갱신)이며 자동으로 넣지 않고 관리자가 고릅니다.
- **담당 변경(`assignment`)과 보는 중/입력 중(`presence`) 이벤트는 `realtime.publish_org()` 로 조직 방에만** 보냅니다.
  상담원 이름이 들어 있어 `publish_conversation()` 으로 보내면 방문자 방(`conv:`)을 거쳐 고객 위젯으로 샙니다(검사가 잡습니다).
  보는 중/입력 중은 저장하지 않으며 실시간 연결이 있을 때만 주고받습니다.
- 다른 상담원이 담당인 대화에 보내면 `api_chat_send` 가 409(`conflict`)를 돌려주고 화면이 [함께 답하기 / 담당 가져오고 보내기] 를 묻습니다.
  담당을 가져오면 `audit_logs`(`chat_take`)에 남깁니다.
- 답변 속도(실제 기록)는 `conversations.first_visitor_at` ~ `first_reply_at` 중 **운영 중에 들어온 문의(`received_in_hours = 1`)만**으로 계산합니다.
  대화는 위젯을 열 때 만들어지므로 `created_at` 을 쓰지 않습니다. 계산값은 한 시간 동안 `organizations.chat_reply_auto_min` 에 둡니다.

### 3.17 매크로 — 고르면 채울 뿐, 보내기는 기존 API

상담 대화 입력창에서 `/` 를 치면 자주 쓰는 답변(매크로) 목록이 열리고, 고르면 **입력창에 채울 뿐 보내지 않습니다.**
전송은 기존 `POST /console/api/chat/send` 로만 합니다(저장·발행 경로를 하나로, 3.11). 설계는 `docs/macro-plan.md`.

| 범위 | 저장 | 고치는 사람 | 쓰는 사람 |
|---|---|---|---|
| 공용 | `macros.owner_agent_id IS NULL` | 조직 관리자 | 조직의 모든 상담원 |
| 개인 | `macros.owner_agent_id = 상담원` | 본인 | 본인만(관리자도 볼 수 없음) |

- **`macros` 는 `macro_model` 만 고칩니다**(`save()` / `delete()` / `import_rows()`). 같은 검사(`clean_macro`), 이름 중복(`owner_key`),
  추천 색인(`macro_terms`)을 함께 처리합니다(검사가 잡습니다). `owner_key` 는 생성 컬럼이 아니라 모델이 채웁니다
  (CASCADE 외래 키가 걸린 컬럼은 STORED 생성 컬럼의 원본이 될 수 없는 MySQL 제약).
- 매크로로 채운 글을 보내면 `api_chat_send` 에 `macro_id` 를 함께 넘기고 서버가 `record_use()` 로 사용 횟수를 올립니다.
  쓸 수 없는 매크로(다른 사람의 개인 매크로)면 기록만 건너뛰고 전송은 그대로 합니다. 고객에게는 매크로 사용 여부가 보이지 않으며 `messages.meta` 에 넣지 않습니다.
- 변수는 `{visitor_name}` `{agent_name}` `{org_name}` 영문 토큰으로 저장하고, **콘솔이 입력창에 채울 때** 바꿉니다. 목록은 `settings/macro.py` 의 `VARIABLES`.
  고객 이름이 없을 때 문구는 고객이 읽으므로 **조직 언어**의 `macro.visitor_fallback` 입니다(3.2).
- 추천은 가이드와 같은 낱말 처리(`guide_matcher.words()/grams()/query_terms()`, 가이드 동의어 포함)로 마지막 고객 메시지에 맞춥니다. AI 를 쓰지 않습니다.
  가중치·기준 점수·개수 제한은 `settings/macro.py` 한 곳입니다.
- 파일 등록은 **CSV(UTF-8, 엑셀 CP949 도 받음)** 이며 Dropzone(`RHImageDrop`, 3.14)으로 받습니다. 한 행이라도 오류가 있으면 **아무것도 저장하지 않고** 오류 행을 돌려줍니다.
  CSV 는 `helpers/php_csv.py` 로 읽고 씁니다(파이썬 `csv` 모듈은 따옴표·줄바꿈 처리가 기존 파일과 다르게 읽힘).
  내려받을 때 `= + - @` 로 시작하는 칸은 엑셀 수식 실행을 막으려고 앞에 `'` 를 붙이고, 가져올 때 뗍니다. XLSX 는 받지 않습니다.
- 매크로 이름·내용은 상담원이 쓴 글이므로 화면은 `textContent` 로 그립니다(`macro*.js` 에서 `innerHTML` 에 값을 넣으면 검사가 잡습니다).
- 공용 매크로의 만들기·고치기·지우기·파일 등록·내려받기는 `audit_logs`(`macro_*`)에 남깁니다. 개인 매크로는 남기지 않습니다.

### 3.18 상담 위젯 인사말·연락처·팔로업 — 답장은 한 길로, 고객이 떠났으면 메일로

위젯 인사말, 대화 안 연락처 카드, 고객이 자리를 비웠을 때의 답장 알림 메일입니다. 설계는 `docs/widget-ops-plan.md` 2~3장.

- **인사말은 위젯을 열 때 화면에만 그리고**, 방문자가 첫 메시지를 보낼 때 그 앞에 시스템 메시지(`meta.type = 'greeting'`, 버튼 포함)로 한 번 저장합니다.
  `widget.send` 가 발행할 메시지를 읽기 전에 넣어 함께 발행합니다(3.11, 검사가 잡습니다). 열기만 한 방문자 수만큼 메시지가 쌓이지 않게 하려는 것입니다.
- 인사말 변수는 매크로와 같은 `{visitor_name}` `{org_name}` 이고 **서버가 채워** 글자로 내려보냅니다. 위젯은 `textContent` 로만 그립니다.
  바로가기 버튼은 3개, `https://` 만 받습니다(`chat_greeting.clean_buttons`). 이름 없는 고객의 호칭은 조직 언어의 `macro.visitor_fallback`.
- 위쪽에 늘 떠 있던 이름/이메일 칸은 없앴습니다. 연락처는 **대화 안 카드**로 첫 메시지 뒤 한 번 묻습니다(`conversations.contact_asked_at`).
  "연락처를 남긴 고객에게만" 이면 카드를 먼저 띄우고 **서버도 새 문의를 409 로 막습니다**(이미 대화 중이면 막지 않음).
- **`visitors` 는 `visitor_model` 만 고칩니다.** 입력 검사는 `clean_contact()` 하나이며 휴대폰은 국가번호 허용 목록(`country_dial_codes()`)으로 E.164 로 저장합니다(검사가 잡습니다).
- **상담원 답장을 저장하는 곳은 모두 `console.publish_new_messages()` 를 거칩니다.** 그 안에서 `chat_followup.after_agent_message()` 가
  메일 여부를 정합니다. 새 답장 경로를 만들 때 이 함수를 빼면 자리를 비운 고객이 답을 영영 못 봅니다(검사가 잡습니다).
- "자리를 비움" 은 `conversations.visitor_seen_at` 이 75초보다 오래된 것입니다. 이 값은 **위젯 폴링(창을 펼치고 탭이 보일 때만 돈다)과 전송·연락처 제출만** 갱신합니다.
  `init` 에서 갱신하면 창을 닫아 둔 방문자도 보고 있는 것으로 셉니다. 같은 대화에는 고객이 다시 볼 때까지 한 통만 보냅니다(`followup_sent_at`, UPDATE 한 번으로 판단).
- 예약 작업(cron)이 없으므로 메일은 상담원 답장 요청 안에서 보냅니다(SMTP 시간 제한 10초). 실패하면 기록을 되돌려 다음 답장 때 다시 시도합니다.
- 메일의 이어 가기 주소는 `customer_url('{조직코드}/chat/{토큰}')`(3.13)이며 토큰은 대화·방문자·만료(14일)를 HMAC 서명한 값입니다.
  키는 `.env` 의 `CHAT_RESUME_SECRET`, 비면 `REALTIME_TOKEN_SECRET` 에서 용도를 나눠 만듭니다. `visitor_key` 는 메일에 넣지 않습니다.
- 메일 문구는 고객이 읽으므로 **조직 언어**입니다(`widget.followup.*`, 3.2). 보낸 사람 이름은 조직 이름, 회신 주소는 `widget_offline_email`.

### 3.19 백엔드(FastAPI) 작성 규칙

포털은 FastAPI(`backend/`, 컨테이너 `api`)이며 앞단 Apache(`web`)가 `/assets` 와 웹소켓 경유를 뺀 요청을 넘깁니다.
기존 데이터·화면·런처와 같은 동작을 지키려고 PHP 시절의 판정 규칙을 그대로 옮긴 도우미가 있습니다. 표준 함수로 바꾸면 결과가 달라집니다.

- **DB 결과는 모든 값이 문자열**입니다(`app/db.py`). 숫자 비교는 `int()` / `php_int()` 로 바꿔서 합니다.
  DB 세션은 시간대 `+00:00`, sql_mode 에서 `STRICT_*` 를 뺍니다(저장된 데이터가 그 동작을 전제로 함, 검사가 잡습니다).
- 응답을 보내고 바로 끝낼 때는 `raise Halt(api_response(...))`, 화면은 `render(request, '폴더/뷰', data, 'layouts/...')` 입니다.
  템플릿은 정의되지 않은 변수가 오류이므로 `is defined` / `|default()` 를 쓰고, `<script>` 안의 값은 `|js` 필터를 씁니다.
- 입력: 폼 `Depends(post_data)`, 쿼리 `Depends(query_data)`, JSON `Depends(json_input)`. `post.xss('x')` 는 xss_clean(`core/security.py`) 을 거칩니다.
  `(int)` `(string)` `empty()` 판정은 `php_int` `php_str` `php_empty`, 템플릿의 참거짓은 `empty()` 로 씁니다(DB 의 `'0'` 이 Jinja 에서는 참).
  dict 의 `items`·`keys`·`values` 키는 메서드 이름과 겹치므로 `x['items']` 로 읽습니다.
- JSON 은 `php_json_encode`(화면·서명 토큰과 같은 바이트) / `php_json_decode`(깊이 511단, NaN·짝 없는 서로게이트 거부)를 씁니다.
- `/\s/u` 범위는 `core/php.PCRE_U_SPACE`, 이메일 `helpers/validation.valid_email`, https 주소 `helpers/php_filter.validate_https_url`,
  주소 해석 `helpers/php_url.parse_url/parse_str`, CSV `helpers/php_csv` 를 씁니다. 파이썬 표준 검사로 바꾸면 기존 결과와 달라집니다.
- 3장의 "한 곳을 거친다" 규칙은 같은 이름의 함수 한 곳으로 지킵니다(예: `guide_model.save_article()`).
- 파이썬 의존성은 `backend/requirements.txt` 에 `==` 로, 컨테이너 기반 이미지는 날짜가 든 태그로 고정합니다(검사가 잡습니다).
- 환경변수는 `docker-compose.yml` 의 `x-portal-env` 한 곳에 두고 api 가 받습니다. 세션·CSRF 쿠키는 `rh_session`, `rh_csrf` 입니다.

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
backend/ relay/ launcher/ scripts/ docker/ sql/ server/ 아래 파일을 수정
  -> scripts/hooks/post-edit-check.py 실행
  -> 위반이 있을 때만 결과를 알림 (통과하면 조용함)
```

검사는 몇 초 안에 끝나며 작업을 막지는 않습니다. 잠시 꺼야 하면 `/hooks` 에서 끌 수 있습니다.

---

## 5. 개발 환경

```bash
cp .env.example .env
docker compose up -d --build
```

| 주소 | 용도 |
|---|---|
| http://localhost:8099/ | 소개 페이지(web → api) |
| http://localhost:8099/login | 상담원 콘솔 (admin@demo.local / Test1234!admin) |
| http://localhost:8099/operator | 조직 가입 승인 (operator@demo.local / Test1234!admin) |
| http://localhost:8099/demo | 고객 접속 페이지 |
| http://localhost:8099/healthz | 포털 상태(DB 연결·시간대·sql_mode) |
| http://localhost:8098/ | FastAPI 직접 접속(개발용, 고객 IP 는 게이트웨이 주소, api 는 코드를 고치면 다시 읽음) |
| https://localhost:8443/healthz | 중계 서버 상태 |
| http://localhost:8091/healthz | 상담 실시간 메시지 서버 상태 |
| https://help.wani.im/ | 시험 서버 외부 주소(Cloudflare Tunnel `remotehelp-help` -> `http://web:80`, `.env` 에 토큰과 `COMPOSE_PROFILES=tunnel`). http 는 Apache 가 https 로 넘긴다 |

윈도우 런처는 이 환경에서 빌드할 수 없습니다. 코드 작성까지만 하고 실기 검증은 별도로 표시합니다.

---

## 6. 현재 결정 사항

| 항목 | 결정 |
|---|---|
| 배포 경로 | **운영은 1차 Microsoft Store(MSIX) 단독.** 직접 다운로드는 `DIRECT_DOWNLOAD_ENABLED` 로 켜고 끈다(운영 기본 false). 개발 `.env` 는 true 이며 `backend/assets/downloads/RemoteHelp.exe` 를 내려준다. 켜지면 고객 페이지의 주 버튼이 다운로드로 바뀐다 |
| 런처 빌드 후 배포 | **런처 exe 를 빌드하면 항상 시험 서버에 배포한다(시험 서버 한정, 사용자 승인).** `launcher/scripts/build-exe.ps1 -TestBuild` 가 `backend/assets/downloads/RemoteHelp.exe` 로 복사하고, 그것이 `http://118.36.197.219:8099/demo/download` 로 내려간다(`.env` 의 `DIRECT_DOWNLOAD_ENABLED=true`). 시험 빌드에는 인증서 예외가 들어 있으므로 서명·운영 배포 금지. 배포 후 해시와 다운로드 응답을 확인해 보고한다 |
| 코드 서명 | Store 는 Microsoft 가 서명하므로 불필요. 직접 배포 시 OV 인증서 필요(`docs/code-signing.md`) |
| 요금 | 연 220,000원 / 상담원 계정 5개. `routers/home.py` 의 `pricing` 한 곳에서 관리 |
| 화면 캡처 | GDI 우선, 가능하면 DXGI 자동 전환 |
| 중계 서버 | Node.js + ws |
| 시험 서버 뷰어 연결 | **relay 가 자체 서명 인증서라 상담원 브라우저는 포털 경유로 붙는다.** `.env` 의 `RELAY_VIEWER_WS_URL=auto` 이면 접속한 주소 기준 `ws(s)://<호스트>/relay-viewer`, web(Apache)이 relay 인증서(`relay-ca` 볼륨)로 검증한 wss 로 넘긴다. 런처(`/agent`)는 그대로 8443 직결. 운영은 공인 인증서 + `wss://<도메인>/viewer` |
| 시험 서버 외부 주소 | **`https://help.wani.im` 은 Cloudflare Tunnel(cloudflared 컨테이너)로 받는다.** 고객 IP 를 얻는 유일한 경로이며 https 인증서도 해결된다. `help.wani.im` 은 Cloudflare 프록시라 `:8099` 로는 접속되지 않는다. 런처 relay 접속은 IP(`118.36.197.219:8443`) 유지 |
| 상담 위젯 | **별도 사이트가 아니라 이 포털 안의 기능.** 조직·상담원 체계를 그대로 쓴다 (`docs/widget-plan.md`) |
| 포털 서버 | **FastAPI(2026-10-22 전환, PHP 삭제).** Jinja2 서버 렌더링, SQLAlchemy Core(동기). 앞단은 PHP 없는 Apache(정적 파일, 고객 IP, 웹소켓 경유) (`docs/fastapi-migration-plan.md`) |
| 상담 실시간 | **`realtime-msg` 컨테이너(Node + ws)로 전달.** 폴링은 연결이 끊겼을 때의 보조 수단으로 남긴다 |
| 상담 운영 시간 | **1차: 요일별 운영 시간(하루 3구간), 휴일(공휴일 후보는 KR 만), 조직 수동 전환, 상담원 개인 상태, 부재중 자동 응답, 운영 시간 외 위젯(받기/안내만/숨기기). 2차: 답변 속도 안내(직접 지정/실제 기록), 중복 응대 방지(담당 표시·가져오기, 보는 중/입력 중, 새 문의 브라우저 알림 1회), 위젯 전화 버튼, 조직 관리 통계의 상담 숫자.** 모두 조직이 켤 때만 동작(기본 꺼짐) (`docs/chat-operation-plan.md`) |
| 위젯 인사말·연락처·팔로업 | **인사말 1개(조직 언어) + 변수 + 바로가기 버튼 3개. 연락처 카드(이름/이메일/휴대폰 선택, 필수 모드). 고객이 떠났으면 상담원 답장을 이메일로 알림(기본 켜짐, SMTP 필요)과 14일 이어 가기 주소.** 휴대폰은 저장만 하고 문자/알림톡은 발송 수단이 정해진 뒤. 언어별 인사말과 여러 운영 설정(창구)은 보류 (`docs/widget-ops-plan.md`) |
| 매크로 | **공용(관리자 편집) + 개인(본인), 입력창 `/` 로 불러와 고쳐 보내기.** 즐겨찾기 100개, 추천은 키워드 매칭(AI 없음), 파일 등록은 CSV(XLSX 는 받지 않음). 공용 2,000 / 개인 300 (`docs/macro-plan.md`) |
| 가이드 | **DB 저장 + guide.json 내려받기/올리기.** 편집은 조직 관리자만, 상담원은 콘솔에서 검색·보내기. 추천은 키워드 매칭(AI 토큰 없음). 조직당 한 언어 (`docs/guide-plan.md`). 공개 화면의 새 기능 모음·동영상 모음은 조직이 켤 때만 (`docs/guide-collections-plan.md`) |

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
