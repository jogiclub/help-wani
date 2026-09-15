-- 파일 위치: sql/01-schema.sql
-- 역할: RemoteHelp 포털 데이터베이스 스키마

SET NAMES utf8mb4;
SET time_zone = '+09:00';

-- 조직
CREATE TABLE IF NOT EXISTS organizations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_code      VARCHAR(32)  NOT NULL COMMENT 'URL 에 쓰이는 조직 식별자',
  name          VARCHAR(100) NOT NULL,
  logo_path     VARCHAR(255) DEFAULT NULL,
  biz_no        VARCHAR(20)  DEFAULT NULL COMMENT '사업자등록번호',
  phone         VARCHAR(30)  DEFAULT NULL,
  status        ENUM('pending','active','rejected','suspended') NOT NULL DEFAULT 'pending'
                COMMENT '가입 승인 상태',
  approved_at   DATETIME     DEFAULT NULL COMMENT '승인 시각',
  approved_by   INT UNSIGNED DEFAULT NULL COMMENT '승인한 운영자',
  status_reason VARCHAR(300) DEFAULT NULL COMMENT '반려/중지 사유',
  plan          VARCHAR(30)  NOT NULL DEFAULT 'basic',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_org_code (org_code),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 상담원
CREATE TABLE IF NOT EXISTS agents (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id        INT UNSIGNED NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  name          VARCHAR(60)  NOT NULL,
  phone         VARCHAR(30)  DEFAULT NULL,
  role          ENUM('admin','agent') NOT NULL DEFAULT 'agent',
  is_super      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '플랫폼 운영자 여부(조직 승인 권한)',
  is_verified   TINYINT(1)   NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at DATETIME     DEFAULT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_agent_email (email),
  KEY idx_agent_org (org_id),
  KEY idx_agents_super (is_super),
  CONSTRAINT fk_agent_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 원격 세션
CREATE TABLE IF NOT EXISTS sessions (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id           INT UNSIGNED NOT NULL,
  agent_id         INT UNSIGNED NOT NULL,
  code             CHAR(6)      NOT NULL COMMENT '고객이 입력하는 6자리 숫자',
  agent_token      CHAR(64)     DEFAULT NULL COMMENT '에이전트 중계 서버 접속 토큰(세션당 1개)',
  launcher_secret  CHAR(64)     DEFAULT NULL COMMENT '런처 후속 호출 인증용',
  status           ENUM('issued','verified','waiting','connected','ended','expired','canceled')
                   NOT NULL DEFAULT 'issued',
  expires_at       DATETIME     NOT NULL COMMENT '코드 유효 만료 시각',
  customer_ip      VARCHAR(45)  DEFAULT NULL,
  customer_pc_name VARCHAR(100) DEFAULT NULL,
  customer_os      VARCHAR(100) DEFAULT NULL,
  launcher_version VARCHAR(20)  DEFAULT NULL,
  verified_at      DATETIME     DEFAULT NULL,
  started_at       DATETIME     DEFAULT NULL COMMENT '원격 제어 시작',
  ended_at         DATETIME     DEFAULT NULL,
  last_heartbeat_at DATETIME    DEFAULT NULL,
  end_reason       VARCHAR(50)  DEFAULT NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sessions_code_status (code, status),
  KEY idx_sessions_org_created (org_id, created_at),
  KEY idx_sessions_agent (agent_id),
  KEY idx_sessions_status (status),
  KEY idx_sessions_agent_token (agent_token),
  CONSTRAINT fk_sessions_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_sessions_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- noVNC 1회용 접속 토큰
CREATE TABLE IF NOT EXISTS session_tokens (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  token      CHAR(64)     NOT NULL,
  purpose    ENUM('viewer') NOT NULL DEFAULT 'viewer',
  expires_at DATETIME     NOT NULL,
  used_at    DATETIME     DEFAULT NULL,
  used_ip    VARCHAR(45)  DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_token (token),
  KEY idx_token_session (session_id),
  CONSTRAINT fk_token_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 세션 이벤트 로그
CREATE TABLE IF NOT EXISTS session_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  event      VARCHAR(50)  NOT NULL,
  detail     VARCHAR(500) DEFAULT NULL,
  actor      ENUM('agent','customer','system') NOT NULL DEFAULT 'system',
  ip         VARCHAR(45)  DEFAULT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_log_session (session_id, created_at),
  CONSTRAINT fk_log_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 상담 메모
CREATE TABLE IF NOT EXISTS session_notes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  agent_id   INT UNSIGNED NOT NULL,
  content    TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_note_session (session_id),
  CONSTRAINT fk_note_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
  CONSTRAINT fk_note_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 만족도 조사
CREATE TABLE IF NOT EXISTS surveys (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id BIGINT UNSIGNED NOT NULL,
  score      TINYINT UNSIGNED NOT NULL,
  comment    VARCHAR(1000) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_survey_session (session_id),
  CONSTRAINT fk_survey_session FOREIGN KEY (session_id) REFERENCES sessions (id) ON DELETE CASCADE,
  CONSTRAINT chk_survey_score CHECK (score BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 관리 행위 감사 로그
CREATE TABLE IF NOT EXISTS audit_logs (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id     INT UNSIGNED DEFAULT NULL,
  agent_id   INT UNSIGNED DEFAULT NULL,
  action     VARCHAR(60)  NOT NULL,
  detail     VARCHAR(500) DEFAULT NULL,
  ip         VARCHAR(45)  DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_org (org_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 코드 검증 실패(무차별 대입) 차단용
CREATE TABLE IF NOT EXISTS verify_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip          VARCHAR(45) NOT NULL,
  code        CHAR(6)     DEFAULT NULL,
  is_success  TINYINT(1)  NOT NULL DEFAULT 0,
  created_at  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempt_ip_time (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
