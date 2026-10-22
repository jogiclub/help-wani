-- 파일 위치: sql/migrations/013-chat-greeting-followup.sql
-- 역할: 상담 위젯 인사말, 연락처 받기, 팔로업(상담원 답장 메일 알림) (docs/widget-ops-plan.md 2장, 3장)
-- 적용: mysql -u<user> -p <db> < 013-chat-greeting-followup.sql   (012 다음에 적용)
--
-- 시각은 모두 UTC 다(CLAUDE.md 3.4).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE organizations
    ADD COLUMN chat_greeting         TEXT         DEFAULT NULL COMMENT '위젯 인사말. 비우면 보이지 않음. {visitor_name} {org_name} 변수' AFTER chat_phone_hours_only,
    ADD COLUMN chat_greeting_buttons VARCHAR(1000) DEFAULT NULL COMMENT '인사말 바로가기 버튼 JSON [{label,url}] 최대 3개, https 만' AFTER chat_greeting,
    ADD COLUMN chat_ask_name         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '연락처 카드에서 이름을 묻는다' AFTER chat_greeting_buttons,
    ADD COLUMN chat_ask_email        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '연락처 카드에서 이메일을 묻는다' AFTER chat_ask_name,
    ADD COLUMN chat_ask_phone        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '연락처 카드에서 휴대폰을 묻는다' AFTER chat_ask_email,
    ADD COLUMN chat_contact_required TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '연락처를 남긴 고객에게만 문의받기' AFTER chat_ask_phone,
    ADD COLUMN chat_contact_notice   VARCHAR(500) DEFAULT NULL COMMENT '연락처 카드 안내 문구. 비우면 기본 문구' AFTER chat_contact_required,
    ADD COLUMN chat_followup_email   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '고객이 자리를 비웠을 때 상담원 답장을 이메일로 알림' AFTER chat_contact_notice;

ALTER TABLE visitors
    ADD COLUMN phone              VARCHAR(20) DEFAULT NULL COMMENT '휴대폰(E.164, 예: +821012345678)' AFTER email,
    ADD COLUMN contact_consent_at DATETIME    DEFAULT NULL COMMENT '수집 안내를 보고 연락처를 낸 시각' AFTER phone;

ALTER TABLE conversations
    ADD COLUMN visitor_seen_at   DATETIME DEFAULT NULL COMMENT '방문자가 대화창을 펼쳐 보고 있던 마지막 시각(위젯 폴링)' AFTER received_in_hours,
    ADD COLUMN contact_asked_at  DATETIME DEFAULT NULL COMMENT '연락처 카드를 내거나 건너뛴 시각. 있으면 다시 묻지 않음' AFTER visitor_seen_at,
    ADD COLUMN followup_sent_at  DATETIME DEFAULT NULL COMMENT '마지막 답장 알림 메일 시각' AFTER contact_asked_at;
