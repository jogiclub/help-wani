# 포털 서버 FastAPI 전환 계획

> 파일 위치: docs/fastapi-migration-plan.md
> 역할: CodeIgniter 3(PHP) 포털을 FastAPI(Python)로 옮기는 단계별 계획

- 작성일: 2026-10-22
- 상태: 진행 중 — 0~5단계 완료(2026-10-22). 8장의 제안대로 진행하기로 결정

---

## 1. 목표와 원칙

**목표**: `portal/` 의 서버 코드(CodeIgniter 3)를 FastAPI 로 다시 작성한다. 화면(JS/CSS/사전/아이콘)과 DB, 중계 서버(`relay/`),
상담 실시간 서버(`realtime/`), 런처는 그대로 둔다.

**원칙**

1. **PHP 는 지우지 않고 나란히 띄운다.** 새 컨테이너 `api` 를 따로 올려 같은 DB 를 쓰게 하고, 기능을 다 옮겨 비교가 끝난 뒤에 전환한다.
   PHP 제거는 마지막 단계(6장 10단계)이며 전환 후 일정 기간 되돌릴 수 있게 남겨 둔다.
2. **프런트엔드는 고치지 않는 것이 기본이다.** 주소, 요청 형식(`csrf_test_name` 포함), 응답 형식(`{ result, message, data }`),
   화면 전역 변수(`XXX_URLS`, `i18n`)를 PHP 와 똑같이 맞춘다. JS 를 고쳐야 한다면 PHP 쪽에서도 동작하게 고친다(전환 전까지 둘 다 돌아야 함).
3. **DB 스키마는 바꾸지 않는다.** 전환 기간에는 PHP 와 FastAPI 가 같은 DB 를 함께 쓰므로 스키마를 바꾸면 한쪽이 깨진다.
   꼭 필요하면 양쪽 모두 호환되는 추가(컬럼 추가 등)만 하고 `sql/migrations/` 에 넣는다.
4. **CLAUDE.md 3장의 규칙은 그대로 옮긴다.** 규칙이 "어느 함수 한 곳을 거친다" 로 정의되어 있으므로
   Python 에서도 같은 이름·같은 책임의 함수 한 곳으로 만든다(예: `Guide_model::save_article()` → `guide_model.save_article()`).
5. **서명 토큰과 비밀번호는 바이트 단위로 호환**시킨다. 전환 순간 로그인·위젯 토큰·이어 가기 메일 주소가 끊기면 안 된다.

---

## 2. 현재 규모 (2026-10-22 기준)

| 구분 | 파일 | 줄 수(대략) |
|---|---|---|
| 컨트롤러 | 14개 (`controllers/`, `controllers/api/`) | 4,200 |
| 모델 | 9개 | 4,900 |
| 라이브러리 | 5개 (`Realtime`, `Mailer`, `Guide_matcher`, `Chat_greeting`, `Chat_followup`) | 900 |
| 헬퍼·코어 | `app_helper`, `i18n_helper`, `MY_Controller` | 600 |
| 설정 | `guide_match`, `guide_samples`, `holidays`, `macro`, `routes` 등 | - |
| 뷰 | 53개 PHP 템플릿 | 4,200 |
| 라우트 | 약 70개 (`config/routes.php`, 마지막 `(:any)` 는 조직 코드) | - |

손대지 않는 것: `portal/assets/` 전체(JS, CSS, 사전, 벤더, 업로드), `relay/`, `realtime/`, `launcher/`, `sql/`.

---

## 3. 목표 구조

```
[브라우저 / 위젯 / 런처]
        |
        |  전환 전: :8099 -> web(PHP)        시험: :8098 -> api(FastAPI)
        |  전환 후: :8099 -> web(Apache, 프록시 전용) -> api
        v
   api (FastAPI + uvicorn)  ---- MySQL(db, 같은 DB)
        |  POST /publish                ^  GET /api/realtime/auth, /api/relay/auth
        v                               |
   realtime-msg / relay  ---------------+
```

### 3.1 디렉터리

```
backend/
  (Dockerfile 은 기존 관례대로 docker/api/Dockerfile 에 둔다)
  requirements.txt         (버전 고정)
  app/
    main.py                앱 생성, 미들웨어, 라우터 등록(조직 코드 라우터는 맨 마지막)
    config.py              .env 읽기 (PHP config/env.php 대응)
    db.py                  DB 연결
    core/
      session.py           로그인 세션 (CI3 ci_session 대응)
      csrf.py              csrf_test_name 검사 (csrf_exclude_uris 대응)
      response.py          api_response()
      deps.py              current_agent / require_admin / require_operator (Agent/Admin/Operator_Controller 대응)
      templates.py         Jinja2 환경 + 전역 함수(asset_url, base_url, customer_url, __, lang_text, to_timezone ...)
      i18n.py              i18n_helper 대응, portal/assets/lang/*.json 을 그대로 읽음
    helpers/app.py         app_helper 대응 (check_uploaded_image, image_file_ext, fetch_external_image ...)
    models/                Organization / Agent / Session / Chat / Chat_hours / Guide / Macro / Visitor / Log
    libraries/             realtime / mailer / guide_matcher / chat_greeting / chat_followup
    settings/              guide_match / guide_samples / holidays / macro (PHP config 배열 대응)
    routers/               home / auth / customer / guide / download / widget / console / console_macro /
                           admin / admin_guide / operator / api_launcher / api_relay / api_realtime_auth / org(맨 마지막)
    templates/             PHP 뷰를 옮긴 Jinja2 템플릿 (views/ 와 같은 폴더 구조)
```

> 기존 `server/` 는 중계 서버 설치 스크립트 폴더이므로 쓰지 않는다. 폴더 이름은 확인 필요 사항(8장) 참고.

### 3.2 컨테이너 (`docker-compose.yml` 에 추가)

| 항목 | 값 |
|---|---|
| 서비스 이름 | `api` (container_name `remotehelp-api`) |
| 포트 | `8098:8000` (기존 8099, 8091, 8443, 13306 과 겹치지 않음) |
| 환경 변수 | `web` 과 같은 값(DB, 비밀값, REALTIME_*, RELAY_*, PUBLIC_BASE_URL, SMTP_* ...)을 `.env` 에서 받는다. 새 비밀값은 만들지 않는다 |
| 볼륨 | `./backend:/app`(개발 중 자동 재시작), `./portal/assets:/srv/assets`(정적 파일과 업로드를 PHP 와 공유) |
| 망 | `default`, `tunnel`(전환 단계에서 cloudflared 가 붙을 수 있게) |
| 의존 | `db` healthy |

정적 파일(`/assets/...`)은 전환 전까지 FastAPI `StaticFiles` 로 함께 내준다. 전환 후에는 Apache 가 직접 내주는 방식으로 바꿀 수 있다(6장 9단계).

---

## 4. 기술 선택

정확한 버전과 옵션은 **구현 시점에 공식 문서·PyPI 로 확인해 고정**하고, 확인 결과를 보고서에 남긴다(CLAUDE.md 1장).

| 용도 | 선택(안) | 이유 |
|---|---|---|
| 웹 프레임워크 | FastAPI + uvicorn | 요청 사항 |
| 템플릿 | Jinja2 | PHP 뷰를 서버 렌더링 그대로 옮길 수 있음. 화면 JS 를 바꾸지 않아도 됨 |
| DB | SQLAlchemy 2 Core + PyMySQL (동기) | 기존 모델이 SQL 중심이라 ORM 매핑보다 Core 가 옮기기 쉬움. 동기 엔드포인트(`def`)로 두고 FastAPI 스레드풀에 맡긴다 |
| 세션 | 서명 쿠키 또는 DB 세션(5.2) | |
| 비밀번호 | `bcrypt` | PHP `password_hash(PASSWORD_DEFAULT)` 의 `$2y$` 해시를 그대로 검증 |
| 폼/파일 | `python-multipart` | Dropzone 업로드, CSV 가져오기 |
| HTTP 클라이언트 | `httpx` | realtime 발행, 외부 이미지 받기 |
| 메일 | 표준 `smtplib` (시간 제한 10초) | `Mailer` 와 같은 동작 |
| 시간대 | 표준 `zoneinfo` | `to_timezone()`, `Chat_hours_model` 벽시계 판정 |
| 이미지 판별 | 파일 머리 바이트 직접 검사 | `image_file_ext()` 와 같은 방식. SVG 거부 유지 |

---

## 5. 반드시 호환시켜야 할 것

전환 순간 끊기거나 조용히 어긋나는 항목입니다. 각 항목은 PHP 와 FastAPI 가 **같은 입력에 같은 출력**을 내는지 비교해 확인합니다.

### 5.1 응답·요청 형식

- 모든 API 는 `{ "result": bool, "message": str, "data": {} }`. 빈 `data` 는 `[]` 가 아니라 `{}`(커밋 5b3f83d 결정).
- HTTP 상태 코드도 맞춘다(401 로그인 필요 + `data.redirect`, 404, 409 `conflict` 등).
- 요청은 jQuery `$.ajax` 폼 인코딩(`application/x-www-form-urlencoded`)과 multipart 를 받는다. JSON 본문만 받게 만들지 않는다.
- AJAX 판별은 CI3 처럼 `X-Requested-With: XMLHttpRequest` 로 한다(로그인 만료 시 리다이렉트/401 갈림).

### 5.2 로그인 세션

- 세션 값: `agent_id`, `locale`, `timezone` 등(`Auth::login` 이 넣는 키를 전수 조사해 맞춘다).
- 만료 7200초, `SameSite=Lax`, `HttpOnly`.
- **PHP 와 세션을 공유하지 않는다.** CI3 파일 세션(PHP 직렬화)을 Python 에서 읽고 쓰는 것은 복잡하고 위험하다.
  시험 기간(:8098)에는 FastAPI 가 자기 세션을 따로 쓰고, 전환 때 한 번 다시 로그인하게 한다(상담원만 해당, 고객 화면은 세션을 쓰지 않음).
- 쿠키 이름은 PHP 와 겹치지 않게 한다(같은 호스트의 다른 포트는 쿠키를 공유하므로 `ci_session` 을 덮어쓰면 PHP 로그인이 풀린다).

### 5.3 CSRF

- 화면은 `<meta name="csrf-name">`, `<meta name="csrf-hash">` 를 읽어 `csrf_test_name` 으로 보낸다(`common.js`). 이름을 그대로 쓴다.
- 쿠키 이중 제출 방식(CI3 `csrf_cookie_name`)과 같은 방식으로 검사한다. 쿠키 이름은 5.2 와 같은 이유로 PHP 와 다르게 둔다.
- 제외 경로: `api/launcher/.*`, `api/relay/.*`, `api/widget/.*`. 위젯은 대신 Origin 허용 도메인 + 방문자 토큰 대조(3.9).

### 5.4 서명 토큰과 무작위 값

| 토큰 | PHP 위치 | 맞출 것 |
|---|---|---|
| 상담 실시간 구독 토큰 | `Realtime.php` (HMAC-SHA256, base64url, `REALTIME_TOKEN_SECRET`) | 본문 JSON 직렬화 순서·형식까지 같게. realtime-msg 가 포털에 검증을 맡기므로 섞여도 동작해야 함 |
| 이어 가기 주소 토큰 | `Chat_followup.php` (`CHAT_RESUME_SECRET`, 비면 `hash_hmac('sha256','chat-resume', REALTIME_TOKEN_SECRET)`) | 이미 보낸 메일의 주소(14일)가 전환 후에도 열려야 함 |
| 런처·뷰어 토큰 | `Session_model.php` (`random_bytes(32)` → hex) | DB 에 저장하는 값이므로 형식(64자 hex)만 맞추면 됨 |
| 비밀번호 | `password_hash` / `password_verify` | `$2y$` bcrypt 검증 확인. 새로 만드는 해시도 PHP 가 읽을 수 있는 형식 |

PHP `json_encode` 는 기본으로 `/` 를 `\/` 로, 한글을 `\uXXXX` 로 바꾼다. 서명 본문을 만들 때 이 차이가 생기지 않는지 토큰 하나하나 확인한다.

### 5.5 시간

- DB 세션 시간대 `+00:00`, 저장은 UTC(3.4). Python 에서 `datetime.now(timezone.utc)` 만 쓰고 로컬 시간을 쓰지 않는다.
- 운영 시간·휴일은 조직 시간대 벽시계 값(3.16 예외)이며 `availability()` 한 곳에서만 판정한다.

### 5.6 다국어·아이콘·자산 주소

- `portal/assets/lang/{ko,en,ja}.json` 을 그대로 읽는다. 언어 결정 우선순위(3.2 표)를 같은 순서로 옮긴다.
- `asset_url('assets/...')` 는 파일 수정 시각을 `?v=` 로 붙인다(Cloudflare 캐시 문제, 3.1). Jinja 전역 함수로 같은 결과를 낸다.
- `Home::landing_icons()` 아이콘 서브셋 목록과 `icon_names` 전달 방식을 유지한다(3.3).

### 5.7 고객 IP, 주소

- 고객 IP 는 `CF-Connecting-IP` 를 **cloudflared 고정 주소(10.250.88.5)에서 온 요청일 때만** 믿는다(3.12).
  uvicorn 의 프록시 헤더 옵션은 `X-Forwarded-For` 기반이므로 쓰지 않고, 이 규칙을 직접 구현한다.
  전환 후 Apache 를 앞에 두면 Apache(`mod_remoteip`)가 복원한 IP 를 FastAPI 로 넘기는 방식을 다시 정한다(6장 9단계).
- 고객에게 보내는 주소는 `customer_url()`(3.13), `PUBLIC_BASE_URL` 기준.
- 웹소켓 경로 `/realtime-ws`, `/relay-viewer` 는 전환 전까지 Apache 가 맡는다. FastAPI 는 주소만 만들어 준다(`Realtime::ws_url()`, `relay_viewer_ws_url()`).

### 5.8 라우트 순서

`(:any)` 조직 코드 라우트는 FastAPI 에서도 **맨 마지막에 등록**한다(3.8). `(:any)/guide/features`, `/guide/videos` 는 `(:any)/guide/(:any)` 보다 앞(3.15).
CI3 의 `(:any)` 는 `/` 를 포함하지 않는 한 구간이므로 FastAPI 경로 매개변수(`{org_code}`)와 같다. `:path` 변환을 쓰지 않는다.


### 5.9 xss_clean — (가) 그대로 옮김 (2026-10-22 결정)

CI3 `input->post('x', TRUE)` 는 값에 xss_clean 을 적용한다(태그·속성 제거, `%xx` 반복 해제 등). 전환 기간에 두 서버가 같은 DB 에
같은 값을 저장하도록 CI3 3.1 의 xss_clean 을 그대로 옮겼다(`app/core/security.py`).
- PHP 의 `post('x', TRUE)` 는 `post.xss('x')`, `get_request_header(name, TRUE)` 는 `get_request_header(name, True)` 로 옮긴다.
- CI3 가 요청 시작 때 모든 GET/POST 값에 하는 정리(키 이름 검사, 제어 문자 제거, `_sanitize_globals`)도 `post_data` / `query_data` 가 한다.
- 남는 차이: URL 디코드로 생긴 잘못된 UTF-8 바이트는 U+FFFD 로 바꾼다(PHP 는 그대로 보내 MySQL 이 경고와 함께 잘라 냄).
---

## 6. 단계별 작업

각 단계는 **옮기기 → PHP 와 비교 → 화면 확인** 순서로 끝낸다. 단계가 끝날 때마다 완료/미완료/확인 필요 사항을 보고한다.

### 0단계. 컨테이너와 뼈대

- `backend/` 뼈대, `docker/api/Dockerfile`, `docker-compose.yml` 에 `api` 추가, `.env.example` 에 필요한 값 확인(새 비밀값 없음).
- `GET /healthz`(DB 연결 확인 포함).
- 정적 파일 `/assets` 마운트.
- 완료 기준: `docker compose up -d api` 후 `http://localhost:8098/healthz` 정상, 기존 컨테이너 영향 없음.
- **결과(2026-10-22 완료)**
  - 기준점 커밋 `cfc8070`(옮기기 전 PHP 변경분 고정).
  - 파일: `backend/requirements.txt`, `backend/app/{main,config,db,static}.py`, `backend/app/core/response.py`, `docker/api/Dockerfile`.
  - 버전(PyPI 확인, 배포 2주 이상): python 3.12.14-slim-bookworm, fastapi 0.142.4, uvicorn 0.54.0, sqlalchemy 2.1.4, pymysql 1.2.3,
    cryptography 50.0.2, jinja2 3.1.6, python-multipart 0.0.32, bcrypt 5.0.0, httpx 0.28.1, itsdangerous 2.2.0. 함께 깔린 starlette 1.9.0, pydantic 2.14.1.
    fastapi 는 10-08 이후 30여 개 판이 연달아 나와 그 전의 마지막 판을 골랐다.
  - 환경변수: `docker-compose.yml` 의 `x-portal-env` 를 web 과 api 가 함께 받는다. PHP 가 읽지만 web 에 넘기지 않던
    `SMTP_*`, `CONTACT_*`, `CHAT_RESUME_SECRET`, `LANDING_WIDGET_ORG`, `DEMO_ORG` 를 빈 값으로 더했다(지금 `.env` 에 모두 비어 있어 동작 변화 없음).
  - DB 세션: 시간대 `+00:00`, sql_mode 에서 `STRICT_*` 제거(CI3 `stricton = FALSE` 와 같음). PHP 세션 값과 같음을 확인.
  - uvicorn: `--no-proxy-headers`(기본은 127.0.0.1 의 X-Forwarded-For 를 믿음, `uvicorn --help` 로 확인), `--no-server-header`. 개발은 `--reload`.
  - 정적 파일: `/assets` 를 `portal/assets` 와 공유. 업로드 폴더의 스크립트·HTML·SVG, `.ini/.log/.sql/.md`, 숨김 파일은 403.
    컨테이너는 www-data(uid 33)로 돌아 PHP 와 업로드 폴더를 같이 쓴다.
  - 규칙 검사 `check_fastapi()` 추가, `backend/app` 머리 주석 검사 포함, 훅 감시 대상에 `backend/` 추가.

### 1단계. 공통 기반

- 설정(`config.py`), DB, `api_response()`, 세션, CSRF, 권한 의존성(상담원 / 조직 관리자 / 플랫폼 운영자, 3.6).
- Jinja2 환경과 전역 함수: `asset_url`, `base_url`, `customer_url`, `__`, `lang_text`, `to_timezone`, `html_escape`(Jinja 자동 이스케이프), `guide_text_html`, `guide_image_url`.
- 레이아웃 4종(`layouts/main`, `operator`, `public`, `customer`)과 `_head` 를 Jinja 로 옮김. `render()` 가 넘기던 `i18n`(locale, intl, timezone, messages)과 `app_name`, `use_grid`, `use_dropzone`, `icon_names` 처리.
- `audit_logs`, `session_logs` 기록(`Log_model`).
- 완료 기준: 빈 화면 하나가 PHP 와 같은 레이아웃·메뉴·언어로 그려짐.
- **결과(2026-10-22 완료)** — 세션·CSRF·권한을 실제로 확인하려고 3단계의 로그인/로그아웃을 앞당겨 옮겼다(가입 신청은 3단계 그대로).
  - 파일: `core/{php,request,i18n,input,errors,csrf,session,middleware,deps,templates,password}.py`, `helpers/{app,validation}.py`,
    `models/{log,agent,organization}_model.py`(조직은 조회 2개만), `routers/{auth,dev}.py`, `templates/layouts/*`(8개), `templates/auth/login.html`.
  - DB 값: PyMySQL 에 읽기 변환기를 주지 않아 **모든 값이 mysqli 처럼 문자열**("id":"12", 날짜 문자열)로 온다. 옮긴 코드는 PHP 처럼 int() 로 바꿔 쓴다.
  - 영향 행 수: SQLAlchemy 가 켜는 FOUND_ROWS 를 꺼서 mysqli 와 같이 '실제로 바뀐 행' 을 센다(같은 값 UPDATE 0건, PHP 와 같음 확인).
  - 세션: 서명 쿠키 `rh_session`(키는 ENCRYPTION_KEY 에서 파생). 5분마다 활동 시각을 적어 마지막 활동부터 7200초 유지(CI3 와 같음).
  - CSRF: 쿠키 `rh_csrf`, 이름 `csrf_test_name`, 폼 본문의 토큰만 인정(PHP 는 JSON 본문을 $_POST 로 읽지 않음), SameSite=Strict.
  - 리다이렉트는 CI3 와 같이 GET 307 / 그 밖 303. 오류 화면(403, 404)은 CI3 화면과 같은 모양.
  - 응답 JSON 은 PHP json_encode 와 같은 바이트('/' → '\/', U+2028/2029 이스케이프, DEL 그대로, 정수 값 실수는 1).
  - `valid_email` 정규식은 php-src 8.2.34 소스에서 자동으로 옮겼다. PHP filter_var 와 359개 입력 비교 불일치 0.
  - 비교 결과(같은 요청을 8099/8098 에): 로그인 화면, CSRF 403, 검사 오류 문구, 비밀번호 오류 401, 로그인 안 한 AJAX 401, 리다이렉트 코드,
    로그아웃, 운영자 거부 403과 감사 로그 형식이 같음. 방문자 레이아웃은 ko/en/ja 모두 내용이 같고(줄바꿈 위치만 다름) `RH_I18N` 은 바이트 단위로 같음.
  - 남는 차이(영향 없음으로 판단): 실수의 지수 표기(1.0e+20 / 1e+20)와 -0, 존재하지 않는 날짜를 PHP 는 다음 날로 넘기고 여기는 원래 값을 돌려줌,
    작은따옴표 이스케이프(&#039; / &#39;), JS 문자열 따옴표('…' / "…").
  - 시험에 데모 계정을 썼고, 생긴 감사 로그 18건을 지우고 관리자 `last_login_at` 을 되돌렸다(AUTO_INCREMENT 는 417부터).
  - 개발 전용 `/_dev/layout/*` 은 레이아웃 비교용이며 화면을 다 옮기면 지운다.
  - **확인 필요**: PHP 는 `post('x', TRUE)` 에서 xss_clean 을 거친다. 3단계부터 입력을 받는 곳마다 같은 처리가 필요한지 정해야 한다(5.9 참고).

### 2단계. 서버 간 API (화면 없음, 가장 작고 검증 쉬움)

- `api/relay/auth`, `api/realtime/auth`, `api/launcher/{verify,status,heartbeat,version}`.
- 확인: relay·realtime-msg 의 `PORTAL_ORIGIN` 을 잠시 `http://api:8000` 으로 바꿔 `./scripts/e2e-test.sh` 통과. 끝나면 `http://web` 으로 되돌린다.
- **결과(2026-10-22 완료)**
  - 파일: `routers/api_{launcher,relay,realtime_auth}.py`, `models/session_model.py`(전체), `libraries/realtime.py`(전체), `settings.py`(RH_* 상수),
    `core/security.py`(xss_clean), `core/data/php_html5_entities.json`(PHP 8.2.34 에서 뽑은 엔티티 표), `core/input.py`(json_input, query_data, 입력 정리),
    `core/php.py`(php_int, php_str, php_empty, utc_str, strtotime_utc).
  - xss_clean: 웹 컨테이너에서 CI3 `CI_Security::xss_clean()` 을 직접 불러 비교. 알려진 XSS 우회 문자열 121개 + 무작위 조합 2만4천 개 + 엔티티 변형 1만6백 개,
    모두 불일치 0. 숫자 엔티티 디코드 규칙은 0 ~ 0x110010 전체를 PHP 에서 확인해 정했다.
  - 입력 정리: 폼 해석(`a[]`, `a[b][c]`, 점·공백 이름) + CI3 `CI_Input` 시작 정리 결과를 PHP 와 16개 사례 비교 일치. (int)/(string) 변환 30개 일치.
  - 실시간 토큰: PHP 원본 라이브러리와 같은 본문(바이트)을 만들고, PHP 토큰을 FastAPI 가 / FastAPI 토큰을 PHP 가 서로 검증. 변조 토큰은 양쪽 403.
  - 응답 비교: version, relay/realtime 인증 실패, heartbeat, 입력 형식별(JSON 숫자, 폼, text/plain JSON, 배열) 런처 응답이 PHP 와 바이트 단위로 같음.
  - e2e: relay·realtime-msg 의 `PORTAL_ORIGIN` 을 `http://api:8000` 으로 바꾸고 `LAUNCHER_BASE=http://localhost:8098` 로 실행 →
    런처 검증·상태·하트비트와 relay 의 에이전트/뷰어 인증(정상 200, 잘못된·재사용 토큰 403)을 FastAPI 가 처리하고 원격 화면 수신까지 통과.
    FastAPI 가 발급한 토큰으로 realtime-msg 를 구독하고 FastAPI 가 발행한 이벤트가 도착함을 확인. 끝난 뒤 `http://web` 으로 되돌리고 기준 e2e 다시 통과.
  - 주의: e2e 스크립트의 `docker compose up agentsim` 이 relay 를 기본 설정으로 다시 만든다. 임시 설정으로 시험할 때는 `COMPOSE_FILE` 에 덮어쓰기 파일을 함께 준다.
  - 시험 데이터: e2e 가 만든 세션 5, 세션 로그 33, 뷰어 토큰 5, 코드 검증 기록 10, 감사 로그 19건을 지우고 관리자 `last_login_at` 을 되돌렸다.
    시험 이벤트 1건(`type: presence`, `test` 값)이 조직 1 방에 발행됐다(그 시각 콘솔을 연 상담원이 있었다면 받았음).
  - 남는 차이: PHP 가 경고를 내는 입력(`code[]=` 배열)에서 PHP 는 경고 HTML + 200, FastAPI 는 400 JSON.
  - Tailwind: v4 자동 탐색이 `backend/` 의 데이터 파일(`&ring;`)을 클래스로 잡아 `@source not "../../../backend"` 로 뺐다(빌드 결과는 기존 app.css 와 같음).
- 런처는 시험 빌드 주소를 바꾸지 않는다(전환 때 같은 도메인이 그대로 api 로 가게 됨).

### 3단계. 소개·로그인·고객 화면

- `Home`(소개, `lang/(:any)`), `Auth`(login/logout/signup), `Customer`(접속 안내, 만족도 조사, 이어 가기 `chat/{토큰}`), `Download`, `Guide`(공개 가이드, 테마 5종, 새 기능·동영상 모음).
- 공개 가이드 테마 뷰(`views/guide/themes/*`), `_article`, `_video`(iframe 은 한 곳), `_tabs`, `_collection`.
- **결과(2026-10-22 완료)**
  - 경로: `routers/{home,customer,download,guide}.py`, `auth.py` 에 가입 신청. 조직 코드 하나만 받는 `/{org_code}` 는 `main.py` 맨 마지막.
  - 모델·라이브러리: `guide_model.py`(공개 화면 부분: 조회, 테마, 공개 페이지 구성, 새 기능·동영상 모음, page_data), `guide_matcher.py`(전체),
    `organization_model.py`(get_active_by_code, insert, update), `settings/guide_match.py`(PHP 설정에서 옮김).
  - 템플릿: 소개, 가입 신청, 고객 접속, 만족도 조사, 다운로드 불가, 공개 가이드(page, 조각 10개, 테마 5개).
  - Guide_matcher: PCRE 와 파이썬의 \p{L}\p{N}·\p{Hangul} 판정이 4,388자에서 달라(PCRE Hangul 은 CJK 구두점까지 포함) PHP 에서 전체 코드 포인트를
    확인해 뽑은 구간 표(`core/data/php_pcre_unicode.json`)를 쓴다. 그리스 문자 Σ 는 PHP 8.2 처럼 글자별로 소문자화. 숫자 낱말은 PHP 배열처럼 정수로 조회.
    낱말·조각·질문·색인 결과를 PHP 원본과 913개 사례 비교 불일치 0.
  - CI3 경로 규칙: 앞뒤·겹친 `/` 무시(리다이렉트 없음), 경로 조각 허용 문자(permitted_uri_chars) 밖이면 400.
  - 검사 규칙: alpha_dash, min_length, max_length, matches 추가. CI3 흐름(required 먼저, 빈 값이면 required/matches 외 건너뜀, 배열 값은 빈 값) 그대로.
  - PHP round(0.5 는 0 에서 먼 쪽)·number_format·(string) 실수(%.14G) 대응.
  - 비교 결과: 소개(ko/en/ja), 로그인, 가입, 고객 접속·이어 가기, 만족도 조사, 공개 가이드(테마 5개 × 목록·검색·상세·새 기능·동영상·분류 거르기·[더 보기]),
    관리자 미리보기(?theme=, 꺼진 모음), 404 가 PHP 와 같음(따옴표 이스케이프 표기 &quot;/&#34; 와 JS 문자열 따옴표만 다름).
    POST: 만족도(검사 오류, 409, 정상 저장 — xss_clean 결과까지 같음), 가입(검사 오류 7종, 중복 409, 정상 저장 — 저장값 같음, 비밀번호 해시 상호 검증).
    언어 선택 쿠키와 리다이렉트, 런처 다운로드 헤더·파일 해시 같음.
  - 시험 데이터: 만족도 2건, 세션 로그 2건, 가입 조직·상담원 각 2건, 감사 로그 10건을 지웠다. 비교를 위해 `webhows` 조직의 테마·모음 설정과
    글 3개(282~284)의 새 기능 표시를 잠시 바꿨다가 저장해 둔 값(updated_at 포함)으로 되돌렸고, 조회수 3건을 원래 값으로 되돌렸다.
  - 남은 것: 이미지 업로드 검사(check_uploaded_image)와 가이드 저장·색인·가져오기는 6단계, 고객 위젯 응답은 4단계.

### 4단계. 상담 위젯 API

- `widget.js` 제공, `api/widget/{init,send,poll,contact}`.
- 같이 옮길 것: `Chat_model`, `Visitor_model`(`clean_contact`, `country_dial_codes`), `Chat_hours_model::availability`, `Chat_greeting`, `Chat_followup`, `Realtime`(발행), `Mailer`, 부재중 자동 응답·인사말 저장 순서(3.16, 3.18).
- 확인: 시험용 HTML 에 `:8098/widget.js` 를 붙여 PHP 위젯과 같은 흐름(인사말 → 첫 메시지 → 연락처 카드 → 실시간 수신 → 폴링 대체)을 확인.
- **결과(2026-10-22 완료)**
  - 경로: `routers/widget.py`(`/widget.js`, `/api/widget/{init,send,contact,poll}`). CI3 처럼 허용하지 않는 메서드도 404(405 없음).
  - 모델·라이브러리(전체 이식, 5단계 콘솔도 씀): `chat_model.py`, `visitor_model.py`, `chat_hours_model.py`(공휴일 후보만 6단계),
    `chat_greeting.py`, `chat_followup.py`, `mailer.py`, `organization_model.py` 위젯 함수, `helpers/php_filter.py`(FILTER_VALIDATE_URL).
  - FILTER_VALIDATE_URL: php-src(logical_filters.c, sanitizing_filters.c, url.c)에서 https 주소 범위를 옮김. PHP 와 5,649개 비교 불일치 0.
    가이드 외부 이미지 검사도 이것을 쓰도록 고쳤다.
  - 운영 시간 판정: 원본 Chat_hours_model 을 상속해 DB 조회만 시험값으로 바꾼 PHP 와 비교 — availability 400가지(수동 상태·복귀 시각·휴일·연말 반복 휴일·
    단축 운영·런던/뉴욕 서머타임 종료 주간 포함), 벽시계→절대 시각 364개(서머타임 시작·종료일의 없는/겹치는 시각), 시각 문구 302개, 요약 400개 모두 일치.
  - 위젯 시나리오(열기, 다시 열기, 잘못된 방문자, 빈 본문, 연락처 오류·저장·건너뛰기, 메시지 2회, 폴링, 잘못된 위젯 키)를 조직 설정 6가지
    (기본 / 응대 가능+인사말 변수·버튼+답변 속도+전화 버튼+연락처 필수 / 운영 시간 외 안내만·숨기기·받기 / 가이드 자동 추천)로 실행해 응답이 PHP 와 같음.
    가이드 자동 추천의 대화당 3번 제한도 같다. 허용 도메인(Origin) 12가지와 CORS 헤더 같음.
  - 이어 가기 토큰: PHP 와 같은 본문, 서로의 토큰을 검증하고 위젯 열기에서 같은 방문자로 이어짐.
  - 남는 차이: 키를 배열로 보내는 등 PHP 가 경고를 내는 입력에서 PHP 는 경고 HTML + 200, FastAPI 는 404/400 JSON.
    메시지 meta 안의 빈 객체 {} 를 PHP 는 [] 로 다시 내보낸다(지금 저장하는 meta 에는 빈 객체가 없다).
  - 시험 데이터: 방문자·대화 32건과 메시지를 지우고, 데모 조직 설정(31개 항목)·운영 시간, `webhows` 위젯 켜기, 관리자 접속 시각을 되돌렸다.
    시험 중 위젯 메시지가 데모·webhows 조직 방으로 실시간 발행됐다(그 시각 상담 대화 화면을 연 상담원은 받았음).
  - 확인 필요: 메일(오프라인 알림, 팔로업)은 개발 환경에 SMTP 가 없어 실제 발송을 확인하지 못했다(두 서버 모두 건너뜀). 브라우저에서 위젯 화면을
    직접 띄워 보는 확인은 하지 않았고 HTTP 응답 비교로 대신했다.

### 5단계. 상담원 콘솔

- 원격지원: 대기열/이력, 세션 생성·종료·메모·로그, 뷰어 토큰, 뷰어 화면.
- 상담 대화: 목록, 메시지, 보내기(409 conflict 포함), 닫기, 내 상태, 담당, 보는 중/입력 중(`publish_org` 만, 3.16), 알림, 원격 연결, 가이드 추천·검색·보내기.
- 매크로: `Console_macro` 전체, `Macro_model`(`owner_key`, `macro_terms`), CSV 가져오기/내보내기(수식 방지 `'`).
- **상담원 답장 저장은 `publish_new_messages()` 한 곳**(3.18)으로 모은다.
- **결과(2026-10-22 완료)**
  - 경로: `routers/console.py`(원격지원 화면·API, 상담 대화 화면·API, 가이드 추천·검색·보내기), `routers/console_macro.py`(매크로 화면·API·CSV).
  - 모델: `macro_model.py`(전체), `settings/macro.py`(PHP 설정에서 옮김). 템플릿: queue, viewer, chat, macro, _macro_edit, _widget_settings.
  - CSV: 파이썬 csv 는 PHP fgetcsv 와 1/3 이 다르게 읽어(따옴표 앞 공백, 홀로 쓴 \r 등) php-src 의 php_fgetcsv 상태 기계를 바이트 단위로 옮겼다
    (`helpers/php_csv.py`). fputcsv 는 공백·탭이 든 칸도 감싸는 PHP 규칙대로. PHP 와 8,011개 비교 불일치 0(파일 끝 따옴표 하나의 '\x00' 까지 같음).
  - 매크로 검사·변수·CSV 만들기·CSV 읽기(BOM, CP949, 깨진 바이트는 '?', 엑셀 수식 방지 따옴표)를 PHP 원본과 비교 일치.
    PHP '/\s+/u' 범위(파이썬과 U+180E, \x1C~\x1F 가 다름)를 `PCRE_U_SPACE` 로 두고 매크로·인사말 버튼 정리에 쓴다.
  - 비교: 로그인 후 화면·읽기 API 22가지 일치(위젯 설정 드로어가 있는 상담 대화 화면 포함). 쓰기 시나리오를 PHP → 정리 → FastAPI → 정리로 실행해
    원격 10단계, 상담 26단계(담당 충돌 409·함께 답하기·가져오기·대기 중·보는 중·알림·내 상태·원격 시작·종료 뒤 거부), 매크로 27단계
    (저장·중복·고치기·즐겨찾기·순서·복사·CSV 등록 오류/건너뛰기/덮어쓰기·내려받기·삭제) 응답이 같음.
  - e2e: 콘솔과 런처를 모두 FastAPI(`BASE=LAUNCHER_BASE=http://localhost:8098`)로 보내 통과. relay 는 PHP 에 인증을 물었고 FastAPI 가 발급한 토큰을 검증했다.
  - 메일: 컨테이너 안 임시 SMTP 수신기로 확인 — 팔로업 메일(조직 언어, 200자 자르기, 다시 볼 때까지 한 통, 이어 가기 토큰 검증)과
    부재 알림(대화당 한 통, 대표 관리자에게). 수신기와 시험 데이터는 지웠다.
  - 시험 데이터: 원격 세션·메모·로그·뷰어 토큰, 대화·메시지·방문자, 매크로·즐겨찾기·사용 기록, 감사 로그를 기준점 뒤 번호로 모두 지우고
    데모 상담원(접속 시각·응대 상태·알림), 기존 대화 27건의 상태와 메시지 읽음 시각을 되돌렸다.
    시험 중 담당 변경·보는 중·메시지 이벤트가 데모 조직 방으로 실시간 발행됐다.
  - 확인 필요: FastAPI(8098)로 연 원격 화면은 `/relay-viewer` 웹소켓 경유가 없어 브라우저에서 화면을 받을 수 없다(e2e 는 relay 에 직접 붙어 통과).
    전환 때 Apache 가 앞에 서면(9단계 (가)) 지금처럼 Apache 가 경유한다. https 로 열 때의 `/realtime-ws` 도 같다.

### 6단계. 조직 관리자 화면

- 조직 관리 통계(`/admin`), 상담원 관리, 조직 정보(로고 업로드), 상담 위젯 설정 드로어, 운영 시간·휴일.
- 가이드 관리(`Admin_guide`): 편집, 미리보기, guide.json 내려받기/올리기, 이미지 업로드, 색인 다시 만들기, 외부 이미지 저장(`fetch_external_image` 의 SSRF 방어 그대로), 샘플 10개 생성.
- `Guide_matcher` 는 낱말·조사 처리가 미묘하므로 **같은 입력으로 PHP 와 Python 결과를 비교**해 일치시킨다(색인 `guide_terms`, 추천 점수).

### 7단계. 플랫폼 운영자

- 조직 가입 관리, 감사 로그.

### 8단계. 비교 검증

- 주요 API 를 PHP(:8099)와 FastAPI(:8098)에 같은 입력으로 보내 응답(상태 코드, `result`, `message`, `data` 키 구조)을 비교하는 스크립트.
  테스트 코드는 요청 시에만 작성하는 규칙이 있으므로 이 스크립트를 만들지는 진행 전에 확인받는다(8장).
- 화면은 PHP 와 FastAPI 를 나란히 열어 메뉴·문구·날짜 표시·그리드·드로어·모달을 직접 비교.
- `check-conventions.py` 를 Python 코드에도 적용되게 고친다(아래 7장).
- 비교에 실제 데이터를 썼다면 원래 값으로 되돌리고 보고한다.

### 9단계. 전환

1. 전환 시각을 정하고 상담원에게 다시 로그인이 필요함을 알린다.
2. 트래픽 경로 변경(둘 중 하나, 8장에서 결정):
   - (가) `web`(Apache)을 프록시 전용으로 바꿔 모든 요청을 `api:8000` 으로 넘긴다. 웹소켓 경유(`/realtime-ws`, `/relay-viewer`), `mod_remoteip`, http→https 전환을 그대로 쓸 수 있어 바꿀 것이 가장 적다.
   - (나) cloudflared 대상과 8099 포트를 `api` 로 직접 돌리고, 웹소켓 경유와 IP 복원을 FastAPI/uvicorn 쪽에서 새로 맡는다.
3. relay·realtime-msg 의 `PORTAL_ORIGIN` 을 `http://api:8000` 으로 바꾼다.
4. `e2e-test.sh`, `check-conventions.sh --live`, `https://help.wani.im` 외부 접속, 런처 실기 확인(윈도우, 별도 표시).
5. **되돌리기**: 2·3번 설정만 원래대로 돌리면 PHP 로 복귀한다. 이 기간에 DB 스키마를 바꾸지 않는다.

### 10단계. PHP 제거 (전환 후 문제없음을 확인한 뒤, 따로 승인받아 진행)

- `portal/application`, `portal/system`, `portal/index.php`, `.htaccess`, `docker/web/php.ini` 제거. `portal/assets/` 는 남긴다(폴더 위치 정리는 별도 판단).
- `docker/web/Dockerfile` 을 PHP 없는 Apache(프록시 전용)로 바꾸거나 (나) 방식이면 `web` 서비스 제거.
- CLAUDE.md 2장 구조 표, 3장 규칙의 PHP 함수 이름, 4장 검증, 6장 결정 표를 Python 기준으로 고친다.

---

## 7. 규칙 검사(`check-conventions.py`)와 훅

- 현재 검사는 PHP 파일 경로와 함수 이름을 직접 찾는다. 전환 기간에는 **PHP 검사는 그대로 두고 Python 검사를 나란히 추가**한다
  (예: `has_available_agent()` 직접 호출, `publish_conversation()` 에 담당자 이름, `macros` 직접 수정, `(:any)` 라우트 순서, iframe 한 곳 등).
- 훅(`scripts/hooks/post-edit-check.py`)의 대상 폴더에 `backend/` 를 추가한다.
- 검사를 추가할 때마다 일부러 어겨 보고 잡히는지 확인한다(CLAUDE.md 7장).
- 모든 Python 파일 맨 위에 위치·역할 주석을 단다(docstring 형식).

```python
"""
파일 위치: backend/app/models/guide_model.py
역할: 가이드 글 저장, 검사, 색인, 공개 화면 데이터
"""
```

---

## 8. 확인 필요 사항 (2026-10-22 제안대로 결정)

| 번호 | 질문 | 제안 |
|---|---|---|
| 1 | 새 코드 폴더 이름 | `backend/` (기존 `server/` 는 설치 스크립트라 피함) |
| 2 | 화면 렌더링 방식 | **Jinja2 서버 렌더링**으로 PHP 뷰를 그대로 옮김. 화면을 SPA 로 바꾸는 것은 범위가 훨씬 커지므로 이번 전환에서 하지 않음 |
| 3 | DB 접근 방식 | SQLAlchemy Core(동기). 비동기(aiomysql)는 이점 대비 옮기는 비용이 큼 |
| 4 | 전환 경로 | (가) Apache 프록시 유지. 웹소켓·IP 복원·https 전환 설정을 그대로 재사용 |
| 5 | 전환을 한 번에 할지, 경로별로 나눌지 | **한 번에**. 경로별로 나누면 PHP 와 세션·CSRF 를 공유해야 해서 5.2 의 위험이 생김. 단, 2단계(서버 간 API)는 세션이 없으므로 먼저 따로 전환해도 됨 |
| 6 | 비교 검증 스크립트(8단계) 작성 여부 | 작성 권장(응답 형식 회귀를 사람이 다 볼 수 없음) |
| 7 | 운영 배포(Store 출시 일정)와의 순서 | 전환은 시험 서버에서 먼저, 운영 배포 전에 끝낼지 결정 필요 |
| 8 | 진행 중인 PHP 변경분 | 현재 작업 트리에 커밋되지 않은 PHP 변경이 많음. 옮기기 전에 커밋해 기준점을 고정하길 권장. 전환 기간에 PHP 에 기능을 더하면 Python 에도 같이 넣어야 함 |

---

## 9. 위험과 대응

| 위험 | 대응 |
|---|---|
| 전환 기간 중 PHP 에 새 기능이 들어가 두 벌을 고쳐야 함 | 전환 기간에는 새 기능을 미루거나 FastAPI 에만 넣고 전환을 앞당김 |
| `Guide_matcher`, `Macro_model` 추천 점수가 미묘하게 달라짐 | 같은 입력 비교로 일치 확인(6단계) |
| PHP `json_encode` / 문자열 함수(`mb_*`)와 Python 의 차이로 서명·잘라내기 결과가 달라짐 | 토큰 비교(5.4), 글자 수 제한(소개 500자 등)은 문자 단위로 확인 |
| 같은 호스트 다른 포트에서 쿠키 충돌 | 쿠키 이름 분리(5.2, 5.3) |
| 업로드 폴더 권한(www-data) | `api` 컨테이너 실행 사용자와 폴더 소유자를 맞춤 |
| 날짜가 통째로 밀림 | DB 세션 `+00:00` 고정, Python 에서 순수 UTC 만 사용 |
| 런처는 이 환경에서 빌드 불가 | 런처 API 는 e2e 와 agentsim 으로 확인하고, 실기 확인은 별도 표시 |
