<!--
  파일 위치: docs/widget-plan.md
  역할: 상담 위젯 기능을 RemoteHelp 포털 안에서 만들기 위한 작업요청서
-->

# 상담 위젯 — 작업요청서 (RemoteHelp 통합판)

별도 사이트로 계획했던 "상담 위젯 미니 SaaS" 를 이 저장소 안의 기능으로 옮겨 적은 문서입니다.
원본 요청서의 컨벤션 중 이 프로젝트와 어긋나는 부분은 `CLAUDE.md` 쪽으로 맞췄고,
바뀐 항목은 8장에 이유와 함께 정리했습니다.

---

## 1. 무엇을 만드는가

고객사 웹사이트에 붙이는 채팅 버블 위젯과, 상담원이 응대하는 콘솔 화면입니다.
방문자가 위젯에서 글을 남기면 상담원 콘솔에 뜨고, 상담원이 답하면 위젯에 표시됩니다.

**이 저장소에 넣었을 때 생기는 차별점**

원본 요청서의 "차별화 방향" 은 미정이었지만, 통합하면 답이 하나 나옵니다.
채팅으로 해결이 안 될 때 **상담원이 대화창에서 바로 원격지원 코드를 발급**할 수 있습니다.

```
방문자: "설정이 안 돼요"  --(위젯 채팅)-->  상담원
상담원: [원격지원 시작] 클릭 -> 6자리 코드가 대화창에 표시
방문자: 코드 입력 -> 런처 실행 -> 화면 공유
```

채팅 위젯만 파는 곳도, 원격지원만 파는 곳도 있지만 둘을 한 화면에서 잇는 곳은 드뭅니다.
그래서 이 문서는 **채팅과 원격지원의 연결(5장 5-4)을 MVP 범위 안에 넣습니다.**

---

## 2. MVP 범위

1. 조직 관리 화면에서 위젯 스니펫 발급 — 고객사 사이트에 붙이면 채팅 버블 노출
2. 방문자가 위젯에서 메시지 전송 (이름/이메일은 선택)
3. 상담원 콘솔에서 대화 목록 확인과 응답 (폴링)
4. 상담원이 모두 오프라인이면 이메일 알림 발송 (대화당 1회)
5. 대화 이력 저장과 조회 (wgrid)
6. 대화 중 원격지원 코드 발급 — 기존 세션 발급 흐름 재사용

### 2-1. 이후 확장 (MVP 제외)

- 키워드 기반 FAQ 자동응답 (단순 매칭)
- 파일 첨부
- 중계 서버(`relay/`)를 통한 실시간 푸시 — 3-1 참고
- 카카오톡 채널 연동, 통계 대시보드

---

## 3. 구조

```
[고객사 웹사이트]
   └─ <script src="https://.../widget.js" data-key="..."> 
         │  Shadow DOM 안에 버블과 대화창을 그린다 (고객사 CSS 와 섞이지 않는다)
         │  AJAX: /api/widget/init, /send, /poll
         ▼
[포털 — FastAPI (backend/)]
   ├─ 테넌시: organizations.widget_key + 허용 도메인 검사
   ├─ 라우터: widget(공개) / console(상담원) / admin(설정)
   └─ 오프라인 시 이메일 알림
         │
         ▼
[상담원 콘솔 — layouts/main.html]
   └─ 대화 목록(wgrid) + 대화창(실시간 + 폴링) + [원격지원 시작]
```

### 3-1. 실시간 통신 — realtime-msg 컨테이너

처음에는 3초 폴링으로 시작했지만, 지금은 **`realtime-msg` 컨테이너(Node + ws)** 가 메시지를 밀어 줍니다.

```
위젯 / 콘솔  --ws  /ws?token=-->  realtime-msg   (구독만 한다)
포털         --POST /publish--->  realtime-msg   (저장한 뒤 발행한다)
realtime-msg --GET /api/realtime/auth--> 포털    (구독 토큰 검증)
```

- 원격 화면을 나르는 `relay/` 와는 **다른 서버**입니다. `relay` 는 1:1 세션 중계, `realtime-msg` 는
  방(room) 단위 발행/구독입니다. 인증 모델도 접속 수명도 달라 한 서버에 합치지 않았습니다.
- **메시지 저장은 포털이 그대로 합니다.** 웹소켓으로는 아무것도 저장하지 않습니다.
  저장 경로가 하나여야 순서와 권한 검사가 어긋나지 않습니다.
- 구독 토큰은 포털이 HMAC 으로 서명합니다. 방문자는 `conv:{대화 id}`, 상담원은 `org:{조직 id}` 만 봅니다.
- **폴링은 남겨 둡니다.** 연결이 끊긴 동안 놓친 메시지를 줍는 보조 수단입니다.
  실시간으로 받는 중에는 간격을 늘립니다(위젯 3초 → 30초, 콘솔 목록 5초 → 20초).
- 콘솔 목록 폴링은 `agents.last_seen_at` 을 갱신해 상담원 접속 여부를 정하므로 멈추지 않습니다.

---

## 4. 데이터베이스

원본 요청서의 `companies` / `agents` 는 **새로 만들지 않습니다.** 이미 있는 것을 씁니다.

| 원본 요청서 | 이 저장소 |
|---|---|
| `companies` | `organizations` (컬럼 추가) |
| `agents` | `agents` (그대로, `last_seen_at` 추가) |
| `visitors` | 신규 |
| `conversations` | 신규 |
| `messages` | 신규 |

마이그레이션은 `sql/migrations/004-chat-widget.sql` 로 만들고 `sql/01-schema.sql` 에도 반영합니다.

### 4-1. organizations 추가 컬럼

| 컬럼 | 설명 |
|---|---|
| `widget_enabled` | TINYINT(1) 기본 0 |
| `widget_key` | CHAR(32) UNIQUE — 스니펫에 들어가는 공개 키. 재발급 가능 |
| `widget_domains` | VARCHAR(500) — 줄바꿈 구분 허용 도메인. 비우면 어디서나 허용(개발용) |
| `widget_color` | CHAR(7) 기본 `#2563eb` |
| `widget_position` | ENUM('right','left') 기본 right |
| `widget_offline_email` | VARCHAR(190) — 오프라인 알림 받을 주소 |

`org_code` 를 그대로 키로 쓰지 않는 이유: URL 에 이미 노출돼 있고 회수할 수 없습니다.
`widget_key` 는 도용 시 재발급할 수 있어야 합니다.

### 4-2. 신규 테이블

```
visitors      id, org_id, visitor_key CHAR(64) UNIQUE, name, email,
              ip, user_agent, page_url, first_seen_at, last_seen_at

conversations id, org_id, visitor_id, status ENUM('open','closed'),
              assigned_agent_id, subject, last_message_at,
              offline_notified_at, created_at, closed_at

messages      id, conversation_id, sender_type ENUM('visitor','agent','system'),
              agent_id, body TEXT, attachment_url, read_at, created_at
```

- 모든 조회는 `org_id` 로 거릅니다. `messages` 는 `conversations` 를 거쳐 조직을 확인합니다.
- 시각은 전부 UTC 저장입니다(`CLAUDE.md` 3.4). 화면에 그릴 때만 변환합니다.
- 인덱스: `idx_conv_org_status_last (org_id, status, last_message_at)`,
  `idx_msg_conv (conversation_id, id)`.

---

## 5. 만들 파일과 화면

### 5-1. 위젯 (공개)

| 파일 | 내용 |
|---|---|
| `backend/assets/js/widget.js` | 임베드 스크립트. Shadow DOM, 자체 CSS, 의존성 없음 |
| `backend/app/routers/widget.py` | 위젯 공개 API |

경로 — `main.py` 에서 조직 코드 라우터(`customer.org_router`)보다 **먼저** 등록합니다 (`CLAUDE.md` 3.8).

```
GET  /widget.js           widget.script
POST /api/widget/init     widget.init
POST /api/widget/send     widget.send
POST /api/widget/contact  widget.contact
GET  /api/widget/poll     widget.poll    (realtime=1 이면 새 구독 토큰도 내려 준다)
```

스니펫 형태:

```html
<script src="https://help.example.com/widget.js" data-key="WIDGET_KEY" defer></script>
```

- `widget.js` 는 **Tailwind 도 `common.js` 도 `i18n.js` 도 쓰지 않습니다.**
  고객사 사이트 안에서 도는 코드라 `app.css` 가 없고, 있어도 고객사 스타일과 충돌합니다.
  Shadow DOM(`attachShadow`) 안에 자체 CSS 를 넣습니다. (`CLAUDE.md` 3.9)
- 문구는 `/api/widget/init` 응답에 담아 서버가 내려줍니다. 위젯이 사전 파일을 직접 읽지 않습니다.
- 언어는 **조직 설정**을 따릅니다. 고객이 보는 화면이기 때문입니다(`CLAUDE.md` 3.2 표).

### 5-2. 상담원 콘솔

| 파일 | 내용 |
|---|---|
| `backend/app/templates/console/chat.html` | 대화 목록 + 대화창 |
| `backend/assets/js/console/chat.js` | 폴링, 전송, 원격지원 연결 |

```
/console/chat                          console.chat
/console/api/chat/list                 console.api_chat_list
/console/api/chat/messages/{id}        console.api_chat_messages
/console/api/chat/send                 console.api_chat_send
/console/api/chat/close                console.api_chat_close
```

(`routers/console.py`, 모두 `Depends(current_agent)`)

- 대기 대화 목록은 wgrid(`RHGrid.create`, 기본 20개)로 그리고, 라우터에서 `'use_grid': True`
  를 넘깁니다. 날짜 열은 `RHGrid.dateTime` 을 씁니다.
- `layouts/main.html` 메뉴에 "상담 대화" 가 있습니다. 운영자 메뉴에는 넣지 않습니다.
- 대화창은 새 화면이 아니라 같은 화면의 오른쪽 패널입니다. 페이지를 새로 그리지 않습니다.

### 5-3. 조직 관리

`backend/app/templates/console/_widget_settings.html`(상담 대화 > 설정 드로어) — 위젯 켜기/끄기, 스니펫 복사, 색상·위치,
허용 도메인, 오프라인 알림 주소, 키 재발급. 저장은 `routers/admin.py` 의 `/admin/api/widget_save`, `/admin/api/widget_regenerate`
(조직 관리자만, `Depends(require_admin)`)입니다.

키 재발급과 도메인 변경은 `audit_logs` 에 남깁니다(`CLAUDE.md` 3.7).

### 5-4. 채팅에서 원격지원 시작

대화창의 [원격지원 시작] 버튼은 **새 흐름을 만들지 않고** 기존
`console/api/session/create` 를 그대로 호출합니다. 발급된 6자리 코드와 접속 주소를
`sender_type='system'` 메시지로 대화에 넣으면 위젯에 그대로 표시됩니다.

원격 세션과 대화를 잇기 위해 `conversations.session_id`(NULL 허용)를 둡니다.

---

## 6. 보안

위젯 API 는 로그인 없이 외부 도메인에서 호출됩니다. 다음을 지킵니다.

| 항목 | 방법 |
|---|---|
| 테넌시 | `widget_key` 로 조직을 찾고, 이후 모든 조회에 `org_id` 를 건다 |
| 도메인 | `Origin` 헤더가 `widget_domains` 허용 목록에 있을 때만 CORS 헤더를 내려준다 |
| 방문자 인증 | 서버가 `visitor_key`(64 hex)를 발급해 localStorage 에 저장. 대화 조회 시 대조 |
| CSRF | `config['csrf_exclude_uris']` 에 `api/widget/.*` 추가. 대신 Origin + `visitor_key` 로 막는다 |
| 도배 | 방문자당 분당 메시지 수 제한. 넘으면 429 와 `result:false` |
| 입력 | 본문은 저장 시 원문, 출력 시 이스케이프. 상태값·색상·위치는 허용 목록 검사(`CLAUDE.md` 3.7) |

응답은 모두 `api_response()` 형식입니다.

---

## 7. 오프라인 이메일 알림

- 온라인 판별: `agents.last_seen_at` 을 콘솔 폴링이 갱신하고, **60초 이내**면 온라인으로 봅니다.
- 조직의 상담원이 모두 오프라인일 때 방문자의 첫 메시지에 대해 1회 발송하고
  `conversations.offline_notified_at` 을 기록합니다. 같은 대화에서 다시 보내지 않습니다.
- 받는 주소는 `organizations.widget_offline_email`, 비어 있으면 조직 관리자 이메일입니다.
- **이 저장소에는 아직 메일 발송 코드가 없습니다.** SMTP 설정을 `.env` 에 새로 넣어야 합니다
  (9장 확인 필요 사항).

---

## 8. 원본 요청서에서 바뀐 것

| 원본 | 이 저장소 | 이유 |
|---|---|---|
| Bootstrap 5.3 | Tailwind + `.btn` `.card` `.form-input` `.badge` `.table` | 3.1. 외부 UI 프레임워크를 쓰지 않는다 |
| pqGrid | wgrid 0.1.0 코어, 기본 20개 | 3.5 |
| `companies` 테이블 신설 | `organizations` 재사용 | 조직·상담원·권한 체계가 이미 있다 |
| 다국어는 2단계 | **1단계 필수**, ko/en/ja 세 사전 동시 수정 | 3.2. 키가 빠지면 그 언어에서 키가 그대로 보인다 |
| 시간 언급 없음 | UTC 저장, 표시할 때만 변환 | 3.4 |
| 안내 문서 지양 | 이 문서 외 추가 문서 없음 | 1장. 가이드 문서는 요청받았을 때만 |
| 차별화 방향 미정 | 채팅 + 원격지원 연결 | 1장 |

jQuery, AJAX MVC, Toast/Modal, 한국어 화면·커밋은 원본 그대로입니다. 이미 같은 방식입니다.

---

## 9. 확인이 필요한 사항

1. **요금** — 원본은 9,900원/상담원/월, 현재 `routers/home.py` 의 `pricing` 은 연 220,000원
   (상담원 5명). 위젯을 별도 상품으로 파는지, 기존 요금에 포함하는지 정해야 `pricing` 을
   고칠 수 있습니다.
2. **메일 발송 수단** — SMTP 서버 정보가 필요합니다. 외부 발송 서비스를 쓸지도 정해야 합니다.
3. **파일 첨부** — MVP 에서 뺐습니다. 넣어야 하면 업로드 경로와 용량 제한을 정합니다.
4. **소개 페이지 반영** — 위젯을 상품으로 소개할지, 내부 기능으로만 둘지.

## 10. 작업 순서와 진행 상황

| 단계 | 내용 | 상태 |
|---|---|---|
| 1 | 마이그레이션 + 모델 (`chat_model`, `visitor_model`) | 완료 |
| 2 | `routers/widget.py` + `widget.js` — 버블 노출과 메시지 전송 | 완료 |
| 3 | 콘솔 대화 목록/응답 (폴링) | 완료 |
| 4 | 조직 관리 설정 화면과 스니펫 발급 | 완료 |
| 5 | 채팅에서 원격지원 코드 발급 연결 | 완료 |
| 6 | 오프라인 이메일 알림 | 완료(SMTP 설정 대기) |
| 7 | 실시간 메시지 서버(`realtime-msg`) 도입 | 완료 |

브라우저에서 위젯이 실제로 그려지는 모습은 아직 육안으로 확인하지 않았습니다.
API 왕복과 서버 응답까지는 개발 환경에서 확인했습니다.

각 단계마다 `./scripts/check-conventions.sh` 를 통과시키고, 새 Tailwind 클래스를 썼으면
`./scripts/build-css.sh` 를 돌립니다.
