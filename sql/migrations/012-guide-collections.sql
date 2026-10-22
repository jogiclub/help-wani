-- 파일 위치: sql/migrations/012-guide-collections.sql
-- 역할: 공개 가이드의 새 기능 모음 / 동영상 모음 (docs/guide-collections-plan.md)
-- 적용: mysql -u<user> -p <db> < 012-guide-collections.sql   (011-macros.sql 다음에 적용)
--
-- 새 기능은 글마다 표시한다(is_feature). 출시일 feature_at 은 조직 시간대 그날 00:00 을 UTC 로 바꾼 값이며,
-- 비어 있으면 처음 공개한 시각(published_at)을 쓴다. 조직 옵션은 기본 모두 꺼짐이다.

SET NAMES utf8mb4;

-- 모음 주소(features, videos)는 글 주소로 쓸 수 없다. 이미 쓰는 글이 있으면 {slug}-1 로 바꾼다.
-- 같은 조직에 {slug}-1 까지 있으면 주소를 비운다(번호로 접속).
UPDATE guide_articles a
LEFT JOIN guide_articles b ON b.org_id = a.org_id AND b.slug = CONCAT(a.slug, '-1')
SET a.slug = IF(b.id IS NULL, CONCAT(a.slug, '-1'), NULL)
WHERE a.slug IN ('features', 'videos');

ALTER TABLE guide_articles
    ADD COLUMN is_feature    TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '새 기능 모음에 보임' AFTER status,
    ADD COLUMN feature_at    DATETIME    DEFAULT NULL COMMENT '출시일(조직 시간대 그날 00:00 의 UTC). NULL 이면 published_at' AFTER is_feature,
    ADD COLUMN feature_label VARCHAR(30) DEFAULT NULL COMMENT '버전 등 짧은 표시' AFTER feature_at,
    ADD COLUMN published_at  DATETIME    DEFAULT NULL COMMENT '처음 공개한 시각, UTC' AFTER feature_label,
    ADD KEY idx_garticle_feature (org_id, status, is_feature, feature_at);

-- 이미 공개된 글은 첫 공개 시각을 알 수 없으므로 마지막 수정 시각으로 채운다.
UPDATE guide_articles SET published_at = updated_at, updated_at = updated_at
WHERE status = 'published' AND published_at IS NULL;

ALTER TABLE organizations
    ADD COLUMN guide_feature_enabled  TINYINT(1)       NOT NULL DEFAULT 0     COMMENT '공개 가이드 새 기능 모음 보이기' AFTER guide_theme,
    ADD COLUMN guide_feature_new_days TINYINT UNSIGNED NOT NULL DEFAULT 30    COMMENT 'NEW 배지 기간(일), 0 이면 끔' AFTER guide_feature_enabled,
    ADD COLUMN guide_feature_banner   TINYINT(1)       NOT NULL DEFAULT 0     COMMENT '가이드 첫 화면에 최근 새 기능 띠' AFTER guide_feature_new_days,
    ADD COLUMN guide_video_enabled    TINYINT(1)       NOT NULL DEFAULT 0     COMMENT '공개 가이드 동영상 모음 보이기' AFTER guide_feature_banner,
    ADD COLUMN guide_video_sort       VARCHAR(10)      NOT NULL DEFAULT 'toc' COMMENT '동영상 정렬: toc | updated' AFTER guide_video_enabled,
    ADD COLUMN guide_video_filter     TINYINT(1)       NOT NULL DEFAULT 0     COMMENT '동영상 모음 분류 거르기' AFTER guide_video_sort;
