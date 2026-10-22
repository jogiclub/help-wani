# 화면 개편 계획 — Tailwind 에서 Bootstrap 6 + 왔니 디자인 시스템으로

작성 2026-10-22. 백엔드 FastAPI 전환(`docs/fastapi-migration-plan.md`)이 끝나서 다음 순서로 화면을 바꿉니다.
이 문서는 **바꾸는 동안의 기준**입니다. 단계가 끝날 때마다 표의 상태를 고치고, 마지막 단계에서 CLAUDE.md 3장에 정식 규칙으로 옮깁니다.

**확정(2026-10-22, 사용자 승인):** 이 문서의 제안을 모두 그대로 따릅니다.
Bootstrap `6.0.0-alpha.1` 고정 사용, 고객 화면은 Bootstrap 없이 일반 CSS, 반응형 기준은 Bootstrap 6 값(1024/1280px),
조직 관리 통계는 사이드바 OVERVIEW 로 이동, 아이콘은 Bootstrap Icons 로 통일, jQuery 는 이번 개편에서 유지.
관리자 화면 시안(조직 관리 통계, 상담원 관리 + 수정 드로어)은 Design 캔버스 "RemoteHelp 화면 개편" 에 있습니다.

---

## 1. 목표와 범위

| 화면 묶음 | 레이아웃 | 바꾼 뒤 | 디자인 |
|---|---|---|---|
| 조직 사용자(상담원·조직 관리자) | `layouts/main.html` | Bootstrap 6 | **왔니 디자인 시스템**(앱 셸, 사이드바, 패널, 드로어) |
| 플랫폼 운영자 | `layouts/operator.html` | Bootstrap 6 | **왔니 디자인 시스템** |
| 로그인·가입 | `layouts/blank.html` | Bootstrap 6 | 왔니 색·글꼴, 가운데 카드 하나 |
| 소개 페이지(방문자) | `layouts/public.html` | Bootstrap 6 | 왔니 색·글꼴을 쓰되 셸 없이 소개용으로 새로 구성 |
| 고객 화면(접속 안내, 만족도, 다운로드 불가) | `layouts/customer.html` | **Bootstrap 미사용**, 일반 CSS | 왔니 색·글꼴 토큰만 공유(4.4 이유) |
| 공개 가이드 | `layouts/guide.html` + 테마 | **바꾸지 않음** | `guide-themes.css` 그대로(이미 Tailwind 밖) |
| 상담 위젯 | `assets/js/widget.js` | **바꾸지 않음** | 고객사 사이트 안의 shadow DOM(CLAUDE.md 3.9) |

하지 않는 것

- 백엔드 라우터·API·응답 형식은 바꾸지 않습니다. 화면 마크업, CSS, 화면 JS 만 바꿉니다.
- wgrid(CLAUDE.md 3.5), Dropzone(3.14)은 그대로 쓰고 색만 새 토큰에 맞춥니다.
- 왔니의 `wani-*.js` 컴포넌트(jQuery + Bootstrap 5 기반)는 가져오지 않습니다. 모양만 우리 CSS 로 옮깁니다(3장).

---

## 2. 확인한 사실 (2026-10-22, 공식 문서와 배포 파일 기준)

CLAUDE.md 1장의 "외부 도구는 추측하지 않는다" 규칙에 따라 아래는 모두 원문 또는 배포 파일에서 확인한 값입니다.

| 항목 | 확인 결과 | 출처 |
|---|---|---|
| 버전 | **`6.0.0-alpha.1`** (알파). npm `next` 태그, 2026-10-08 배포. `latest` 는 여전히 5.3.8 | getbootstrap.com/docs/6.0/getting-started/install, registry.npmjs.org/bootstrap |
| 배포 파일 | `dist/css/bootstrap.min.css`(381KB), `dist/js/bootstrap.bundle.min.js`(220KB, Floating UI·Vanilla Calendar Pro 포함) | jsdelivr `bootstrap@6.0.0-alpha.1` |
| 무결성 | CSS `sha384-B/GM4XqrwHnWXNOWMbloTmrYXZg10cakYGmpfsR/bbzQ6JAJI4ihuyADKLnBgrCe`, 번들 `sha384-1a/pXj49ZQ1aHEmrJ+gMw1otqoVsYwlEnlD8mIfY2TV03r20Y0CN7uqx1tQogjPL` | 설치 문서 |
| JS 형식 | **ESM 전용.** `<script type="module">` 이어야 하고 `window.bootstrap` 전역이 없음. 번들 끝이 `export{Alert,Button,Carousel,Chips,Collapse,Combobox,Datepicker,Dialog,Drawer,Menu,NavOverflow,OtpInput,Popover,Range,ScrollSpy,Strength,Tab,Toast,Toggler,Tooltip}` | 마이그레이션 문서, 번들 파일 끝 |
| jQuery | Bootstrap 의 jQuery 지원 제거. 우리 코드가 jQuery 를 쓰는 것은 무관 | 마이그레이션 문서 |
| 브라우저 하한 | Chrome·Edge 130, Firefox 132, **Safari 18(macOS·iOS)**. 폴리필 없음(`light-dark()`, `:has()`, 컨테이너 쿼리, `oklch()`, 네이티브 `<dialog>` 사용) | 마이그레이션 문서 |
| CSS 변수 접두사 | 배포 CSS 는 **`--bs-`** (문서 예시는 접두사가 섞여 있으나 실제 파일은 `--bs-primary-base` 등) | `bootstrap.min.css` |
| primary 토큰 | `--bs-primary-base` `-bg` `-bg-muted` `-bg-subtle` `-border` `-contrast` `-fg` `-fg-emphasis` `-focus-ring` | 같은 파일 |
| 파란색 직접 사용 | `--bs-blue-*` 를 primary 정의 밖에서 쓰는 규칙 **없음**. 5.3 처럼 컴포넌트별로 덮어쓸 필요가 없다 | 같은 파일 검색 |
| 다크 모드 | 항상 들어 있음(`light-dark()`). `[data-bs-theme=light]{color-scheme:light}` 로 고정 가능 | 같은 파일 |
| 브레이크포인트 | sm 576 / md 768 / **lg 1024** / **xl 1280** / **2xl 1536**(이전 xxl). 미디어 쿼리는 `width>=1024px` 형식 | 마이그레이션 문서, 배포 CSS |
| 반응형 클래스 | 접두사 방식: `.col-md-6` → `.md:col-6`, `.d-md-none` → `.md:d-none` | 마이그레이션 문서 |
| 간격 단위 | 키가 바뀜: 1=4px, 2=6px, **3=8px, 4=12px, 5=16px**, 7=24px, 12=48px (`--bs-spacer-*`) | 배포 CSS |
| 모서리 | `--bs-radius-0`~`9`, 3=4px, 4=6px, 5=8px, 9=16px | 배포 CSS |
| 함께 쓸 것 | `bootstrap-icons` **1.13.2**(2026-10-08), `pretendard` **1.3.9** | registry.npmjs.org |

### 2.1 5.3 에서 바뀐 이름 (이 프로젝트에 해당하는 것만)

| 5.3 / 왔니 원본 | 6.0 |
|---|---|
| `.modal`, `data-bs-toggle="modal"` | `<dialog class="dialog">`, `data-bs-toggle="dialog"` |
| `.offcanvas-end` | `<dialog class="drawer drawer-end">`, `data-bs-dismiss="drawer"` |
| `.dropdown-menu` / `.dropdown-item` | `.menu` / `.menu-item`, `data-bs-toggle="menu"` |
| `.btn-primary` / `.btn-outline-primary` | `.btn-solid.theme-primary` / `.btn-outline.theme-primary` |
| `.badge.bg-primary` | `.badge.theme-primary` (`.badge-subtle`, `.badge-outline`) |
| `.text-*`, `.text-muted` | `.fg-*` (`.text-muted` 없음, `.fg-3` 사용) |
| `.form-select` | `<select class="form-control">` |
| `.form-check-input` | `<input class="check">` / `.radio`, 스위치는 `.switch` 감싸기 |
| `.bg-light`, `.bg-body-tertiary` | `.bg-1`, `.bg-2` |
| `.fs-1`~`.fs-6` | `.fs-4xl`~`.fs-md` |
| `.was-validated` | `<form data-bs-validate>` |
| 메서드 반환 | `show()`/`hide()` 가 Promise 를 돌려줌 |

---

## 3. 왔니 디자인 시스템을 Bootstrap 6 에 옮기는 방법

왔니 원본은 **Bootstrap 5.3 + `color.css` + `wani-*.js`** 입니다. 원본 화면 구성과 값(토큰)은 따르고, 구현은 6.0 기준으로 다시 만듭니다.

### 3.1 토큰

- 디자인 시스템의 `tokens.json` 값을 `backend/assets/css/wani.css` 맨 위 `:root, :host` 에 옮깁니다(값을 손으로 바꾸지 않음).
- Bootstrap 연결은 primary 토큰만 덮어씁니다. 5.3 의 `-rgb` 변수와 컴포넌트별 덮어쓰기는 6.0 에 없으므로 옮기지 않습니다.

```css
:root, :host {
    --bs-primary-base: var(--wani-primary);
    --bs-primary-bg: var(--wani-primary);
    --bs-primary-fg: var(--wani-primary);
    --bs-primary-border: var(--wani-primary-focus-border);
    --bs-primary-contrast: #fff;
    --bs-primary-focus-ring: var(--wani-focus-ring);
    --bs-body-font-family: "Pretendard Variable", Pretendard, "Malgun Gothic", system-ui, sans-serif;
}
```

- 관리 화면은 라이트 전용입니다. `<html data-bs-theme="light">` 로 고정합니다(OS 다크 모드에서 색이 뒤집히지 않게).
- `success` `danger` `warning` `info` 는 상태에만 씁니다(왔니 규칙).

### 3.2 화면 셸 (main, operator 공통)

왔니 README 의 Layout 을 따릅니다. 수치는 디자인 시스템 토큰 이름으로 적습니다.

```
+------------------------------------------------------------------+
| 헤더 layout-header-height(60px): 로고 · 조직 이름 | 상태 · 로그아웃 |
+-----------+------------------------------------------------------+
| 사이드바   | 빵부스러기 > 페이지 제목              [도움말 버튼] |
| 200px     | +--------------------------------------------------+ |
| (접힘 70) | | 패널(.card) 머리: 제목 20px/700 + 동작 버튼       | |
|           | | 패널 본문 (높이 고정, 안에서 스크롤)              | |
| 구역 이름 | +--------------------------------------------------+ |
| 메뉴      |                                                      |
+-----------+------------------------------------------------------+
  배경: 왼쪽 위 파란 빛 + 오른쪽 아래 보라 빛 방사형 그라데이션(.color-background)
```

사이드바 구역(왔니는 구역 이름만 영어. CLAUDE.md 3.10 용어를 지킵니다)

| 레이아웃 | 구역 | 메뉴 | 권한 |
|---|---|---|---|
| main | OVERVIEW | 조직 관리 통계(`/admin`) | 관리자 |
| main | CHAT | 상담 대화(`/console/chat`) | 모두 |
| main | REMOTE | 원격지원(`/console`) | 모두 |
| main | SETTING | 가이드, 상담원 관리, 조직 정보 | 관리자 |
| operator | OPERATOR | 조직 가입 관리, 감사 로그 | 운영자 |

- 지금 왼쪽 위 서비스 이름으로 열던 조직 관리 통계를 사이드바 OVERVIEW 로 옮깁니다(CLAUDE.md 3.6 표를 고칩니다).
- 상담 위젯 설정(드로어)과 매크로([매크로] 버튼)의 진입 위치는 그대로 둡니다.
- 헤더 오른쪽: 운영자 콘솔 전환(운영자만), 상담원 개인 상태(3.16), 로그아웃.
- 원격 화면(`console/viewer`)은 화면을 넓게 써야 하므로 사이드바를 접은 채(70px) 엽니다.
- 상담 대화(`console/chat`)는 왔니 `SplitLayout` 구조(왼쪽 대화 목록 / 오른쪽 대화)로 그립니다.

반응형 기준은 **Bootstrap 6 의 값을 따릅니다**(왔니 원본 992/1200 과 다름). 유틸리티 접두사(`lg:`, `xl:`)와 맞추기 위해서입니다.

| 폭 | 동작 | 원본 왔니 |
|---|---|---|
| `xl` 1280px 미만 | 사이드바가 왼쪽 드로어로 바뀌고 본문 100%. `.xl:drawer.drawer-start`(5.3 의 `.offcanvas-xl` 에 해당, 0단계에서 동작 확인) | 1200px |
| `lg` 1024px 미만 | 분할 패널이 세로로 쌓임 | 992px |
| `md` 768px 미만 | 패널 머리 버튼은 아이콘만 | 768px |

### 3.3 컴포넌트 대응

| 왔니 | 6.0 구현 | 우리 이름 |
|---|---|---|
| `AppShell` | 일반 마크업 + `wani.css` | `.rh-shell`, `.rh-header`, `.rh-sidebar`, `.rh-main` |
| `PageHeader` | `.breadcrumb` + `.breadcrumb-link`/`.breadcrumb-divider` | `.rh-page-header`, `.page-title` |
| `Panel` | `.card` + `wani.css` 보정(`radius-lg`, `shadow-panel`, 머리 테두리 없음) | `.card.rh-panel` |
| `Offcanvas`(폭 700, 왼쪽 2px primary) | `<dialog class="drawer drawer-end">` | `.drawer.rh-drawer` |
| `Modal` | `<dialog class="dialog">` | — |
| 토스트 | `.toast` (`Toast`) | `showToast()` 안에서 사용 |
| 탭 | `.nav-tabs` (`Tab`) | — |
| 버튼 아이콘(`data-wani-btn`) | Bootstrap Icons + 동작 이름 한 곳 | `data-rh-btn="save"` (3.5) |
| `HelpDock`, `Fab` | 쓰지 않음 | 이 서비스에는 해당 메뉴가 없음 |
| 그리드 | wgrid 유지 | `RHGrid.create()` 그대로 |

### 3.4 글자와 간격

- 글꼴은 Pretendard Variable 한 가족. 표와 폼은 `body-sm`, 숫자 정렬 칸은 `font-variant-numeric: tabular-nums`.
- 간격은 왔니 `space-*`(4·8·12·16·24·48px)만 씁니다. **6.0 의 키가 5.3 과 다르므로** 아래 표로 고릅니다.

| 왔니 | 4px | 8px | 12px | 16px | 24px | 48px |
|---|---|---|---|---|---|---|
| 6.0 클래스 키 | `1` | `3` | `4` | `5` | `7` | `12` |
| 예 | `gap-1` | `p-3` | `mb-4` | `p-5` | `my-7` | `py-12` |

### 3.5 아이콘 — Material Symbols 에서 Bootstrap Icons 로

- 왔니가 Bootstrap Icons 를 쓰므로 Bootstrap 6 화면은 **`bootstrap-icons@1.13.2`** 의 `bi bi-*` 로 바꿉니다.
- 동작 버튼 아이콘은 `common.js` 한 곳(`RH_BUTTON_ICONS`)에서 정하고 마크업에는 `data-rh-btn="save"` 만 적습니다.
  저장 `check-lg`, 취소·닫기 `x-lg`, 삭제 `dash-lg`, 추가 `plus-lg`, 수정 `pencil-square`, 검색 `search`, 초기화 `arrow-counterclockwise`, 내려받기 `download`, 올리기 `upload`.
- 패널 머리 버튼은 768px 미만에서 아이콘만 남으므로 **반드시 아이콘이 있는 버튼**만 둡니다.
- 전환이 끝나면 Material Symbols 서브셋(CLAUDE.md 3.3, `landing_icons()`)은 소개 페이지까지 함께 정리합니다.

---

## 4. 파일 구성과 불러오기

### 4.1 벤더 파일은 저장소에 둔다

wgrid 와 같은 방식으로 `backend/assets/vendor/` 에 버전 폴더째 둡니다. CDN 을 쓰지 않는 이유는 알파 버전이 바뀌어도 화면이 그대로여야 하고,
`asset_url()` 의 `?v=` 로 Cloudflare 캐시를 다루기 위해서입니다(CLAUDE.md 3.1).

```
backend/assets/vendor/bootstrap/6.0.0-alpha.1/bootstrap.min.css
backend/assets/vendor/bootstrap/6.0.0-alpha.1/bootstrap.bundle.min.js
backend/assets/vendor/bootstrap-icons/1.13.2/bootstrap-icons.min.css (+ fonts/)
backend/assets/vendor/pretendard/1.3.9/pretendardvariable-dynamic-subset.css (+ 글꼴)
backend/assets/css/wani.css        왔니 토큰 + Bootstrap 보정 + 셸 + wgrid·Dropzone 색
backend/assets/css/customer.css    고객 화면용 일반 CSS(같은 토큰, Bootstrap 없음)
backend/assets/js/bs.js            Bootstrap ESM 을 불러와 common.js 에 넘기는 연결 모듈
```

받은 파일은 위 2장의 sha384 와 대조하고, 대조 결과를 커밋 메시지에 남깁니다.

### 4.2 ESM 연결 (`bs.js`)

Bootstrap 6 은 전역을 만들지 않습니다. 기존 화면 스크립트와 `common.js` 는 일반 스크립트이므로 모듈 하나로 이어 줍니다.

```html
<script type="module" src="{{ asset_url('assets/js/bs.js') }}"></script>
```

```js
// bs.js
import { Dialog, Drawer, Menu, Tab, Toast, Tooltip } from '../vendor/bootstrap/6.0.0-alpha.1/bootstrap.bundle.min.js';
window.RHBootstrap = { Dialog, Drawer, Menu, Tab, Toast, Tooltip };
document.dispatchEvent(new Event('rh:bootstrap-ready'));
```

- 모듈은 지연 실행되므로 **화면 스크립트가 읽히는 순간 Bootstrap 을 부르면 안 됩니다.** 클릭 등 사용자 동작 안에서만 부르고,
  처음부터 열어야 하는 것은 `rh:bootstrap-ready` 이벤트에서 엽니다.
- `data-bs-toggle="dialog|drawer|menu"` 같은 선언형 속성은 모듈이 읽히면 Bootstrap 이 알아서 붙습니다.
- import 경로가 버전 폴더를 담으므로 Bootstrap 을 올릴 때는 `bs.js` 와 `_head.html` 두 곳을 같이 고칩니다(검사로 잡습니다, 6장).

### 4.3 공통 함수는 이름을 그대로 둔다

화면 JS 수정 범위를 줄이려고 CLAUDE.md 3.7 의 함수 이름과 인자는 유지하고 속만 바꿉니다.

| 함수 | 지금 | 바꾼 뒤 |
|---|---|---|
| `showToast(message, type)` | jQuery 로 만든 div | `.toast` + `Toast` |
| `showConfirmModal(title, message, onConfirm, onCancel)` | 자체 모달 | 공통 `<dialog class="dialog">` 하나 + `Dialog` |
| `openModal(id)` / `closeModal(id)` | 클래스 토글 | `Dialog.getOrCreateInstance(el).show()/hide()` |
| `openDrawer(id)` / `closeDrawer(id)` | `.drawer-root` 토글 | `<dialog class="drawer drawer-end">` + `Drawer` |
| `apiPost` / `apiGet` | jQuery ajax | 그대로(Bootstrap 과 무관) |

- jQuery(3.7.1)는 우리 코드가 쓰므로 **이번 개편에서는 남깁니다**(19개 파일). 빼는 것은 별도 작업입니다.
- 배지·버튼을 JS 로 만드는 곳(`grid.js` 의 `badgeRenderer`/`buttonsRenderer` 등)은 **`RHUi.badge(톤)` / `RHUi.button(톤)`** 으로 클래스를 고릅니다.
  톤 이름은 버튼 `primary` `secondary` `success` `danger` `ghost`, 배지 `gray` `blue` `amber` `green` `red` `dark` 입니다.
  예전 이름(`btn-primary`, `badge-amber`)도 받지만 Bootstrap 으로 옮긴 화면의 JS 는 톤 이름만 씁니다(검사가 잡습니다).
- 다이얼로그는 `<dialog class="dialog">` 면 `openModal(id)`, 드로어는 `<dialog class="drawer drawer-end rh-drawer">` 면 `openDrawer(id)` 가 Bootstrap 으로 엽니다.
  닫기 요소는 `data-bs-dismiss="dialog|drawer"` 입니다(`data-modal-close`, `data-drawer-close` 는 Tailwind 화면 전용).
- 드로어 마크업 규칙(`data-drawer-close`)은 Bootstrap 의 `data-bs-dismiss="drawer"` 로 바꿉니다.

### 4.4 고객 화면에 Bootstrap 6 을 쓰지 않는 이유

고객 접속 페이지는 원격지원을 받으려는 고객이 처음 여는 화면이고, 어떤 기기·브라우저로 올지 우리가 고를 수 없습니다.
Bootstrap 6 은 Safari 18·Chrome 130 미만에서 폴리필 없이 깨지므로(`oklch()`, `light-dark()`, `<dialog>`),
iOS 17 이하나 업데이트가 막힌 회사 PC 에서 다운로드 버튼이 안 보일 수 있습니다.
그래서 고객 화면은 **Bootstrap 없이 `customer.css`(일반 CSS, 16진 색, 미디어 쿼리)** 로 그리고 왔니 색·글꼴만 맞춥니다.
상담원·관리자·운영자는 업무용 최신 브라우저를 전제로 합니다.

---

## 5. 전환 순서

**레이아웃 단위로 한 번에 바꿉니다.** Tailwind 의 `.btn` `.card` `.badge` `.table` 과 Bootstrap 의 같은 이름 클래스가 충돌하므로
한 화면에 두 CSS 를 함께 불러오지 않습니다. `_head.html` 이 레이아웃이 넘긴 `ui_kit`(`'tailwind'` 기본, `'bootstrap'`)으로 하나만 불러옵니다.
그 레이아웃을 쓰는 화면(`content_view`)은 같은 단계에서 모두 바꿉니다.

| 단계 | 내용 | 대상 | 상태 |
|---|---|---|---|
| 0 | 기반: 벤더 파일, `wani.css` 토큰·셸, `bs.js`, `_head.html` 의 `ui_kit` 분기, `common.js` 두 방식 지원, 검사 추가 | — | **완료(2026-10-22)** |
| 1 | 운영자 (가장 작아 시범으로 먼저) | `operator.html`, `operator/organizations`, `operator/audit` | **완료(2026-10-22)** |
| 2 | 조직 관리 | `main.html`, `admin/dashboard` `agents` `organization` `guide` `guide_edit` | 대기 |
| 3 | 상담원 콘솔 | `console/chat` `queue` `viewer` `macro` `_macro_edit` `_widget_settings` | 대기 |
| 4 | 로그인·가입·소개 | `blank.html`, `auth/*`, `public.html`, `home/landing` | **완료(2026-10-22)**. 아이콘을 Bootstrap Icons 로 바꾸고 Material Symbols 를 뺌(CLAUDE.md 3.3) |
| 5 | 고객 화면(일반 CSS) | `customer.html`, `customer/*` | **완료(2026-10-22)**. `ui_kit = 'plain'`, `customer.css` 하나(oklch·:has·범위식 미디어 쿼리 없음) |
| 6 | 정리: Tailwind·`app.css`·`build-css.sh`·`.tools/tailwindcss`·Material Symbols 제거, `ui_kit` 분기 삭제, CLAUDE.md 정식 반영 | — | 대기 |

- `main.html` 은 콘솔(3단계)과 같은 레이아웃이므로 2·3단계는 **한 브랜치에서 함께 끝낸 뒤** 합칩니다.
- 각 단계 끝에 `./scripts/check-conventions.sh --live` 와 화면 확인을 하고, 3단계는 원격 화면 때문에 `./scripts/e2e-test.sh` 도 돌립니다.
- 화면 확인은 데스크톱 1440px, 노트북 1280px, 태블릿 1024px, 휴대폰 390px 네 폭으로 합니다.

---

## 6. 규칙 검사(`check-conventions.py`) 변경

| 검사 | 바꾸는 내용 | 단계 |
|---|---|---|
| `check_tailwind` | `ui_kit` 이 `tailwind` 인 레이아웃이 남아 있는 동안만 돌고, 6단계에서 삭제 | 0, 6 |
| 새 `check_bootstrap` (**추가함**) | 벤더 파일 sha384 = 공식 값, `bs.js` import 버전 = `_head.html` 버전, JS 에서 `bootstrap` 전역 사용 금지, Bootstrap 레이아웃(`set ui_kit = 'bootstrap'`)으로 그리는 화면·include·화면 JS 에 5.3 이름(`modal-*` `offcanvas` `dropdown-*` `btn-primary` `form-select` `text-muted` `data-bs-toggle="modal"` `badge-amber` 등)과 Tailwind 전용 클래스(`bg-slate-*`, `text-xs`, `truncate` 등)가 없음, 그 파일들이 `tailwind.src.css` 의 `@source not` 으로 빠져 있음. 일부러 어겨 잡히는 것을 확인함 | 0 |
| 새 `check_customer_css` | `customer.html` 과 그 화면이 Bootstrap 파일·`bs.js` 를 부르지 않음 | 5 |
| `check_icons` | Bootstrap 화면은 `bi-*` 만, Material Symbols 는 남은 화면에서만. 6단계에서 정리 | 4, 6 |
| `check_asset_version` | 그대로(벤더 파일도 `asset_url()`) | — |

CLAUDE.md 7장에 따라 검사를 붙일 때마다 일부러 어겨 보고 잡히는지 확인합니다.

---

## 7. 전환이 끝나면 CLAUDE.md 에서 바뀌는 곳

| 절 | 지금 | 바뀐 뒤(초안) |
|---|---|---|
| 2 구조 | `backend/assets/` 설명 | Bootstrap·아이콘·글꼴 벤더 폴더 추가 |
| 3.1 | Tailwind 다시 빌드 | **Bootstrap 6 + 왔니 디자인 시스템.** 빌드 없음. 색은 `wani.css` 토큰만, 5.3 이름 금지, 간격 키 표(3.4), Bootstrap 은 `bs.js` 로만, 고객 화면은 `customer.css` |
| 3.3 | Material Symbols 서브셋 | Bootstrap Icons, 버튼 아이콘은 `data-rh-btn` |
| 3.5 | wgrid 색은 `tailwind.src.css` 끝 | wgrid 색은 `wani.css` |
| 3.6 | 상단 메뉴, 왼쪽 위 이름 = 조직 관리 | 왔니 사이드바 구역 표(3.2) |
| 3.7 | `.drawer-root` 마크업 | `<dialog class="drawer">`, `<dialog class="dialog">` |
| 3.14 | `.image-drop` 은 `tailwind.src.css` | `wani.css` |
| 4 | `build-css.sh` | 삭제 |
| 6 결정 사항 | — | "화면: Bootstrap 6(알파 고정) + 왔니" 행 |

---

## 8. 위험과 대응

| 위험 | 대응 |
|---|---|
| **알파 버전이라 정식판에서 이름이 또 바뀔 수 있음** | 버전 폴더째 고정, 올릴 때는 이 문서 2.1 표와 검사 목록을 함께 갱신. Bootstrap 클래스를 JS 에서 문자열로 만들지 않고 템플릿에 둔다 |
| 왔니 원본(5.3)과 6.0 의 모양 차이(모서리 수치, 간격 키, 폼 컨트롤) | `wani.css` 에서 토큰 값으로 보정. 원본(wani.im/wani-component)과 나란히 놓고 비교 |
| **알파의 연한 색이 틀어짐**: `--bs-*-bg-subtle` 등을 `color-mix(in oklch, 흰색, 색)` 으로 만드는데 Chromium 에서 색상각이 틀어져 초록 배지가 주황으로 보임(2026-10-22 확인) | `wani.css` 에서 상태 색의 `bg-subtle` `bg-muted` `border` `fg-emphasis` 를 sRGB 로 직접 정함. 새 테마 색을 쓰면 같은 방식으로 더한다 |
| ESM 지연 실행으로 화면 스크립트가 Bootstrap 을 먼저 부름 | `rh:bootstrap-ready` 규칙, 공통 함수 안에서 준비 여부 확인 |
| `.btn` 등 이름 충돌 | 레이아웃 단위 전환(5장), `check_ui_kit_mix` |
| 콘솔 화면이 커서(`chat.js` 998줄) 한 번에 바꾸기 어려움 | 마크업을 만드는 JS 함수부터 목록으로 뽑고 클래스 대응표로 바꾼다 |

---

## 부록 A. 화면 전환 규칙 (2단계부터, 배포 CSS 로 확인한 이름)

운영자 화면(`layouts/operator.html`, `operator/organizations.html`)이 기준 예시입니다. 모르는 클래스는 추측하지 말고
`backend/assets/vendor/bootstrap/6.0.0-alpha.1/bootstrap.min.css` 에서 `grep -c -- '\.이름[^a-zA-Z0-9_-]'` 로 있는지 확인합니다.

### A.1 화면 뼈대

```html
<div class="rh-page-header">
  <div>
    <nav aria-label="현재 위치">
      <ol class="breadcrumb">
        <li class="breadcrumb-item"><span class="breadcrumb-link">SETTING</span></li>
        <li class="breadcrumb-divider"></li>
        <li class="breadcrumb-item" aria-current="page"><span class="breadcrumb-link active">상담원 관리</span></li>
      </ol>
    </nav>
    <h1 class="page-title">상담원 관리</h1>
  </div>
  <div class="rh-page-actions"><!-- 화면 전체 동작(선택) --></div>
</div>

<section class="card">                         <!-- 그리드를 담으면 card overflow-hidden -->
  <div class="card-header">
    <h2 class="card-title">상담원 목록</h2>
    <div class="card-actions">
      <button type="button" class="btn-solid theme-primary">
        <i class="bi bi-plus-lg" aria-hidden="true"></i><span class="btn-label">상담원 추가</span>
      </button>
    </div>
  </div>
  <div class="card-body">…</div>
</section>
```

- 빵부스러기 구역 이름은 사이드바 구역(OVERVIEW / CHAT / REMOTE / SETTING)과 같게 씁니다.
- 패널 머리 버튼은 **아이콘 + `<span class="btn-label">`** 입니다(768px 미만에서 글자를 숨김).
- 카드 안에 카드를 넣지 않습니다. 구역은 `bg-1 rounded-5 p-5` 또는 `border-top` 으로 나눕니다.

### A.2 버튼과 아이콘

| 용도 | 클래스 |
|---|---|
| 주 동작(저장, 추가, 확인) | `btn-solid theme-primary` |
| 보조(취소, 닫기, 필터 끔) | `btn-outline theme-secondary` |
| 위험(삭제) | `btn-outline theme-danger` (확인 다이얼로그를 거침) |
| 글자 버튼 | `btn-text theme-secondary` / `btn-text theme-primary` |
| 아이콘만 | `btn-icon` + `aria-label` |
| 크기 | `btn-sm`, `btn-xs`, `btn-lg` |

- 아이콘은 Bootstrap Icons `<i class="bi bi-…" aria-hidden="true"></i>`. 저장 `check-lg`, 취소·닫기 `x-lg`, 삭제 `trash`, 추가 `plus-lg`,
  수정 `pencil-square`, 검색 `search`, 초기화 `arrow-counterclockwise`, 내려받기 `download`, 올리기 `upload`, 복사 `copy`, 설정 `gear`.
- JS 로 만드는 버튼·배지는 `RHUi.button('primary')`, `RHUi.badge('green')` 톤 이름으로 클래스를 받습니다.

### A.3 폼

| 요소 | 마크업 |
|---|---|
| 라벨 | `<label class="form-label" for="x">` |
| 입력·textarea·select | `class="form-control"` (`form-select` 없음) |
| 도움말 | `<div class="form-text">` |
| 앞뒤 붙임 | `<div class="input-group"><span class="input-group-text">…</span><input class="form-control"></div>` |
| 체크박스 | `<div class="form-field"><input type="checkbox" class="check" id="a"><label for="a">…</label></div>` |
| 라디오 | 같은 구조에 `class="radio"` |
| 스위치 | `<div class="form-field"><div class="switch"><input type="checkbox" id="s" role="switch" switch></div><label for="s">…</label></div>` |
| 배치 | `<div class="row g-5">` + `col-12 md:col-6` (반응형은 접두사 `md:`) |

### A.4 다이얼로그·드로어·알림·탭

- 확인·짧은 입력은 `<dialog class="dialog" id="…">` + `dialog-header/body/footer`, 닫기 `data-bs-dismiss="dialog"`. 여닫기 `openModal(id)` / `closeModal(id)`.
- 상세 수정·설정은 오른쪽 드로어 `<dialog class="drawer drawer-end rh-drawer" tabindex="-1">` + `drawer-header/body/footer`(바닥은 취소·저장 반반),
  닫기 `data-bs-dismiss="drawer"`. 여닫기 `openDrawer(id)` / `closeDrawer(id)`. 열고 닫을 때 `drawer:open` / `drawer:close` jQuery 이벤트가 그대로 납니다.
- 알림 상자는 `<div class="alert theme-danger" role="alert">`(테마 `primary` `success` `warning` `danger` `info` `secondary`).
- 탭은 `<div class="nav nav-underline" role="tablist">` 안의 `<button class="nav-link active" …>` 와 `.tab-content > .tab-pane.active`.
  이미 있는 자체 탭 JS(data-tab)를 유지해도 되며, 그때는 활성 탭에 `active` 클래스를 붙입니다.

### A.5 Tailwind → Bootstrap 6 자주 쓰는 대응 (간격 키가 다릅니다)

| Tailwind | Bootstrap 6 |
|---|---|
| `flex` / `inline-flex` / `grid` / `hidden` / `block` | `d-flex` / `d-inline-flex` / `d-grid` / `d-none` / `d-block` |
| `sm:flex` `md:hidden` | `sm:d-flex` `md:d-none` |
| `items-center` `justify-between` `flex-wrap` `flex-1` `shrink-0` | `align-items-center` `justify-content-between` `flex-wrap` `flex-grow-1` `flex-shrink-0` |
| `gap-1`(4) `gap-2`(8) `gap-3`(12) `gap-4`(16) `gap-6`(24) | `gap-1` `gap-3` `gap-4` `gap-5` `gap-7` |
| `p-2`(8) `p-3`(12) `p-4`(16) `p-5`(20) `p-6`(24) | `p-3` `p-4` `p-5` `p-6` `p-7` (m 도 같은 키) |
| `space-y-4` | 부모에 `vstack gap-5` |
| `grid grid-cols-2 sm:grid-cols-3` | `d-grid grid-cols-2 sm:grid-cols-3` 또는 `row g-5` + `col-*` |
| `text-xs` `text-sm` `text-base` `text-lg` `text-xl` `text-2xl` | `fs-xs` `fs-sm` `fs-md` `fs-lg` `fs-xl` `fs-2xl` |
| `font-medium` `font-semibold` `font-bold` `font-mono` | `fw-medium` `fw-semibold` `fw-bold` `font-monospace` |
| `text-slate-800/900` · `600/700` · `400/500` | `fg-1` · `fg-2` · `fg-3` |
| `text-red-600` `text-emerald-600` `text-amber-600` | `fg-danger` `fg-success` `fg-emphasis-warning` |
| `bg-white` `bg-slate-50` `bg-slate-100` | `bg-body` `bg-1` `bg-2` |
| `border` `border-t` `rounded` `rounded-lg` `rounded-full` | `border` `border-top` `rounded-3` `rounded-5` `rounded-pill` |
| `truncate` `whitespace-nowrap` `break-all` | `text-truncate` `text-nowrap` `text-break` |
| `w-full` `h-full` `min-w-0` | `w-100` `h-100` `min-w-0` |
| `sr-only` | `visually-hidden` |

### A.6 Bootstrap 6 에서 알아 둘 함정

- **유틸리티가 `@layer` 안에 있습니다.** 그래서 `wani*.css` 처럼 레이어 밖에 둔 규칙이 `d-none` 같은 유틸리티를 이깁니다.
  우리 클래스가 `display` 를 정하는 요소를 숨길 때는 `d-none` 대신 그 클래스 쪽에 `.rh-xxx.d-none { display: none; }` 을 두거나, 숨김 전용 상태 클래스를 씁니다.
- **`vstack` 은 `flex: 1 1 auto`** 입니다. 세로 flex 부모 안에서 남는 높이를 나눠 가지므로, `wani.css` 가 `drawer-body` / `dialog-body` / `card-body` 바로 아래의 `vstack` 은 내용 높이로 묶어 둡니다.
  더 깊은 곳에서 벌어지면 `d-flex flex-column` 을 씁니다.
- **연한 테마 색**(`--bs-*-bg-subtle` 등)은 `wani.css` 가 sRGB 로 직접 정합니다(위 8장 위험 표).

### A.7 하지 않는 것

- 5.3 이름(`btn-primary`, `form-select`, `text-muted`, `modal*`, `offcanvas*`, `dropdown-*`, `data-bs-toggle="modal"`)과 Tailwind 클래스를 남기지 않습니다(`check_bootstrap` 이 잡습니다).
- 인라인 `style` 로 색을 주지 않습니다. 화면 전용 모양은 그 화면 묶음의 `wani-*.css` 에 `--wani-*` 토큰으로 둡니다.
- 기능·문구·API 호출·요소 id 는 바꾸지 않습니다(JS 가 id 로 찾습니다). 바꾸는 것은 마크업 구조와 클래스뿐입니다.
- 사용자 입력은 지금처럼 `textContent` 로 그립니다(`innerHTML` 에 값을 넣지 않습니다, CLAUDE.md 3.17).
