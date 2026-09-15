-- 파일 위치: sql/migrations/002-org-approval.sql
-- 역할: 조직 가입 승인 프로세스에 필요한 컬럼과 플랫폼 운영자 권한 추가
-- 적용: mysql -u<user> -p <db> < 002-org-approval.sql

SET NAMES utf8mb4;

-- 조직: 승인 이력과 반려 사유
ALTER TABLE organizations
    MODIFY COLUMN status ENUM('pending','active','rejected','suspended')
        NOT NULL DEFAULT 'pending' COMMENT '가입 승인 상태',
    ADD COLUMN approved_at DATETIME DEFAULT NULL COMMENT '승인 시각' AFTER status,
    ADD COLUMN approved_by INT UNSIGNED DEFAULT NULL COMMENT '승인한 운영자' AFTER approved_at,
    ADD COLUMN status_reason VARCHAR(300) DEFAULT NULL COMMENT '반려/중지 사유' AFTER approved_by;

-- 상담원: 플랫폼 운영자 권한
-- 조직 관리자(role=admin)는 자기 조직만, 플랫폼 운영자(is_super=1)는 전체 조직을 관리한다.
ALTER TABLE agents
    ADD COLUMN is_super TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '플랫폼 운영자 여부(조직 승인 권한)' AFTER role,
    ADD KEY idx_agents_super (is_super);
