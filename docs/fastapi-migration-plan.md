# 포털 서버 FastAPI 전환 기록

> 파일 위치: docs/fastapi-migration-plan.md
> 역할: CodeIgniter 3(PHP) 포털을 FastAPI(Python)로 옮긴 계획·결정·검증 기록

- 작성일: 2026-10-22
- 상태: **완료**(2026-10-22). 0~10단계를 마치고 시험 서버를 FastAPI 로 전환한 뒤 PHP 를 지웠다.
  지금의 작성 규칙은 `CLAUDE.md`(특히 3.19) 를 따르고, 이 문서는 왜 그렇게 만들었는지 확인하는 기록으로 남긴다.

---

## 0. 요약

| 항목 | 내용 |
|---|---|
| 무엇을 | `portal/` 의 CodeIgniter 3 서버 코드(컨트롤러 14, 모델 9, 뷰 53, 경로 약 70개)를 FastAPI 로 다시 작성 |
| 그대로 둔 것 | 화면 JS/CSS/사전/아이콘(주소와 요청·응답 형식 포함), DB 스키마, 중계 서버, 상담 실시간 서버, 런처 |
| 방식 | PHP 와 나란히 띄워(같은 DB, 같은 환경변수) 단계마다 같은 요청을 두 서버에 보내 비교하고, 한 번에 전환한 뒤 PHP 를 지움 |
| 지금 구조 | `web`(PHP 없는 Apache: `/assets`, 웹소켓 경유, 고객 IP, http→https) → `api`(FastAPI) → MySQL (3장) |
| 비교 규모 | xss_clean 3만4천여 입력, 이메일·URL·CSV·이미지 판별·주소 해석·가이드 검사 등 PHP 원본과 수만 건 비교 불일치 0, 화면·API 쓰기 시나리오 182단계(5단계 63, 6단계 97, 7단계 22) |
| 커밋 | 기준점 `cfc8070` → 0단계 `231d698` … 9단계(전환) `c487e5f`, 10단계(PHP 제거) `8db4000`, 폴더 정리 `0e8995a` |
| 되돌리기 | 설정 하나로 PHP 로 돌아가던 장치(`PORTAL_BACKEND`)는 PHP 와 함께 없앴다. 지금은 `8db4000` 이전으로 git 되돌리기 + web 이미지 다시 빌드 |
| 남은 일 | 런처 실기 확인, 화면 눈 확인, 운영 서버 반영 순서 등(11장) |

---

## 1. 목표와 원칙

**목표**: `portal/` 의 서버 코드(CodeIgniter 3)를 FastAPI 로 다시 작성한다. 화면(JS/CSS/사전/아이콘)과 DB, 중계 서버(`relay/`),
상담 실시간 서버(`realtime/`), 런처는 그대로 둔다.

**원칙**(지킨 결과는 6장)

1. **PHP 는 지우지 않고 나란히 띄운다.** 새 컨테이너 `api` 를 따로 올려 같은 DB 를 쓰게 하고, 기능을 다 옮겨 비교가 끝난 뒤에 전환한다.
   PHP 제거는 마지막 단계(10단계)에서 따로 승인받아 했다.
2. **프런트엔드는 고치지 않는 것이 기본이다.** 주소, 요청 형식(`csrf_test_name` 포함), 응답 형식(`{ result, message, data }`),
   화면 전역 변수(`XXX_URLS`, `i18n`)를 PHP 와 똑같이 맞춘다. 전환 뒤에 고친 화면 JS 는 상담 위젯의 실시간 재접속(eeb0140, PHP 쪽도 함께 고침) 하나다.
3. **DB 스키마는 바꾸지 않는다.** 전환 기간에는 PHP 와 FastAPI 가 같은 DB 를 함께 쓰므로 스키마를 바꾸면 한쪽이 깨진다. 실제로 바꾸지 않았다.
4. **CLAUDE.md 3장의 규칙은 그대로 옮긴다.** 규칙이 "어느 함수 한 곳을 거친다" 로 정의되어 있으므로
   Python 에서도 같은 이름·같은 책임의 함수 한 곳으로 만든다(예: `Guide_model::save_article()` → `guide_model.save_article()`).
5. **서명 토큰과 비밀번호는 바이트 단위로 호환**시킨다. 전환 순간 로그인·위젯 토큰·이어 가기 메일 주소가 끊기면 안 된다.
   (상담원 로그인 세션만은 일부러 공유하지 않아 전환 때 한 번 다시 로그인한다, 5.2)

---

## 2. 옮기기 전 규모 (2026-10-22, 기준점 cfc8070)

| 구분 | 파일 | 줄 수(대략) |
|---|---|---|
| 컨트롤러 | 14개 (`controllers/`, `controllers/api/`) | 4,200 |
| 모델 | 9개 | 4,900 |
| 라이브러리 | 5개 (`Realtime`, `Mailer`, `Guide_matcher`, `Chat_greeting`, `Chat_followup`) | 900 |
| 헬퍼·코어 | `app_helper`, `i18n_helper`, `MY_Controller` | 600 |
| 설정 | `guide_match`, `guide_samples`, `holidays`, `macro`, `routes` 등 | - |
| 뷰 | 53개 PHP 템플릿 | 4,200 |
| 라우트 | 약 70개 (`config/routes.php`, 마지막 `(:any)` 는 조직 코드) | - |

---

## 3. 최종 구조

```
[브라우저 / 위젯 / 런처]
        |  :8099, https://help.wani.im(cloudflared)
        v
   web (Apache, PHP 없음)  -- /assets 직접 제공, /realtime-ws·/relay-viewer 경유, mod_remoteip, http->https
        |  나머지 요청 + X-RemoteHelp-Client-IP(덮어씀)
        v
   api (FastAPI + uvicorn) ---- MySQL(db)
        |  POST /publish                ^  GET /api/realtime/auth, /api/relay/auth (http://web 을 거쳐 api)
        v                               |
   realtime-msg / relay  ---------------+
```

### 3.1 디렉터리

```
backend/
  requirements.txt         버전 고정(4장)
  assets/                  정적 파일(JS, CSS, 사전, 아이콘, 업로드). 주소는 /assets/... (예전 portal/assets)
  app/
    main.py                앱 생성, 미들웨어, 라우터 등록(조직 코드 라우터는 맨 마지막)
    config.py  db.py  static.py
    core/                  php(PHP 와 같은 결과의 기본 함수), request, input, security(xss_clean), session, csrf,
                           deps(current_agent / require_admin / require_operator), templates, i18n, errors, response, password, middleware
      data/                PHP 8.2.34 에서 뽑은 표(HTML5 엔티티, PCRE 유니코드 구간)
    helpers/               app(asset_url, customer_url, image_file_ext, check_uploaded_image ...), validation, php_filter, php_url, php_csv
    models/                organization, agent, session, chat, chat_hours, guide, macro, visitor, log
    libraries/             realtime, mailer, guide_matcher, chat_greeting, chat_followup
    settings/              guide_match, guide_samples, holidays, macro (예전 PHP config 배열)
    routers/               home, auth, customer(+org_router 맨 마지막), guide, download, widget, console, console_macro,
                           admin, admin_guide, operator, api_launcher, api_relay, api_realtime_auth, dev(개발 전용)
    templates/             PHP 뷰를 옮긴 Jinja2 템플릿(views/ 와 같은 폴더 구조)
docker/api/Dockerfile      python:3.12.14-slim-bookworm, www-data 로 실행
docker/web/Dockerfile      debian:trixie-20261005-slim + apache2 (rewrite, headers, proxy, proxy_http, proxy_wstunnel, ssl, remoteip)
docker/web/apache-vhost.conf
```

### 3.2 컨테이너

| 서비스 | 포트 | 볼륨 | 망 |
|---|---|---|---|
| `web` (remotehelp-web) | `8099:80` | `./backend/assets:/var/www/html/assets:ro`, `relay-ca`(읽기) | default, tunnel(10.250.88.2 고정) |
| `api` (remotehelp-api) | `8098:8000`(개발 직접 접속용) | `./backend/app:/app/app`(개발 중 다시 읽기), `./backend/assets:/srv/assets` | default, tunnel(10.250.88.3 고정) |

환경변수는 `docker-compose.yml` 의 `x-portal-env` 한 곳에서 api 가 받는다(전환 기간에는 web 과 함께 받았다).

---

## 4. 기술 선택 (확정)

버전은 구현 시점에 PyPI·Docker Hub 에서 확인해 배포 2주 이상 지난 판으로 고정했다(0단계 결과).

| 용도 | 선택 | 이유 |
|---|---|---|
| 웹 프레임워크 | FastAPI 0.142.4 + uvicorn 0.54.0 (`--no-proxy-headers`) | 요청 사항. 프록시 헤더는 직접 처리(5.7) |
| 템플릿 | Jinja2 3.1.6 | PHP 뷰를 서버 렌더링 그대로 옮길 수 있음. 화면 JS 를 바꾸지 않아도 됨 |
| DB | SQLAlchemy 2.1.4 Core + PyMySQL 1.2.3 (동기) | 기존 모델이 SQL 중심이라 Core 가 옮기기 쉬움. 값은 mysqli 처럼 모두 문자열로 받음 |
| 세션 | 서명 쿠키 `rh_session`(Starlette SessionMiddleware, itsdangerous) | DB 스키마를 바꾸지 않음. PHP 세션과는 공유하지 않음(5.2) |
| 비밀번호 | `bcrypt` 5.0.0 | PHP `$2y$` 해시를 그대로 검증, 새 해시도 PHP 가 읽을 수 있음(확인) |
| 폼/파일 | `python-multipart` 0.0.32 | Dropzone 업로드, CSV 가져오기 |
| HTTP 클라이언트 | `httpx`(실시간 발행), 표준 `http.client`(외부 이미지, IP 고정 접속) | |
| 메일 | 표준 `smtplib` (시간 제한 10초) | `Mailer` 와 같은 동작 |
| 시간대 | 표준 `zoneinfo` | 서머타임 경계까지 PHP DateTime 과 같은 결과 확인(4단계) |
| 이미지 판별 | libmagic 5.40 규칙 + getimagesize 판정을 직접 구현 | PHP `finfo` + `getimagesize()` 와 같은 결과(6단계) |

---

## 5. 반드시 호환시켜야 했던 것

전환 순간 끊기거나 조용히 어긋나는 항목이었습니다. 지금도 같은 동작을 지켜야 하는 이유가 되므로 남겨 둡니다(CLAUDE.md 3.19). 각 항목은 PHP 와 FastAPI 가 **같은 입력에 같은 출력**을 내는지 비교해 확인합니다.

### 5.1 응답·요청 형식

- 모든 API 는 `{ "result": bool, "message": str, "data": {} }`. 빈 `data` 는 `[]` 가 아니라 `{}`(커밋 5b3f83d 결정).
- HTTP 상태 코드도 맞춘다(401 로그인 필요 + `data.redirect`, 404, 409 `conflict` 등).
- 요청은 jQuery `$.ajax` 폼 인코딩(`application/x-www-form-urlencoded`)과 multipart 를 받는다. JSON 본문만 받게 만들지 않는다.
- AJAX 판별은 CI3 처럼 `X-Requested-With: XMLHttpRequest` 로 한다(로그인 만료 시 리다이렉트/401 갈림).

### 5.2 로그인 세션

- 세션 값: `agent_id`, `locale`, `timezone` 등(`Auth::login` 이 넣는 키를 전수 조사해 맞춘다).
- 만료 7200초, `HttpOnly`.
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
  → 전환 뒤에는 Apache(`mod_remoteip`)가 복원한 IP 를 `X-RemoteHelp-Client-IP` 에 덮어써 넘기고 FastAPI 는 web 고정 주소에서 온 것만 믿는다(9단계 결과).
- 고객에게 보내는 주소는 `customer_url()`(3.13), `PUBLIC_BASE_URL` 기준.
- 웹소켓 경로 `/realtime-ws`, `/relay-viewer` 는 전환 뒤에도 Apache 가 맡는다. FastAPI 는 주소만 만들어 준다(`Realtime::ws_url()`, `relay_viewer_ws_url()`).

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

## 6. 단계별 작업과 결과

각 단계는 **옮기기 → PHP 와 비교 → 화면 확인** 순서로 끝냈다. 단계마다 계획(앞부분)과 결과(**결과**)를 함께 남긴다. 단계가 끝날 때마다 완료/미완료/확인 필요 사항을 보고한다.

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
  - 개발 전용 `/_dev/layout/*` 은 레이아웃 비교용이다(`APP_ENV=development` 에서만 열림, 아직 남아 있음 — 남은 일 참고).
  - 확인 필요였던 xss_clean 처리는 2단계에서 (가) 그대로 옮기기로 정했다(5.9).

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
  - 확인 필요였던 것: FastAPI(8098)로 직접 연 원격 화면은 `/relay-viewer` 웹소켓 경유가 없었다. 9단계에서 Apache 를 앞에 세워 해결했다(`/realtime-ws` 도 같음).

### 6단계. 조직 관리자 화면

- 조직 관리 통계(`/admin`), 상담원 관리, 조직 정보(로고 업로드), 상담 위젯 설정 드로어, 운영 시간·휴일.
- 가이드 관리(`Admin_guide`): 편집, 미리보기, guide.json 내려받기/올리기, 이미지 업로드, 색인 다시 만들기, 외부 이미지 저장(`fetch_external_image` 의 SSRF 방어 그대로), 샘플 10개 생성.
- `Guide_matcher` 는 낱말·조사 처리가 미묘하므로 **같은 입력으로 PHP 와 Python 결과를 비교**해 일치시킨다(색인 `guide_terms`, 추천 점수).
- **결과(2026-10-22 완료)**
  - 경로: `routers/admin.py`(통계, 상담원, 조직 정보·로고, 위젯 설정 저장·키 재발급, 운영 상태, 휴일 후보·추가·삭제),
    `routers/admin_guide.py`(목록·편집·미리보기·내려받기, 글 저장·상태·삭제, 이미지, 외부 이미지 저장, 분류·동의어·테마·구성·색인·매칭 시험, 올리기).
    모두 `Depends(require_admin)`. 템플릿: admin/dashboard, agents, organization, guide, guide_edit.
  - 모델: `guide_model` 의 저장·검사·색인·이미지·샘플·guide.json 함수 전부(`import` 는 파이썬 예약어라 `import_guide`),
    `organization_model.statistics`, `chat_hours_model.holiday_candidates`. 설정: `settings/guide_samples.py`, `settings/holidays.py`(PHP 가 읽은 값을 그대로 변환).
  - 이미지 판별: PHP 는 `finfo`(PHP 에 묶인 libmagic 5.40) + `getimagesize()` 를 함께 본다. file 5.40 의 magic 원본(Magdir/images, jpeg, riff)과
    php-src 8.2.34 `ext/standard/image.c` 를 확인해 `helpers/app.py` 의 `image_file_ext()` 로 옮겼다(JPEG 표시 탐색, PNG 25바이트, WEBP VP8 덩어리,
    ff d8 로 시작하는 파일의 XBM 판정까지). 실제 이미지·잘라낸 파일·바이트를 바꾼 파일·위장 파일 4,929개 비교 불일치 0.
  - `parse_url()` 은 php-src `php_url_parse_ex2` 를 그대로 옮겼다(`helpers/php_url.py`, `parse_str` 포함). `json_decode` 대응 `php_json_decode`
    (깊이 511단, 짝 없는 서로게이트·NaN 거부, int64 초과는 실수), `php_json_encode(pretty=True)`(JSON_PRETTY_PRINT) 를 더했다.
  - 검사 비교: `clean_article`(제목·주소·분류·블록·키워드·새 기능 날짜, 서머타임 시간대 포함), `youtube_id`, `parse_url` 을 무작위 입력
    9,000 + 12,000 줄로 PHP 와 비교 불일치 0(PHP 가 예외를 내는 줄은 아래 차이로 따로 셈).
  - 화면·API 비교: 읽기 16가지, 쓰기 시나리오 97단계(상담원·조직·로고·위젯 설정·운영 상태·휴일·가이드 글·이미지·외부 이미지 저장·분류·동의어·테마·구성·
    색인·가져오기 병합/교체·내려받기·샘플 생성)를 PHP → 복원 → FastAPI → 복원으로 실행해 응답이 같음(JS 안 주소 따옴표 표기만 다름).
    실행 뒤 DB 상태(조직 53열, 상담원, 가이드 표, 운영 시간·휴일), 감사 로그 38건, 올린 파일 12개 내용까지 같음.
  - 외부 이미지 저장은 실제로 받아 확인했다(구글 로고 PNG 저장 성공, favicon.ico 는 형식 거부, localhost·127.0.0.1·10.x·169.254.x 는 접속 전 거부,
    리다이렉트를 따라가 다시 검사, 만료 인증서 거부). 확인한 IP 로 고정 접속하고 인증서와 SNI 는 원래 호스트 이름으로 검사한다(`_PinnedHTTPSConnection`).
  - PHP 버그 수정: `Guide_model::localize_external_images()` 가 `save_article()` 에 새 기능 표시를 넘기지 않아, 외부 이미지를 저장하면 그 글의
    "새 기능" 표시·출시일·버전이 지워졌다. PHP 와 FastAPI 모두 넘기도록 고쳤다.
  - FastAPI 쪽 수정: `guide_model.org_timezone()` 이 시간대를 프로세스 전역에 기억해 조직 정보에서 시간대를 바꿔도 예전 값을 썼다(PHP 는 요청마다). 매번 읽는다.
    `format_duration()` 이 평균 시간(소수 문자열)에서 오류가 나던 것을 PHP `(int)` 와 같게 고쳤다.
  - 남은 차이(PHP 가 오류를 내거나 쓸 수 없는 값):
    `watch?v[]=` 처럼 v 가 배열이면 PHP 는 TypeError(500), FastAPI 는 "알아보지 못했습니다".
    출시일 0000 년은 PHP 가 받지만(DATETIME 범위 밖) FastAPI 는 형식 오류. 외부 이미지 접속 실패 문구는 curl 대신 파이썬 오류 문구.
    8MB(PHP post_max_size) 를 넘는 요청은 PHP 가 본문을 버려 CSRF 403, FastAPI 는 크기 오류 문구. ff d8 로 시작하는 AVIF 겸용 파일은 FastAPI 만 거부.
  - 시험 데이터: 데모 조직(1)의 조직 행, 상담원, 가이드 표, 운영 시간·휴일, 감사 로그, 올린 파일을 시작 전 상태로 되돌렸고(스냅샷과 비교해 같음),
    자동 증가 번호도 맞춰 두었다. 웹하우스(9) 조직은 건드리지 않았다. 외부 이미지 시험으로 google.com, upload.wikimedia.org 에 GET 요청을 보냈다.

### 7단계. 플랫폼 운영자

- 조직 가입 관리, 감사 로그.
- **결과(2026-10-22 완료)**
  - 경로: `routers/operator.py`(조직 가입 관리 화면·상태 거르기, 감사 로그 1,000건, 조직 정보 수정·승인·반려·중지·다시 활성화). 모두 `Depends(require_operator)`,
    레이아웃 `layouts/operator`. 템플릿: operator/organizations, operator/audit. 모델: `organization_model` 의 list_for_review·count_by_status·approve·set_status.
  - 비교: 운영자로 읽기 6가지, 조직 관리자로 들어왔을 때의 거부 3가지(화면 403 오류 페이지, AJAX 403, `operator_access_denied` 감사 기록)가 같음.
    쓰기 시나리오 22단계(시험 조직 2개를 SQL 로 만들어 반려·승인·중지·운영 조직 중지 거부·다시 활성화·정보 수정·주소 중복 409·허용 목록 밖 값)를
    PHP → 복원 → FastAPI → 복원으로 실행해 응답(JS 안 주소 따옴표 표기만 다름), 조직·상담원 행, 감사 로그 9건이 같음.
  - 이로써 PHP routes.php 의 모든 경로가 FastAPI 에 있다. 로그인 없이 42개 주소를 두 서버에 요청해 상태 코드가 모두 같음.
  - 검사: `check_fastapi_admin` 에 운영자 경로의 `require_operator` 확인을 더했다(일부러 빼 보고 잡히는 것 확인).
  - 시험 데이터: 시험 조직·상담원·감사 로그를 지우고 자동 증가 번호를 맞췄다. 읽기 시험 로그인으로 바뀐 데모 관리자·운영자의 마지막 로그인 시각은
    시험 전 마지막 로그인 감사 기록의 시각으로 되돌렸다(데모 관리자는 6단계 스냅샷 값과 같음을 확인). 실제 조직(wani, webhows)은 건드리지 않았다.

### 8단계. 비교 검증

- 주요 API 를 PHP(:8099)와 FastAPI(:8098)에 같은 입력으로 보내 응답(상태 코드, `result`, `message`, `data` 키 구조)을 비교하는 스크립트.
  테스트 코드는 요청 시에만 작성하는 규칙이 있으므로 이 스크립트를 만들지는 진행 전에 확인받는다(8장).
- 화면은 PHP 와 FastAPI 를 나란히 열어 메뉴·문구·날짜 표시·그리드·드로어·모달을 직접 비교.
- `check-conventions.py` 를 Python 코드에도 적용되게 고친다(아래 7장).
- 비교에 실제 데이터를 썼다면 원래 값으로 되돌리고 보고한다.
- **결과(2026-10-22 완료)**
  - `scripts/compare-portals.py`(8장 6번 결정, PHP 를 지운 10단계에서 함께 지웠다): 방문자 22 / 조직 관리자 34 / 플랫폼 운영자 7, 모두 63가지 화면·읽기 API·내려받기를 두 서버에 보내
    상태 코드, 본문, Location, 내려받기 헤더를 비교한다. 매번 바뀌는 값과 JSON 표기 차이('\/', JS 주소 따옴표)는 맞춘 뒤 비교한다.
    로그인·콘솔 목록·대화 열기·내려받기가 남기는 기록(마지막 로그인/접속 시각, 읽음 처리, 감사 로그)은 api 컨테이너에서 저장했다가 되돌린다.
    결과 63/63 같음. 템플릿 문구 하나를 일부러 바꿔 다름 1건으로 잡히는 것과, 끝난 뒤 기록이 비교 전 값과 같은 것을 확인했다.
  - 쓰기 비교는 단계마다 PHP → 복원 → FastAPI → 복원 시나리오로 했다(5단계 63단계, 6단계 97단계, 7단계 22단계). 데이터를 바꾸므로 저장소 스크립트에는 넣지 않았다.
  - e2e: 콘솔·런처를 모두 FastAPI 로 보내(`BASE=LAUNCHER_BASE=http://localhost:8098`) 다시 통과. 만든 세션·토큰·로그·감사 기록은 지우고 번호를 맞췄다.
  - 정적 자산: `portal/assets` 의 파일을 두 서버에서 받아 내용이 모두 같음. 헤더 차이는 JS·CSS 의 `charset=utf-8`(Starlette 가 붙임),
    .exe 형식 이름(`application/x-msdos-program` / `application/octet-stream`), 막은 파일(.htaccess, downloads/README.md)의 403 오류 본문 형식,
    폴더 주소가 Apache 403 / FastAPI 404(둘 다 목록을 보이지 않음). 전환 때 Apache 가 `/assets` 를 직접 내주면(9단계) 차이가 없어진다.
  - 화면을 나란히 여는 눈 확인은 사람이 할 일로 남긴다. HTML 은 위 비교에서 같으므로 차이가 난다면 브라우저 JS 실행 경로(실시간 웹소켓 등)다.
  - 시험 데이터: 6~7단계 끝의 경로 점검이 남긴 다운로드 감사 기록 4건을 지웠다. 비교와 e2e 기록도 모두 되돌려 감사 로그는 398번까지다.

### 9단계. 전환

1. 전환 시각을 정하고 상담원에게 다시 로그인이 필요함을 알린다.
2. 트래픽 경로 변경(둘 중 하나, 8장에서 결정):
   - (가) `web`(Apache)을 프록시 전용으로 바꿔 모든 요청을 `api:8000` 으로 넘긴다. 웹소켓 경유(`/realtime-ws`, `/relay-viewer`), `mod_remoteip`, http→https 전환을 그대로 쓸 수 있어 바꿀 것이 가장 적다.
   - (나) cloudflared 대상과 8099 포트를 `api` 로 직접 돌리고, 웹소켓 경유와 IP 복원을 FastAPI/uvicorn 쪽에서 새로 맡는다.
3. relay·realtime-msg 의 `PORTAL_ORIGIN` 을 `http://api:8000` 으로 바꾼다.
4. `e2e-test.sh`, `check-conventions.sh --live`, `https://help.wani.im` 외부 접속, 런처 실기 확인(윈도우, 별도 표시).
5. **되돌리기**: 2·3번 설정만 원래대로 돌리면 PHP 로 복귀한다. 이 기간에 DB 스키마를 바꾸지 않는다.
- **결과(2026-10-22 시험 서버 전환 완료, (가) 방식)**
  - `apache-vhost.conf` 가 `Include /etc/apache2/remotehelp/backend-${PORTAL_BACKEND}.conf` 로 처리 서버를 고른다(Apache `${VAR}` 는 기동 전 환경변수로 바뀜,
    공식 문서 configuring.html 확인). `backend-fastapi.conf`: `/assets`·`/realtime-ws`·`/relay-viewer` 는 `ProxyPass "!"` 로 빼고(첫 규칙이 이김, mod_proxy 문서),
    나머지를 `http://10.250.88.3:8000/`(api tunnel 고정 주소)로 넘긴다. `ProxyPreserveHost On`, `ProxyAddHeaders Off`, `ProxyTimeout 600`.
    `backend-php.conf` 는 비어 있어 예전처럼 PHP 가 처리한다. compose 기본값은 php, 시험 서버 `.env` 는 `PORTAL_BACKEND=fastapi`.
    (이 선택 장치는 10단계에서 없애고 프록시 설정을 `apache-vhost.conf` 에 바로 넣었다.)
  - 고객 IP: Apache 가 mod_remoteip 로 복원한 `REMOTE_ADDR` 를 `RequestHeader set X-RemoteHelp-Client-IP "expr=%{REMOTE_ADDR}"` 로 덮어써 넘기고,
    FastAPI 는 web tunnel 고정 주소(10.250.88.2)에서 온 이 머리글만 믿는다. web·api 에 tunnel 고정 주소를 줬다.
    확인: help.wani.im 으로 보낸 요청은 실제 공인 IP 로 기록되고 꾸민 머리글은 덮어써짐, 8099·8098 직접 요청은 꾸민 머리글을 무시하고 게이트웨이 주소.
  - 3번(relay·realtime-msg 의 `PORTAL_ORIGIN`)은 바꾸지 않았다. 계속 `http://web` 을 부르고 Apache 가 넘기므로 되돌리기가 `PORTAL_BACKEND` 하나로 끝난다.
  - 4번 확인: 8099·help.wani.im 의 화면과 healthz 가 FastAPI 로 응답(쿠키 `rh_csrf`), `/assets` 는 Apache, http→https 전환 유지,
    e2e(기본 주소 8099, relay 인증도 web 경유 FastAPI) 통과, 상담 실시간 웹소켓을 로컬(ws://localhost:8091)과 외부(wss://help.wani.im/realtime-ws) 모두
    연결해 `ready`(org:1) 수신, `check-conventions.sh --live` 통과.
  - 5번 되돌리기 시험: `PORTAL_BACKEND=php` 로 web 을 다시 만들자 8099·외부 모두 PHP(`ci_session`)로 응답, 다시 fastapi 로 돌려 FastAPI 응답 확인.
  - 검사: `TRUSTED_PROXY_IP` = compose 의 web tunnel 주소, ProxyPass 대상 = api tunnel 주소, 고객 IP 머리글은 `set`, X-Forwarded-For 미사용,
    세 경로 제외를 확인한다(일부러 어겨 4가지가 잡히는 것 확인).
  - 시험 데이터: 검증 로그인·IP 시험 실패 로그인·e2e 세션과 기록을 지우고 상담원 값과 번호를 되돌렸다(감사 로그 398번까지).
  - 남은 일은 맨 아래 '11. 남은 일' 로 모았다.

### 10단계. PHP 제거 (따로 승인받아 진행)

- `portal/application`, `portal/system`, `portal/index.php`, `.htaccess`, `docker/web/php.ini` 제거. `portal/assets/` 는 남긴다(폴더 위치 정리는 별도 판단).
- `docker/web/Dockerfile` 을 PHP 없는 Apache(프록시 전용)로 바꾸거나 (나) 방식이면 `web` 서비스 제거.
- CLAUDE.md 2장 구조 표, 3장 규칙의 PHP 함수 이름, 4장 검증, 6장 결정 표를 Python 기준으로 고친다.
- **결과(2026-10-22 완료)**
  - 지운 것: `portal/application`, `portal/system`, `portal/index.php`, `portal/.htaccess` 와 CI3 부속 파일(composer.json, license, readme, .editorconfig, .gitignore),
    `docker/web/php.ini`, `backend-php.conf`/`backend-fastapi.conf`(처리 서버 선택), `scripts/compare-portals.py`(PHP 비교용). `portal/assets/` 는 남겼다.
    지운 코드는 git 기록(eeb0140 이전)에 있다.
  - web 이미지: `debian:trixie-20261005-slim`(Docker Hub 태그 확인, 기존 php:8.2-apache 와 같은 Debian 13) + apt `apache2`, 모듈 rewrite·headers·proxy·
    proxy_http·proxy_wstunnel·ssl·remoteip. `apache2ctl -D FOREGROUND`. `portal/assets` 만 `/var/www/html/assets` 로 읽기 전용으로 붙이고,
    프록시 설정을 `apache-vhost.conf` 에 바로 넣었다(`PORTAL_BACKEND` 없앰). 루트 `.htaccess` 가 막던 ini/log/sql/md 는 vhost 의 `FilesMatch` 로 옮겼다.
  - Tailwind: `@import "tailwindcss" source(none)` + `@source "../../../backend/app"`(데이터 폴더 제외) + `@source "../js"`(v4 문서 detecting-classes 확인).
    PHP 뷰가 있을 때 결과와 비교해 빠진 선택자 12개는 템플릿·JS 어디서도 클래스로 쓰지 않던 것(설정 파일 글자 등 자동 탐색이 잘못 잡은 것)임을 확인했다.
  - 규칙 검사(`check-conventions.py`)를 백엔드 기준으로 다시 썼다. PHP 파일을 보던 검사는 같은 규칙을 파이썬·템플릿에 적용하고,
    PHP 와 같아야 하던 대조(설정·쿠키 이름)와 CI3 전용 규칙(컨트롤러·라이브러리 이름 충돌)은 뺐다. 그리드·Dropzone 플래그 검사는
    템플릿이 include 하는 템플릿과 싣는 JS 까지 보도록 넓혔다. 일부러 어긴 9가지가 모두 잡히는 것을 확인했다.
  - CLAUDE.md 를 파이썬 이름 기준으로 고쳤다(2·3·4·5·6장, 3.11 CI3 이름 규칙 삭제, 3.19 를 백엔드 작성 규칙으로). README 도 고쳤다.
    백엔드 파일 머리 주석의 "PHP 무엇 대응" 표기는 지우고, 동작이 같아야 하는 이유를 설명하는 주석(php_int, php_csv 등)은 남겼다.
  - 확인: 8099·help.wani.im 화면과 healthz, 정적 파일, 막은 파일(403), http->https, `/realtime-ws`·`/relay-viewer` 웹소켓 핸드셰이크(101),
    e2e 통과, `check-conventions.sh --live` 통과. e2e 가 만든 세션·기록은 지우고 번호와 상담원 값을 되돌렸다.
  - 뒤이은 정리: 정적 파일 폴더를 `portal/assets` 에서 `backend/assets` 로 옮겼다(0e8995a, 주소 `/assets/...` 와 DB 의 경로는 그대로,
    `guide_model.portal_file()` → `asset_file()`). 설계 문서의 PHP 경로·이름도 FastAPI 기준으로 고쳤다(ea1935c, 5ddfa9d).
    이 문서의 1~10단계 기록에 나오는 `portal/assets` 는 그때의 경로다.

---

---

## 7. 규칙 검사(`check-conventions.py`)와 훅

- 전환 기간에는 PHP 검사를 그대로 두고 Python 검사(`check_fastapi*`)를 나란히 더했다. PHP 와 같아야 하는 목록(설정·아이콘·테마·CSRF 제외 경로)도 대조했다.
- 10단계에서 백엔드 기준으로 다시 썼다. PHP 파일을 보던 규칙은 같은 내용을 파이썬·템플릿에 적용하고, 전환 전용 대조와 CI3 전용 규칙은 뺐다.
- 훅(`scripts/hooks/post-edit-check.py`)은 `backend/` 를 포함해 감시한다. 검사를 더할 때마다 일부러 어겨 잡히는지 확인했다(CLAUDE.md 7장).
- 모든 Python 파일과 템플릿 맨 위에 위치·역할 주석을 단다.

---

## 8. 결정 사항 (2026-10-22, 제안대로 결정)

| 번호 | 질문 | 결정 | 결과 |
|---|---|---|---|
| 1 | 새 코드 폴더 이름 | `backend/` (기존 `server/` 는 설치 스크립트라 피함) | 그대로. 정적 파일도 `backend/assets` 로 옮김 |
| 2 | 화면 렌더링 방식 | **Jinja2 서버 렌더링**으로 PHP 뷰를 그대로 옮김(SPA 로 바꾸지 않음) | 그대로 |
| 3 | DB 접근 방식 | SQLAlchemy Core(동기) | 그대로 |
| 4 | 전환 경로 | (가) Apache 프록시 유지 | 9단계에서 적용, 10단계에서 PHP 없는 Apache 로 |
| 5 | 한 번에 / 경로별 | **한 번에**(세션·CSRF 를 PHP 와 공유하지 않기 위해) | 9단계에서 한 번에 전환 |
| 6 | 비교 검증 스크립트 | 작성 | 8단계 `compare-portals.py`(10단계에서 지움), 쓰기 비교는 단계별 시나리오 |
| 7 | 운영 배포(Store 출시)와의 순서 | 시험 서버에서 먼저 | 시험 서버 전환 완료. **운영 서버 반영 시점은 아직 정하지 않음**(11장) |
| 8 | 진행 중인 PHP 변경분 | 옮기기 전에 커밋해 기준점 고정 | 기준점 `cfc8070` |

---

## 9. 위험과 대응

| 위험 | 대응 | 결과 |
|---|---|---|
| 전환 기간 중 PHP 에 새 기능이 들어가 두 벌을 고쳐야 함 | 새 기능을 미루고 전환을 앞당김 | 하루 안에 끝냈다. 두 벌을 함께 고친 것은 버그 수정 두 건(외부 이미지 저장, 위젯 재접속) |
| `Guide_matcher`, `Macro_model` 추천 점수가 미묘하게 달라짐 | 같은 입력 비교 | 불일치 0(3·5단계). 기존 색인을 다시 만들 필요 없음 |
| `json_encode` / 문자열 함수 차이로 서명·잘라내기 결과가 달라짐 | 토큰 상호 검증, 바이트 비교 | 일치(2·4단계) |
| 같은 호스트 다른 포트에서 쿠키 충돌 | 쿠키 이름 분리(`rh_session`, `rh_csrf`) | 충돌 없음 |
| 업로드 폴더 권한(www-data) | `api` 를 www-data 로 실행 | 쓰기 확인(6단계, 폴더 이동 뒤 다시 확인) |
| 날짜가 통째로 밀림 | DB 세션 `+00:00`, 순수 UTC | 일치 |
| 런처는 이 환경에서 빌드 불가 | e2e 와 agentsim 으로 확인 | e2e 통과. **실기 확인은 남음** |

---

## 10. 전환 뒤 바뀐 동작

PHP 와 일부러 다르게 둔 것, 또는 PHP 가 오류를 내던 자리에서 FastAPI 가 다르게 답하는 것입니다(자세한 것은 각 단계의 '남는 차이').

- 상담원은 전환 때 한 번 다시 로그인한다(세션 쿠키 `ci_session` → `rh_session`).
- PHP 가 경고 HTML 이나 500 을 내던 잘못된 입력(배열 값 등)에 FastAPI 는 400/404 JSON 으로 답한다.
- 8MB 를 넘는 요청: PHP 는 본문을 버려 CSRF 403, FastAPI 는 크기 오류 문구.
- PHP 쪽에서 찾은 버그 두 가지는 전환 전에 PHP 와 FastAPI 모두 고쳤다: 외부 이미지 저장 때 새 기능 표시가 지워지던 것(6단계),
  위젯이 만료 토큰으로 3초마다 다시 붙던 것(eeb0140).

---

## 11. 남은 일

| 할 일 | 내용 |
|---|---|
| 런처 실기 확인 | 윈도우에서 시험 빌드로 코드 검증·원격 화면·종료까지(8099 의 `/api/launcher/*` 가 FastAPI 로 감). 이 환경에서는 빌드할 수 없음 |
| 화면 눈 확인 | 콘솔·관리·고객·공개 가이드를 브라우저로 열어 메뉴·드로어·모달·실시간 표시 확인(HTML 은 비교로 같음을 확인). 위젯을 2시간 넘게 열어 둔 뒤 실시간 재접속 |
| 상담원 안내 | 다시 로그인 필요 |
| 운영 서버 반영 | 운영 배포(Store 출시) 전에 같은 구조로 올릴지와 시점(8장 7번). 운영은 공인 인증서와 `RELAY_VIEWER_WS_URL=wss://<도메인>/viewer` |
| 정리 | 개발 전용 `/_dev/layout/*`(routers/dev.py, templates/_dev) 를 지울지, 8098 직접 접속 포트를 운영에서 닫을지 |
