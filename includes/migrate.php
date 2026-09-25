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
    ];
    $current = (int)setting('schema_version', 1);
    foreach ($steps as $version => $sqls) {
        if ($version <= $current) { continue; }
        try {
            foreach ($sqls as $sql) { db()->exec($sql); }
            set_setting('schema_version', (string)$version);
        } catch (Throwable $e) {
            log_error('Database upgrade to version ' . $version . ' failed: ' . $e->getMessage());
            return;                                           // retried on the next request
        }
    }
}
