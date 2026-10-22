<!--
  파일 위치: docs/macro-plan.md
  역할: 상담 대화 매크로(자주 쓰는 답변) 기능 기획서 — 생성, 일괄 등록, 사용, 관리, 즐겨찾기, 추천
-->

# 매크로 — 기획서

> **구현 상태(2026-10-22)**: M1~M8 모두 구현. 9장의 확인 사항은 기본안으로 정했습니다(CSV, 메뉴 없이 상담 대화의 [매크로] 버튼,
> 제안한 개수 제한, 첨부·가이드 카드 매크로 제외). 규칙 요약은 `CLAUDE.md` 3.17.

고객이 자주 묻는 질문에 대한 답변을 미리 만들어 두고, 상담 대화 입력창에서 **`/` 한 번으로 불러와 보내는** 기능입니다.
여러 상담원이 같은 문구로 답하게 되어 응대 품질이 고르고, 답변 시간이 줄어듭니다.

참고 자료(타사 상담 도구의 매크로 도움말)의 항목을 이 포털 구조에 맞춰 옮겼습니다.

> 참고 이미지 해상도가 낮아 세부 수치와 문구는 읽지 못했습니다. 항목 구성(설정 화면 생성, 파일 일괄 등록,
> 전송 메시지로 만들기, `/` 호출, 추천 순서, 관리 목록, 즐겨찾기, FAQ)만 참고했고,
> 수치와 동작 규칙은 이 포털의 현재 구조(조직·상담원 체계, `api_chat_send`, 가이드 키워드 매칭)를 기준으로 새로 정했습니다.

---

## 1. 범위와 용어

- 매크로는 **상담(위젯 글 대화) 기능**입니다. 원격지원(`sessions`)에는 쓰지 않습니다(CLAUDE.md 3.10).
- 매크로를 고르면 **입력창에 문구가 채워질 뿐, 바로 보내지지 않습니다.** 상담원이 고쳐서 보낼 수 있고,
  전송은 기존 `POST /console/api/chat/send` 를 그대로 탑니다. 저장 경로와 실시간 발행이 하나로 유지됩니다(3.11).
- 가이드와의 차이

| | 매크로 | 가이드 |
|---|---|---|
| 형태 | 상담원이 보내는 **글 답변** | 고객이 읽는 **공개 문서**(카드로 전달) |
| 고객에게 보이는 것 | 일반 상담원 메시지 | 가이드 카드 + 공개 페이지 |
| 편집 | 공용은 관리자, 개인은 본인 | 조직 관리자 |
| 추천 | 입력창 `/` 목록 안에서 | 대화창 옆 추천 패널, 위젯 자동 추천 |

### 매크로의 두 범위

| 범위 | 누가 만들고 고치나 | 누가 쓰나 | 용도 |
|---|---|---|---|
| **공용** | 조직 관리자 | 조직의 모든 상담원 | 조직 표준 답변(환불 규정, 운영 시간 안내 등) |
| **개인** | 상담원 본인 | 본인만 | 자기 말투의 인사, 자주 쓰는 짧은 문구 |

- 상담원은 공용 매크로를 **보고 쓰기만** 합니다. 고치고 싶으면 [개인 매크로로 복사] 를 씁니다.
- 관리자는 상담원의 개인 매크로를 볼 수 없습니다(본인 것만). 퇴사자 정리는 상담원 삭제 시 함께 지워집니다.

---

## 2. 기능 목록과 우선순위

| # | 기능 | 우선 | 요약 |
|---|---|---|---|
| M1 | 매크로 만들기/고치기 | 1차 | 관리 화면에서 이름·내용 입력, 변수 삽입 |
| M2 | 입력창에서 불러오기 | 1차 | `/` 입력 → 목록 → Enter 로 입력창에 채움 |
| M3 | 매크로 관리 목록 | 1차 | wgrid 목록, 검색, 정렬, 사용 횟수·최근 사용 |
| M4 | 보낸 메시지로 만들기 | 1차 | 대화창의 내 메시지에서 [매크로로 저장] |
| M5 | 즐겨찾기 | 1차 | 상담원별 최대 100개, `/` 목록 맨 위, 순서 끌어 바꾸기 |
| M6 | 파일 일괄 등록/내려받기 | 2차 | XLSX(또는 CSV) 양식으로 한 번에 등록, 전체 내려받기 |
| M7 | 추천 매크로 | 2차 | 마지막 고객 메시지와 키워드가 맞는 매크로를 `/` 목록에 먼저 보여줌(AI 없음) |
| M8 | 사용 통계 | 2차 | 기간별 많이 쓴 매크로, 상담원별 사용량 |

1차는 "만들고 바로 쓰기", 2차는 "많이 만들고 잘 찾기" 입니다.

---

## 3. 기능 상세

### M1. 매크로 만들기/고치기

**화면 위치**: 상담 대화(`/console/chat`) 상단의 **[매크로]** 버튼 → 매크로 관리(`/console/macro`).
편집은 **드로어**(`openDrawer('macroEditDrawer')`, 3.7)에서 합니다. 드로어는 `templates/console/_macro_edit.html` + `macro-editor.js` 로
관리 화면과 상담 대화 화면([매크로로 저장])이 함께 씁니다.

```
매크로 편집                                         [x]
-----------------------------------------------------
범위      (o) 공용  ( ) 개인          ← 상담원에게는 '개인' 고정
이름      [ 환불 규정 안내                 ]  12/40
내용      +-------------------------------------+
          | {고객 이름}님, 환불은 결제일로부터   |
          | 7일 이내에 ...                       |
          +-------------------------------------+  320/2000
          변수: [고객 이름] [상담원 이름] [조직 이름]
키워드    [ 환불, 취소, 반품                 ]   ← 추천(M7)용, 선택
-----------------------------------------------------
                                     [취소] [저장]
```

입력 규칙

| 칸 | 규칙 |
|---|---|
| 이름 | 필수, 1~40자, 앞뒤 공백 제거, 줄바꿈 불가. **같은 범위 안에서 중복 불가**(공용끼리, 내 개인끼리). 공용과 개인은 이름이 같아도 됨 |
| 내용 | 필수, 1~2,000자, 줄바꿈 허용. **HTML 을 받지 않습니다**(화면에 낼 때 이스케이프). 위젯 메시지 최대 길이를 넘지 않게 맞춤 |
| 키워드 | 선택, 쉼표 구분 최대 300자. M7 추천에만 씀 |
| 범위 | 허용 목록 `org` / `personal` 검사(3.7). 상담원이 `org` 를 보내면 거부 |

개수 제한

| 범위 | 최대 |
|---|---|
| 공용 | 조직당 2,000개 |
| 개인 | 상담원당 300개 |

- 수치는 `settings/macro.py` 한 곳에서 고칩니다. 화면 저장과 파일 가져오기는 **같은 검사 함수 `macro_model.clean_macro()`** 를 거칩니다(가이드의 `clean_article` 과 같은 방식).

#### 변수

내용에 `{고객 이름}` 같은 칩을 넣으면 **불러올 때 실제 값으로 바뀐 채** 입력창에 채워집니다.

| 저장 형식 | 화면 표시 | 바뀌는 값 | 값이 없을 때 |
|---|---|---|---|
| `{visitor_name}` | 고객 이름 | `visitors.name` | 사전 문구 `macro.var.visitor_fallback`("고객") |
| `{agent_name}` | 상담원 이름 | 보내는 상담원 이름 | — |
| `{org_name}` | 조직 이름 | 조직 이름 | — |

- 저장은 영문 토큰, 화면에서만 언어별 이름으로 보여줍니다(조직 언어가 바뀌어도 매크로가 깨지지 않게).
- 치환은 **콘솔 JS 가 입력창에 채울 때** 합니다. 서버는 상담원이 최종 확인한 글을 그대로 저장합니다
  (치환 결과를 상담원이 눈으로 보고 보내야 하므로).
- 목록에 없는 `{...}` 는 그대로 둡니다. 저장할 때 알 수 없는 토큰이 있으면 경고만 하고 막지는 않습니다.

### M2. 입력창에서 불러오기

```
+--------------------------------------------------------------+
| / 환불                                                        |
+--------------------------------------------------------------+
|  ★ 즐겨찾기                                                   |
|    환불 규정 안내        {고객 이름}님, 환불은 결제일로부터… |
|  추천                                                         |
|    반품 접수 방법        반품은 마이페이지 > 주문 내역에서…  |
|  전체                                                         |
|    환불 완료 안내        요청하신 환불이 처리되었습니다…     |
|    부분 환불 안내        …                                    |
+--------------------------------------------------------------+
  ↑↓ 이동   Enter 넣기   Esc 닫기   Tab 미리보기
```

동작

- 입력창(`#chatInput`)이 **비어 있거나 커서 앞이 공백/줄 처음일 때** `/` 를 치면 목록이 열립니다.
  URL(`https://…/`) 처럼 글 중간의 `/` 에서는 열리지 않습니다.
- `/` 뒤 글자로 걸러냅니다. 이름 일치 → 이름 포함 → 내용 포함 순으로 묶고, 한글 초성 검색(`ㅎㅂ` → 환불)을 지원합니다.
- 목록 순서: **즐겨찾기 → 추천(M7, 2차) → 전체**. 전체 안에서는 개인 → 공용, 각각 최근 사용 순.
  같은 매크로는 위 묶음에 한 번만 보입니다.
- 선택하면 `/검색어` 부분을 **매크로 내용(변수 치환 후)으로 바꿉니다.** 기존 입력 글은 지우지 않습니다.
- 입력창 왼쪽에 **[/] 버튼**도 둡니다(마우스 사용자, `/` 를 모르는 상담원용). 커서 자리에 `/` 를 넣어 같은 목록을 엽니다.
- 한글 조합 중의 Enter 는 글자 확정이므로 목록 선택이나 전송으로 쓰지 않습니다(`event.isComposing`).
- 목록 데이터는 콘솔을 열 때 한 번 받아 브라우저 메모리에 두고, 관리 화면에서 바뀌면 다음 열기 때 다시 받습니다.
  2,300개(공용+개인 최대)도 수백 KB 이하라 한 번에 받습니다. 필터는 화면에서 합니다(서버 왕복 없음).
- 키보드 조작이 기본입니다. 목록이 열려 있을 때 Enter 는 전송이 아니라 선택입니다.

#### 사용 기록

- 매크로로 채운 글을 보내면 `api_chat_send` 에 **`macro_id`** 를 함께 넘깁니다(선택 후 입력창을 비우면 지움).
- 서버는 메시지 저장 뒤 그 매크로가 **이 상담원이 쓸 수 있는 것인지 확인하고**(공용 또는 본인 개인)
  `use_count`, `last_used_at` 을 올리고 `macro_uses` 에 한 줄 남깁니다. 확인에 실패해도 메시지 전송은 그대로 성공합니다.
- 고객에게는 매크로 사용 여부가 보이지 않습니다. `messages.meta` 에 넣지 않습니다
  (meta 는 위젯이 카드로 그리는 값이라 섞으면 위젯 표시가 깨질 수 있음).

### M3. 매크로 관리 목록

**주소**: `/console/macro` (모든 상담원). 레이아웃 `layouts/main.html`.

```
매크로                                   [양식 받기] [파일로 등록] [내려받기] [+ 매크로]
[ 전체 | 공용 | 개인 | 즐겨찾기 ]   [ 이름·내용 검색            ]

| ★ | 이름          | 내용(앞부분)           | 범위 | 만든 사람 | 사용 | 최근 사용   | 수정       |    |
|---|---------------|------------------------|------|-----------|------|-------------|------------|----|
| ★ | 환불 규정 안내 | {고객 이름}님, 환불은… | 공용 | 김관리    | 128  | 10-21 14:02 | 10-02 09:10 | ⋯ |
```

- 목록은 **wgrid**(`RHGrid.create()`, 기본 20개, 3.5)로 그립니다. 라우터에서 `'use_grid': True`.
- 컬럼 `key`: `_fav`, `name`, `body`, `scope`, `created_by_name`, `use_count`, `last_used_at`, `updated_at`, `_actions`.
  날짜 열은 `format: RHGrid.shortDateTime`(조직 시간대, 3.4).
- 정렬: 이름, 사용 횟수, 최근 사용, 수정 시각. 기본은 **최근 사용 내림차순**(안 쓴 것은 맨 아래).
- 행의 `⋯` 메뉴: 고치기 / 개인 매크로로 복사 / 삭제. 상담원에게 공용 행은 [고치기] [삭제] 가 없습니다.
- 여러 행 골라 삭제(관리자: 공용+본인 개인, 상담원: 본인 개인만). 삭제는 `showConfirmModal()` 확인 뒤.
- 오래 안 쓴 매크로를 정리할 수 있도록 [90일 이상 미사용] 필터를 둡니다.

### M4. 보낸 메시지로 만들기

- 대화창에서 **상담원이 보낸 메시지**(본인 또는 동료)에 마우스를 올리면 `[매크로로 저장]` 이 보입니다.
  고객 메시지, 시스템 메시지, 가이드 카드 메시지에는 없습니다.
- 누르면 M1 의 편집 드로어가 열리고 내용 칸이 그 메시지로 채워집니다. 이름은 내용 첫 줄 앞 20자로 미리 채웁니다.
- 고객 이름이 들어 있으면 `{고객 이름}` 으로 바꾸라고 안내만 합니다(자동 치환하지 않음 — 우연히 같은 단어일 수 있음).

### M5. 즐겨찾기

- 상담원마다 **최대 100개**. 공용, 개인 모두 즐겨찾기 할 수 있습니다.
- 관리 목록의 ★, `/` 목록의 미리보기 칸 ★ 로 켜고 끕니다.
- 관리 화면 [즐겨찾기] 탭에서 **끌어서 순서를 바꿉니다**. 이 순서가 `/` 목록 맨 위 순서입니다.
- 101번째를 누르면 `showToast('즐겨찾기는 100개까지입니다.', 'warning')`.
- 공용 매크로가 삭제되면 모든 상담원의 즐겨찾기에서도 빠집니다(FK CASCADE).

### M6. 파일 일괄 등록/내려받기 (2차)

처음 도입하는 조직이 기존 답변 모음(엑셀)을 옮겨 오는 용도입니다.

**양식** (`[양식 받기]` 로 내려받는 파일, 첫 행은 머리글)

| 열 | 설명 | 필수 | 제한 | 예 |
|---|---|---|---|---|
| `name` | 매크로 이름 | 필수 | 40자, 줄바꿈 불가 | 환불 규정 안내 |
| `content` | 매크로 내용 | 필수 | 2,000자, 셀 안 줄바꿈(Alt+Enter) 허용 | {visitor_name}님, 환불은… |
| `keywords` | 추천 키워드 | 선택 | 300자, 쉼표 구분 | 환불, 취소 |

- 범위는 파일에 넣지 않습니다. **올리는 화면의 탭(공용/개인)이 범위**입니다. 상담원은 개인만 올릴 수 있습니다.
- 파일 선택은 **Dropzone.js**(`RHImageDrop.create()`, `accept`, `maxMb`, `params` 옵션, 3.14). `<input type="file">` 을 쓰지 않습니다.
- 한 파일 최대 **1,000행, 5MB**. 개수 제한(M1)을 넘으면 전부 거부합니다(일부만 들어가는 일 없음).

**같은 이름이 이미 있을 때** — 올리기 전에 고릅니다.

| 방식 | 동작 |
|---|---|
| 건너뛰기(기본) | 기존 매크로를 그대로 두고 그 행은 넘김 |
| 덮어쓰기 | 기존 매크로의 내용·키워드를 바꿈(사용 횟수, 즐겨찾기는 유지) |

**처리 순서**

1. 서버가 파일 전체를 읽어 행마다 `clean_macro()` 검사.
2. **한 행이라도 오류가 있으면 아무것도 저장하지 않고** 오류 목록을 돌려줍니다.
   `{ result:false, message:'3개 행에 오류가 있습니다.', data:{ errors:[{row:5, field:'name', message:'40자를 넘습니다.'}] } }`
3. 오류가 없으면 한 트랜잭션으로 저장하고 `추가 n / 덮어씀 n / 건너뜀 n` 을 알립니다.
4. `audit_logs` 에 `macro_import` (파일명, 건수).

**내려받기**: 지금 탭(공용/개인)의 매크로를 같은 양식으로 내려받습니다. 백업과 일괄 편집 후 다시 올리기에 씁니다.

**파일 형식** — CSV 로 결정

- 포털에는 엑셀 라이브러리가 없습니다(`backend/requirements.txt` 에 넣지 않음).
- XLSX 는 ZIP 안의 XML 이라 표준 `zipfile` 로 첫 시트와 공유 문자열만 읽는 최소 파서를 만들 수 있으나, 지금은 만들지 않았습니다.
- **CSV(UTF-8, BOM 포함 — 엑셀에서 한글이 깨지지 않게)** 로 받고 내려줍니다. 엑셀 기본 저장(CP949)도 받습니다.
  머리글은 `name, content, keywords` 이며 한글 머리글(`이름, 내용, 키워드`)도 받습니다.
- 내려받을 때 `= + - @` 로 시작하는 칸은 엑셀이 수식으로 실행하지 않도록 앞에 `'` 를 붙이고, 가져올 때 뗍니다.
- `zip` 확장 추가가 결정되면 XLSX 를 더합니다.

### M7. 추천 매크로 (2차)

- 가이드 추천과 같은 방식으로 **AI 없이 키워드 매칭**합니다. 색인 만들기와 단어 쪼개기는 `Guide_matcher` 의
  `words()` / `grams()` 를 그대로 재사용합니다(조사 제거, 정규화가 같아야 결과가 일관됨).
- 색인 대상: 매크로 **이름 + 키워드**(가중치 높게), 내용(낮게). 매크로를 저장·삭제할 때 `macro_terms` 를 다시 만듭니다.
  저장은 반드시 `macro_model.save()` 를 거칩니다(가이드의 `save_article()` 규칙과 같음).
- 질의: 그 대화의 **마지막 고객 메시지 1건**. `/` 목록을 열 때 `GET /console/api/macro/suggest/{conversation_id}` 로 최대 3개를 받습니다.
- 기준 점수와 가중치는 `settings/macro.py` 에 둡니다. 기준 미만이면 추천 묶음을 숨깁니다.
- 개인 매크로는 본인 것만 후보에 넣습니다.

### M8. 사용 통계 (2차)

- 관리 목록의 `사용`, `최근 사용` 열이 1차 통계입니다.
- 조직 관리 통계(`/admin`)에 "많이 쓴 공용 매크로 10개(최근 30일)" 카드를 둡니다. 다른 통계와 같이 30일 고정입니다. `macro_uses` 를 집계합니다.
- 개인 매크로의 내용은 관리자 통계에 보이지 않습니다(건수만).

---

## 4. 권한 정리

| 행위 | 상담원 | 조직 관리자 |
|---|---|---|
| 공용 매크로 보기·쓰기 | O | O |
| 공용 매크로 만들기·고치기·지우기·파일 등록 | X | O |
| 개인 매크로 (본인) 전체 | O | O |
| 다른 사람의 개인 매크로 보기 | X | X |
| 즐겨찾기 (본인) | O | O |

- 공용 매크로를 바꾸는 API 는 `macro_model` 안에서 `role = 'admin'` 을 검사합니다. 거부 응답은
  `api_response(False, ..., None, 403)` 입니다(3.6).
- 모든 조회·수정은 `org_id = 로그인 상담원의 org_id` 조건을 겁니다. 개인 매크로는 `owner_agent_id = 본인` 까지 겁니다.

---

## 5. 데이터베이스 (마이그레이션 `011-macros.sql`)

`009` / `010` 은 상담 운영 기능(`docs/chat-operation-plan.md`)이 쓰므로 `011` 을 씁니다. `01-schema.sql` 에도 같은 정의를 넣었습니다.

```sql
-- 매크로
CREATE TABLE IF NOT EXISTS macros (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id          INT UNSIGNED NOT NULL,
  owner_agent_id  INT UNSIGNED DEFAULT NULL COMMENT 'NULL 이면 공용, 값이 있으면 그 상담원의 개인 매크로',
  owner_key       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '이름 중복 검사용. 공용 0, 개인은 owner_agent_id',
  name            VARCHAR(40)  NOT NULL,
  body            TEXT         NOT NULL,
  keywords        VARCHAR(300) DEFAULT NULL COMMENT '쉼표 구분 원문(추천용)',
  use_count       INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at    DATETIME     DEFAULT NULL,
  created_by      INT UNSIGNED DEFAULT NULL,
  updated_by      INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_macro_name (org_id, owner_key, name),
  KEY idx_macro_org (org_id, owner_agent_id, last_used_at),
  CONSTRAINT fk_macro_org   FOREIGN KEY (org_id)         REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_macro_owner FOREIGN KEY (owner_agent_id) REFERENCES agents (id)        ON DELETE CASCADE,
  CONSTRAINT fk_macro_cby   FOREIGN KEY (created_by)     REFERENCES agents (id)        ON DELETE SET NULL,
  CONSTRAINT fk_macro_uby   FOREIGN KEY (updated_by)     REFERENCES agents (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 즐겨찾기 (상담원별, 순서 포함)
CREATE TABLE IF NOT EXISTS macro_favorites (
  agent_id    INT UNSIGNED NOT NULL,
  macro_id    INT UNSIGNED NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (agent_id, macro_id),
  KEY idx_mfav_order (agent_id, sort_order),
  CONSTRAINT fk_mfav_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE,
  CONSTRAINT fk_mfav_macro FOREIGN KEY (macro_id) REFERENCES macros (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 사용 기록 (통계용)
CREATE TABLE IF NOT EXISTS macro_uses (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id           INT UNSIGNED    NOT NULL,
  macro_id         INT UNSIGNED    DEFAULT NULL,
  agent_id         INT UNSIGNED    DEFAULT NULL,
  conversation_id  BIGINT UNSIGNED DEFAULT NULL,
  message_id       BIGINT UNSIGNED DEFAULT NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_muse_org (org_id, created_at),
  KEY idx_muse_macro (macro_id, created_at),
  CONSTRAINT fk_muse_org   FOREIGN KEY (org_id)   REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_muse_macro FOREIGN KEY (macro_id) REFERENCES macros (id)        ON DELETE SET NULL,
  CONSTRAINT fk_muse_agent FOREIGN KEY (agent_id) REFERENCES agents (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 추천 색인 (2차, guide_terms 와 같은 구조)
CREATE TABLE IF NOT EXISTS macro_terms (
  org_id    INT UNSIGNED NOT NULL,
  term      VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  macro_id  INT UNSIGNED NOT NULL,
  weight    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (org_id, term, macro_id),
  KEY idx_mterm_macro (macro_id),
  CONSTRAINT fk_mterm_macro FOREIGN KEY (macro_id) REFERENCES macros (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `owner_key` 는 처음에 생성 컬럼(`AS (IFNULL(owner_agent_id, 0)) STORED`)으로 잡았으나, MySQL 은 CASCADE 외래 키가 걸린 컬럼을
  STORED 생성 컬럼의 원본으로 허용하지 않아(ERROR 1215) **일반 컬럼으로 두고 `macro_model` 이 채웁니다.**
  상담원이 지워지면 개인 매크로가 함께 지워지므로 값이 어긋날 일은 없습니다.
- 시각 컬럼은 모두 UTC 저장, 화면에서만 변환합니다(3.4).
- 상담원을 삭제하면 그 사람의 개인 매크로와 즐겨찾기가 함께 지워집니다. 공용 매크로는 `created_by` 만 비워지고 남습니다.
- `macro_uses` 는 쌓이기만 하므로 1년 지난 행은 정리 작업 대상으로 둡니다(8장).

---

## 6. API

모두 `{ result, message, data }` 형식(`api_response()`), CSRF 적용, 로그인 상담원 기준.

| 메서드 | 주소 | 설명 |
|---|---|---|
| GET | `/console/macro` | 관리 화면 |
| GET | `/console/api/macro/list` | 내가 쓸 수 있는 전체(공용+내 개인) + 내 즐겨찾기 순서. `/` 목록과 관리 목록 공용 |
| POST | `/console/api/macro/save` | 만들기/고치기 (`id` 있으면 고치기) |
| POST | `/console/api/macro/delete` | 삭제 (`ids[]`) |
| POST | `/console/api/macro/copy` | 공용 → 개인 복사 |
| POST | `/console/api/macro/favorite` | 즐겨찾기 켜기/끄기 |
| POST | `/console/api/macro/favorite_order` | 즐겨찾기 순서 저장 (`ids[]`) |
| GET | `/console/api/macro/suggest/{conversation_id}` | 추천 3개 (2차) |
| GET | `/console/macro/template` | 양식 파일 (2차) |
| POST | `/console/api/macro/import` | 파일 등록 (2차) |
| GET | `/console/macro/export?scope=org\|personal` | 내려받기 (2차) |
| POST | `/console/api/chat/send` | **기존.** 선택 인자 `macro_id` 추가 |

- 경로는 `routers/console_macro.py` 에 있고 `main.py` 에서 조직 코드 라우터보다 먼저 등록합니다(3.8).
- JS 는 템플릿에서 `base_url()` 로 만든 절대 주소를 **`MACRO_URLS`** 전역에 담아 씁니다(3.7). 콘솔 대화 화면은 기존 `CHAT_URLS` 에 `macroList`, `macroSuggest` 를 더합니다.
- `audit_logs` 기록: `macro_create`, `macro_update`, `macro_delete`, `macro_import` (공용 매크로만. 개인 매크로는 남기지 않음).

---

## 7. 화면과 파일

| 위치 | 변경 |
|---|---|
| `routers/console_macro.py` | 관리 화면과 API. `routers/console.py` 는 `api_chat_send` 의 `macro_id` 기록과 `chat()` 의 편집 설정만 더함 |
| `routers/admin.py`, `templates/admin/dashboard.html` | 많이 쓴 공용 매크로 카드(M8) |
| `models/macro_model.py` | `list_for_agent()`, `clean_macro()`, `save()`, `delete()`, `record_use()`, 즐겨찾기, 색인 |
| `settings/macro.py` | 개수 제한, 길이 제한, 파일 제한, 추천 가중치·기준 점수 |
| `templates/console/macro.html` | 관리 목록(wgrid) + 즐겨찾기 순서 + 파일 등록 모달 |
| `templates/console/_macro_edit.html` | 편집 드로어(관리 화면, 상담 대화 화면 공용) |
| `templates/console/chat.html` | 상단 [매크로] 링크, 입력창 옆 매크로 버튼, `/` 팝업 마크업, `CHAT_URLS` 추가 |
| `assets/js/console/macro.js` (신규) | 관리 화면 |
| `assets/js/console/macro-common.js` (신규) | 검색 순위(초성 포함), 변수 치환·표시 (`RHMacro`) |
| `assets/js/console/macro-editor.js` (신규) | 편집 드로어 (`RHMacroEditor`) |
| `assets/js/console/macro-picker.js` (신규) | `/` 팝업: 필터, 키보드 조작, 변수 치환. chat.js 에서 `RHMacroPicker.attach({...})` |
| `assets/js/image-drop.js` | 실패 응답 본문을 받는 `onError` 옵션(파일 등록 오류 행 표시) |
| `assets/js/console/chat.js` | 전송에 `macro_id` 추가, 내 메시지 hover [매크로로 저장] |
| `assets/lang/{ko,en,ja}.json` | `macro.visitor_fallback`(고객 이름이 없을 때). **세 파일 모두**(3.2). 콘솔 화면 문구는 다른 콘솔 화면처럼 한국어로 둠 |
| `assets/css/tailwind.src.css` | 팝업 스타일이 필요하면 추가 후 `./scripts/build-css.sh`(3.1) |
| `sql/migrations/010-macros.sql`, `sql/01-schema.sql` | 5장 |
| `scripts/check-conventions.py` | 아래 검사 추가 |

- 뷰에서 JS 는 `asset_url('assets/js/console/macro.js')` 로 넣습니다(3.1).
- 아이콘(예: `bolt`, `star`, `upload_file`, `download`)을 쓰면 `home.landing_icons()` 목록에도 넣습니다(3.3).
- 위젯(`widget.js`)은 바뀌지 않습니다. 고객에게는 일반 상담원 메시지로만 보입니다.

### 규칙 검사 (`check-conventions.py` 의 `check_macro`)

| 검사 | 이유 |
|---|---|
| `macros` 테이블에 INSERT/UPDATE/DELETE 하는 곳이 `macro_model` 밖에 없는지 | 색인(M7)과 검사(`clean_macro`)를 우회하지 않게 |
| `macro*.js` 가 `innerHTML` / `.html()` 에 값을 넣지 않는지 | 매크로 내용은 사용자가 쓴 글 — 이스케이프 누락 방지 |
| `api_chat_send` 에 `record_use()` 와 발행이 함께 있는지 | 매크로 전송이 기존 저장·발행 경로를 벗어나지 않게 |

세 검사 모두 일부러 어겨 보고 잡히는 것을 확인했습니다(CLAUDE.md 7장).

---

## 8. 작업 순서 제안

| 단계 | 내용 | 끝났다고 보는 기준 |
|---|---|---|
| 1 | 마이그레이션 + `macro_model` + `settings/macro.py` | 스키마 적용, 모델 단위 동작 |
| 2 | 관리 화면(M1, M3) + 다국어 3종 | 공용/개인 만들기·고치기·지우기, 권한 거부 확인 |
| 3 | `/` 팝업(M2) + 사용 기록 | 콘솔에서 불러와 보내기, 위젯에 정상 수신, `use_count` 증가 |
| 4 | 즐겨찾기(M5) + 보낸 메시지로 만들기(M4) | 순서 바꾸기, 100개 제한 |
| 5 | 파일 등록/내려받기(M6) | 오류 행 보고, 전부 또는 전무 저장 |
| 6 | 추천(M7) + 통계(M8) | 가이드 추천과 같은 정규화로 결과 확인 |

각 단계 끝에 `./scripts/check-conventions.sh` 를 통과시킵니다.
매크로 전송은 기존 실시간 발행 경로를 그대로 쓰지만, 3단계 뒤에는 위젯 쪽 수신을 실제로 확인합니다.

---

## 9. 확인 사항

정한 것(기본안대로)

1. **파일 형식**: CSV. XLSX 는 필요해지면 `zipfile` 기반 파서나 라이브러리 추가를 검토합니다.
2. **진입 위치**: 헤더 메뉴에 두지 않고 상담 대화 화면의 [매크로] 버튼과 입력창 / 목록의 [매크로 관리] 로 들어갑니다.
3. **개수 제한**: 공용 2,000 / 개인 300 / 즐겨찾기 100 (`settings/macro.py`).
4. **이미지·파일 첨부 매크로**, **가이드 카드 매크로**: 넣지 않았습니다.

아직 열려 있는 것

5. **사용 기록 보관 기간**: `macro_uses` 를 1년 뒤 지울지, 집계 후 지울지. 지금은 지우지 않습니다.
6. **조직 언어 변경 시**: 매크로 내용은 조직이 쓴 글이라 번역하지 않습니다. 조직당 한 언어 원칙(가이드와 동일)으로 충분한지.

---

## 10. 자주 묻는 질문 (도움말 초안)

**매크로를 분류(폴더)로 나눌 수 있나요?**
1차에는 분류가 없습니다. 이름 앞에 `[환불]`, `[배송]` 처럼 머리말을 붙이면 `/` 검색으로 묶어 찾을 수 있습니다.
사용 빈도와 즐겨찾기로 충분하지 않다는 의견이 모이면 분류를 더합니다.

**매크로는 어떤 순서로 보이나요?**
`/` 목록은 즐겨찾기(내가 정한 순서) → 추천 → 나머지(최근에 쓴 순) 입니다.

**다른 상담원이 만든 매크로도 쓸 수 있나요?**
공용 매크로는 조직의 모든 상담원이 씁니다. 개인 매크로는 만든 사람만 보고 씁니다.

**매크로를 고치면 이미 보낸 메시지도 바뀌나요?**
아니요. 보낸 메시지는 보낸 순간의 글로 남습니다.

**매크로 내용으로도 검색되나요?**
네. `/` 뒤에 쓴 글자는 이름과 내용 모두에서 찾습니다. 이름이 맞는 것이 먼저 보입니다.

**고객 이름이 없을 때 `{고객 이름}` 은 어떻게 되나요?**
"고객" 으로 바뀝니다(조직 언어에 맞춰). 보내기 전에 입력창에서 고칠 수 있습니다.

**파일로 등록하다가 일부만 들어가면 어떡하나요?**
일부만 들어가는 일은 없습니다. 오류가 하나라도 있으면 아무것도 저장하지 않고 오류 행 번호를 알려 드립니다.

**매크로를 내려받아 고친 뒤 다시 올릴 수 있나요?**
네. 내려받은 파일을 고쳐 [파일로 등록] 에서 "같은 이름은 덮어쓰기" 를 고르면 됩니다.
