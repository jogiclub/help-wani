# RemoteHelp 프로젝트 지침

이 파일은 이 저장소에서 작업할 때 지켜야 할 규칙을 모아 둔 곳입니다.
**새 규칙이 정해지면 반드시 여기에 추가합니다.**

---

## 1. 작업 방식

- 한국어로 답변합니다. 코드 설명에 이모티콘을 쓰지 않습니다.
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
| `relay/` | Node.js 중계 서버 |
| `launcher/` | .NET 8 WPF 런처 + 원격제어 엔진 |
| `docker/` | 개발용 컨테이너 |
| `server/` | 중계 서버 설치 스크립트 |
| `sql/` | 스키마, 마이그레이션, 시드 |
| `scripts/` | 검증/빌드 스크립트 |
| `docs/` | 프로토콜, 검증 보고서, 제출 문서 |

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
| `layouts/main.php` | 조직 소속 사용자 | 대기열 / 상담 이력 / 조직 관리 |
| `layouts/operator.php` | 플랫폼 운영자 | 조직 가입 관리 / 감사 로그 |
| `layouts/public.php` | 방문자 | 소개 페이지 |
| `layouts/customer.php` | 고객 | 접속 안내, 만족도 조사 |

| 권한 | 범위 | 판별 |
|---|---|---|
| 상담원 | 자기 조직 상담 | `agents.role = 'agent'` |
| 조직 관리자 | 자기 조직 설정 | `agents.role = 'admin'` |
| 플랫폼 운영자 | 전체 조직 승인 | `agents.is_super = 1` |

운영자 전용 화면은 `Operator_Controller` 를 상속합니다.

### 3.7 API 와 UI 공통 규약

- 모든 API 응답은 `{ "result": true|false, "message": "", "data": {} }` 형식입니다. `api_response()` 를 씁니다.
- 알림은 `showToast(message, type)`, 확인은 `showConfirmModal(title, message, onConfirm, onCancel)`,
  모달은 `openModal(id)` / `closeModal(id)` 를 씁니다. 외부 UI 프레임워크를 쓰지 않습니다.
- 관리 행위는 `audit_logs`, 세션 이벤트는 `session_logs` 에 남깁니다.
- 사용자 입력으로 상태를 바꿀 때는 **허용 목록 검사**를 합니다(언어, 시간대, 국가, 상태값 등).

### 3.8 라우팅 순서

`$route['(:any)']` 는 조직 코드를 받는 규칙이라 **반드시 맨 아래**에 둡니다.
새 최상위 경로를 추가할 때는 그보다 위에 넣습니다.

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

윈도우 런처는 이 환경에서 빌드할 수 없습니다. 코드 작성까지만 하고 실기 검증은 별도로 표시합니다.

---

## 6. 현재 결정 사항

| 항목 | 결정 |
|---|---|
| 배포 경로 | **1차는 Microsoft Store(MSIX) 단독.** 직접 다운로드는 `DIRECT_DOWNLOAD_ENABLED=false` 로 꺼 둠 |
| 코드 서명 | Store 는 Microsoft 가 서명하므로 불필요. 직접 배포 시 OV 인증서 필요(`docs/code-signing.md`) |
| 요금 | 연 220,000원 / 상담원 계정 5개. `Home::index()` 의 `$pricing` 한 곳에서 관리 |
| 화면 캡처 | GDI 우선, 가능하면 DXGI 자동 전환 |
| 중계 서버 | Node.js + ws |

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
