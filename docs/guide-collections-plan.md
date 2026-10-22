<!--
  파일 위치: docs/guide-collections-plan.md
  역할: 공개 가이드의 "새 기능 모음" 과 "동영상 모음" 페이지, 가이드 관리의 설정 옵션 기획서
-->

# 공개 가이드 — 새 기능 모음 / 동영상 모음 기획서

공개 가이드(`/{조직코드}/guide`, 예: `https://help.wani.im/webhows/guide`)에 두 가지 모아 보기 페이지를 더합니다.

1. **새 기능 모음** — 관리자가 "새 기능" 으로 표시한 가이드만, 최신 출시순으로 모아 봅니다.
2. **동영상 모음** — 공개된 가이드 안의 유튜브 동영상만 모아서 봅니다.

두 페이지는 가이드 관리(`/admin/guide`)의 **공개 페이지 구성** 옵션으로 켜고 끕니다. 기본은 모두 꺼짐입니다.

기존 설계는 `docs/guide-plan.md` 를 따르며, 이 문서는 달라지는 부분만 적습니다.

---

## 1. 무엇을 만드는가

| 화면 | 주소 | 대상 | 비고 |
|---|---|---|---|
| 새 기능 모음 | `/{조직코드}/guide/features` | 고객(누구나) | 옵션이 꺼져 있으면 404 |
| 동영상 모음 | `/{조직코드}/guide/videos` | 고객(누구나) | 옵션이 꺼져 있으면 404 |
| 공개 가이드 목록/상세 | 기존 주소 | 고객 | 상단에 탭(전체 / 새 기능 / 동영상) 추가 |
| 가이드 관리 | `/admin/guide` | 조직 관리자 | 오른쪽에 "공개 페이지 구성" 카드 추가 |
| 가이드 편집 | `/admin/guide/edit/{id}` | 조직 관리자 | "새 기능으로 표시" 항목 추가 |

```
[조직 로고 / 이름]
 ┌──────────┬──────────┬──────────┐
 │  전체 가이드 │  새 기능  NEW │  동영상 (8) │      <- 켜 둔 탭만 보인다. 하나도 안 켜면 탭 줄 자체가 없다
 └──────────┴──────────┴──────────┘
```

---

## 2. 새 기능 모음

### 2-1. "새 기능" 의 기준 — 관리자가 글마다 직접 표시한다

| 검토한 방법 | 판단 |
|---|---|
| 최근 N일 안에 공개된 글을 자동으로 새 기능으로 본다 | **채택하지 않음.** 오타 수정, 문제 해결 글도 섞여 들어간다 |
| 특정 분류(예: "새 기능")에 넣은 글 | **채택하지 않음.** 글은 분류를 하나만 가지므로 "설치" 분류의 새 기능 글을 함께 둘 수 없다 |
| **글마다 "새 기능" 표시 + 출시일** | **채택.** 분류와 따로 움직이고, 출시일로 정렬·묶음이 된다 |

가이드 편집 화면에 다음 항목을 추가합니다.

```
[✓] 새 기능으로 표시
    출시일  [2026-10-21]   (조직 시간대 기준, 비우면 처음 공개한 날)
    버전    [v2.3        ]  (선택, 30자)
```

- 출시일은 관리자가 조직 시간대로 고르고, 저장은 **UTC** 로 합니다(CLAUDE.md 3.4). 조직 시간대의 그날 00:00 을 UTC 로 바꿔 저장합니다.
- 출시일을 비우면 그 글을 처음 공개(published)한 시각을 씁니다. 이를 위해 `published_at`(첫 공개 시각)을 함께 기록합니다.
- 임시(draft) 글은 표시가 있어도 모음에 나오지 않습니다.

### 2-2. 화면

```
새 기능
최근에 추가된 기능과 사용 방법입니다.

2026년 10월 ───────────────────────────
  [NEW] v2.3  10월 21일
  상담 운영 시간 설정하기
  요일별 운영 시간과 휴일을 정하는 방법                    >

          10월 3일
  가이드 공개 페이지 테마 바꾸기                           >

2026년 9월 ────────────────────────────
  ...
```

- 출시일 내림차순, **조직 시간대 기준 월별 묶음**. 날짜는 `to_timezone()` 으로 변환해 그립니다(원본 출력 금지, 3.4).
- 한 쪽 20개, 아래 [더 보기](`?page=2`). 공개 화면은 wgrid 를 쓰지 않습니다(관리 목록 규칙인 3.5 의 대상이 아님).
- 출시일이 **NEW 배지 기간**(옵션, 기본 30일) 안이면 `NEW` 배지를 붙입니다. 같은 배지를 전체 목록과 검색 결과의 제목 옆에도 붙입니다.
- 항목을 누르면 기존 상세(`/{조직코드}/guide/{slug}`)로 갑니다. 상세 상단에도 `새 기능 · v2.3 · 2026년 10월 21일` 을 보여 줍니다.
- 새 기능 글이 하나도 없으면 "아직 소개할 새 기능이 없습니다." 안내를 보입니다(옵션은 켜져 있어도 탭은 그대로).

### 2-3. 목록 첫 화면 소식 띠 (옵션)

옵션 "가이드 첫 화면에 최근 새 기능 보이기" 를 켜면 목록 첫 화면(검색 전) 위에 최근 새 기능 3건을 한 줄 띠로 보입니다.

```
새 기능  상담 운영 시간 설정하기 · 가이드 테마 바꾸기 · ...      전체 보기 >
```

---

## 3. 동영상 모음

### 3-1. 모으는 방식 — 공개 글의 video 블록을 그때그때 모은다

| 검토한 방법 | 판단 |
|---|---|
| 동영상을 따로 등록하는 테이블/화면 | **채택하지 않음.** 원본이 두 곳이 되어 글과 어긋난다 |
| 저장할 때 `guide_videos` 색인 테이블을 다시 만든다 | 보류. `guide_terms` 처럼 `save_article()`/`set_status()` 에서 함께 만들어야 해 고칠 곳이 늘어난다 |
| **조회할 때 공개 글의 `blocks` 에서 video 블록을 모은다** | **채택.** 원본이 하나(가이드 본문)이고, 조직당 글이 수백 건 수준이라 PHP 에서 풀어도 충분하다 |

- `Guide_model::public_videos($org_id)` 하나에서 만듭니다. 공개 글만, 가이드 목차 순서(`list_articles` 정렬) 또는 최신 수정순(옵션).
- 같은 영상(`youtube_id`)이 여러 글에 있으면 **처음 나온 하나만** 보이고, "이 영상이 있는 가이드" 에 여러 글을 함께 적습니다.
- 각 동영상은 `{youtube_id, caption, article_id, article_title, article_slug, category_name}` 입니다.
  제목은 블록 설명(`caption`)을 쓰고, 비어 있으면 글 제목을 씁니다.
- 글이 많아져 느려지면 그때 `guide_videos` 색인으로 바꿉니다(바꿔도 `public_videos()` 의 반환 형식은 같게 둡니다).

### 3-2. 화면

```
동영상 (8)
[분류: 전체 ▾]

┌──────────────┐ ┌──────────────┐ ┌──────────────┐
│  (유튜브 iframe) │ │  (유튜브 iframe) │ │  (유튜브 iframe) │
└──────────────┘ └──────────────┘ └──────────────┘
 프린터 추가하기         와이파이 문제 해결       ...
 가이드: 프린터가 연결되지 않을 때 >
```

- 넓은 화면 3열, 중간 2열, 좁은 화면 1열 격자.
- 영상은 기존 본문과 같은 규칙으로 그립니다(CLAUDE.md 3.15). `youtube-nocookie.com/embed/{id}`, `loading="lazy"`,
  `referrerpolicy="strict-origin-when-cross-origin"`. iframe 마크업은 `_article.php` 에서 **`_video.php` 조각으로 떼어 두 곳이 함께 씁니다.**
  주소 규칙이 한 곳에만 있도록 하기 위해서입니다.
- iframe 이 한꺼번에 많이 뜨지 않도록 **한 쪽 12개**, 아래 [더 보기](`?page=2`).
- 분류 고르기(`?category={id}`)는 옵션 "분류로 거르기" 를 켰을 때만 보입니다. 값은 그 조직의 분류 id 허용 목록으로 검사합니다(3.7).
- 썸네일 이미지만 먼저 보이고 누르면 재생하는 방식은 **이번에는 쓰지 않습니다.** 썸네일 주소(`i.ytimg.com`)를 공식 문서로 확인하지 못했습니다
  (`docs/guide-plan.md` 3-2). 확인 필요 사항 3 참고.

---

## 4. 가이드 관리의 옵션 — "공개 페이지 구성"

`/admin/guide` 오른쪽, 기존 "공개 페이지 테마" 카드 아래에 카드를 하나 더 둡니다.

```
공개 페이지 구성
──────────────────────────────────────
[ ] 새 기능 모음 보이기
      NEW 배지 기간   [30일 ▾]   (끔 / 7 / 14 / 30 / 60 / 90일)
      [ ] 가이드 첫 화면에 최근 새 기능 3건 보이기
[ ] 동영상 모음 보이기
      정렬           [가이드 목차 순 ▾]   (가이드 목차 순 / 최근 수정순)
      [ ] 분류로 거르기
──────────────────────────────────────
새 기능 표시된 공개 가이드 4건 · 공개 가이드의 동영상 8개
[미리보기: 새 기능]  [미리보기: 동영상]              [저장]
```

- 하위 항목은 상위 옵션을 켰을 때만 고를 수 있습니다(꺼져 있으면 흐리게).
- 카드 아래에 지금 모음에 나올 개수를 보여 줍니다. 0건인데 켜면 "고객 화면에 빈 페이지가 보입니다" 를 `showConfirmModal` 로 묻습니다.
- [미리보기] 는 테마 미리보기와 같이 새 창으로 엽니다. 옵션이 꺼져 있어도 **그 조직 관리자 세션이면** 미리 볼 수 있습니다
  (`?preview=1`, `Guide::preview_theme()` 와 같은 조건).
- 저장은 `POST /admin/guide/api/page_options_save`, 응답은 `api_response()`(3.7). 모든 값은 허용 목록으로 검사합니다.
- 저장하면 `audit_logs` 에 `guide_page_options` 로 바뀐 값을 남깁니다.
- 가이드 관리 목록(wgrid)에 "새 기능" 열(배지 + 출시일)을 더하고, 위쪽 거르기에 "새 기능만" 을 더합니다.

---

## 5. 데이터베이스

마이그레이션 `sql/migrations/012-guide-collections.sql`, `sql/01-schema.sql` 에도 반영합니다.

```sql
-- 글: 새 기능 표시
ALTER TABLE guide_articles
  ADD COLUMN is_feature    TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '새 기능 모음에 보임' AFTER status,
  ADD COLUMN feature_at    DATETIME    DEFAULT NULL COMMENT '출시일(조직 시간대 그날 00:00 의 UTC). NULL 이면 published_at' AFTER is_feature,
  ADD COLUMN feature_label VARCHAR(30) DEFAULT NULL COMMENT '버전 등 짧은 표시' AFTER feature_at,
  ADD COLUMN published_at  DATETIME    DEFAULT NULL COMMENT '처음 공개한 시각, UTC' AFTER feature_label,
  ADD KEY idx_garticle_feature (org_id, status, is_feature, feature_at);

-- 이미 공개된 글은 첫 공개 시각을 알 수 없으므로 마지막 수정 시각으로 채운다.
UPDATE guide_articles SET published_at = updated_at WHERE status = 'published' AND published_at IS NULL;

-- 조직: 공개 페이지 구성
ALTER TABLE organizations
  ADD COLUMN guide_feature_enabled   TINYINT(1)       NOT NULL DEFAULT 0  COMMENT '새 기능 모음 보이기',
  ADD COLUMN guide_feature_new_days  TINYINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'NEW 배지 기간(일), 0 이면 끔',
  ADD COLUMN guide_feature_banner    TINYINT(1)       NOT NULL DEFAULT 0  COMMENT '첫 화면에 최근 새 기능 띠',
  ADD COLUMN guide_video_enabled     TINYINT(1)       NOT NULL DEFAULT 0  COMMENT '동영상 모음 보이기',
  ADD COLUMN guide_video_sort        VARCHAR(10)      NOT NULL DEFAULT 'toc' COMMENT 'toc | updated',
  ADD COLUMN guide_video_filter      TINYINT(1)       NOT NULL DEFAULT 0  COMMENT '동영상 모음 분류 거르기';
```

- 기존 설정(`guide_theme`, `guide_autosuggest`)과 같이 조직 컬럼으로 둡니다. 옵션 목록과 허용값은 `Guide_model::page_options()` 한 곳에 둡니다.
- `published_at` 은 `save_article()` / `set_status()` 에서 **draft → published 로 처음 바뀔 때만** 찍습니다. 다시 임시로 돌렸다가 공개해도 바꾸지 않습니다.
- 새 기능 값의 검사는 화면 저장과 guide.json 가져오기가 함께 쓰는 `clean_article()` 에 넣습니다(3.15, 검사를 따로 만들지 않음).

---

## 6. 주소와 예약어

`(:any)/guide/(:any)` 는 글 주소(slug)를 받으므로 새 주소를 **그보다 위에** 둡니다(3.8).

```php
// 공개 가이드 (고객 화면 묶음 안, (:any) 보다 위)
$route['(:any)/guide/features'] = 'guide/features/$1';
$route['(:any)/guide/videos']   = 'guide/videos/$1';
$route['(:any)/guide']          = 'guide/index/$1';
$route['(:any)/guide/(:any)']   = 'guide/article/$1/$2';
```

- **`features`, `videos` 는 글 주소로 쓸 수 없게 막습니다.** `Guide_model::RESERVED_SLUGS` 에 두고 `clean_article()` 이 거부합니다.
  앞으로 모음 페이지를 더할 때도 이 목록에 먼저 넣습니다.
- 마이그레이션 전에 이 두 slug 를 이미 쓴 글이 있는지 확인합니다. 있으면 `{slug}-1` 로 바꾸고 그 조직 `audit_logs` 에 남깁니다.
- guide.json 가져오기에서 예약어 slug 를 만나면 slug 를 비우고(번호 주소로 접속) 경고에 적습니다.

---

## 7. 공개 화면 구조 (테마 5종 공통)

테마 5개(`card`, `docs`, `portal`, `faq`, `dark`)마다 새 화면을 따로 만들지 않고 **공통 조각을 테마가 불러 씁니다.**
본문 블록을 모든 테마가 `_article.php` 로 그리는 것과 같은 방식입니다.

| 파일 | 역할 |
|---|---|
| `views/guide/_tabs.php` | 탭 줄(전체 / 새 기능 / 동영상). 켜 둔 탭만, 지금 화면 강조 |
| `views/guide/_features.php` | 새 기능 모음 본문 |
| `views/guide/_videos.php` | 동영상 모음 본문 |
| `views/guide/_video.php` | 유튜브 iframe 하나(`_article.php` 와 `_videos.php` 가 함께 씀) |
| `views/guide/_feature_strip.php` | 첫 화면 최근 새 기능 띠 |

- `Guide_model::page_data()` 에 `view`(`list` / `features` / `videos`)를 받아 값을 더합니다.
  `features` 면 `features`(월별 묶음), `videos` 면 `videos` 와 `video_total`, 공통으로 `options`, `tabs` 를 돌려줍니다.
- 테마 뷰는 목록을 그리던 자리에서 `$view` 를 보고 `_features` / `_videos` 를 부릅니다. 모양 차이는 `assets/css/guide-themes.css` 에
  `.gt-{테마} .guide-feature-*`, `.guide-video-grid` 로 덧입힙니다(일반 CSS, Tailwind 빌드 밖).
- `docs` 테마의 왼쪽 목차와 `portal` 테마의 분류 카드 위에도 같은 `_tabs.php` 를 둡니다.
- 새 Tailwind 클래스를 공통 조각에 쓰면 `./scripts/build-css.sh` 로 다시 빌드합니다(3.1).
- 아이콘은 테마와 같이 SVG 를 마크업에 직접 넣습니다(Material Symbols 목록 등록 불필요).
- 상세 하단 "해결되지 않았나요?" 와 상담 위젯 열기는 모음 페이지에도 그대로 둡니다.
- 문구는 조직 설정 언어를 따릅니다(3.2, 고객 화면). 조회수는 모음 페이지에서 올리지 않습니다.

### 7-1. 다국어 키 (ko / en / ja 세 사전 모두)

| 키 | ko |
|---|---|
| `guide.tab_all` | 전체 가이드 |
| `guide.tab_features` | 새 기능 |
| `guide.tab_videos` | 동영상 |
| `guide.features_title` | 새 기능 |
| `guide.features_desc` | 최근에 추가된 기능과 사용 방법입니다. |
| `guide.features_empty` | 아직 소개할 새 기능이 없습니다. |
| `guide.features_strip` | 새 기능 |
| `guide.view_all` | 전체 보기 |
| `guide.new_badge` | NEW |
| `guide.released_on` | {date} 출시 |
| `guide.videos_title` | 동영상 |
| `guide.videos_desc` | 가이드에 들어 있는 동영상을 모아 봅니다. |
| `guide.videos_empty` | 아직 동영상이 없습니다. |
| `guide.videos_in_article` | 이 영상이 있는 가이드 |
| `guide.category_all` | 전체 분류 |
| `guide.more` | 더 보기 |
| `guide.month_label` | {year}년 {month}월 |

관리 화면 문구는 기존 가이드 관리처럼 한국어로 뷰에 둡니다.

---

## 8. guide.json

`version` 은 1 그대로 두고 글에 선택 필드를 더합니다. 필드가 없는 기존 파일도 그대로 가져옵니다.

```json
{
  "slug": "chat-hours",
  "title": "상담 운영 시간 설정하기",
  "status": "published",
  "feature": { "date": "2026-10-21", "label": "v2.3" },
  "blocks": [ ... ]
}
```

- `feature` 가 있으면 새 기능 표시, 없으면 표시 안 함. `date` 는 조직 시간대 날짜(`YYYY-MM-DD`)로 주고받습니다(사람이 편집하는 형식).
- `published_at` 은 내보내지 않습니다(서버가 정하는 값).
- 조직의 공개 페이지 구성 옵션은 guide.json 에 넣지 않습니다(글 데이터가 아니라 조직 설정).

---

## 9. 작업 순서

1. 마이그레이션 `012-guide-collections.sql`, `01-schema.sql` 반영, 예약어 slug 점검
2. `Guide_model`: `RESERVED_SLUGS`, `page_options()` / `set_page_options()`, `clean_article()` 새 기능 검사,
   `published_at` 기록, `public_features()`, `public_videos()`, `page_data()` 의 `view`, export/import 의 `feature`
3. `Guide` 컨트롤러 `features()` / `videos()`(옵션 꺼짐이면 404, 관리자 미리보기 예외), 라우트 추가
4. 공통 조각 `_tabs` / `_features` / `_videos` / `_video` / `_feature_strip`, 테마 5종에 연결, `guide-themes.css`
5. 가이드 관리: "공개 페이지 구성" 카드, `api_page_options_save`, 목록의 새 기능 열과 거르기
6. 가이드 편집: 새 기능 표시 / 출시일 / 버전
7. 다국어 키 세 사전, Tailwind 재빌드
8. `check-conventions.py` 검사 추가(10장), 일부러 어겨 보고 잡히는지 확인
9. `CLAUDE.md` 3.15 에 요약 추가, `docs/guide-plan.md` 12장에 구현 현황 추가

---

## 10. 지침(CLAUDE.md)과 규칙 검사에 추가할 것

| 규칙 | 검사 함수(안) |
|---|---|
| `(:any)/guide/features`, `(:any)/guide/videos` 가 `(:any)/guide/(:any)` 보다 위 | `check_routes()` 에 순서 검사 추가 |
| 라우트의 모음 경로 이름이 모두 `RESERVED_SLUGS` 에 있다 | `check_guide()` — `routes.php` 의 `(:any)/guide/{낱말}` 과 목록 대조 |
| 테마 뷰 5개가 모두 `_tabs` 를 부른다 | `check_guide()` — 테마 뷰 누락 검사에 이어서 |
| 유튜브 iframe 은 `_video.php` 한 곳에서만 만든다 | `youtube-nocookie.com/embed` 가 `_video.php` 밖의 뷰에 없는지 |

CLAUDE.md 3.15 에 넣을 요약(안)

> - 공개 가이드의 **새 기능 모음**(`/{조직코드}/guide/features`)과 **동영상 모음**(`/guide/videos`)은 조직 옵션으로 켤 때만 보인다(기본 꺼짐).
>   옵션 목록은 `Guide_model::page_options()` 한 곳. 모음 경로는 `RESERVED_SLUGS` 에 넣어 글 주소로 쓸 수 없게 한다(검사가 잡는다).
> - 새 기능은 글마다 표시(`is_feature`, 출시일 `feature_at` UTC)한다. 동영상 모음은 따로 저장하지 않고 공개 글의 video 블록에서 모은다.
> - 유튜브 iframe 은 `views/guide/_video.php` 하나로 그린다.

---

## 11. 확인 필요 사항

1. **새 기능의 기준**: 이 문서는 "글마다 관리자가 표시" 로 잡았습니다. 최근 공개 글을 자동으로 넣는 방식이 필요하면 알려 주세요.
2. **NEW 배지 기본 기간**: 30일로 잡았습니다.
3. **동영상 썸네일**: 지금 안은 iframe 을 12개씩 바로 띄웁니다. 썸네일만 먼저 보이게 하려면 유튜브 oEmbed 응답의
   `thumbnail_url` 을 글 저장 때 받아 두는 방법이 있으나, 서버가 외부에 요청하므로 `fetch_external_image()` 와 같은 검사가 필요하고
   oEmbed 응답 형식을 공식 문서로 다시 확인해야 합니다. 2단계로 미뤘습니다.
4. **메뉴 이름 바꾸기**: 탭 이름("새 기능", "동영상")을 조직이 직접 바꾸게 할지. 지금 안은 사전 문구 고정입니다
   (조직이 바꾸면 세 언어 사전을 쓸 수 없어 한 언어로만 보입니다).
5. **동영상 모음에서 특정 영상 빼기**: 블록마다 "모음에서 숨기기" 가 필요할지. 지금 안은 공개 글의 영상을 모두 모읍니다.
6. **상담 연계**: 상담원 콘솔 가이드 검색에 "새 기능만" 거르기, 위젯에 새 기능 안내를 넣을지. 이번 범위에서는 뺐습니다.
7. **알림 구독(RSS, 메일)**: 새 기능 모음을 구독하는 기능은 이번 범위에서 뺐습니다.

---

## 12. 구현 현황 (2026-10-22)

이 문서의 기본안(확인 필요 사항 1~7 의 현재 안)대로 구현했습니다.

| 항목 | 파일 |
|---|---|
| 스키마 | `sql/migrations/012-guide-collections.sql`(011 은 매크로가 먼저 씀), `sql/01-schema.sql` |
| 저장/검사/조회 | `Guide_model`: `RESERVED_SLUGS`, `clean_feature()`, `mark_published()`, `page_options()` / `set_page_options()`, `public_features()`, `public_videos()`, `new_feature_ids()`, `collection_data()`, export/import 의 `feature` |
| 공개 화면 | `Guide::features()` / `videos()`, `views/guide/_tabs`, `_collection`, `_features`, `_videos`, `_video`, `_new_badge`, `_feature_strip`, 테마 5종, `assets/css/guide-themes.css` |
| 관리 화면 | `Admin_guide::api_page_options_save()`, `views/admin/guide.php`(공개 페이지 구성 카드, 새 기능만 거르기, 새 기능 열), `views/admin/guide_edit.php`(새 기능 표시/출시일/버전), `assets/js/admin/guide.js`, `guide-edit.js` |
| 다국어 | `guide.tab_*`, `guide.features_*`, `guide.videos_*`, `guide.load_more`, `guide.month_label` 등 19개 키(ko/en/ja) |
| 규칙 검사 | `check_guide()` 5번: 모음 라우트 순서, 예약어 대조, 테마의 `_tabs`/`_collection`, iframe 은 `_video.php` 에서만 |

계획과 달라진 점

- 마이그레이션 번호가 011 에서 **012** 로 바뀌었습니다.
- 모음 본문을 테마가 고르지 않도록 `_collection.php` 조각을 하나 더 두었습니다(테마는 `$view !== 'list'` 이면 이것만 부름).
- 모음의 [더 보기] 는 `?page=n` 이면 처음부터 n 쪽 분량을 보입니다(앞의 항목이 사라지지 않게). 최대 50쪽.
- 분류 거르기는 버튼(칩) 목록으로 그렸습니다(스크립트 없이 동작). 동영상이 있는 분류가 둘 이상일 때만 보입니다.
- 마이그레이션은 이미 공개된 글의 `published_at` 을 마지막 수정 시각으로 채우고, `features`/`videos` 주소를 쓰던 글은 `{slug}-1` 로 바꿉니다
  (개발 DB 에 해당 글은 없었습니다).
