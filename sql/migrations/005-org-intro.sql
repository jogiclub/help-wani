-- 파일 위치: sql/migrations/005-org-intro.sql
-- 역할: 고객 접속 페이지(/{조직코드})에 보여줄 조직 소개 문구 추가
-- 적용: mysql -u<user> -p <db> < 005-org-intro.sql
--
-- 로고는 기존 logo_path 를 쓴다(조직 정보 화면에서 업로드).

SET NAMES utf8mb4;

ALTER TABLE organizations
    ADD COLUMN intro VARCHAR(500) DEFAULT NULL COMMENT '고객 접속 페이지에 보이는 조직 소개' AFTER logo_path;
