<!--
  파일 위치: docs/guide-ai-api-plan.md
  역할: 외부 AI 가 접근 키로 조직 가이드(guide.json)를 읽고 고치는 API 의 기획서
-->

# 가이드 AI 연결 API — 기획서

조직 관리자가 **접근 키**를 발급해 AI(Claude, ChatGPT, 사내 스크립트 등)에게 주면,
AI 가 `https://help.wani.im/admin/guide` 의 가이드를 직접 읽고 고칠 수 있게 하는 기능입니다.

- 글 내용을 읽고 고친다 (guide.json 전체 또는 글 하나 단위)
- AI 가 만든 이미지를 올리고 블록의 이미지 주소를 바꾼다
- 유튜브 영상 주소를 바꾸거나 새로 넣는다

> 이 문서는 기획 단계입니다. 아직 구현하지 않았습니다. 11장의 확인 필요 사항을 정한 뒤 착수합니다.

---

## 1. 왜 화면이 아니라 API 인가

지금 `/admin/guide` 는 **로그인 세션 + CSRF 토큰**으로 보호되는 화면입니다. AI 는 로그인 화면을 거칠 수 없고,
관리자 비밀번호를 AI 에게 주는 것은 위험합니다(조직 전체 권한, 폐기 불가, 누가 고쳤는지 구분 불가).

그래서 **가이드에만 쓸 수 있는 별도 키**와 **키로만 들어오는 API 경로**를 따로 둡니다.

| 비교 | 관리자 계정을 주는 방식 | 접근 키 방식(이 기획) |
|---|---|---|
| 범위 | 조직 전체(상담원, 조직 정보, 원격지원) | 가이드만, 그 안에서도 읽기/쓰기/공개 분리 |
| 폐기 | 비밀번호 변경 → 사람도 영향 | 키 하나만 폐기 |
| 기록 | 사람이 한 것과 구분 안 됨 | `audit_logs` 에 어떤 키가 했는지 남음 |
| 만료 | 없음 | 기본 30일 |

---

## 2. 전체 흐름

```
[조직 관리자]  /admin/guide > 설정 > AI 연결
     |  키 발급 (한 번만 보여줌)  +  "AI 에게 줄 안내문" 복사
     v
[AI]  Authorization: Bearer rhg_xxxxxxxx...
     |
     |  1. GET  /api/guide/v1/guide.json          현재 가이드 받기
     |  2. POST /api/guide/v1/images               만든 이미지 올리기 -> src 받음
     |  3. PATCH /api/guide/v1/articles/{slug}     이미지 src / 유튜브 주소 교체
     |     (또는 PUT /api/guide/v1/guide.json 으로 통째로 올리기)
     v
[포털]  키 검증 -> 범위 검사 -> Guide_model::clean_article() -> save_article() -> 색인 재생성 -> audit_logs
```

**검사와 저장 경로는 화면과 같습니다**(CLAUDE.md 3.15). API 전용 검사를 따로 만들지 않습니다.

- 글 검사: `Guide_model::clean_article()` / `clean_block()`
- 저장: `Guide_model::save_article()` / `set_status()` (색인 함께 갱신)
- 통째로 올리기: `Guide_model::import()`
- 이미지 검사: `check_uploaded_image()` (PNG/JPG/WEBP, SVG 거부)
- 외부 이미지 받기: `fetch_external_image()` (https, 공인 IPv4, 리다이렉트 재검사, 2MB)

---

## 3. 접근 키

### 3-1. 형식

```
rhg_ + 40자 무작위(base62)        예) rhg_7Kq2...(총 44자)
```

- `rhg_` 접두어: 깃허브 비밀 스캔이나 로그에서 알아보기 쉽게 붙입니다.
- **DB 에는 SHA-256 해시만 저장**합니다. 평문은 발급 직후 한 번만 화면에 보여주고 다시 볼 수 없습니다.
- 목록에는 앞 8자(`rhg_7Kq2`)만 표시해 어느 키인지 구분합니다.

### 3-2. 범위(scope)

| 범위 | 할 수 있는 것 | 기본 |
|---|---|---|
| `read` | guide.json 받기, 글 조회 | 켜짐 |
| `write` | 글 만들기/고치기, 블록 교체, 이미지 올리기 | 켜짐 |
| `publish` | 글을 `published` 로 바꾸기, 삭제, 전체 교체(`replace`) | **꺼짐** |

- `publish` 가 없는 키로 쓴 글은 **항상 `draft`(임시) 로 저장**합니다. 이미 공개된 글을 고치면
  공개 상태는 유지하되 내용은 바로 반영됩니다 → 11장에서 결정 필요(공개 글 수정 시 임시 사본을 만들지).
- AI 가 고객에게 바로 보이는 화면을 바꾸지 못하게 하는 것이 기본값입니다. 관리자가 검토 후 공개합니다.

### 3-3. 그 밖의 설정

| 항목 | 값 |
|---|---|
| 이름 | 관리자가 붙이는 메모(예: "Claude 가이드 정리용") 60자 |
| 만료 | 7일 / 30일(기본) / 90일 / 무기한 중 선택 |
| 허용 IP | 비우면 제한 없음. 적으면 그 IPv4 들만(고객 IP 와 같은 방식으로 `CF-Connecting-IP` 기준, 3.12) |
| 조직당 키 수 | 최대 5개 |
| 폐기 | 즉시. 폐기한 키는 목록에 남기고(기록용) 다시 살릴 수 없음 |

### 3-4. DB (`sql/migrations/009-guide-api-keys.sql`)

```sql
CREATE TABLE guide_api_keys (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id        INT UNSIGNED NOT NULL,
  name          VARCHAR(60)  NOT NULL,
  key_prefix    CHAR(8)      NOT NULL,            -- 표시용 앞 8자
  key_hash      CHAR(64)     NOT NULL,            -- SHA-256 hex
  scopes        VARCHAR(40)  NOT NULL,            -- 'read,write' 등 허용 목록 값만
  allowed_ips   VARCHAR(500) NULL,
  expires_at    DATETIME     NULL,                -- UTC
  last_used_at  DATETIME     NULL,                -- UTC
  last_used_ip  VARCHAR(45)  NULL,
  created_by    INT UNSIGNED NOT NULL,            -- agents.id (발급한 관리자)
  created_at    DATETIME     NOT NULL,
  revoked_at    DATETIME     NULL,
  UNIQUE KEY uq_key_hash (key_hash),
  KEY ix_org (org_id)
);
```

시간은 모두 UTC 로 저장하고 화면에서 `RHGrid.dateTime` 으로 변환합니다(3.4).

---

## 4. API

### 4-1. 공통

- 기본 주소: `{PUBLIC_BASE_URL}api/guide/v1/` (시험 서버 `https://help.wani.im/api/guide/v1/`)
- 인증: `Authorization: Bearer rhg_...` 헤더만 받습니다. **주소(쿼리)로 키를 받지 않습니다**(로그·Referer 에 남음).
- 응답: 기존 규약 `{ "result": true|false, "message": "", "data": {} }` (`api_response()`)
- CSRF: 세션이 없는 서버 간 호출이므로 `csrf_exclude_uris` 에 `api/guide/.*` 추가. 대신 키 검증으로 막습니다.
  세션 쿠키는 이 경로에서 **읽지도 쓰지도 않습니다**(키 없이 로그인 세션으로 통과하는 길을 막음).
- 라우트: `routes.php` 의 `$route['(:any)']` 보다 위에 둡니다(3.8).
- 컨트롤러: `controllers/api/Guide_api.php` (라이브러리 이름과 겹치지 않게, 3.11)
- 키 검증 실패는 `api_response()` 뒤 `$this->output->_display(); exit;` (3.6)

| 상태 코드 | 경우 |
|---|---|
| 401 | 키 없음, 틀림, 만료, 폐기 |
| 403 | 범위 부족, 허용 IP 아님 |
| 409 | `If-Match` 불일치(그 사이 사람이 고침) |
| 413 | 파일 크기 초과 |
| 422 | 검사 실패(사유는 `message`, 블록별 경고는 `data.warnings`) |
| 429 | 호출 한도 초과 (`Retry-After` 헤더) |

### 4-2. 엔드포인트

| 메서드 | 경로 | 범위 | 내용 |
|---|---|---|---|
| GET | `/guide.json` | read | 전체 내보내기. `Guide_model::export()` 와 같은 형식(guide-plan.md 7장) |
| PUT | `/guide.json?mode=merge` | write | 전체 올리기. `Guide_model::import()`. `mode=replace` 는 `publish` 필요 |
| GET | `/articles` | read | 글 목록(slug, 제목, 상태, 수정 시각, 버전) |
| GET | `/articles/{slug}` | read | 글 하나. 응답 헤더 `ETag` |
| PUT | `/articles/{slug}` | write | 글 하나 통째로 만들기/바꾸기 |
| PATCH | `/articles/{slug}` | write | 필드 일부 또는 블록 단위 수정(4-3) |
| DELETE | `/articles/{slug}` | publish | 삭제 |
| POST | `/images` | write | 이미지 올리기(4-4) → `src` 돌려줌 |
| POST | `/videos/check` | read | 유튜브 주소 검사(4-5) |
| GET | `/openapi.json` | 없음 | API 명세(OpenAPI 3.1). AI 도구 등록용 |
| GET | `/instructions` | read | AI 에게 줄 작업 안내문(텍스트, 6장) |

모든 쓰기 요청은 `?dry_run=1` 을 받습니다. 저장하지 않고 **검사 결과와 바뀔 내용만** 돌려줍니다.
AI 가 먼저 확인하고 저장하도록 안내문에 적습니다.

### 4-3. 블록 단위 수정 (PATCH)

이미지 하나, 영상 하나만 바꾸려고 글 전체를 다시 보내면 AI 가 다른 문단을 실수로 바꿀 수 있습니다.
그래서 **작업 목록(ops)** 으로 필요한 블록만 고치게 합니다.

```http
PATCH /api/guide/v1/articles/printer-connect
Authorization: Bearer rhg_...
If-Match: "a3f9c1"
Content-Type: application/json

{
  "title": "프린터가 연결되지 않을 때 (2026 개정)",
  "ops": [
    { "op": "replace", "index": 2, "expect_type": "image",
      "block": { "type": "image", "src": "assets/uploads/guides/1/9c1e0b.webp", "caption": "새 장치 관리자 화면" } },

    { "op": "replace", "index": 4, "expect_type": "video",
      "block": { "type": "video", "url": "https://youtu.be/AbCdEfGhIjK", "caption": "설정 따라하기" } },

    { "op": "insert", "index": 5,
      "block": { "type": "text", "text": "3. 드라이버를 다시 설치합니다." } },

    { "op": "remove", "index": 7, "expect_type": "text" }
  ]
}
```

- `op` 허용 목록: `replace` / `insert` / `remove` / `move`(`from`, `to`)
- `index` 는 **요청 시점 원본 기준**입니다. 서버가 뒤에서부터 적용해 번호가 밀리지 않게 합니다.
- `expect_type` 이 실제 블록 종류와 다르면 전체 거부(422). AI 가 번호를 잘못 짚는 것을 막습니다.
- 유튜브는 `url` 로 보내도 됩니다. 기존 `clean_block()` 이 `youtube_id()` 로 11자 id 만 뽑아 저장합니다.
- 적용 후 결과 블록 전체를 `clean_article()` 로 다시 검사한 뒤 `save_article()` 로 저장합니다.
- `If-Match` 는 글 `updated_at` 과 블록 내용으로 만든 짧은 해시입니다. 없으면 받되 경고를 붙이고,
  다르면 409 와 함께 현재 글을 돌려줍니다(관리자가 화면에서 고친 내용을 AI 가 덮지 않게).

### 4-4. 이미지 올리기

AI 가 이미지를 만드는 방법은 도구마다 다르므로 세 가지 입력을 받습니다.

| 방식 | 요청 | 쓰는 곳 |
|---|---|---|
| 파일 | `multipart/form-data`, 필드 `image` | curl, 스크립트, Claude Code |
| base64 | JSON `{ "data": "iVBORw0...", "filename": "step1.png" }` | 파일 첨부가 어려운 AI 도구 |
| 외부 주소 | JSON `{ "url": "https://..." }` | 이미지 생성 서비스가 준 https 주소를 서버가 받아 저장 |

- 검사: 파일·base64 는 `check_uploaded_image()`, 외부 주소는 `fetch_external_image()` 만 거칩니다(3.15). 2MB 상한.
- 저장 위치: 기존과 같은 `assets/uploads/guides/{org_id}/{무작위}.{ext}`
- 응답

  ```json
  { "result": true, "message": "이미지를 올렸습니다.",
    "data": { "src": "assets/uploads/guides/1/9c1e0b.webp",
              "url": "https://help.wani.im/assets/uploads/guides/1/9c1e0b.webp" } }
  ```

  AI 는 `src` 를 블록에 넣습니다(`url` 은 확인용).
- 올리고 아무 글에도 쓰지 않은 이미지는 기존 `cleanup_images()` 가 정리합니다.
  이 함수는 이미 **수정된 지 24시간이 지난 미사용 파일만** 지우므로, 올린 뒤 블록에 넣기 전에 지워지지 않습니다(코드 확인).
- 키당 하루 200장 한도.

### 4-5. 유튜브

- 블록에는 지금처럼 **영상 id(11자)만 저장**하고 `youtube-nocookie.com/embed/{id}` 로만 그립니다(3.15).
- `POST /videos/check { "url": "..." }` → `{ youtube_id, title, embeddable }`
  - 유튜브 oEmbed 로 영상이 있는지, 퍼가기가 허용되는지 확인합니다(샘플 영상 교체 규칙과 같은 기준).
  - 쓰기 요청에서도 영상 블록이 바뀌면 같은 검사를 하고, 없는 영상이면 그 블록만 빼고 경고합니다.
  - oEmbed 호출 형식과 응답은 구현 전에 공식 문서로 확인합니다(11장).

### 4-6. 호출 한도

| 대상 | 한도 |
|---|---|
| 키당 전체 요청 | 분당 60회 |
| 쓰기 요청 | 분당 20회 |
| 이미지 | 하루 200장 |
| `PUT /guide.json` | 분당 2회, 2MB 이하(기존 `IMPORT_MAX_BYTES`) |

---

## 5. 기록과 되돌리기

### 5-1. 감사 로그

모든 쓰기 요청을 `audit_logs` 에 남깁니다.

| action | 내용 |
|---|---|
| `guide_api_key_create` / `guide_api_key_revoke` | 발급·폐기(관리자) |
| `guide_api_article_save` | slug, 바뀐 블록 번호, 키 id |
| `guide_api_import` | 방식, 건수, 키 id |
| `guide_api_image` | 저장 경로, 입력 방식, 키 id |

글의 `updated_by` 는 키를 발급한 관리자로 두고, 로그에 키 id 를 함께 적어 사람과 AI 를 구분합니다.
가이드 목록 화면에 "AI 수정" 표시를 붙여 관리자가 검토할 글을 찾게 합니다.

### 5-2. 변경 이력 (되돌리기)

AI 는 한 번에 많은 글을 바꿀 수 있으므로 **저장 직전 내용을 남겨 둡니다.**

```sql
CREATE TABLE guide_revisions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  org_id      INT UNSIGNED NOT NULL,
  article_id  INT UNSIGNED NOT NULL,
  snapshot    MEDIUMTEXT   NOT NULL,     -- 저장 전 글(JSON)
  source      VARCHAR(20)  NOT NULL,     -- 'api' | 'admin' | 'import'
  api_key_id  INT UNSIGNED NULL,
  created_at  DATETIME     NOT NULL,
  KEY ix_article (article_id, id)
);
```

- `save_article()` 안에서 남기므로 화면 저장에도 적용됩니다. 글당 최근 20개만 보관합니다.
- 편집 화면에 [이전 버전] 목록과 [이 버전으로 되돌리기] 를 둡니다(되돌리기도 `save_article()` 경유).
- `PUT /guide.json?mode=replace` 전에는 그 시점 전체 guide.json 을 한 건 더 보관합니다.

---

## 6. 관리자 화면 — 가이드 관리 > 설정 > AI 연결

드로어(`openDrawer`)로 엽니다(3.7). 키 목록은 wgrid(`RHGrid.create`, 3.5).

```
+-- AI 연결 ---------------------------------------------+
| AI 에게 키를 주면 가이드를 읽고 고칠 수 있습니다.       |
| AI 가 만든 글은 임시 상태로 저장되며, 검토 후 공개하세요.|
|                                                         |
| [새 키 만들기]                                          |
|                                                         |
| 이름              앞자리    범위         만료    마지막 사용  |
| Claude 가이드 정리 rhg_7Kq2  읽기,쓰기   11-20  10-21 14:02 [폐기] |
+---------------------------------------------------------+
```

키를 만들면 모달로 **한 번만** 보여줍니다.

```
+-- 키를 만들었습니다 -----------------------------------+
| 이 창을 닫으면 다시 볼 수 없습니다.                     |
| rhg_7Kq2xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx   [복사]  |
|                                                         |
| [AI 에게 줄 안내문 복사]                                |
+---------------------------------------------------------+
```

**AI 에게 줄 안내문**(`/instructions` 와 같은 내용)에는 키, 기본 주소, 작업 순서가 들어 있어
AI 채팅에 그대로 붙여 넣으면 됩니다.

```text
너는 RemoteHelp 가이드 편집 도우미다.
기본 주소: https://help.wani.im/api/guide/v1/
인증: 모든 요청에 헤더 Authorization: Bearer rhg_7Kq2... 를 붙인다.
API 명세: GET /openapi.json

작업 순서
1. GET /guide.json 으로 현재 가이드를 받는다.
2. 이미지를 바꿀 때는 POST /images 로 올리고 돌려받은 data.src 를 쓴다.
3. 영상을 바꿀 때는 POST /videos/check 로 퍼가기 허용을 확인한다.
4. 글은 PATCH /articles/{slug} 의 ops 로 필요한 블록만 고친다. GET 때 받은 ETag 를 If-Match 에 넣는다.
5. 저장 전에 같은 요청에 ?dry_run=1 을 붙여 경고가 없는지 확인한다.
6. 본문에 HTML 을 넣지 않는다. 블록은 text / image / video 만 쓴다.
7. 끝나면 바꾼 글의 slug 와 바꾼 내용을 요약해 보고한다.
```

문구는 `ko/en/ja.json` 세 사전에 함께 넣습니다(3.2). 안내문 본문은 조직 언어로 내려줍니다.

---

## 7. AI 쪽에서 쓰는 방법

### 7-1. 바로 쓸 수 있는 방법 (1단계)

**(가) 셸 명령을 실행할 수 있는 AI** — Claude Code, 사내 스크립트

```bash
export RH_GUIDE_KEY=rhg_7Kq2...
```

```bash
curl -s -H "Authorization: Bearer $RH_GUIDE_KEY" https://help.wani.im/api/guide/v1/guide.json -o guide.json
```

```bash
curl -s -H "Authorization: Bearer $RH_GUIDE_KEY" -F image=@step1.webp https://help.wani.im/api/guide/v1/images
```

키는 대화창에 붙이기보다 환경변수나 `.env` 로 넘기는 것을 권장합니다(대화 기록에 남지 않게).

**(나) HTTP 호출 도구를 등록할 수 있는 AI** — OpenAPI 명세를 읽어 동작하는 도구

- `GET /openapi.json` 주소를 등록하고, 인증 방식을 "API 키 / Bearer" 로 지정해 키를 넣습니다.
- 도구별 등록 화면과 지원 범위는 각 서비스 문서로 확인한 뒤 사용 안내에 적습니다(11장).

**(다) 파일만 주고받는 AI** — 웹 채팅에 파일을 올리는 방식

- 지금도 가능한 방법입니다: [내려받기] → AI 에게 guide.json 을 주고 고치게 함 → [올리기].
- 단, 이 방식은 **이미지를 새로 넣을 수 없습니다**(외부 https 주소만 가능 → 올린 뒤 [외부 이미지를 본 서버로 저장]).
  키 방식이 필요한 이유입니다.

### 7-2. MCP 서버 (2단계)

AI 앱에 "도구"로 붙이는 방식입니다. 같은 API 를 `list_articles`, `get_article`, `patch_article`,
`upload_image`, `check_video` 도구로 감싼 MCP 서버를 둡니다.

- 위치: 포털 안 경로(`/api/guide/mcp`) 또는 별도 Node 컨테이너. 실제 저장은 반드시 위 API → `Guide_model` 을 거칩니다.
- AI 앱마다 원격 MCP 연결 시 요구하는 인증 방식(정적 키 / OAuth)이 다를 수 있어,
  **지원 방식을 공식 문서로 확인한 뒤** 설계를 확정합니다(11장).

---

## 8. 보안 점검표

| 위험 | 대책 |
|---|---|
| 키 유출 | 해시 저장, 한 번만 표시, 만료 기본 30일, 즉시 폐기, 허용 IP, `rhg_` 접두어로 비밀 스캔 |
| AI 가 공개 화면을 망가뜨림 | `publish` 범위 기본 꺼짐 → 임시 저장, 변경 이력과 되돌리기 |
| 다른 조직 접근 | 키 하나 = 조직 하나. 모든 조회에 `org_id` 를 키에서 가져와 붙임(요청 값은 쓰지 않음) |
| 악성 본문 | HTML 미허용, 기존 `clean_article()` / `html_escape()` 그대로 |
| 이미지로 서버 내부 접근(SSRF) | 외부 주소는 `fetch_external_image()` 만 사용(공인 IPv4, 고정 접속, 리다이렉트 재검사) |
| 사람과 AI 동시 수정 | `If-Match` / 409 |
| 무차별 키 대입 | 401 이 IP 당 분당 10회를 넘으면 10분 차단, 키 비교는 해시 일치 조회 |
| 로그에 키 노출 | 쿼리 키 거부, Apache 로그에 `Authorization` 헤더를 남기지 않음 확인 |
| 대량 삭제 | 삭제·전체 교체는 `publish` 범위, 교체 전 전체 스냅샷 |

---

## 9. 단계

### 1단계

- [ ] `009-guide-api-keys.sql` (키, 변경 이력 테이블)
- [ ] `Guide_api_key_model` (발급, 해시 검증, 범위·IP·만료 검사, 마지막 사용 갱신)
- [ ] `controllers/api/Guide_api.php` + 라우트 + `csrf_exclude_uris`
- [ ] 엔드포인트: `guide.json` GET/PUT, `articles` GET/PUT/PATCH/DELETE, `images`, `videos/check`, `dry_run`
- [ ] `If-Match` / ETag
- [ ] `save_article()` 에 변경 이력 저장 추가, 편집 화면 [이전 버전]
- [ ] 관리 화면 설정 드로어 > AI 연결 (발급, 목록, 폐기, 안내문 복사)
- [ ] `openapi.json`, `instructions`
- [ ] `audit_logs`, 호출 한도
- [ ] 다국어 세 사전, 아이콘 추가 시 `Home::landing_icons()` 등록

### 2단계

- [ ] MCP 서버
- [ ] 이미지 자동 축소(2MB 넘는 생성 이미지를 WEBP 로 변환)
- [ ] 목록의 "AI 수정" 검토 대기 필터, 검토 후 일괄 공개

---

## 10. 지침(CLAUDE.md)과 규칙 검사에 추가할 것

3.15 아래 또는 새 절(3.16)로 추가합니다.

- 가이드 API 는 `Authorization: Bearer` 키로만 들어오며 세션을 쓰지 않는다.
- 키는 해시로만 저장한다. 평문 저장, 쿼리로 키 받기는 금지.
- API 쓰기도 `clean_article()` → `save_article()` 를 거친다. API 전용 검사를 만들지 않는다.
- `publish` 범위가 없는 키의 저장은 `draft` 로 강제한다.

`scripts/check-conventions.py` 에 넣을 검사

- `Guide_api.php` 에서 `guide_articles` 테이블을 직접 `insert`/`update` 하지 않는지
- `guide_api_keys` 에 `key_hash` 외 평문 키 컬럼이 없는지, 코드에서 `$_GET` 으로 키를 읽지 않는지
- `routes.php` 에서 `api/guide/` 규칙이 `(:any)` 보다 위에 있는지
- `csrf_exclude_uris` 에 `api/guide/.*` 가 있는지

---

## 11. 확인 필요 사항

| 번호 | 내용 | 기본안 |
|---|---|---|
| 1 | `publish` 없는 키로 **이미 공개된 글**을 고칠 때 | 내용은 바로 반영하고 "AI 수정" 표시. 대안: 임시 사본을 만들어 검토 후 교체 |
| 2 | 키를 AI 웹 채팅에 붙여 넣는 사용을 허용할지 | 허용하되 만료 7일 권장 문구 표시 |
| 3 | 서버가 직접 이미지를 생성할지(이미지 생성 API 호출) | **하지 않음.** AI 쪽에서 만들어 올림. 서버 생성은 비용과 외부 키 관리가 따로 필요 |
| 4 | 이미지 2MB 상한 유지 여부 | 유지, 2단계에서 서버 축소 |
| 5 | 유튜브 oEmbed 요청 형식·응답 | 구현 전 공식 문서로 확인 |
| 6 | OpenAPI 도구 등록(ChatGPT 등)의 인증 지원 방식 | 구현 전 각 서비스 공식 문서로 확인 |
| 7 | 원격 MCP 연결에서 정적 Bearer 키를 받는지, OAuth 가 필요한지 | 2단계 착수 전 공식 문서로 확인 |
| 8 | 키 발급 권한 | 조직 관리자(`Admin_controller`)만 |
