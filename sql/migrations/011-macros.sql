-- 파일 위치: sql/migrations/011-macros.sql
-- 역할: 상담 대화 매크로(docs/macro-plan.md) — 매크로, 즐겨찾기, 사용 기록, 추천 색인
-- 적용: mysql -u<user> -p <db> < 011-macros.sql   (010-chat-operation-2.sql 다음에 적용)
--
-- 시각 컬럼은 모두 UTC 다(CLAUDE.md 3.4).

SET NAMES utf8mb4;

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
