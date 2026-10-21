-- 파일 위치: sql/migrations/006-guides.sql
-- 역할: 조직별 가이드(도움말)와 키워드 매칭 색인, 상담 메시지의 가이드 카드 정보 추가
-- 적용: mysql -u<user> -p <db> < 006-guides.sql
--
-- 설계 근거는 docs/guide-plan.md 4장. 원본은 DB 이고 guide.json 은 내려받기/올리기 형식이다.
-- 시각은 모두 UTC 로 저장한다.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

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
  sort_order   INT          NOT NULL DEFAULT 0,
  view_count   INT UNSIGNED NOT NULL DEFAULT 0,
  updated_by   INT UNSIGNED DEFAULT NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_garticle_slug (org_id, slug),
  KEY idx_garticle_org (org_id, status, category_id, sort_order),
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

-- 메시지에 가이드 카드를 붙인다. {"type":"guide","article_id":1,"title":"..","url":".."}
ALTER TABLE messages
    ADD COLUMN meta JSON DEFAULT NULL COMMENT '카드 정보(가이드 등)' AFTER attachment_url;

-- 위젯에서 가이드 자동 추천 사용 여부 (기본 꺼짐)
ALTER TABLE organizations
    ADD COLUMN guide_autosuggest TINYINT(1) NOT NULL DEFAULT 0 COMMENT '위젯에서 가이드 자동 추천' AFTER widget_offline_email;
