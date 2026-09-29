-- Practice Onboarding Tracker - schema
-- Target: MySQL 5.7+ / MariaDB 10.2+ (no CTEs, no window functions,
-- so it runs on typical GoDaddy shared hosting).
-- Contains no patient data. Do not store PHI in this database.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Reference data
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS products (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(120) NOT NULL,
  slug         VARCHAR(140) NOT NULL,
  description  VARCHAR(500) DEFAULT NULL,
  -- Hex colour used for this product's chips, tabs and chart bars.
  color        CHAR(7) DEFAULT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_slug (slug),
  KEY ix_products_order (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(120) NOT NULL,
  color        CHAR(7) DEFAULT NULL,
  -- Tasks here are the practice's own to do, so they are the only ones
  -- listed as actions on the shared practice page.
  is_customer  TINYINT(1) NOT NULL DEFAULT 0,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_name (name),
  KEY ix_categories_order (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- People are also the user accounts. A person with a PIN can sign in
-- with that PIN alone. They see everything and may change only the
-- tasks assigned to them. `name` is a denormalised display name kept in
-- step with first_name and last_name on every save. `username` is no
-- longer used and is kept only so the change stays reversible.
CREATE TABLE IF NOT EXISTS assignees (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  first_name   VARCHAR(80) NOT NULL DEFAULT '',
  last_name    VARCHAR(80) NOT NULL DEFAULT '',
  name         VARCHAR(120) NOT NULL,
  username     VARCHAR(60) DEFAULT NULL,
  pin_hash     VARCHAR(255) DEFAULT NULL,
  last_login   DATETIME DEFAULT NULL,
  role_title   VARCHAR(120) DEFAULT NULL,
  -- 'member' or 'specialist'. The administrator is not a row here.
  access_level VARCHAR(20) NOT NULL DEFAULT 'member',
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_assignees_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Global task definitions.
-- One row here is one task that applies to EVERY practice using the
-- product. Per-practice state lives in practice_tasks.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id   INT UNSIGNED NOT NULL,
  category_id  INT UNSIGNED NOT NULL,
  -- Whoever normally picks this task up. A practice task with no
  -- assignee of its own falls back to this one.
  default_assignee_id INT UNSIGNED DEFAULT NULL,
  name         VARCHAR(200) NOT NULL,
  description  TEXT DEFAULT NULL,
  sort_order   INT NOT NULL DEFAULT 0,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_tasks_product (product_id, sort_order, id),
  KEY ix_tasks_category (category_id),
  KEY ix_tasks_default_assignee (default_assignee_id),
  CONSTRAINT fk_tasks_product  FOREIGN KEY (product_id)  REFERENCES products (id)   ON DELETE CASCADE,
  CONSTRAINT fk_tasks_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
  CONSTRAINT fk_tasks_default_assignee FOREIGN KEY (default_assignee_id) REFERENCES assignees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Practices and which products each is onboarding
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS practices (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                VARCHAR(180) NOT NULL,
  slug                VARCHAR(200) NOT NULL,
  location            VARCHAR(180) DEFAULT NULL,
  onboarding_state    ENUM('active','on_hold','completed') NOT NULL DEFAULT 'active',
  target_go_live_date DATE DEFAULT NULL,
  notes               TEXT DEFAULT NULL,
  -- A long random token, not the id, because the link is the only
  -- credential a practice representative has. Null means no link.
  share_token         CHAR(32) DEFAULT NULL,
  share_created_at    DATETIME DEFAULT NULL,
  is_archived         TINYINT(1) NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_practices_slug (slug),
  UNIQUE KEY uq_practices_share_token (share_token),
  KEY ix_practices_state (onboarding_state, is_archived),
  KEY ix_practices_golive (target_go_live_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS practice_products (
  practice_id  INT UNSIGNED NOT NULL,
  product_id   INT UNSIGNED NOT NULL,
  added_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (practice_id, product_id),
  KEY ix_pp_product (product_id),
  CONSTRAINT fk_pp_practice FOREIGN KEY (practice_id) REFERENCES practices (id) ON DELETE CASCADE,
  CONSTRAINT fk_pp_product  FOREIGN KEY (product_id)  REFERENCES products (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Per-practice state for a global task.
-- A row exists only once someone has touched the task. Absence of a row
-- means Not Started, which is why a brand new global task appears for
-- every practice immediately with no fan-out write.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS practice_tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  practice_id  INT UNSIGNED NOT NULL,
  task_id      INT UNSIGNED NOT NULL,
  status       ENUM('not_started','in_progress','waiting','blocked','completed','not_applicable')
               NOT NULL DEFAULT 'not_started',
  assignee_id  INT UNSIGNED DEFAULT NULL,
  due_date     DATE DEFAULT NULL,
  notes        TEXT DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_practice_task (practice_id, task_id),
  KEY ix_pt_status (status),
  KEY ix_pt_assignee (assignee_id),
  KEY ix_pt_due (due_date),
  CONSTRAINT fk_pt_practice FOREIGN KEY (practice_id) REFERENCES practices (id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_task     FOREIGN KEY (task_id)     REFERENCES tasks (id)     ON DELETE CASCADE,
  CONSTRAINT fk_pt_assignee FOREIGN KEY (assignee_id) REFERENCES assignees (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit trail. Also the source of truth for "Last updated".
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS activity_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  practice_id  INT UNSIGNED DEFAULT NULL,
  task_id      INT UNSIGNED DEFAULT NULL,
  actor        VARCHAR(120) NOT NULL DEFAULT 'admin',
  entity       VARCHAR(60) NOT NULL,
  entity_id    INT UNSIGNED DEFAULT NULL,
  action       VARCHAR(40) NOT NULL,
  field        VARCHAR(60) DEFAULT NULL,
  old_value    TEXT DEFAULT NULL,
  new_value    TEXT DEFAULT NULL,
  summary      VARCHAR(400) DEFAULT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_log_practice (practice_id, created_at),
  KEY ix_log_created (created_at),
  -- Each of these leads with the filtered column and follows with
  -- created_at, so one index serves both the Activity screen's filter
  -- and its ORDER BY created_at DESC.
  KEY ix_log_actor (actor, created_at),
  KEY ix_log_action (action, created_at),
  KEY ix_log_entity (entity, created_at),
  KEY ix_log_field (field, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  setting_key   VARCHAR(60) NOT NULL,
  setting_value TEXT DEFAULT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-in attempts, counted per address. Kept in the database
-- rather than the session, which an attacker can simply discard.
CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARBINARY(16) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_attempts_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(190) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
