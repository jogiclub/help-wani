-- 파일 위치: sql/migrations/004-chat-widget.sql
-- 역할: 상담 위젯(방문자 채팅) 테이블과 조직 위젯 설정 추가
-- 적용: mysql -u<user> -p <db> < 004-chat-widget.sql
--
-- 설계 근거는 docs/widget-plan.md 4장.
-- 시각은 모두 UTC 로 저장한다. 화면에 보여줄 때만 조직 타임존으로 변환한다.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 조직별 위젯 설정
ALTER TABLE organizations
    ADD COLUMN widget_enabled       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '위젯 사용 여부' AFTER timezone,
    ADD COLUMN widget_key           CHAR(32)     DEFAULT NULL COMMENT '스니펫에 들어가는 공개 키(재발급 가능)' AFTER widget_enabled,
    ADD COLUMN widget_domains       VARCHAR(500) DEFAULT NULL COMMENT '허용 도메인. 줄바꿈 구분. 비우면 어디서나 허용' AFTER widget_key,
    ADD COLUMN widget_color         CHAR(7)      NOT NULL DEFAULT '#2563eb' COMMENT '버블 색상' AFTER widget_domains,
    ADD COLUMN widget_position      ENUM('right','left') NOT NULL DEFAULT 'right' COMMENT '버블 위치' AFTER widget_color,
    ADD COLUMN widget_offline_email VARCHAR(190) DEFAULT NULL COMMENT '오프라인 알림 받을 주소' AFTER widget_position,
    ADD UNIQUE KEY uk_widget_key (widget_key);

-- 상담원 접속 여부 판별용. 콘솔 폴링이 갱신하고 60초 이내면 온라인으로 본다.
ALTER TABLE agents
    ADD COLUMN last_seen_at DATETIME DEFAULT NULL COMMENT '마지막 콘솔 활동 시각' AFTER last_login_at,
    ADD KEY idx_agents_seen (org_id, last_seen_at);

-- 위젯 방문자
CREATE TABLE IF NOT EXISTS visitors (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id        INT UNSIGNED NOT NULL,
  visitor_key   CHAR(64)     NOT NULL COMMENT '서버가 발급해 브라우저에 저장하는 식별자',
  name          VARCHAR(60)  DEFAULT NULL,
  email         VARCHAR(190) DEFAULT NULL,
  ip            VARCHAR(45)  DEFAULT NULL,
  user_agent    VARCHAR(255) DEFAULT NULL,
  page_url      VARCHAR(500) DEFAULT NULL COMMENT '위젯이 떠 있던 마지막 페이지',
  first_seen_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_visitor_key (visitor_key),
  KEY idx_visitor_org (org_id, last_seen_at),
  CONSTRAINT fk_visitor_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 대화
CREATE TABLE IF NOT EXISTS conversations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id              INT UNSIGNED NOT NULL,
  visitor_id          BIGINT UNSIGNED NOT NULL,
  status              ENUM('open','closed') NOT NULL DEFAULT 'open',
  assigned_agent_id   INT UNSIGNED DEFAULT NULL,
  session_id          BIGINT UNSIGNED DEFAULT NULL COMMENT '대화에서 시작한 원격 세션',
  last_message_at     DATETIME     DEFAULT NULL,
  unread_for_agent    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '상담원이 아직 못 본 방문자 메시지 수',
  offline_notified_at DATETIME     DEFAULT NULL COMMENT '오프라인 이메일을 보낸 시각(대화당 1회)',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at           DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_conv_org_status_last (org_id, status, last_message_at),
  KEY idx_conv_visitor (visitor_id),
  CONSTRAINT fk_conv_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_visitor FOREIGN KEY (visitor_id) REFERENCES visitors (id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_agent FOREIGN KEY (assigned_agent_id) REFERENCES agents (id) ON DELETE SET NULL,
  CONSTRAINT fk_conv_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 메시지
CREATE TABLE IF NOT EXISTS messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  sender_type     ENUM('visitor','agent','system') NOT NULL,
  agent_id        INT UNSIGNED DEFAULT NULL,
  body            TEXT NOT NULL,
  attachment_url  VARCHAR(500) DEFAULT NULL,
  read_at         DATETIME     DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_msg_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
