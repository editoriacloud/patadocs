-- ============================================================================
-- PATADOCS — Kenyan Document Discovery, Sharing & Download Hub
-- MySQL 5.7+ / MariaDB 10.3+   (InnoDB, utf8mb4)
--
-- Safe to run more than once: every table uses CREATE TABLE IF NOT EXISTS and
-- the starter data uses INSERT IGNORE.
-- NOTE FOR THE INSTALLER: no statement below contains a semicolon inside a
-- string, and every statement ends with ";" at the end of a line.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- SETTINGS (key/value; defaults live in includes/functions.php default_settings())
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value MEDIUMTEXT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ADMIN USERS, ROLES, PERMISSIONS, LOGIN + ACTIVITY LOGS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admin_users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(60)  NOT NULL,
  email         VARCHAR(190) NOT NULL,
  full_name     VARCHAR(120) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('SUPER_ADMIN','ADMIN','CONTENT_MANAGER','REVIEWER') NOT NULL DEFAULT 'CONTENT_MANAGER',
  status        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_username (username),
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role       VARCHAR(30) NOT NULL,
  permission VARCHAR(60) NOT NULL,
  PRIMARY KEY (role, permission)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifier VARCHAR(190) NOT NULL,
  ip         VARCHAR(45)  NOT NULL,
  success    TINYINT(1)   NOT NULL DEFAULT 0,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_la_ident (identifier, created_at),
  KEY idx_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_activity (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id   INT UNSIGNED NULL,
  action     VARCHAR(80)  NOT NULL,
  entity     VARCHAR(40)  NULL,
  entity_id  VARCHAR(40)  NULL,
  details    VARCHAR(500) NULL,
  ip         VARCHAR(45)  NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_aa_created (created_at),
  KEY idx_aa_admin (admin_id),
  CONSTRAINT fk_aa_admin FOREIGN KEY (admin_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- CATEGORIES (unlimited depth; "path" holds the full slug path for clean URLs)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id        INT UNSIGNED NULL,
  name             VARCHAR(150) NOT NULL,
  slug             VARCHAR(120) NOT NULL,
  path             VARCHAR(191) NOT NULL,
  depth            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  description      TEXT NULL,
  icon             VARCHAR(16) NULL,
  image            VARCHAR(255) NULL,
  seo_title        VARCHAR(190) NULL,
  meta_description VARCHAR(320) NULL,
  status           ENUM('active','hidden') NOT NULL DEFAULT 'active',
  featured         TINYINT(1) NOT NULL DEFAULT 0,
  sort_order       INT NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_path (path),
  KEY idx_cat_parent (parent_id, sort_order),
  KEY idx_cat_featured (featured, status),
  CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- METADATA FIELDS (attach to a category; inherited by all its descendants.
-- category_id NULL = applies to every category)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS metadata_fields (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category_id   INT UNSIGNED NULL,
  label         VARCHAR(100) NOT NULL,
  field_key     VARCHAR(60)  NOT NULL,
  field_type    ENUM('text','textarea','number','year','dropdown','checkbox','radio') NOT NULL DEFAULT 'text',
  options       TEXT NULL,
  is_required   TINYINT(1) NOT NULL DEFAULT 0,
  is_searchable TINYINT(1) NOT NULL DEFAULT 1,
  is_filterable TINYINT(1) NOT NULL DEFAULT 0,
  is_seo        TINYINT(1) NOT NULL DEFAULT 0,
  sort_order    INT NOT NULL DEFAULT 0,
  status        ENUM('active','hidden') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mf_cat (category_id, sort_order),
  KEY idx_mf_key (field_key),
  CONSTRAINT fk_mf_cat FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- DOCUMENTS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documents (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title            VARCHAR(255) NOT NULL,
  slug             VARCHAR(191) NOT NULL,
  category_id      INT UNSIGNED NULL,
  description      TEXT NULL,
  doc_type         VARCHAR(80) NULL,
  file_name        VARCHAR(100) NULL,
  original_name    VARCHAR(255) NULL,
  file_ext         VARCHAR(10) NULL,
  file_mime        VARCHAR(100) NULL,
  file_size        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_hash        CHAR(64) NULL,
  pages            SMALLINT UNSIGNED NULL,
  is_free          TINYINT(1) NOT NULL DEFAULT 1,
  price            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency         CHAR(3) NOT NULL DEFAULT 'KES',
  status           ENUM('draft','pending_review','published','archived','rejected') NOT NULL DEFAULT 'draft',
  featured         TINYINT(1) NOT NULL DEFAULT 0,
  popular          TINYINT(1) NOT NULL DEFAULT 0,
  preview_status   ENUM('none','ready','failed') NOT NULL DEFAULT 'none',
  preview_dir      VARCHAR(80) NULL,
  preview_pages    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  preview_limit    TINYINT UNSIGNED NULL,
  seo_title        VARCHAR(190) NULL,
  meta_description VARCHAR(320) NULL,
  seo_keywords     VARCHAR(400) NULL,
  search_text      MEDIUMTEXT NULL,
  content_text     MEDIUMTEXT NULL,
  content_status   ENUM('none','ok','empty','unsupported','failed') NOT NULL DEFAULT 'none',
  source           ENUM('admin','community') NOT NULL DEFAULT 'admin',
  contribution_id  INT UNSIGNED NULL,
  contributor_name VARCHAR(120) NULL,
  contributor_badge ENUM('none','community','verified') NOT NULL DEFAULT 'none',
  show_contributor TINYINT(1) NOT NULL DEFAULT 0,
  author           VARCHAR(190) NULL,
  download_limit   SMALLINT UNSIGNED NULL,
  view_count       INT UNSIGNED NOT NULL DEFAULT 0,
  preview_count    INT UNSIGNED NOT NULL DEFAULT 0,
  free_downloads   INT UNSIGNED NOT NULL DEFAULT 0,
  paid_downloads   INT UNSIGNED NOT NULL DEFAULT 0,
  purchase_count   INT UNSIGNED NOT NULL DEFAULT 0,
  revenue          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_by       INT UNSIGNED NULL,
  published_at     DATETIME NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_slug (slug),
  KEY idx_doc_status_cat (status, category_id),
  KEY idx_doc_status_pub (status, published_at),
  KEY idx_doc_status_upd (status, updated_at),
  KEY idx_doc_status_pop (status, popular, view_count),
  KEY idx_doc_status_free (status, is_free),
  KEY idx_doc_featured (status, featured),
  KEY idx_doc_hash (file_hash),
  CONSTRAINT fk_doc_cat FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_admin FOREIGN KEY (created_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80)  NOT NULL,
  slug VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tag_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_tags (
  document_id INT UNSIGNED NOT NULL,
  tag_id      INT UNSIGNED NOT NULL,
  PRIMARY KEY (document_id, tag_id),
  KEY idx_dt_tag (tag_id),
  CONSTRAINT fk_dt_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_dt_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_meta (
  document_id INT UNSIGNED NOT NULL,
  field_id    INT UNSIGNED NOT NULL,
  meta_value  TEXT NOT NULL,
  PRIMARY KEY (document_id, field_id),
  KEY idx_dm_field_val (field_id, meta_value(100)),
  CONSTRAINT fk_dm_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_dm_field FOREIGN KEY (field_id) REFERENCES metadata_fields (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- SEARCH: synonyms + anonymous search log
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS search_synonyms (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  term      VARCHAR(100) NOT NULL,
  canonical VARCHAR(150) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_syn (term, canonical)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  query        VARCHAR(190) NOT NULL,
  norm_query   VARCHAR(190) NOT NULL,
  results      INT UNSIGNED NOT NULL DEFAULT 0,
  filters      VARCHAR(500) NULL,
  session_hash CHAR(16) NULL,
  ip_hash      CHAR(16) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sl_norm (norm_query, created_at),
  KEY idx_sl_created (created_at),
  KEY idx_sl_results (results, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- COLLECTIONS (bundles)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collections (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title            VARCHAR(255) NOT NULL,
  slug             VARCHAR(191) NOT NULL,
  description      TEXT NULL,
  cover_image      VARCHAR(255) NULL,
  is_free          TINYINT(1) NOT NULL DEFAULT 0,
  price            DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  seo_title        VARCHAR(190) NULL,
  meta_description VARCHAR(320) NULL,
  featured         TINYINT(1) NOT NULL DEFAULT 0,
  status           ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  sort_order       INT NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_col_slug (slug),
  KEY idx_col_status (status, featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collection_documents (
  collection_id INT UNSIGNED NOT NULL,
  document_id   INT UNSIGNED NOT NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (collection_id, document_id),
  KEY idx_cd_doc (document_id),
  CONSTRAINT fk_cd_col FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE,
  CONSTRAINT fk_cd_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ORDERS, PAYMENTS (via Payment Hub), WEBHOOK EVENTS
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_code    VARCHAR(20) NOT NULL,
  access_key    CHAR(32) NOT NULL,
  document_id   INT UNSIGNED NULL,
  collection_id INT UNSIGNED NULL,
  item_title    VARCHAR(255) NOT NULL,
  amount        DECIMAL(10,2) NOT NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'KES',
  phone         VARCHAR(15) NOT NULL,
  email         VARCHAR(190) NULL,
  customer_name VARCHAR(120) NULL,
  status        ENUM('pending','paid','failed','expired','refunded') NOT NULL DEFAULT 'pending',
  mpesa_receipt VARCHAR(30) NULL,
  hub_reference VARCHAR(100) NULL,
  stk_count     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  stk_sent_at   DATETIME NULL,
  invoice_ref   VARCHAR(100) NULL,
  hub_invoice_id VARCHAR(100) NULL,
  hub_status    VARCHAR(255) NULL,
  hub_note      VARCHAR(255) NULL,
  search_log_id BIGINT UNSIGNED NULL,
  ip            VARCHAR(45) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NULL,
  paid_at       DATETIME NULL,
  review_asked_at DATETIME NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_order_code (order_code),
  UNIQUE KEY uq_order_receipt (mpesa_receipt),
  KEY idx_order_status (status, created_at),
  KEY idx_order_invoice_ref (invoice_ref),
  KEY idx_order_hub_invoice (hub_invoice_id),
  KEY idx_order_phone (phone),
  KEY idx_order_doc (document_id),
  KEY idx_order_col (collection_id),
  KEY idx_order_search (search_log_id),
  CONSTRAINT fk_order_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL,
  CONSTRAINT fk_order_col FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id       INT UNSIGNED NOT NULL,
  hub_reference  VARCHAR(100) NULL,
  phone          VARCHAR(15) NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  currency       CHAR(3) NOT NULL DEFAULT 'KES',
  method         VARCHAR(30) NOT NULL DEFAULT 'mpesa_stk',
  status         ENUM('initiated','pending','success','failed','cancelled','timeout') NOT NULL DEFAULT 'initiated',
  mpesa_receipt  VARCHAR(30) NULL,
  result_desc    VARCHAR(255) NULL,
  webhook_status ENUM('none','received','verified','rejected') NOT NULL DEFAULT 'none',
  last_checked_at DATETIME NULL,
  response_json  TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at   DATETIME NULL,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_order (order_id),
  KEY idx_pay_hub (hub_reference),
  KEY idx_pay_status (status, created_at),
  CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id     VARCHAR(120) NOT NULL,
  order_code   VARCHAR(100) NULL,
  signature_ok TINYINT(1) NOT NULL DEFAULT 0,
  payload      MEDIUMTEXT NULL,
  ip           VARCHAR(45) NULL,
  result       VARCHAR(60) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wh_event (event_id),
  KEY idx_wh_order (order_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- SECURE DOWNLOAD TOKENS + LOG
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS download_tokens (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  token             CHAR(64) NOT NULL,
  document_id       INT UNSIGNED NOT NULL,
  order_id          INT UNSIGNED NULL,
  type              ENUM('free','paid') NOT NULL DEFAULT 'free',
  status            ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
  max_downloads     SMALLINT UNSIGNED NOT NULL DEFAULT 3,
  download_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  first_download_at DATETIME NULL,
  last_download_at  DATETIME NULL,
  expires_at        DATETIME NOT NULL,
  created_ip        VARCHAR(45) NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  KEY idx_dtk_order (order_id),
  KEY idx_dtk_doc (document_id),
  KEY idx_dtk_status (status, expires_at),
  CONSTRAINT fk_dtk_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_dtk_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS download_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_id    INT UNSIGNED NULL,
  document_id INT UNSIGNED NULL,
  order_id    INT UNSIGNED NULL,
  type        ENUM('free','paid') NOT NULL DEFAULT 'free',
  ip          VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dl_doc (document_id, created_at),
  KEY idx_dl_created (created_at),
  KEY idx_dl_token (token_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- COMMUNITY CONTRIBUTIONS (free resources only in v1, no accounts)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contributions (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title                  VARCHAR(255) NOT NULL,
  description            TEXT NULL,
  category_id            INT UNSIGNED NULL,
  tags                   VARCHAR(400) NULL,
  metadata_json          TEXT NULL,
  file_name              VARCHAR(100) NOT NULL,
  original_name          VARCHAR(255) NULL,
  file_ext               VARCHAR(10) NOT NULL,
  file_mime              VARCHAR(100) NULL,
  file_size              BIGINT UNSIGNED NOT NULL DEFAULT 0,
  preview_image          VARCHAR(100) NULL,
  contributor_name       VARCHAR(120) NOT NULL,
  contributor_email      VARCHAR(190) NOT NULL,
  contributor_phone      VARCHAR(20) NULL,
  source_info            VARCHAR(255) NULL,
  copyright_confirmed    TINYINT(1) NOT NULL DEFAULT 0,
  copyright_confirmed_at DATETIME NULL,
  ip                     VARCHAR(45) NULL,
  status                 ENUM('pending','under_review','approved','rejected','published','archived') NOT NULL DEFAULT 'pending',
  show_attribution       TINYINT(1) NOT NULL DEFAULT 1,
  badge                  ENUM('none','community','verified') NOT NULL DEFAULT 'community',
  admin_notes            TEXT NULL,
  reviewed_by            INT UNSIGNED NULL,
  reviewed_at            DATETIME NULL,
  document_id            INT UNSIGNED NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_con_status (status, created_at),
  KEY idx_con_email (contributor_email),
  CONSTRAINT fk_con_cat FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_con_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL,
  CONSTRAINT fk_con_admin FOREIGN KEY (reviewed_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- DOCUMENT REQUESTS, REPORTS, CONTACT MESSAGES
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_requests (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title       VARCHAR(255) NOT NULL,
  norm_title  VARCHAR(190) NOT NULL,
  category_id INT UNSIGNED NULL,
  description TEXT NULL,
  phone       VARCHAR(20) NULL,
  email       VARCHAR(190) NULL,
  status      ENUM('new','reviewing','found','created','rejected') NOT NULL DEFAULT 'new',
  admin_notes TEXT NULL,
  document_id INT UNSIGNED NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_req_status (status, created_at),
  KEY idx_req_norm (norm_title),
  CONSTRAINT fk_req_cat FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_req_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT UNSIGNED NOT NULL,
  reason      ENUM('copyright','incorrect','broken','duplicate','inappropriate','other') NOT NULL DEFAULT 'other',
  message     TEXT NULL,
  email       VARCHAR(190) NULL,
  status      ENUM('new','reviewing','resolved','dismissed') NOT NULL DEFAULT 'new',
  admin_notes TEXT NULL,
  handled_by  INT UNSIGNED NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rep_status (status, created_at),
  KEY idx_rep_doc (document_id),
  CONSTRAINT fk_rep_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_rep_admin FOREIGN KEY (handled_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  email      VARCHAR(190) NOT NULL,
  message    TEXT NOT NULL,
  status     ENUM('new','read','archived') NOT NULL DEFAULT 'new',
  ip         VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cm_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- DAILY VIEW STATS + GENERIC RATE LIMITER
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_stats_daily (
  stat_date     DATE NOT NULL,
  document_id   INT UNSIGNED NOT NULL,
  views         INT UNSIGNED NOT NULL DEFAULT 0,
  preview_views INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (stat_date, document_id),
  KEY idx_sd_doc (document_id),
  CONSTRAINT fk_sd_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rl_key     VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rl (rl_key, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- STARTER DATA (all editable/deletable from the admin panel)
-- ============================================================================

-- Role permissions. SUPER_ADMIN always has everything (handled in code).
INSERT IGNORE INTO role_permissions (role, permission) VALUES
('ADMIN','dashboard.view'),('ADMIN','documents.view'),('ADMIN','documents.edit'),('ADMIN','documents.delete'),
('ADMIN','categories.manage'),('ADMIN','metadata.manage'),('ADMIN','collections.manage'),('ADMIN','homepage.manage'),
('ADMIN','synonyms.manage'),('ADMIN','contributions.review'),('ADMIN','reports.manage'),('ADMIN','requests.manage'),
('ADMIN','orders.view'),('ADMIN','orders.manage'),('ADMIN','payments.view'),('ADMIN','downloads.manage'),
('ADMIN','analytics.view'),('ADMIN','settings.manage'),
('CONTENT_MANAGER','dashboard.view'),('CONTENT_MANAGER','documents.view'),('CONTENT_MANAGER','documents.edit'),
('CONTENT_MANAGER','categories.manage'),('CONTENT_MANAGER','metadata.manage'),('CONTENT_MANAGER','collections.manage'),
('CONTENT_MANAGER','homepage.manage'),('CONTENT_MANAGER','synonyms.manage'),('CONTENT_MANAGER','requests.manage'),
('CONTENT_MANAGER','analytics.view'),
('REVIEWER','dashboard.view'),('REVIEWER','documents.view'),('REVIEWER','contributions.review'),
('REVIEWER','reports.manage'),('REVIEWER','requests.manage');

-- Starter categories (the ten tiles of the design + the examples from the brief)
INSERT IGNORE INTO categories (id, parent_id, name, slug, path, depth, description, icon, featured, sort_order) VALUES
(1, NULL, 'Education',    'education',  'education',  0, 'Schemes of work, exams, notes, past papers and teaching resources for Kenyan schools.', '📚', 1, 1),
(2, NULL, 'Business',     'business',   'business',   0, 'Business plans, templates and guides for Kenyan entrepreneurs and companies.', '💼', 1, 2),
(3, NULL, 'Government',   'government', 'government', 0, 'Forms, guides, policies and public documents from Kenyan government agencies.', '🏛️', 1, 3),
(4, NULL, 'Careers',      'careers',    'careers',    0, 'CV templates, cover letters and job-search resources for the Kenyan market.', '👔', 1, 4),
(5, NULL, 'Agriculture',  'agriculture','agriculture',0, 'Farming guides, business plans and agribusiness resources.', '🌾', 1, 5),
(6, NULL, 'NGOs & CBOs',  'ngos-cbos',  'ngos-cbos',  0, 'Constitutions, registration guides and templates for NGOs and community organizations.', '🤝', 1, 6),
(7, NULL, 'Templates',    'templates',  'templates',  0, 'Ready-to-edit templates for everyday work.', '📄', 0, 7),
(8, NULL, 'Finance',      'finance',    'finance',    0, 'Financial templates, budgets and record-keeping tools.', '💰', 0, 8),
(9, NULL, 'College',      'college',    'college',    0, 'Notes, past papers and resources for colleges and universities.', '🎓', 0, 9),
(10,NULL, 'Personal',     'personal',   'personal',   0, 'Personal and family documents, letters and templates.', '📁', 0, 10),
(11, 1,  'Grade 7',            'grade-7',        'education/grade-7',                                   1, NULL, '🎒', 0, 1),
(12, 11, 'Mathematics',        'mathematics',    'education/grade-7/mathematics',                       2, NULL, '➗', 0, 1),
(13, 12, 'Schemes of Work',    'schemes-of-work','education/grade-7/mathematics/schemes-of-work',       3, NULL, NULL, 0, 1),
(14, 2,  'Business Plans',     'business-plans', 'business/business-plans',                             1, NULL, '📈', 0, 1),
(15, 14, 'Agriculture',        'agriculture',    'business/business-plans/agriculture',                 2, NULL, '🌾', 0, 1),
(16, 15, 'Poultry',            'poultry',        'business/business-plans/agriculture/poultry',         3, NULL, '🐔', 0, 1),
(17, 4,  'CV Templates',       'cv-templates',   'careers/cv-templates',                                1, NULL, '📝', 0, 1);

-- Starter metadata fields (inherited by every sub-category of the category they are attached to)
INSERT IGNORE INTO metadata_fields (id, category_id, label, field_key, field_type, options, is_required, is_searchable, is_filterable, is_seo, sort_order) VALUES
(1, 1, 'Education Level', 'education_level', 'dropdown', CONCAT_WS(CHAR(10),'Pre-Primary','Primary','Junior Secondary','Senior Secondary','College / University','Teacher Resource'), 0, 1, 1, 1, 1),
(2, 1, 'Grade',           'grade',           'dropdown', CONCAT_WS(CHAR(10),'PP1','PP2','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6','Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12','Form 1','Form 2','Form 3','Form 4'), 0, 1, 1, 1, 2),
(3, 1, 'Subject',         'subject',         'dropdown', CONCAT_WS(CHAR(10),'Mathematics','English','Kiswahili','Integrated Science','Science and Technology','Social Studies','Agriculture and Nutrition','Creative Arts','Pre-Technical Studies','Christian Religious Education','Islamic Religious Education','Biology','Chemistry','Physics','Geography','History','Business Studies','Computer Studies','Other'), 0, 1, 1, 1, 3),
(4, 1, 'Term',            'term',            'dropdown', CONCAT_WS(CHAR(10),'Term 1','Term 2','Term 3'), 0, 1, 1, 1, 4),
(5, 1, 'Year',            'year',            'year',     NULL, 0, 1, 1, 1, 5),
(6, 1, 'Curriculum',      'curriculum',      'dropdown', CONCAT_WS(CHAR(10),'CBC','8-4-4','Other'), 0, 1, 1, 0, 6),
(7, 1, 'Resource Type',   'resource_type',   'dropdown', CONCAT_WS(CHAR(10),'Scheme of Work','Lesson Plan','Notes','Exams','Revision Papers','Marking Scheme','Past Papers','Record of Work','Template','Guide','Other'), 0, 1, 1, 1, 7),
(8, 2, 'Industry',        'industry',        'text',     NULL, 0, 1, 1, 1, 1),
(9, 2, 'Business Type',   'business_type',   'dropdown', CONCAT_WS(CHAR(10),'Sole Proprietorship','Partnership','Limited Company','Cooperative','Other'), 0, 1, 1, 0, 2),
(10,2, 'Country',         'country',         'text',     NULL, 0, 1, 0, 1, 3),
(11,2, 'Year',            'year',            'year',     NULL, 0, 1, 1, 0, 4),
(12,3, 'Agency',          'agency',          'text',     NULL, 0, 1, 1, 1, 1),
(13,3, 'Document Type',   'document_type',   'dropdown', CONCAT_WS(CHAR(10),'Form','Guide','Policy','Act / Regulation','Report','Application','Other'), 0, 1, 1, 1, 2),
(14,3, 'County',          'county',          'dropdown', CONCAT_WS(CHAR(10),'Baringo','Bomet','Bungoma','Busia','Elgeyo-Marakwet','Embu','Garissa','Homa Bay','Isiolo','Kajiado','Kakamega','Kericho','Kiambu','Kilifi','Kirinyaga','Kisii','Kisumu','Kitui','Kwale','Laikipia','Lamu','Machakos','Makueni','Mandera','Marsabit','Meru','Migori','Mombasa','Murang\'a','Nairobi','Nakuru','Nandi','Narok','Nyamira','Nyandarua','Nyeri','Samburu','Siaya','Taita-Taveta','Tana River','Tharaka-Nithi','Trans-Nzoia','Turkana','Uasin Gishu','Vihiga','Wajir','West Pokot'), 0, 1, 1, 1, 3),
(15,3, 'Year',            'year',            'year',     NULL, 0, 1, 1, 0, 4);

-- Starter search synonyms (two-way: either word finds documents using the other)
INSERT IGNORE INTO search_synonyms (term, canonical) VALUES
('math','mathematics'),('maths','mathematics'),('exam','examinations'),('exams','examinations'),
('scheme','schemes of work'),('schemes','schemes of work'),('cv','curriculum vitae'),('resume','curriculum vitae'),
('cbo','community based organization'),('ngo','non governmental organization'),('cbc','competency based curriculum'),
('sst','social studies'),('cre','christian religious education'),('ire','islamic religious education'),
('ict','information and communication technology'),('kisw','kiswahili'),('sci','science'),('eng','english'),
('lesson plans','lesson plan'),('past papers','past paper'),('bizplan','business plan');

-- ----------------------------------------------------------------------------
-- REVIEWS (verified buyers only; moderated) — schema version 2
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS document_reviews (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_id INT UNSIGNED NOT NULL,
  order_id    INT UNSIGNED NOT NULL,
  rating      TINYINT UNSIGNED NOT NULL,
  name        VARCHAR(80) NOT NULL,
  comment     TEXT NULL,
  status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_order_doc (order_id, document_id),
  KEY idx_review_doc (document_id, status, created_at),
  KEY idx_review_status (status, created_at),
  CONSTRAINT fk_review_doc FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE CASCADE,
  CONSTRAINT fk_review_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- AUTOMATION ENGINE — schema version 3
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_runs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job         VARCHAR(40) NOT NULL,
  trigger_by  ENUM('cron','web','admin') NOT NULL DEFAULT 'cron',
  status      ENUM('ok','error') NOT NULL DEFAULT 'ok',
  message     VARCHAR(500) NULL,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_job_runs (job, created_at),
  KEY idx_job_runs_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS search_vocab (
  word  VARCHAR(60) NOT NULL,
  freq  INT UNSIGNED NOT NULL DEFAULT 1,
  len   TINYINT UNSIGNED NOT NULL,
  first CHAR(1) NOT NULL,
  PRIMARY KEY (word),
  KEY idx_vocab_lookup (first, len)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PAYMENTS DIAGNOSTICS — schema version 4
CREATE TABLE IF NOT EXISTS hub_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  method      VARCHAR(8) NOT NULL,
  path        VARCHAR(190) NOT NULL,
  status      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  order_code  VARCHAR(100) NULL,
  error       VARCHAR(255) NULL,
  response    TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hub_log_created (created_at),
  KEY idx_hub_log_order (order_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES ('schema_version', '6') ON DUPLICATE KEY UPDATE setting_value = setting_value;
