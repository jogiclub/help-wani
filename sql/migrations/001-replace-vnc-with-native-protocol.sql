-- 파일 위치: sql/migrations/001-replace-vnc-with-native-protocol.sql
-- 역할: VNC(UltraVNC Repeater) 방식에서 자체 프로토콜로 전환하는 스키마 변경
-- 적용: mysql -u<user> -p <db> < 001-replace-vnc-with-native-protocol.sql

SET NAMES utf8mb4;

ALTER TABLE sessions
    ADD COLUMN agent_token CHAR(64) DEFAULT NULL
        COMMENT '에이전트 중계 서버 접속 토큰(세션당 1개)' AFTER code,
    ADD KEY idx_sessions_agent_token (agent_token);

ALTER TABLE sessions
    DROP COLUMN repeater_id,
    DROP COLUMN vnc_password_enc;

-- 진행 중이던 세션은 접속 정보가 사라지므로 종료 처리한다.
UPDATE sessions
   SET status = 'ended', ended_at = NOW(), end_reason = 'protocol_migration'
 WHERE status IN ('issued', 'verified', 'waiting', 'connected');
