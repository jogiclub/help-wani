<!--
  파일 위치: docs/guide-plan.md
  역할: 조직별 가이드(도움말) 관리와 상담 연계 기능의 작업요청서
-->

# 가이드 관리 — 작업요청서

조직이 자기 고객에게 보여줄 도움말(가이드)을 직접 만들고, 상담 중에 그 가이드를
**AI 호출 없이 키워드 매칭으로** 찾아 답변에 붙이는 기능입니다.

---

## 1. 무엇을 만드는가

| 화면 | 주소 | 대상 | 레이아웃 |
|---|---|---|---|
| 가이드 관리 | `/admin/guide` | 조직 관리자 | `layouts/main.html` |
| 가이드 편집 | `/admin/guide/edit/{id}` | 조직 관리자 | `layouts/main.html` |
| 공개 가이드 목록 + 검색 | `/{조직코드}/guide` | 고객(누구나) | `layouts/guide.html` |
| 공개 가이드 상세 | `/{조직코드}/guide/{slug}` | 고객(누구나) | `layouts/guide.html` |

- 요청의 `help.wani.im/{조직id}/guide` 는 **`org_code`** 로 만듭니다. 고객 접속 페이지(`/{조직코드}`)와 같은 식별자이며,
  숫자 id 를 주소에 내보내지 않습니다. 고객에게 보내는 주소는 `customer_url(org.org_code + '/guide')` 로 만듭니다(3.13).
- 헤더(`layouts/main.html` 의 메뉴)의 조직 관리 묶음에 **`가이드 관리`** 를 추가합니다.

```
원격지원 | 상담 대화 | 조직 관리 | 상담원 관리 | 조직 정보 | 상담 위젯 | 가이드 관리
```

### 1-1. 상담 연계 (이 기능을 만드는 이유)

```
방문자: "프린터 연결이 안 돼요"  --(위젯)-->  포털 저장
                                              |
                                  키워드 매칭(DB 색인 조회, AI 없음)
                                              |
          +-----------------------------------+-------------------------------+
          |                                                                   |
  [상담원 콘솔] 대화창 옆 "추천 가이드" 3건                     [위젯] 자동 추천(조직 설정으로 켬)
  상담원이 [보내기] -> 가이드 카드 메시지                       "이 가이드가 도움이 될 수 있어요" 카드
```

---

## 2. 저장 방식 판단 — DB 에 저장하고, guide.json 은 주고받는 형식으로 쓴다

**결론: 원본은 DB, `guide.json` 은 업로드/다운로드(백업, 이관, 일괄 편집) 형식입니다.**
서버에 guide.json 파일 하나를 두고 그것을 고치는 방식은 쓰지 않습니다.

| 항목 | 파일 하나(guide.json) | DB(채택) |
|---|---|---|
| 동시 편집 | 두 관리자가 저장하면 나중 사람이 앞 사람 것을 통째로 덮음 | 글 단위 저장이라 충돌 범위가 한 글 |
| 상담 중 검색 | 매 요청마다 파일 전체를 읽고 분석 | 미리 만든 색인 테이블을 `IN (...)` 으로 조회 |
| 조직 격리 | 파일 경로로만 구분, 권한 검사 별도 | 기존처럼 모든 조회를 `org_id` 로 거름 |
| 백업 / 서버 이전 | 업로드 폴더와 따로 챙겨야 함 | MySQL 백업에 함께 들어감 |
| 감사 로그, 수정 시각 | 직접 구현 | `audit_logs`, `updated_at` 그대로 사용 |
| 내용 구조 유연성 | 높음 | 본문을 `JSON` 컬럼(블록 배열)으로 두어 같은 수준 확보 |

- 글 본문은 MySQL 8.0 의 `JSON` 컬럼에 **블록 배열**로 저장합니다. 그래서 DB 한 행이 곧 guide.json 의 글 하나와 같은
  모양이 되고, 내보내기/가져오기가 거의 그대로 옮겨 적는 작업이 됩니다.
- 다운로드는 요청 시 DB 에서 만들어 내려줍니다. 서버에 파일을 남기지 않습니다.

---

## 3. 가이드 구조

```
조직
 └─ 분류(category)      예) 설치, 계정, 결제
     └─ 가이드(article)  제목, 부제목, 키워드, 본문 블록들
          ├─ text   글
          ├─ image  이미지 + 설명
          └─ video  유튜브 동영상 + 설명
```

| 항목 | 규칙 |
|---|---|
| 제목 | 필수, 100자 |
| 부제목 | 선택, 200자. 목록과 검색 결과에 보임 |
| slug | 주소용. 영문 소문자/숫자/하이픈/밑줄 60자, 조직 안에서 유일, 숫자만은 안 됨. 비우면 `{id}` 로 접속 |
| 키워드 | 선택, 쉼표 구분 최대 20개. **매칭에서 가장 높은 가중치** |
| 상태 | `draft`(관리자만 봄) / `published`(공개 + 상담 추천 대상) |
| 순서 | 분류 안 정렬 순서(`sort_order`) |
| 본문 | 블록 배열, 최대 50블록 |

### 3-1. 본문 블록

| type | 필드 | 규칙 |
|---|---|---|
| `text` | `text` | 일반 글 5,000자. 줄바꿈 유지, `https://` 주소는 자동 링크. **HTML 은 받지 않고 출력 시 모두 이스케이프** |
| `image` | `src`, `caption` | 포털에 올린 이미지(`assets/uploads/guides/{org_id}/...` 상대 경로) 또는 외부 https 주소 |
| `video` | `youtube_id`, `caption` | 유튜브만. 저장은 영상 id(11자)만 |

- 서식(굵게, 목록)이 필요해지면 그때 Markdown 일부를 받는 것으로 넓힙니다. MVP 는 일반 글입니다.
  HTML 을 받으면 고객 페이지에 스크립트가 들어갈 길이 생기므로 받지 않습니다.

### 3-2. 유튜브

- 입력은 다음 형식만 허용하고, 그 안에서 영상 id(`[A-Za-z0-9_-]{11}`)만 뽑아 저장합니다(허용 목록 검사, 3.7).
  - `https://www.youtube.com/watch?v=ID`
  - `https://youtu.be/ID`
  - `https://www.youtube.com/shorts/ID`
  - `https://www.youtube.com/embed/ID`
- 출력은 `https://www.youtube-nocookie.com/embed/{id}` iframe 하나로 고정합니다. 저장된 주소를 그대로 iframe 에 넣지 않습니다.
- 공개 페이지는 처음에 재생 버튼 자리만 보이고 누르면 iframe 을 만듭니다(`assets/js/guide.js`).
  페이지를 여는 것만으로 유튜브에 요청이 가지 않게 하고, 로딩도 가볍게 하기 위해서입니다.
- iframe 에 `referrerpolicy="strict-origin-when-cross-origin"` 을 둡니다.

> 확인 결과(2026-10-21, 공식 문서)
> - 임베드 주소 `https://www.youtube.com/embed/VIDEO_ID` — developers.google.com/youtube/player_parameters
> - 개인정보 보호 강화 모드는 도메인을 `https://www.youtube-nocookie.com` 으로 바꾼다 — support.google.com/youtube/answer/171780
> - 임베드는 HTTP Referer 로 식별해야 하며 `strict-origin-when-cross-origin` 을 권장 — developers.google.com/youtube/terms/required-minimum-functionality
> - 썸네일 주소(`i.ytimg.com`)는 위 문서에서 확인되지 않아 **쓰지 않았습니다.**

### 3-3. 이미지

- **Dropzone.js(`RHImageDrop.create`)** 로만 올립니다(3.14). 라우터는 `'use_dropzone': True`.
- PNG/JPG/WEBP, 2MB 이하, SVG 거부. 서버는 로고 업로드와 같은 내용 기준 형식 판별을 씁니다
  (로고 업로드와 같은 `check_uploaded_image()` 를 함께 씁니다).
- 저장 위치 `backend/assets/uploads/guides/{org_id}/{임의이름}.{확장자}`. `.gitignore` 에 추가하고 `.gitkeep` 만 둡니다.
- 글을 지우거나 블록에서 이미지를 빼면 파일도 지웁니다. 다른 글이 같은 경로를 쓰고 있으면 남깁니다.
- 올리고 저장하지 않은 파일은 다음 업로드/가져오기 때 하루가 지난 것부터 정리합니다(`guide_model.cleanup_images`).

---

## 4. 데이터베이스

마이그레이션 `sql/migrations/006-guides.sql`, `sql/01-schema.sql` 에도 반영합니다. 시각은 모두 UTC(3.4).

```sql
-- 분류
CREATE TABLE guide_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id      INT UNSIGNED NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gcat_org (org_id, sort_order),
  CONSTRAINT fk_gcat_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
);

-- 가이드
CREATE TABLE guide_articles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id       INT UNSIGNED NOT NULL,
  category_id  INT UNSIGNED DEFAULT NULL,
  slug         VARCHAR(60)  DEFAULT NULL,
  title        VARCHAR(100) NOT NULL,
  subtitle     VARCHAR(200) DEFAULT NULL,
  keywords     VARCHAR(500) DEFAULT NULL COMMENT '쉼표 구분, 정규화 전 원문',
  blocks       JSON         NOT NULL COMMENT '본문 블록 배열',
  status       ENUM('draft','published') NOT NULL DEFAULT 'draft',
  sort_order   INT          NOT NULL DEFAULT 0,
  view_count   INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_garticle_slug (org_id, slug),
  KEY idx_garticle_org (org_id, status, category_id, sort_order),
  CONSTRAINT fk_garticle_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_garticle_cat FOREIGN KEY (category_id) REFERENCES guide_categories (id) ON DELETE SET NULL
);

-- 매칭 색인 (글을 저장할 때마다 그 글의 행을 지우고 다시 만든다)
CREATE TABLE guide_terms (
  org_id      INT UNSIGNED NOT NULL,
  term        VARCHAR(40)  NOT NULL COMMENT '정규화된 낱말 또는 2글자 조각',
  article_id  INT UNSIGNED NOT NULL,
  weight      SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (org_id, term, article_id),
  KEY idx_gterm_article (article_id),
  CONSTRAINT fk_gterm_article FOREIGN KEY (article_id) REFERENCES guide_articles (id) ON DELETE CASCADE
);

-- 동의어 묶음 (조직별). 예) "로그인, 접속, 사인인"
CREATE TABLE guide_synonyms (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id  INT UNSIGNED NOT NULL,
  words   VARCHAR(300) NOT NULL COMMENT '쉼표 구분',
  PRIMARY KEY (id),
  KEY idx_gsyn_org (org_id),
  CONSTRAINT fk_gsyn_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
);

-- 메시지에 가이드 카드를 붙이기 위한 컬럼
ALTER TABLE messages
  ADD COLUMN meta JSON DEFAULT NULL COMMENT '{"type":"guide","article_id":..,"title":..,"url":..}' AFTER attachment_url;

-- 위젯 자동 추천 사용 여부
ALTER TABLE organizations
  ADD COLUMN guide_autosuggest TINYINT(1) NOT NULL DEFAULT 0 COMMENT '위젯에서 가이드 자동 추천' AFTER widget_offline_email;
```

- 모든 조회는 `org_id` 로 거릅니다. `guide_terms` 도 `org_id` 가 기본 키 맨 앞이라 다른 조직 글이 섞이지 않습니다.
- 매칭 개선용 기록(어떤 질문에 무엇을 추천했고 보냈는지, 추천이 없던 질문)은 2단계에서 `guide_match_logs` 로 추가합니다(9장).

---

## 5. 키워드 매칭 (AI 토큰 없음)

외부 API 를 부르지 않고 **서버 문자열 처리 + MySQL 색인 조회**만으로 추천합니다. 비용은 질의 한 번에 SELECT 한 번입니다.

### 5-1. 정규화 (색인과 질문에 똑같이 적용)

1. 유니코드 정규화(NFKC, `intl` 의 `Normalizer` 가 있으면), 소문자화
2. 문장부호와 기호를 공백으로
3. 공백으로 나눠 낱말 목록
4. 한국어 조사·어미 떼기: 낱말 끝이 목록에 있으면 잘라냄
   `에서, 으로, 에게, 까지, 부터, 은, 는, 이, 가, 을, 를, 에, 로, 와, 과, 도, 만, 요, 해요, 합니다, 안돼요, 돼요` 등
   (목록은 `backend/app/settings/guide_match.py` 에 둡니다. 남는 낱말이 1글자가 되면 떼지 않습니다)
5. 불용어 제거: `안녕하세요, 문의, 질문, 좀, 너무, 어떻게, 왜, 그리고` 등, 영어 `the, a, how, is` 등
6. 한글 낱말이 3글자 이상이면 **2글자 조각(bi-gram)** 을 추가로 만듦
   예) `프린터연결` → `프린, 린터, 터연, 연결`. 띄어쓰기를 안 하거나 합성어로 쓴 질문을 잡기 위해서입니다.
7. 동의어 펼치기: 질문 쪽에서만, 그 조직의 `guide_synonyms` 묶음 안 낱말을 함께 찾음

### 5-2. 색인 가중치

| 출처 | 낱말 | 2글자 조각 |
|---|---|---|
| 키워드(관리자가 직접 입력) | 10 | 3 |
| 제목 | 6 | 2 |
| 부제목 | 4 | 1 |
| 본문 글, 이미지/동영상 설명 | 1 | - |

같은 글에서 같은 낱말이 여러 곳에 나오면 가장 높은 가중치 하나만 저장합니다(본문에 같은 낱말을 반복해 순위를 올리는 것을 막음).
`published` 글만 색인합니다. `draft` 로 바꾸면 그 글의 색인 행을 지웁니다.

### 5-3. 조회와 점수

```sql
SELECT t.article_id, SUM(t.weight) AS score, COUNT(*) AS hits
  FROM guide_terms t
 WHERE t.org_id = ? AND t.term IN (?, ?, ...)
 GROUP BY t.article_id
HAVING score >= ?
 ORDER BY score DESC, hits DESC
 LIMIT 3
```

| 사용처 | 기준 점수 | 이유 |
|---|---|---|
| 상담원 콘솔 추천 | 4 | 사람이 보고 고르므로 넓게 |
| 위젯 자동 추천 | 10 (키워드 1개 일치 수준) | 고객에게 바로 보이므로 좁게 |
| 공개 페이지 검색 | 1 | 사용자가 직접 찾는 것 |

- 질문 낱말은 최대 30개로 자릅니다(긴 붙여넣기 대비).
- 점수 기준과 가중치는 `settings/guide_match.py` 에 두어 코드 수정 없이 조정합니다.

### 5-4. 검토한 다른 방법

| 방법 | 판단 |
|---|---|
| MySQL `FULLTEXT ... WITH PARSER ngram` | 한글 검색은 되지만 `ngram_token_size` 가 서버 전체 설정이고, 키워드/제목 가중치를 따로 주기 어렵다. 공개 페이지 검색 보조로는 나중에 고려 |
| 형태소 분석기(mecab-ko 등) | 정확도는 좋지만 컨테이너에 사전과 확장을 넣어야 하고 운영 부담이 크다. 가이드 수가 수백 건 규모라 과하다 |
| 임베딩/LLM | 요청 조건(토큰 비용 없음)에 맞지 않음 |

**채택한 방식의 핵심은 "관리자가 넣는 키워드"** 입니다. 자동 분석보다 관리자가 "이 글은 `프린터, 인쇄, 출력` 질문에 나와야 한다"
고 적는 것이 정확합니다. 그래서 2단계의 "추천 없던 질문" 목록(9장)이 품질을 올리는 주된 수단이 됩니다.

---

## 6. 상담 연계

### 6-1. 상담원 콘솔 (`/console/chat`)

- 대화창 오른쪽에 **추천 가이드** 영역을 둡니다.
  - 방문자의 최근 메시지 3개를 합쳐 매칭합니다(마지막 한 줄만 보면 "네 그거요" 같은 말에 결과가 없음).
  - 새 방문자 메시지가 오면 다시 조회합니다(실시간 수신 시, 또는 폴링 시).
  - 검색창도 함께 둬서 상담원이 직접 찾을 수 있게 합니다.
- 각 추천에 **[보내기]**: 가이드 카드 메시지를 저장합니다.
  - `messages.sender_type = 'agent'`, `body` = 제목 + 주소(카드를 못 그리는 화면에서도 읽히도록), `meta` = 카드 정보
  - **저장 후 `realtime.publish_message()` 로 발행합니다(3.11).** 기존 `api_chat_send` 와 같은 경로를 탑니다.
- 새 API: `GET /console/api/guide/suggest/{conversation_id}`, `GET /console/api/guide/search?q=`,
  `POST /console/api/chat/send_guide` (`conversation_id`, `article_id`). 주소는 뷰에서 `base_url()` 로 만들어 `CHAT_URLS` 에 담습니다(3.7).

### 6-2. 상담 위젯 (`widget.js`)

- 조직이 `guide_autosuggest` 를 켰을 때만 동작합니다(조직 관리 > 상담 위젯 화면에 체크박스).
- 방문자가 메시지를 보내면 `widget.send()` 안에서 매칭하고, 기준 점수(10) 이상이면
  `sender_type = 'system'` 카드 메시지 1건을 저장하고 발행합니다. 상담원도 대화창에서 "자동 추천됨" 으로 봅니다.
- 같은 대화에서 같은 글은 한 번만 자동 추천합니다. 대화당 자동 추천은 최대 3회입니다.
- 위젯은 의존성 없이 돕니다(3.9). 카드 모양은 Shadow DOM 안 CSS 로 그리고, 문구(`이 가이드가 도움이 될 수 있어요`, `가이드 보기`)는
  `/api/widget/init` 응답에 담아 내려줍니다.
- 카드를 누르면 새 탭으로 `/{조직코드}/guide/{slug}` 를 엽니다. 위젯 안에 iframe 으로 넣지 않습니다(고객사 CSP 충돌 방지).

### 6-3. 고객 접속 페이지 (`/{조직코드}`)

- 공개된 가이드가 1건 이상이면 "도움말 보기" 링크를 둡니다. 원격지원 전에 스스로 해결할 기회를 줍니다.

---

## 7. guide.json 형식 (업로드 / 다운로드)

```json
{
  "format": "remotehelp-guide",
  "version": 1,
  "exported_at": "2026-10-21T06:00:00Z",
  "org_code": "demo",
  "categories": [
    { "key": "install", "name": "설치", "sort_order": 1 }
  ],
  "synonyms": [
    ["로그인", "접속", "사인인"]
  ],
  "articles": [
    {
      "slug": "printer-connect",
      "category": "install",
      "title": "프린터가 연결되지 않을 때",
      "subtitle": "USB 와 네트워크 프린터 점검 순서",
      "keywords": ["프린터", "인쇄", "출력", "printer"],
      "status": "published",
      "sort_order": 1,
      "blocks": [
        { "type": "text",  "text": "1. 전원과 케이블을 확인합니다.\n2. ..." },
        { "type": "image", "src": "assets/uploads/guides/1/a8f3c2.png", "caption": "장치 관리자 화면" },
        { "type": "video", "youtube_id": "dQw4w9WgXcQ", "caption": "설정 따라하기" }
      ],
      "updated_at": "2026-10-20T03:12:00Z"
    }
  ]
}
```

### 7-1. 다운로드

- `GET /admin/guide/export` → `Content-Disposition: attachment; filename="guide-{org_code}-{YYYYMMDD}.json"`
- 분류는 DB id 대신 `key`(내보낼 때 만든 문자열)로 잇습니다. id 는 조직마다 다르므로 이관 시 의미가 없습니다.
- 이미지는 **경로만** 들어갑니다. 파일까지 옮기는 묶음 내보내기(zip)는 2단계입니다(9장).
- `audit_logs` 에 `guide_export` 를 남깁니다.

### 7-2. 업로드(가져오기)

- `POST /admin/api/guide_import` (multipart, 1개 파일, 2MB 이하, `application/json`)
- 방식 선택
  - **병합**(기본): `slug` 가 같으면 덮어쓰고, 없으면 새로 만든다. slug 없는 글은 새로 만든다.
  - **전체 교체**: 기존 글과 분류를 지우고 파일 내용으로 바꾼다. `showConfirmModal` 로 한 번 더 묻는다.
- 검사(통과하지 못한 글은 건너뛰고 결과에 사유를 적음)
  - `format`, `version` 일치. 모르는 버전이면 전체 거부
  - 필드 허용 목록, 길이 제한, 블록 type 허용 목록(`text/image/video`), 글 최대 500건
  - `image.src` 는 이 조직의 업로드 경로에 실제로 있는 파일이나 **외부 https 주소**를 받음.
    다른 조직 경로, http, `data:`, `javascript:` 는 그 블록만 빼고 경고.
    (처음에는 외부 이미지를 막았으나, 다른 사이트의 가이드를 옮겨 오는 경우를 위해 2026-10-21 에 https 만 허용으로 바꿈.
    외부 이미지는 그 사이트에서 지우면 함께 사라지며, 고객 페이지 주소가 넘어가지 않도록 Referer 를 보내지 않는다.)
  - `video.youtube_id` 는 11자 형식 검사
- 하나의 트랜잭션으로 저장하고, 끝난 뒤 그 조직의 색인을 다시 만듭니다.
- 응답 `data`: `{ "created": n, "updated": n, "skipped": [{ "slug": "...", "reason": "..." }] }`
- `audit_logs` 에 `guide_import`(방식, 건수)를 남깁니다.

---

## 8. 화면

### 8-1. 가이드 관리 (`/admin/guide`)

- 상단: `[새 가이드] [guide.json 내려받기] [guide.json 올리기] [동의어 관리]`, 분류 관리
- 목록: **AG Grid**, 기본 100개(3.5). 열: 분류, 제목, 부제목, 키워드, 상태(badge), 수정 시각(`RHGrid.dateTime`), 조회수
- 상단 오른쪽에 **매칭 시험** 입력칸: 문장을 넣으면 어떤 글이 몇 점으로 나오는지 보여줌. 관리자가 키워드를 조정할 때 씁니다.

### 8-2. 가이드 편집 (`/admin/guide/edit/{id}`)

```
제목 [                         ]   상태 (o) 임시 ( ) 공개
부제목 [                       ]   분류 [설치 v]
주소   /demo/guide/[printer-connect]
키워드 [프린터, 인쇄, 출력       ]

본문
 +- [글]    ------------------------------  [위로][아래로][삭제]
 |   텍스트 입력
 +- [이미지] -----------------------------
 |   (Dropzone 영역)  설명 [       ]
 +- [동영상] -----------------------------
 |   유튜브 주소 [         ] -> 썸네일 미리보기  설명 [  ]
 +- [+ 글] [+ 이미지] [+ 동영상]

[미리보기] [저장]
```

- 블록 순서는 위/아래 버튼으로 바꿉니다(끌어다 놓기 라이브러리를 새로 넣지 않음).
- 저장은 `blocks` 배열을 JSON 으로 보내고 서버가 7-2 와 같은 검사 함수를 거칩니다. 화면 입력과 파일 가져오기의 검사를 한 곳에 둡니다.
- 미리보기는 공개 페이지와 같은 뷰 조각(`templates/guide/_article.html`)을 씁니다.

### 8-3. 공개 페이지 (`/{조직코드}/guide`)

- 상단은 고객 접속 페이지와 같은 조직 로고/이름(`layouts/customer.html`).
- 검색창 + 분류별 목록. 상세에서 조회수를 1 올립니다(같은 브라우저 연속 조회는 세션으로 거름).
- 문구 언어는 **조직 설정**을 따릅니다(3.2 표, 고객이 보는 화면). 가이드 내용 자체는 관리자가 쓴 언어 그대로입니다.
- 상세 하단: "해결되지 않았나요? [원격지원 받기]" → `/{조직코드}` 로 이동.
- `<meta name="robots">` 는 기본 `index` 로 둡니다. 막아야 하면 조직 설정으로 추가합니다(확인 필요 사항 참고).

---

## 9. 단계

### 1단계 (MVP)

1. 마이그레이션 `006-guides.sql`, `01-schema.sql` 반영
2. `Guide_model`(글/분류/동의어 CRUD, 블록 검사, 색인 재생성), `Guide_matcher` 라이브러리(정규화, 조회)
3. `Admin` 가이드 관리/편집 화면, 이미지 업로드, 동의어 관리, 매칭 시험
4. guide.json 내려받기 / 올리기(병합, 전체 교체)
5. 공개 목록/상세/검색 (`Guide` 컨트롤러)
6. 상담원 콘솔 추천 영역 + [보내기] 카드 메시지 + 발행
7. 위젯 자동 추천(조직 설정) + 카드 표시
8. 다국어 키 세 사전 추가, 아이콘은 Bootstrap Icons 이름(CLAUDE.md 3.3)
9. `check-conventions.py` 검사 추가(10장)

### 2단계

- `guide_match_logs`: 추천 표시/전송/클릭, **추천이 없던 방문자 질문 목록** → 관리 화면에서 "이 질문에 키워드 추가"
- 이미지 포함 zip 내보내기/가져오기
- 본문 Markdown 일부 허용, 공개 페이지 "도움이 됐나요" 평가

---

## 10. 지침(CLAUDE.md)과 규칙 검사에 추가할 것

| 규칙 | 검사 함수 |
|---|---|
| 라우트 `(:any)/guide`, `(:any)/guide/(:any)` 가 `(:any)` 보다 위 | 기존 라우팅 순서 검사에 포함되는지 확인, 없으면 추가 |
| 유튜브 iframe 주소는 `youtube-nocookie.com/embed/` 로만 만든다 | 뷰/JS 에서 `youtube.com/embed` 직접 사용 금지 |
| 가이드 카드 메시지 저장 뒤 발행 | `send_guide` 에 `publish_message` 호출 존재 |
| 가이드 업로드 폴더 git 제외 | `.gitignore` 에 `backend/assets/uploads/guides/*` |
| 가이드 본문 출력 시 이스케이프 | 공개 가이드 템플릿에서 `\|safe` 금지(Jinja 자동 이스케이프), 글 블록은 `guide_text_html()` |

경로 (`main.py` 에서 조직 코드 라우터 `customer.org_router` 보다 먼저 등록, 3.8)

```
# 조직 관리 > 가이드 (routers/admin_guide.py, require_admin)
/admin/guide                 index
/admin/guide/new             new
/admin/guide/edit/{id}       edit
/admin/guide/preview/{id}    preview
/admin/guide/export          export
/admin/guide/api/{이름}       api_{이름}
# 상담원 콘솔 가이드 추천 (routers/console.py)
/console/api/guide/suggest/{conversation_id}   api_guide_suggest
/console/api/guide/search                      api_guide_search
/console/api/chat/send_guide                   api_chat_send_guide
# 공개 가이드 (routers/guide.py, 모음 주소가 글 주소보다 먼저)
/{org_code}/guide            index
/{org_code}/guide/{slug}     article
```

- 조직 관리(`routers/admin.py`)와 나눠 가이드 관리는 `routers/admin_guide.py` 에 두고 둘 다 `Depends(require_admin)` 을 씁니다.

---

## 11. 확인 필요 사항

1. **편집 권한**: 가이드 관리를 조직 관리자만 할지, 상담원도 할지. 이 문서는 "관리자만 편집, 상담원은 콘솔에서 검색/전송" 으로 잡았습니다.
2. **공개 범위**: 가이드 페이지를 검색엔진에 노출해도 되는지, 조직별로 비공개(링크를 아는 사람만) 선택이 필요한지.
3. **위젯 자동 추천 기본값**: 꺼짐으로 잡았습니다. 상담원이 붙기 전에 자동 카드가 나가는 것을 조직이 원하지 않을 수 있습니다.
4. **요금제 제한**: 가이드 수, 이미지 용량에 조직당 한도를 둘지(현재 안: 글 500건, 이미지 1장 2MB, 조직당 합계 200MB).
5. **다국어 가이드**: 한 조직이 같은 글을 한/영/일로 따로 두어야 하는지. MVP 는 조직당 한 언어입니다. 필요하면 `locale` 컬럼과 글 묶음 id 를 추가합니다.
6. 유튜브 주소 형식과 nocookie 임베드 주소는 구현 전에 공식 문서로 재확인합니다(3-2).

---

## 12. 구현 현황 (2026-10-21, 1단계)

확인 필요 사항(11장)은 이 문서의 기본안으로 구현했습니다. 바꾸려면 알려 주세요.

| 항목 | 파일 |
|---|---|
| 스키마 | `sql/migrations/006-guides.sql`, `sql/01-schema.sql` |
| 매칭 | `libraries/guide_matcher.py`, `settings/guide_match.py` |
| 저장/검사/색인/guide.json | `models/guide_model.py` |
| 관리 화면 | `routers/admin_guide.py`, `templates/admin/guide.html`, `templates/admin/guide_edit.html`, `assets/js/admin/guide.js`, `assets/js/admin/guide-edit.js` |
| 공개 화면 | `routers/guide.py`, `templates/guide/*`, `assets/js/guide.js` |
| 상담 연계 | `console.api_guide_suggest / api_guide_search / api_chat_send_guide`, `widget._suggest_guide`, `assets/js/console/chat.js`, `assets/js/widget.js` |
| 설정 | 상담 대화 > 설정 드로어의 "가이드 자동 추천" (`organizations.guide_autosuggest`) |
| 규칙 검사 | `scripts/check-conventions.py` 의 `check_guide()`, `check_routes()`(조직 코드 라우터가 맨 마지막, 모음 주소 순서·예약) |

계획과 달라진 점

- 관리 화면에 **미리보기**(`/admin/guide/preview/{id}`)를 더했습니다. 임시 글도 고객 화면 모양으로 봅니다.
- 관리자 검사는 `require_admin`(core/deps.py) 하나를 `routers/admin.py` 와 `routers/admin_guide.py` 가 함께 씁니다.
- guide.json 올리기도 Dropzone(`RHImageDrop`)으로 받습니다(CLAUDE.md 3.14 의 파일 입력 금지 규칙).
- 공개 검색에서 낱말이 하나도 맞지 않으면 제목/부제목에 그 말이 들어 있는지 한 번 더 봅니다(1글자 질문 등).
- 조사를 떼기 전과 후 모두 불용어를 봅니다("안녕하세요" 가 "안녕하" 로 남는 문제).

### 12-1. 가이드 샘플 (2026-10-21 추가)

- `/admin/guide` 를 처음 열 때 글이 없으면 샘플 10개를 만듭니다(`guide_model.seed_samples`).
  분류 5개(시작하기, 원격지원, 문제 해결, 계정, 결제)와 동의어 5묶음도 함께 만듭니다.
- 형태: 글만 7개, 글+이미지 1개(원격지원 받는 방법, 이미지 2장), 글+이미지+유튜브 2개(프린터, 인터넷).
- 모두 **임시** 상태입니다. 고객 화면과 상담 추천에 바로 나오지 않게 하려는 것이며, 화면 상단에 안내를 띄웁니다.
- 한 번만 만듭니다. `organizations.guide_seeded_at`(마이그레이션 007)을 먼저 찍어 동시에 열어도 한 번만 만들고,
  관리자가 모두 지워도 되살리지 않습니다. 이미 글이 있는 조직은 표시만 찍고 건너뜁니다.
- 문구의 `{org}`, `{phone}`, `{customer_url}` 은 조직 값으로 바뀝니다. 샘플은 한국어뿐입니다.
- 이미지는 `assets/img/guide-samples/` 의 화면 모형(PNG, 나눔스퀘어로 그림)을 조직 폴더로 복사해 씁니다.
- 동영상은 2026-10-21 에 유튜브 oEmbed 로 존재와 퍼가기 허용을 확인했습니다.
  - `hXMtG7iD7R0` "Windows 11: Add a Printer" (SHSUServiceDesk)
  - `t8IZKJXBSBQ` "Windows 11: Fix any WiFi problem (easy way)" (Pureinfotech)

### 12-2. 공개 페이지 테마 (2026-10-21 추가)

가이드 관리 오른쪽의 "공개 페이지 테마" 에서 고르고 [적용] 합니다(`organizations.guide_theme`, 마이그레이션 008).

| 번호 | 키 | 이름 | 모양 |
|---|---|---|---|
| 1 | `card` | 기본 카드형 | 지금까지의 화면. 가운데 한 줄, 분류별 카드 목록 |
| 2 | `docs` | 문서형 (왼쪽 목차) | 상단 검색 + 왼쪽 번호 목차 + 본문 카드, 이동 경로, 이전/다음. 좁은 화면은 목차 버튼으로 연다. 참고: 사내 WANI 사용자 가이드 화면 |
| 3 | `portal` | 헬프센터형 | 강조색 배너와 큰 검색창, 분류 카드(5개 넘으면 더 보기), 상세 옆에 같은 분류 목록 |
| 4 | `faq` | FAQ 아코디언형 | 질문을 누르면 그 자리에서 답이 펼쳐진다(`<details>`, 스크립트 없음) |
| 5 | `dark` | 다크 미니멀형 | 어두운 배경, 큰 글씨, 번호 목록, 이전/다음 |

- 강조색은 조직의 상담 위젯 색(`widget_color`)을 따릅니다.
- 미리보기는 `/{조직코드}/guide?theme=키` 이며 그 조직 관리자로 로그인한 세션에서만 받습니다. 다른 방문자에게는 저장된 테마가 보입니다.
- 구조: `guide_model.page_data()` 가 목록/검색/상세 데이터를 만들고 `templates/guide/page.html` 이 테마 템플릿을 고릅니다.
  관리자 미리보기(`/admin/guide/preview/{id}`)도 같은 경로를 탑니다. 레이아웃은 `layouts/guide.html`.
- 테마 2~5 의 모양은 `assets/css/guide-themes.css` 입니다. 공개 가이드가 싣는 유일한 화면 CSS 이며(`ui_kit = 'guide'`),
  공통 본문 조각의 `guide-*` 클래스에 테마별 모양을 덧입힙니다.
- 아이콘은 SVG 를 마크업에 직접 넣었습니다(Material Symbols 목록 등록 불필요).

