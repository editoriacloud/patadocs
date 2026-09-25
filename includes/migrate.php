<?php
/**
 * PATADOCS — database upgrades for existing installations.
 * init.php runs db_migrate() whenever settings.schema_version is lower than PD_SCHEMA_VERSION, so uploading new
 * code is enough — no manual SQL. Every step must be idempotent (safe to run twice). database.sql already contains
 * the latest schema for fresh installs.
 */
function db_migrate(): void
{
    $steps = [
        2 => [   // verified-buyer reviews
            "CREATE TABLE IF NOT EXISTS document_reviews (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ],
        3 => [   // automation engine: job log, extracted document text, review-request marker, spelling vocabulary
            "CREATE TABLE IF NOT EXISTS job_runs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS search_vocab (
              word  VARCHAR(60) NOT NULL,
              freq  INT UNSIGNED NOT NULL DEFAULT 1,
              len   TINYINT UNSIGNED NOT NULL,
              first CHAR(1) NOT NULL,
              PRIMARY KEY (word),
              KEY idx_vocab_lookup (first, len)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ['documents', 'content_text', "ALTER TABLE documents ADD COLUMN content_text MEDIUMTEXT NULL AFTER search_text"],
            ['documents', 'content_status', "ALTER TABLE documents ADD COLUMN content_status ENUM('none','ok','empty','unsupported','failed') NOT NULL DEFAULT 'none' AFTER content_text"],
            ['orders', 'review_asked_at', "ALTER TABLE orders ADD COLUMN review_asked_at DATETIME NULL AFTER paid_at"],
        ],
        4 => [   // payments: Hub API call log (diagnostics) + STK prompt tracking (resend limits)
            "CREATE TABLE IF NOT EXISTS hub_log (
              id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              method      VARCHAR(8) NOT NULL,
              path        VARCHAR(190) NOT NULL,
              status      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
              order_code  VARCHAR(20) NULL,
              error       VARCHAR(255) NULL,
              response    TEXT NULL,
              created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_hub_log_created (created_at),
              KEY idx_hub_log_order (order_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ['orders', 'stk_count', "ALTER TABLE orders ADD COLUMN stk_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER hub_reference"],
            ['orders', 'stk_sent_at', "ALTER TABLE orders ADD COLUMN stk_sent_at DATETIME NULL AFTER stk_count"],
        ],
        5 => [   // the Hub's invoice reference identifies an order everywhere; last Hub verdict shown to the buyer
            ['orders', 'invoice_ref', "ALTER TABLE orders ADD COLUMN invoice_ref VARCHAR(100) NULL AFTER hub_reference, ADD KEY idx_order_invoice_ref (invoice_ref)"],
            ['orders', 'hub_invoice_id', "ALTER TABLE orders ADD COLUMN hub_invoice_id VARCHAR(100) NULL AFTER invoice_ref, ADD KEY idx_order_hub_invoice (hub_invoice_id)"],
            ['orders', 'hub_status', "ALTER TABLE orders ADD COLUMN hub_status VARCHAR(60) NULL AFTER hub_invoice_id"],
            ['orders', 'hub_note', "ALTER TABLE orders ADD COLUMN hub_note VARCHAR(255) NULL AFTER hub_status"],
            "ALTER TABLE hub_log MODIFY order_code VARCHAR(100) NULL",
            "ALTER TABLE webhook_events MODIFY order_code VARCHAR(100) NULL",
            // existing orders: their invoice id was kept on the payment row
            "UPDATE orders o JOIN (SELECT order_id, MAX(hub_reference) inv FROM payments WHERE hub_reference IS NOT NULL GROUP BY order_id) p ON p.order_id = o.id
               SET o.hub_invoice_id = COALESCE(o.hub_invoice_id, p.inv), o.invoice_ref = COALESCE(o.invoice_ref, p.inv)",
        ],
        6 => [   // the Hub's own words (or the exact reason a check failed) are shown to the buyer
            "ALTER TABLE orders MODIFY hub_status VARCHAR(255) NULL",
        ],
        7 => [   // blog: posts, categories, tags, revisions, comments, media library; author profiles; blog permissions
            "CREATE TABLE IF NOT EXISTS blog_categories (
              id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
              name             VARCHAR(120) NOT NULL,
              slug             VARCHAR(140) NOT NULL,
              description      TEXT NULL,
              seo_title        VARCHAR(190) NULL,
              meta_description VARCHAR(320) NULL,
              sort_order       INT NOT NULL DEFAULT 0,
              created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_blog_cat_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS blog_posts (
              id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
              title            VARCHAR(255) NOT NULL,
              slug             VARCHAR(191) NOT NULL,
              excerpt          TEXT NULL,
              content          MEDIUMTEXT NULL,
              content_text     MEDIUMTEXT NULL,
              cover_image      VARCHAR(255) NULL,
              cover_alt        VARCHAR(255) NULL,
              category_id      INT UNSIGNED NULL,
              author_id        INT UNSIGNED NULL,
              status           ENUM('draft','pending','published','private','trash') NOT NULL DEFAULT 'draft',
              published_at     DATETIME NULL,
              seo_title        VARCHAR(190) NULL,
              meta_description VARCHAR(320) NULL,
              focus_keyword    VARCHAR(120) NULL,
              canonical_url    VARCHAR(500) NULL,
              robots_noindex   TINYINT(1) NOT NULL DEFAULT 0,
              schema_type      VARCHAR(20) NOT NULL DEFAULT 'BlogPosting',
              featured         TINYINT(1) NOT NULL DEFAULT 0,
              allow_comments   TINYINT(1) NOT NULL DEFAULT 1,
              show_toc         TINYINT(1) NOT NULL DEFAULT 1,
              seo_score        TINYINT UNSIGNED NOT NULL DEFAULT 0,
              word_count       INT UNSIGNED NOT NULL DEFAULT 0,
              views            INT UNSIGNED NOT NULL DEFAULT 0,
              doc_ids          VARCHAR(255) NULL,
              created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_blog_post_slug (slug),
              KEY idx_blog_post_pub (status, published_at),
              KEY idx_blog_post_cat (category_id, status, published_at),
              KEY idx_blog_post_author (author_id),
              CONSTRAINT fk_blog_post_cat FOREIGN KEY (category_id) REFERENCES blog_categories (id) ON DELETE SET NULL,
              CONSTRAINT fk_blog_post_author FOREIGN KEY (author_id) REFERENCES admin_users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS blog_tags (
              id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
              name  VARCHAR(80) NOT NULL,
              slug  VARCHAR(100) NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_blog_tag_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS blog_post_tags (
              post_id INT UNSIGNED NOT NULL,
              tag_id  INT UNSIGNED NOT NULL,
              PRIMARY KEY (post_id, tag_id),
              KEY idx_bpt_tag (tag_id),
              CONSTRAINT fk_bpt_post FOREIGN KEY (post_id) REFERENCES blog_posts (id) ON DELETE CASCADE,
              CONSTRAINT fk_bpt_tag FOREIGN KEY (tag_id) REFERENCES blog_tags (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS blog_revisions (
              id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
              post_id    INT UNSIGNED NOT NULL,
              author_id  INT UNSIGNED NULL,
              kind       ENUM('revision','autosave') NOT NULL DEFAULT 'revision',
              title      VARCHAR(255) NOT NULL,
              excerpt    TEXT NULL,
              content    MEDIUMTEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_blog_rev_post (post_id, kind, created_at),
              CONSTRAINT fk_blog_rev_post FOREIGN KEY (post_id) REFERENCES blog_posts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS blog_comments (
              id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
              post_id    INT UNSIGNED NOT NULL,
              parent_id  INT UNSIGNED NULL,
              name       VARCHAR(80) NOT NULL,
              email      VARCHAR(190) NULL,
              body       TEXT NOT NULL,
              status     ENUM('pending','approved','spam','trash') NOT NULL DEFAULT 'pending',
              is_staff   TINYINT(1) NOT NULL DEFAULT 0,
              ip         VARCHAR(45) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_blog_com_post (post_id, status, created_at),
              KEY idx_blog_com_status (status, created_at),
              CONSTRAINT fk_blog_com_post FOREIGN KEY (post_id) REFERENCES blog_posts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS blog_slug_history (
              old_slug   VARCHAR(191) NOT NULL,
              post_id    INT UNSIGNED NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (old_slug),
              KEY idx_blog_slug_post (post_id),
              CONSTRAINT fk_blog_slug_post FOREIGN KEY (post_id) REFERENCES blog_posts (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS blog_media (
              id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
              file_path   VARCHAR(255) NOT NULL,
              thumb_path  VARCHAR(255) NULL,
              file_name   VARCHAR(200) NOT NULL,
              width       INT UNSIGNED NOT NULL DEFAULT 0,
              height      INT UNSIGNED NOT NULL DEFAULT 0,
              size        INT UNSIGNED NOT NULL DEFAULT 0,
              alt         VARCHAR(255) NULL,
              uploaded_by INT UNSIGNED NULL,
              created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_blog_media_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ['admin_users', 'bio', "ALTER TABLE admin_users ADD COLUMN bio TEXT NULL AFTER full_name"],
            ['admin_users', 'author_title', "ALTER TABLE admin_users ADD COLUMN author_title VARCHAR(120) NULL AFTER bio"],
            ['admin_users', 'author_links', "ALTER TABLE admin_users ADD COLUMN author_links TEXT NULL AFTER author_title"],
            "INSERT IGNORE INTO role_permissions (role, permission) VALUES ('ADMIN','blog.write'),('ADMIN','blog.publish'),('ADMIN','blog.comments'),
               ('CONTENT_MANAGER','blog.write'),('CONTENT_MANAGER','blog.publish'),('CONTENT_MANAGER','blog.comments'),('REVIEWER','blog.comments')",
            "INSERT IGNORE INTO blog_categories (id, name, slug, description, sort_order) VALUES (1, 'Guides', 'guides', 'Step-by-step guides and how-tos.', 1), (2, 'News', 'news', 'Updates and announcements.', 2)",
        ],
    ];
    $current = (int)setting('schema_version', 1);
    foreach ($steps as $version => $sqls) {
        if ($version <= $current) { continue; }
        try {
            foreach ($sqls as $sql) {
                if (is_array($sql)) {                          // [table, column, ALTER ...] — only when the column is missing
                    [$table, $column, $alter] = $sql;
                    if (db_val('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column])) { continue; }
                    $sql = $alter;
                }
                db()->exec($sql);
            }
            set_setting('schema_version', (string)$version);
        } catch (Throwable $e) {
            log_error('Database upgrade to version ' . $version . ' failed: ' . $e->getMessage());
            return;                                           // retried on the next request
        }
    }
}
