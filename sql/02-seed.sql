-- 파일 위치: sql/02-seed.sql
-- 역할: 개발/검증용 초기 데이터
-- 주의: 운영 환경에는 적용하지 않는다.

-- mysql 클라이언트 기본 문자셋이 utf8mb4 가 아닐 때 한글이 이중 인코딩되는 것을 막는다.
SET NAMES utf8mb4;

INSERT INTO organizations (org_code, name, biz_no, phone, status, plan)
VALUES ('demo', '데모 고객센터', '000-00-00000', '02-000-0000', 'active', 'basic')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 비밀번호: Test1234!admin
INSERT INTO agents (org_id, email, password_hash, name, phone, role, is_verified, is_active)
SELECT o.id, 'admin@demo.local',
       '$2y$10$cYzoDJKV4uuZ.1VqK.L0KeUTf4RDVfuMI8EuZzIhSGC9QeyM825mS',
       '데모 관리자', '02-000-0001', 'admin', 1, 1
FROM organizations o WHERE o.org_code = 'demo'
ON DUPLICATE KEY UPDATE name = VALUES(name);
