-- 파일 위치: sql/migrations/007-guide-samples.sql
-- 역할: 가이드 샘플을 조직마다 한 번만 만들기 위한 표시 추가
-- 적용: mysql -u<user> -p <db> < 007-guide-samples.sql
--
-- 가이드 관리(/admin/guide)를 처음 열 때 글이 없으면 샘플을 만들고 이 시각을 찍는다.
-- 이미 찍혀 있으면 관리자가 샘플을 모두 지워도 다시 만들지 않는다.

SET NAMES utf8mb4;

ALTER TABLE organizations
    ADD COLUMN guide_seeded_at DATETIME DEFAULT NULL COMMENT '가이드 샘플을 만든(또는 건너뛴) 시각, UTC' AFTER guide_autosuggest;
