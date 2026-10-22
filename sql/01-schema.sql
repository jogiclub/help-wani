-- 파일 위치: sql/01-schema.sql
-- 역할: RemoteHelp 포털 데이터베이스 스키마

SET NAMES utf8mb4;

-- 시간은 모두 UTC 로 저장하고, 화면에 보여줄 때 조직 타임존으로 변환한다.
SET time_zone = '+00:00';

-- 조직
CREATE TABLE IF NOT EXISTS organizations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_code      VARCHAR(32)  NOT NULL COMMENT 'URL 에 쓰이는 조직 식별자',
  name          VARCHAR(100) NOT NULL,
  logo_path     VARCHAR(255) DEFAULT NULL COMMENT '로고 파일 경로(assets/uploads/logos/...)',
  intro         VARCHAR(500) DEFAULT NULL COMMENT '고객 접속 페이지에 보이는 조직 소개',
  biz_no        VARCHAR(20)  DEFAULT NULL COMMENT '사업자등록번호',
  phone         VARCHAR(30)  DEFAULT NULL,
  status        ENUM('pending','active','rejected','suspended') NOT NULL DEFAULT 'pending'
                COMMENT '가입 승인 상태',
  approved_at   DATETIME     DEFAULT NULL COMMENT '승인 시각',
  approved_by   INT UNSIGNED DEFAULT NULL COMMENT '승인한 운영자',
  status_reason VARCHAR(300) DEFAULT NULL COMMENT '반려/중지 사유',
  plan          VARCHAR(30)  NOT NULL DEFAULT 'basic',
  country       CHAR(2)      NOT NULL DEFAULT 'KR' COMMENT '국가 코드(ISO 3166-1 alpha-2)',
  locale        VARCHAR(10)  NOT NULL DEFAULT 'ko' COMMENT '화면 언어(ko/en/ja)',
  timezone      VARCHAR(50)  NOT NULL DEFAULT 'Asia/Seoul' COMMENT 'IANA 타임존',
  widget_enabled       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '상담 위젯 사용 여부',
  widget_key           CHAR(32)     DEFAULT NULL COMMENT '스니펫에 들어가는 공개 키(재발급 가능)',
  widget_domains       VARCHAR(500) DEFAULT NULL COMMENT '허용 도메인. 줄바꿈 구분. 비우면 어디서나 허용',
  widget_color         CHAR(7)      NOT NULL DEFAULT '#2563eb' COMMENT '버블 색상',
  widget_position      ENUM('right','left') NOT NULL DEFAULT 'right' COMMENT '버블 위치',
  widget_offline_email VARCHAR(190) DEFAULT NULL COMMENT '오프라인 알림 받을 주소',
  guide_autosuggest    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '위젯에서 가이드 자동 추천',
  guide_seeded_at      DATETIME     DEFAULT NULL COMMENT '가이드 샘플을 만든(또는 건너뛴) 시각, UTC',
  guide_theme          VARCHAR(20)  NOT NULL DEFAULT 'card' COMMENT '공개 가이드 테마',
  guide_feature_enabled  TINYINT(1)       NOT NULL DEFAULT 0     COMMENT '공개 가이드 새 기능 모음 보이기',
  guide_feature_new_days TINYINT UNSIGNED NOT NULL DEFAULT 30    COMMENT 'NEW 배지 기간(일), 0 이면 끔',
  guide_feature_banner   TINYINT(1)       NOT NULL DEFAULT 0     COMMENT '가이드 첫 화면에 최근 새 기능 띠',
  guide_video_enabled    TINYINT(1)       NOT NULL DEFAULT 0     COMMENT '공개 가이드 동영상 모음 보이기',
  guide_video_sort       VARCHAR(10)      NOT NULL DEFAULT 'toc' COMMENT '동영상 정렬: toc | updated',
  guide_video_filter     TINYINT(1)       NOT NULL DEFAULT 0     COMMENT '동영상 모음 분류 거르기',
  chat_hours_enabled   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '운영 시간 사용. 끄면 상담원 접속 여부로만 판단',
  chat_manual_state    ENUM('auto','open','closed') NOT NULL DEFAULT 'auto' COMMENT '조직 운영 상태 수동 전환',
  chat_manual_until    DATETIME     DEFAULT NULL COMMENT '수동 상태가 자동으로 돌아가는 시각(UTC). NULL 이면 직접 바꿀 때까지',
  chat_offhours_mode   ENUM('accept','info','hide') NOT NULL DEFAULT 'accept' COMMENT '운영 시간 외 위젯: 받기/안내만/숨기기',
  chat_msg_closed      VARCHAR(500) DEFAULT NULL COMMENT '운영 시간 외 자동 응답. 비우면 기본 문구',
  chat_msg_holiday     VARCHAR(500) DEFAULT NULL COMMENT '휴일 자동 응답. 비우면 기본 문구',
  chat_msg_away        VARCHAR(500) DEFAULT NULL COMMENT '상담원 부재 자동 응답. 비우면 기본 문구',
  chat_reply_mode      ENUM('off','manual','auto') NOT NULL DEFAULT 'off' COMMENT '위젯 답변 속도 안내: 끔/직접 지정/실제 기록',
  chat_reply_minutes   SMALLINT UNSIGNED DEFAULT NULL COMMENT '직접 지정한 답변 속도(분)',
  chat_reply_auto_min  SMALLINT UNSIGNED DEFAULT NULL COMMENT '실제 기록 계산값(분, 최근 7일 중앙값). 표본이 적으면 NULL',
  chat_reply_calc_at   DATETIME     DEFAULT NULL COMMENT '실제 기록 계산 시각(UTC)',
  chat_phone_button    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '위젯 전화 상담 버튼 표시',
  chat_phone_hours_only TINYINT(1)  NOT NULL DEFAULT 1 COMMENT '전화 버튼을 운영 시간에만 표시',
  chat_greeting         TEXT         DEFAULT NULL COMMENT '위젯 인사말. 비우면 보이지 않음. {visitor_name} {org_name} 변수',
  chat_greeting_buttons VARCHAR(1000) DEFAULT NULL COMMENT '인사말 바로가기 버튼 JSON [{label,url}] 최대 3개, https 만',
  chat_ask_name         TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '연락처 카드에서 이름을 묻는다',
  chat_ask_email        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '연락처 카드에서 이메일을 묻는다',
  chat_ask_phone        TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '연락처 카드에서 휴대폰을 묻는다',
  chat_contact_required TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '연락처를 남긴 고객에게만 문의받기',
  chat_contact_notice   VARCHAR(500) DEFAULT NULL COMMENT '연락처 카드 안내 문구. 비우면 기본 문구',
  chat_followup_email   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '고객이 자리를 비웠을 때 상담원 답장을 이메일로 알림',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_org_code (org_code),
  UNIQUE KEY uk_widget_key (widget_key),
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
  last_seen_at  DATETIME     DEFAULT NULL COMMENT '마지막 콘솔 활동 시각(온라인 판별)',
  chat_status   ENUM('available','away','off') NOT NULL DEFAULT 'available' COMMENT '상담 응대 상태(로그인하면 available)',
  chat_notify   TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '새 상담 문의 브라우저 알림',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_agent_email (email),
  KEY idx_agent_org (org_id),
  KEY idx_agents_super (is_super),
  KEY idx_agents_seen (org_id, last_seen_at),
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

-- 위젯 방문자
CREATE TABLE IF NOT EXISTS visitors (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id        INT UNSIGNED NOT NULL,
  visitor_key   CHAR(64)     NOT NULL COMMENT '서버가 발급해 브라우저에 저장하는 식별자',
  name          VARCHAR(60)  DEFAULT NULL,
  email         VARCHAR(190) DEFAULT NULL,
  phone              VARCHAR(20) DEFAULT NULL COMMENT '휴대폰(E.164, 예: +821012345678)',
  contact_consent_at DATETIME    DEFAULT NULL COMMENT '수집 안내를 보고 연락처를 낸 시각',
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
  auto_reply_at       DATETIME     DEFAULT NULL COMMENT '마지막 부재중 자동 응답 시각',
  auto_reply_kind     VARCHAR(10)  DEFAULT NULL COMMENT '마지막 자동 응답 종류(closed/holiday/away)',
  first_visitor_at    DATETIME     DEFAULT NULL COMMENT '방문자 첫 메시지 시각(답변 속도 계산)',
  first_reply_at      DATETIME     DEFAULT NULL COMMENT '그 뒤 상담원 첫 답변 시각',
  received_in_hours   TINYINT(1)   DEFAULT NULL COMMENT '첫 메시지가 운영 중(closed 아님)에 들어왔는지',
  visitor_seen_at     DATETIME     DEFAULT NULL COMMENT '방문자가 대화창을 펼쳐 보고 있던 마지막 시각(위젯 폴링)',
  contact_asked_at    DATETIME     DEFAULT NULL COMMENT '연락처 카드를 내거나 건너뛴 시각. 있으면 다시 묻지 않음',
  followup_sent_at    DATETIME     DEFAULT NULL COMMENT '마지막 답장 알림 메일 시각',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at           DATETIME     DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_conv_org_status_last (org_id, status, last_message_at),
  KEY idx_conv_visitor (visitor_id),
  KEY idx_conv_org_first (org_id, first_visitor_at),
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
  meta            JSON         DEFAULT NULL COMMENT '카드 정보(가이드 등)',
  read_at         DATETIME     DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_msg_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- 상담 운영 (docs/chat-operation-plan.md)
-- 날짜와 시각은 UTC 가 아니라 조직 시간대의 벽시계 값이다(CLAUDE.md 3.4 의 예외).

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

-- ---------------------------------------------------------------- 가이드 (docs/guide-plan.md)

-- 분류
CREATE TABLE IF NOT EXISTS guide_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id      INT UNSIGNED NOT NULL,
  name        VARCHAR(60)  NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gcat_org (org_id, sort_order),
  CONSTRAINT fk_gcat_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 가이드
CREATE TABLE IF NOT EXISTS guide_articles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id       INT UNSIGNED NOT NULL,
  category_id  INT UNSIGNED DEFAULT NULL,
  slug         VARCHAR(60)  DEFAULT NULL COMMENT '주소용. 비우면 id 로 접속',
  title        VARCHAR(100) NOT NULL,
  subtitle     VARCHAR(200) DEFAULT NULL,
  keywords     VARCHAR(500) DEFAULT NULL COMMENT '쉼표 구분 원문',
  blocks       JSON         NOT NULL COMMENT '본문 블록 배열 [{type:text|image|video,...}]',
  status       ENUM('draft','published') NOT NULL DEFAULT 'draft',
  is_feature    TINYINT(1)  NOT NULL DEFAULT 0 COMMENT '새 기능 모음에 보임',
  feature_at    DATETIME    DEFAULT NULL COMMENT '출시일(조직 시간대 그날 00:00 의 UTC). NULL 이면 published_at',
  feature_label VARCHAR(30) DEFAULT NULL COMMENT '버전 등 짧은 표시',
  published_at  DATETIME    DEFAULT NULL COMMENT '처음 공개한 시각, UTC',
  sort_order   INT          NOT NULL DEFAULT 0,
  view_count   INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_garticle_slug (org_id, slug),
  KEY idx_garticle_org (org_id, status, category_id, sort_order),
  KEY idx_garticle_feature (org_id, status, is_feature, feature_at),
  CONSTRAINT fk_garticle_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_garticle_cat FOREIGN KEY (category_id) REFERENCES guide_categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_garticle_agent FOREIGN KEY (updated_by) REFERENCES agents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 매칭 색인. 글을 저장할 때마다 그 글의 행을 지우고 다시 만든다(공개 글만).
-- term 은 PHP 가 정규화한 값이라 대소문자/악센트를 다시 접지 않도록 bin 비교로 둔다.
CREATE TABLE IF NOT EXISTS guide_terms (
  org_id      INT UNSIGNED NOT NULL,
  term        VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  article_id  INT UNSIGNED NOT NULL,
  weight      SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (org_id, term, article_id),
  KEY idx_gterm_article (article_id),
  CONSTRAINT fk_gterm_article FOREIGN KEY (article_id) REFERENCES guide_articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 동의어 묶음 (조직별, 한 줄에 한 묶음)
CREATE TABLE IF NOT EXISTS guide_synonyms (
  id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id  INT UNSIGNED NOT NULL,
  words   VARCHAR(300) NOT NULL COMMENT '쉼표 구분',
  PRIMARY KEY (id),
  KEY idx_gsyn_org (org_id),
  CONSTRAINT fk_gsyn_org FOREIGN KEY (org_id) REFERENCES organizations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- 매크로 (docs/macro-plan.md)

-- 매크로(자주 쓰는 답변). owner_agent_id 가 NULL 이면 조직 공용, 값이 있으면 그 상담원의 개인 매크로.
-- owner_key 는 이름 중복 검사용이다(UNIQUE 는 NULL 끼리 겹침을 검사하지 않는다). Macro_model 이 IFNULL(owner_agent_id, 0) 으로 채운다.
-- 생성 컬럼으로 두지 않는 이유: CASCADE 외래 키가 걸린 컬럼은 STORED 생성 컬럼의 원본이 될 수 없다(MySQL 제약).
CREATE TABLE IF NOT EXISTS macros (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id          INT UNSIGNED NOT NULL,
  owner_agent_id  INT UNSIGNED DEFAULT NULL COMMENT 'NULL 이면 공용, 값이 있으면 그 상담원의 개인 매크로',
  owner_key       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '이름 중복 검사용. 공용 0, 개인은 owner_agent_id',
  name            VARCHAR(40)  NOT NULL,
  body            TEXT         NOT NULL,
  keywords        VARCHAR(300) DEFAULT NULL COMMENT '쉼표 구분 원문(추천용)',
  use_count       INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at    DATETIME     DEFAULT NULL,
  created_by      INT UNSIGNED DEFAULT NULL,
  updated_by      INT UNSIGNED DEFAULT NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_macro_name (org_id, owner_key, name),
  KEY idx_macro_org (org_id, owner_agent_id, last_used_at),
  CONSTRAINT fk_macro_org   FOREIGN KEY (org_id)         REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_macro_owner FOREIGN KEY (owner_agent_id) REFERENCES agents (id)        ON DELETE CASCADE,
  CONSTRAINT fk_macro_cby   FOREIGN KEY (created_by)     REFERENCES agents (id)        ON DELETE SET NULL,
  CONSTRAINT fk_macro_uby   FOREIGN KEY (updated_by)     REFERENCES agents (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 즐겨찾기 (상담원별, 순서 포함)
CREATE TABLE IF NOT EXISTS macro_favorites (
  agent_id    INT UNSIGNED NOT NULL,
  macro_id    INT UNSIGNED NOT NULL,
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (agent_id, macro_id),
  KEY idx_mfav_order (agent_id, sort_order),
  KEY idx_mfav_macro (macro_id),
  CONSTRAINT fk_mfav_agent FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE,
  CONSTRAINT fk_mfav_macro FOREIGN KEY (macro_id) REFERENCES macros (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 사용 기록 (통계용). 매크로로 채운 글을 보낼 때 한 줄씩 남긴다.
CREATE TABLE IF NOT EXISTS macro_uses (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  org_id           INT UNSIGNED    NOT NULL,
  macro_id         INT UNSIGNED    DEFAULT NULL,
  agent_id         INT UNSIGNED    DEFAULT NULL,
  conversation_id  BIGINT UNSIGNED DEFAULT NULL,
  message_id       BIGINT UNSIGNED DEFAULT NULL,
  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_muse_org (org_id, created_at),
  KEY idx_muse_macro (macro_id, created_at),
  CONSTRAINT fk_muse_org   FOREIGN KEY (org_id)   REFERENCES organizations (id) ON DELETE CASCADE,
  CONSTRAINT fk_muse_macro FOREIGN KEY (macro_id) REFERENCES macros (id)        ON DELETE SET NULL,
  CONSTRAINT fk_muse_agent FOREIGN KEY (agent_id) REFERENCES agents (id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 추천 색인 (guide_terms 와 같은 구조). 매크로를 저장할 때마다 그 매크로의 행을 지우고 다시 만든다.
CREATE TABLE IF NOT EXISTS macro_terms (
  org_id    INT UNSIGNED NOT NULL,
  term      VARCHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  macro_id  INT UNSIGNED NOT NULL,
  weight    SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (org_id, term, macro_id),
  KEY idx_mterm_macro (macro_id),
  CONSTRAINT fk_mterm_macro FOREIGN KEY (macro_id) REFERENCES macros (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
