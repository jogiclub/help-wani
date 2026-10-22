-- 파일 위치: sql/migrations/009-chat-operation.sql
-- 역할: 상담 운영 기능 1차(docs/chat-operation-plan.md) — 운영 시간, 휴일, 운영 상태, 부재중 자동 응답, 상담원 개인 상태
-- 적용: mysql -u<user> -p <db> < 009-chat-operation.sql
--
-- chat_hours / chat_holidays 의 날짜와 시각은 UTC 가 아니라 "조직 시간대의 벽시계" 값이다(CLAUDE.md 3.4 의 예외).
-- 판정할 때만 지금 시각(UTC)을 조직 시간대로 바꿔 비교한다.

SET NAMES utf8mb4;

ALTER TABLE organizations
    ADD COLUMN chat_hours_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '운영 시간 사용. 끄면 상담원 접속 여부로만 판단' AFTER guide_theme,
    ADD COLUMN chat_manual_state  ENUM('auto','open','closed') NOT NULL DEFAULT 'auto' COMMENT '조직 운영 상태 수동 전환' AFTER chat_hours_enabled,
    ADD COLUMN chat_manual_until  DATETIME     DEFAULT NULL COMMENT '수동 상태가 자동으로 돌아가는 시각(UTC). NULL 이면 직접 바꿀 때까지' AFTER chat_manual_state,
    ADD COLUMN chat_offhours_mode ENUM('accept','info','hide') NOT NULL DEFAULT 'accept' COMMENT '운영 시간 외 위젯: 받기/안내만/숨기기' AFTER chat_manual_until,
    ADD COLUMN chat_msg_closed    VARCHAR(500) DEFAULT NULL COMMENT '운영 시간 외 자동 응답. 비우면 기본 문구' AFTER chat_offhours_mode,
    ADD COLUMN chat_msg_holiday   VARCHAR(500) DEFAULT NULL COMMENT '휴일 자동 응답. 비우면 기본 문구' AFTER chat_msg_closed,
    ADD COLUMN chat_msg_away      VARCHAR(500) DEFAULT NULL COMMENT '상담원 부재 자동 응답. 비우면 기본 문구' AFTER chat_msg_holiday;

-- 요일별 운영 구간. 하루 최대 3개, 자정을 넘는 구간은 다음 날 00:00 구간으로 나눠 저장한다.
CREATE TABLE IF NOT EXISTS chat_hours (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id      INT UNSIGNED NOT NULL,
  weekday     TINYINT UNSIGNED NOT NULL COMMENT '0=일 ... 6=토',
  start_min   SMALLINT UNSIGNED NOT NULL COMMENT '시작(자정부터 분, 조직 로컬)',
  end_min     SMALLINT UNSIGNED NOT NULL COMMENT '끝(자정부터 분, 최대 1440, 조직 로컬)',
  PRIMARY KEY (id),
  KEY idx_chat_hours_org (org_id, weekday),
  CONSTRAINT fk_chat_hours_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 휴일. 휴일이 요일별 운영 시간보다 우선한다.
CREATE TABLE IF NOT EXISTS chat_holidays (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id        INT UNSIGNED NOT NULL,
  start_date    DATE         NOT NULL COMMENT '조직 로컬 날짜',
  end_date      DATE         NOT NULL COMMENT '조직 로컬 날짜(포함)',
  name          VARCHAR(60)  NOT NULL,
  mode          ENUM('closed','short') NOT NULL DEFAULT 'closed' COMMENT '종일 휴무 / 단축 운영',
  start_min     SMALLINT UNSIGNED DEFAULT NULL COMMENT '단축 운영 시작(분)',
  end_min       SMALLINT UNSIGNED DEFAULT NULL COMMENT '단축 운영 끝(분)',
  repeat_yearly TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '매년 같은 양력 날짜에 반복',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_holidays_org (org_id, start_date),
  CONSTRAINT fk_chat_holidays_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE agents
    ADD COLUMN chat_status ENUM('available','away','off') NOT NULL DEFAULT 'available' COMMENT '상담 응대 상태(로그인하면 available)' AFTER last_seen_at;

ALTER TABLE conversations
    ADD COLUMN auto_reply_at   DATETIME    DEFAULT NULL COMMENT '마지막 부재중 자동 응답 시각' AFTER offline_notified_at,
    ADD COLUMN auto_reply_kind VARCHAR(10) DEFAULT NULL COMMENT '마지막 자동 응답 종류(closed/holiday/away)' AFTER auto_reply_at;
