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
