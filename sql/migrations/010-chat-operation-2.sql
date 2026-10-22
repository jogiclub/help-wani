-- 파일 위치: sql/migrations/010-chat-operation-2.sql
-- 역할: 상담 운영 기능 2차(docs/chat-operation-plan.md F6~F8) — 답변 속도 안내, 중복 응대 방지(브라우저 알림), 전화 버튼
-- 적용: mysql -u<user> -p <db> < 010-chat-operation-2.sql   (009-chat-operation.sql 다음에 적용)

SET NAMES utf8mb4;

ALTER TABLE organizations
    ADD COLUMN chat_reply_mode       ENUM('off','manual','auto') NOT NULL DEFAULT 'off' COMMENT '위젯 답변 속도 안내: 끔/직접 지정/실제 기록' AFTER chat_msg_away,
    ADD COLUMN chat_reply_minutes    SMALLINT UNSIGNED DEFAULT NULL COMMENT '직접 지정한 답변 속도(분)' AFTER chat_reply_mode,
    ADD COLUMN chat_reply_auto_min   SMALLINT UNSIGNED DEFAULT NULL COMMENT '실제 기록 계산값(분, 최근 7일 중앙값). 표본이 적으면 NULL' AFTER chat_reply_minutes,
    ADD COLUMN chat_reply_calc_at    DATETIME     DEFAULT NULL COMMENT '실제 기록 계산 시각(UTC)' AFTER chat_reply_auto_min,
    ADD COLUMN chat_phone_button     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '위젯 전화 상담 버튼 표시' AFTER chat_reply_calc_at,
    ADD COLUMN chat_phone_hours_only TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '전화 버튼을 운영 시간에만 표시' AFTER chat_phone_button;

ALTER TABLE agents
    ADD COLUMN chat_notify TINYINT(1) NOT NULL DEFAULT 1 COMMENT '새 상담 문의 브라우저 알림' AFTER chat_status;

ALTER TABLE conversations
    ADD COLUMN first_visitor_at  DATETIME   DEFAULT NULL COMMENT '방문자 첫 메시지 시각(답변 속도 계산)' AFTER auto_reply_kind,
    ADD COLUMN first_reply_at    DATETIME   DEFAULT NULL COMMENT '그 뒤 상담원 첫 답변 시각' AFTER first_visitor_at,
    ADD COLUMN received_in_hours TINYINT(1) DEFAULT NULL COMMENT '첫 메시지가 운영 중(closed 아님)에 들어왔는지' AFTER first_reply_at,
    ADD KEY idx_conv_org_first (org_id, first_visitor_at);

-- 이미 있는 대화를 채운다. 예전에는 운영 시간이 없었으므로 모두 운영 중에 들어온 것으로 본다.
UPDATE conversations c
SET c.first_visitor_at = (SELECT MIN(m.created_at) FROM messages m
                          WHERE m.conversation_id = c.id AND m.sender_type = 'visitor');

UPDATE conversations c
SET c.first_reply_at = (SELECT MIN(m.created_at) FROM messages m
                        WHERE m.conversation_id = c.id AND m.sender_type = 'agent'
                          AND m.created_at >= c.first_visitor_at),
    c.received_in_hours = 1
WHERE c.first_visitor_at IS NOT NULL;
