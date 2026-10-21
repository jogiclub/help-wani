-- 파일 위치: sql/migrations/008-guide-theme.sql
-- 역할: 공개 가이드(/{조직코드}/guide)의 화면 테마 선택 추가
-- 적용: mysql -u<user> -p <db> < 008-guide-theme.sql
--
-- 값은 Guide_model::themes() 의 키만 받는다(card, docs, portal, faq, dark). 기본은 지금 화면인 card.

SET NAMES utf8mb4;

ALTER TABLE organizations
    ADD COLUMN guide_theme VARCHAR(20) NOT NULL DEFAULT 'card' COMMENT '공개 가이드 테마' AFTER guide_seeded_at;
