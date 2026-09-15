-- 파일 위치: sql/migrations/003-locale-timezone.sql
-- 역할: 조직별 언어(로케일)와 타임존 설정 추가
-- 적용: mysql -u<user> -p <db> < 003-locale-timezone.sql
--
-- 주의: 이 변경과 함께 시간 저장 기준이 UTC 로 바뀝니다.
--   - MySQL: --default-time-zone=+00:00
--   - PHP  : date.timezone = UTC
--   - 표시 : 조직 타임존으로 변환해 보여준다
-- 기존 데이터가 한국 시각으로 저장되어 있었다면 아래 UPDATE 로 UTC 로 옮깁니다.

SET NAMES utf8mb4;

ALTER TABLE organizations
    ADD COLUMN country CHAR(2) NOT NULL DEFAULT 'KR' COMMENT '국가 코드(ISO 3166-1 alpha-2)' AFTER plan,
    ADD COLUMN locale VARCHAR(10) NOT NULL DEFAULT 'ko' COMMENT '화면 언어(ko/en/ja)' AFTER country,
    ADD COLUMN timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Seoul' COMMENT 'IANA 타임존' AFTER locale;

-- 기존 데이터를 한국 시각 -> UTC 로 보정 (이미 UTC 로 저장 중이면 실행하지 말 것)
UPDATE organizations  SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00'),
                          updated_at = CONVERT_TZ(updated_at, '+09:00', '+00:00'),
                          approved_at = CONVERT_TZ(approved_at, '+09:00', '+00:00');
UPDATE agents         SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00'),
                          updated_at = CONVERT_TZ(updated_at, '+09:00', '+00:00'),
                          last_login_at = CONVERT_TZ(last_login_at, '+09:00', '+00:00');
UPDATE sessions       SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00'),
                          updated_at = CONVERT_TZ(updated_at, '+09:00', '+00:00'),
                          expires_at = CONVERT_TZ(expires_at, '+09:00', '+00:00'),
                          verified_at = CONVERT_TZ(verified_at, '+09:00', '+00:00'),
                          started_at = CONVERT_TZ(started_at, '+09:00', '+00:00'),
                          ended_at = CONVERT_TZ(ended_at, '+09:00', '+00:00'),
                          last_heartbeat_at = CONVERT_TZ(last_heartbeat_at, '+09:00', '+00:00');
UPDATE session_tokens SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00'),
                          expires_at = CONVERT_TZ(expires_at, '+09:00', '+00:00'),
                          used_at = CONVERT_TZ(used_at, '+09:00', '+00:00');
UPDATE session_logs   SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00');
UPDATE session_notes  SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00'),
                          updated_at = CONVERT_TZ(updated_at, '+09:00', '+00:00');
UPDATE surveys        SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00');
UPDATE audit_logs     SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00');
UPDATE verify_attempts SET created_at = CONVERT_TZ(created_at, '+09:00', '+00:00');
