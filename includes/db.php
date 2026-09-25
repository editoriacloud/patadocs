<?php
/**
 * Database access — PDO with prepared statements everywhere.
 * Never concatenate user input into SQL: pass it in the $params array.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        // Keep MySQL's NOW() in the same timezone as PHP's date().
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        $pdo->exec("SET SESSION sql_mode = REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', '')");
    } catch (PDOException $e) {
        log_error('DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        if (defined('PD_AJAX')) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => 'Service temporarily unavailable. Please try again shortly.']);
        } else {
            echo '<!DOCTYPE html><meta charset="utf-8"><title>Service unavailable</title>'
               . '<body style="font-family:Tahoma,sans-serif;text-align:center;padding:60px">'
               . '<h1>Service temporarily unavailable</h1><p>Please try again in a few minutes.</p></body>';
        }
        exit;
    }
    return $pdo;
}

/** Prepare + execute, returns the statement. */
function db_run(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute(array_values($params));
    return $st;
}
function db_all(string $sql, array $params = []): array { return db_run($sql, $params)->fetchAll(); }
function db_row(string $sql, array $params = []): ?array { $r = db_run($sql, $params)->fetch(); return $r === false ? null : $r; }
function db_val(string $sql, array $params = []) { $r = db_run($sql, $params)->fetchColumn(); return $r === false ? null : $r; }
/** Executes and returns the number of affected rows. */
function db_exec(string $sql, array $params = []): int { return db_run($sql, $params)->rowCount(); }
/** Executes an INSERT and returns the new id. */
function db_insert(string $sql, array $params = []): int { db_run($sql, $params); return (int)db()->lastInsertId(); }
/** "?,?,?" placeholder string for IN (...) lists. */
function db_in(array $values): string { return implode(',', array_fill(0, max(1, count($values)), '?')); }

/** Writes to a private error log (never shown to visitors). */
function log_error(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . str_replace(["\r", "\n"], ' ', $msg) . "\n";
    $dir = defined('PRIVATE_DIR') ? PRIVATE_DIR : sys_get_temp_dir();
    if (!@error_log($line, 3, rtrim($dir, '/') . '/error.log')) { error_log(trim($line)); }
}
