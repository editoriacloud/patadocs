<?php
/**
 * PATADOCS — security layer: headers, sessions, CSRF, rate limiting,
 * admin authentication + permissions, upload validation, secure tokens.
 */

// ============================================================================
// BOOT: headers, session, error handling
// ============================================================================

function security_boot(): void
{
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header("Content-Security-Policy: object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
        if (is_https()) { header('Strict-Transport-Security: max-age=15552000'); }
        header_remove('X-Powered-By');
        // Admin screens and AJAX/JSON endpoints must never appear in search results
        if (defined('PD_AJAX') || preg_match('#/admin/[^/]*$#', str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''))) { header('X-Robots-Tag: noindex, nofollow'); }
        canonical_host_redirect();
    }
    set_exception_handler('pd_exception_handler');
    // Search-engine bots don't need (or get) sessions: avoids thousands of session files.
    if (!defined('PD_NO_SESSION') && (!is_bot() || defined('PD_NEEDS_SESSION'))) { session_boot(); }
}

/**
 * One URL per page: GET requests that arrive on another scheme or a www/non-www variant of BASE_URL's host
 * get a 301 to the same path on BASE_URL (e.g. http://www.knickpoint.co.ke/... → https://knickpoint.co.ke/...).
 * Unrelated hosts (a local copy, a server IP) are left alone, and a request is only treated as plain http when
 * neither the server nor a proxy header says it is https — so a proxy setup can never cause a redirect loop.
 */
function canonical_host_redirect(): void
{
    if (PHP_SAPI === 'cli' || defined('PD_AJAX') || !defined('BASE_URL') || BASE_URL === '') { return; }
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || setting('force_canonical_host', '1') !== '1') { return; }
    $want = parse_url(BASE_URL);
    if (empty($want['host']) || empty($want['scheme'])) { return; }
    $wantHost = strtolower($want['host']) . (isset($want['port']) ? ':' . $want['port'] : '');
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || preg_replace('/^www\./', '', $host) !== preg_replace('/^www\./', '', $wantHost)) { return; }
    $httpsSeen = is_https() || stripos((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 'https') !== false
        || stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false || strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';
    $schemeWrong = strtolower($want['scheme']) === 'https' && !$httpsSeen;
    if ($host === $wantHost && !$schemeWrong) { return; }
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
    if ($uri === '' || $uri[0] !== '/') { $uri = '/' . $uri; }
    header('Location: ' . strtolower($want['scheme']) . '://' . $wantHost . $uri, true, 301);
    exit;
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('pd_sid');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (site_root_path() ?: '/'),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // Session hijacking defence: a session is tied to the browser's User-Agent.
    $ua = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');
    if (isset($_SESSION['_ua']) && !hash_equals($_SESSION['_ua'], $ua)) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['_ua'] = $ua;
}

function pd_exception_handler(Throwable $e): void
{
    log_error(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    if (!headers_sent()) { http_response_code(500); }
    if (wants_json()) {
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['ok' => false, 'message' => 'Something went wrong on our side. Please try again.']);
        exit;
    }
    if (defined('APP_ENV') && APP_ENV === 'development') { echo '<pre style="padding:20px">' . htmlspecialchars((string)$e) . '</pre>'; exit; }
    echo '<!DOCTYPE html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title>'
       . '<body style="font-family:Tahoma,sans-serif;background:#c0c0c0;text-align:center;padding:60px 20px">'
       . '<div style="max-width:520px;margin:auto;background:#d8d8d8;border:2px solid #808080;border-radius:4px">'
       . '<div style="background:linear-gradient(90deg,#000080,#2e86c1);color:#fff;padding:14px;font-weight:bold;font-size:1.3rem">SOMETHING WENT WRONG</div>'
       . '<div style="padding:24px"><p>We hit an unexpected problem. Nothing was lost — please try again in a moment.</p><p><a href="./">Back to home</a></p></div></div></body></html>';
    exit;
}

// ============================================================================
// CSRF
// ============================================================================

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) { return ''; }
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_valid(): bool
{
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}
/** Stops the request unless a valid CSRF token was sent (POST field "csrf" or X-CSRF-Token header). */
function csrf_check(bool $json = false): void
{
    if (csrf_valid()) { return; }
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {      // PHP discards the whole request when post_max_size is exceeded
        $msg = 'The upload is larger than this server allows (limit ' . ini_get('post_max_size') . '). Choose a smaller file or ask your host to raise post_max_size / upload_max_filesize.';
        if ($json || wants_json()) { json_out(['ok' => false, 'code' => 'too_large', 'message' => $msg], 413); }
        abort_page(413, 'File too large', $msg);
    }
    if ($json || wants_json()) { json_out(['ok' => false, 'code' => 'csrf', 'message' => 'Your session expired. Please refresh the page and try again.'], 419); }
    abort_page(419, 'Session expired', 'Your session expired or the form was invalid. Please go back, refresh the page and try again.');
}

// ============================================================================
// RATE LIMITING (per key, stored in MySQL)
// ============================================================================

/** Returns true when the action is allowed (and records it); false when the limit is exceeded. */
function rate_limit(string $key, int $max, int $seconds): bool
{
    $key = mb_substr($key, 0, 120);
    $n = (int)db_val('SELECT COUNT(*) FROM rate_limits WHERE rl_key = ? AND created_at > (NOW() - INTERVAL ? SECOND)', [$key, $seconds]);
    if ($n >= $max) { return false; }
    db_exec('INSERT INTO rate_limits (rl_key) VALUES (?)', [$key]);
    if (mt_rand(1, 200) === 1) { db_exec('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)'); }
    return true;
}

// ============================================================================
// TOKENS
// ============================================================================

function secure_token(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }

/** Order IDs like DOC-8F92K4X7 (no look-alike characters). */
function order_code_gen(): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = '';
    for ($i = 0; $i < 8; $i++) { $s .= $chars[random_int(0, strlen($chars) - 1)]; }
    return 'DOC-' . $s;
}

// ============================================================================
// ADMIN AUTHENTICATION + PERMISSIONS
// ============================================================================

/** Returns the signed-in admin (session data) or null. Enforces the idle timeout. */
function admin_current(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) { return null; }
    $a = $_SESSION['admin'] ?? null;
    if (!$a) { return null; }
    $timeout = max(5, (int)setting('session_timeout', 60)) * 60;
    if (time() - (int)($a['last'] ?? 0) > $timeout) { unset($_SESSION['admin']); return null; }
    $_SESSION['admin']['last'] = time();
    if (time() - (int)($a['checked'] ?? 0) > 60) {           // re-validate against the DB once a minute
        $row = db_row('SELECT id, username, email, full_name, role, status FROM admin_users WHERE id = ?', [$a['id']]);
        if (!$row || $row['status'] !== 'active') { unset($_SESSION['admin']); return null; }
        $_SESSION['admin'] = array_merge($_SESSION['admin'], ['role' => $row['role'], 'name' => $row['full_name'] ?: $row['username'], 'checked' => time()]);
    }
    return $_SESSION['admin'];
}

/** Attempts a login. Returns ['ok' => bool, 'error' => string]. */
function admin_login(string $identifier, string $password): array
{
    $ident = mb_strtolower(trim($identifier)); $ip = client_ip();
    $window = max(1, (int)setting('lockout_minutes', 15)); $max = max(1, (int)setting('max_login_attempts', 5));
    $failsByIdent = (int)db_val(
        "SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND success = 0 AND created_at > (NOW() - INTERVAL ? MINUTE)
         AND created_at > COALESCE((SELECT MAX(created_at) FROM login_attempts WHERE identifier = ? AND success = 1), '2000-01-01')", [$ident, $window, $ident]);
    $failsByIp = (int)db_val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND created_at > (NOW() - INTERVAL ? MINUTE)', [$ip, $window]);
    if ($failsByIdent >= $max || $failsByIp >= $max * 3) {
        return ['ok' => false, 'error' => 'Too many failed attempts. Please wait ' . $window . ' minutes and try again.'];
    }
    $user = db_row('SELECT * FROM admin_users WHERE (LOWER(username) = ? OR LOWER(email) = ?) LIMIT 1', [$ident, $ident]);
    if ($user) { $ok = password_verify($password, $user['password_hash']) && $user['status'] === 'active'; }
    else { password_hash($password, PASSWORD_DEFAULT); $ok = false; }      // same work either way → no user-enumeration by timing
    db_exec('INSERT INTO login_attempts (identifier, ip, success, user_agent) VALUES (?, ?, ?, ?)', [mb_substr($ident, 0, 190), $ip, $ok ? 1 : 0, mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    if (mt_rand(1, 100) === 1) { db_exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 180 DAY)'); }
    if (!$ok) { return ['ok' => false, 'error' => 'Invalid username or password.']; }

    if (session_status() !== PHP_SESSION_ACTIVE) { session_boot(); }
    session_regenerate_id(true);                                  // prevents session fixation
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['admin'] = ['id' => (int)$user['id'], 'username' => $user['username'], 'name' => $user['full_name'] ?: $user['username'],
        'role' => $user['role'], 'last' => time(), 'checked' => time()];
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_exec('UPDATE admin_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }
    db_exec('UPDATE admin_users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [$ip, $user['id']]);
    log_admin('login', 'admin', $user['id'], 'Signed in');
    return ['ok' => true, 'error' => ''];
}

function admin_logout(): void
{
    if (!empty($_SESSION['admin'])) { log_admin('logout', 'admin', $_SESSION['admin']['id'], 'Signed out'); }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}

function admin_perms(string $role): array
{
    static $cache = [];
    if (!isset($cache[$role])) { $cache[$role] = array_column(db_all('SELECT permission FROM role_permissions WHERE role = ?', [$role]), 'permission'); }
    return $cache[$role];
}
function admin_can(string $perm): bool
{
    $a = $_SESSION['admin'] ?? null;
    if (!$a) { return false; }
    return $a['role'] === 'SUPER_ADMIN' || in_array($perm, admin_perms($a['role']), true);
}

/** Every admin page/endpoint calls this first. Redirects to login (or returns JSON 401) when needed. */
function require_admin(string $perm = ''): array
{
    $a = admin_current();
    if (!$a) {
        if (defined('PD_AJAX')) { json_out(['ok' => false, 'code' => 'auth', 'message' => 'Please sign in again.'], 401); }
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect(url('admin/login.php') . ($next !== '' ? '?next=' . urlencode($next) : ''));
    }
    if ($perm !== '' && !admin_can($perm)) {
        if (defined('PD_AJAX')) { json_out(['ok' => false, 'code' => 'forbidden', 'message' => 'You do not have permission to do that.'], 403); }
        abort_page(403, 'Access denied', 'Your role does not have permission to open this page.');
    }
    return $a;
}

function log_admin(string $action, string $entity = '', $entityId = null, string $details = ''): void
{
    cache_flush();                                     // any admin change → public counters/lists refresh immediately
    try {
        db_exec('INSERT INTO admin_activity (admin_id, action, entity, entity_id, details, ip) VALUES (?, ?, ?, ?, ?, ?)', [
            $_SESSION['admin']['id'] ?? null, mb_substr($action, 0, 80), $entity !== '' ? mb_substr($entity, 0, 40) : null,
            $entityId !== null ? (string)$entityId : null, $details !== '' ? mb_substr($details, 0, 500) : null, client_ip(),
        ]);
    } catch (Throwable $e) { log_error('log_admin failed: ' . $e->getMessage()); }
}

/** Only allow post-login redirects to admin URLs on this site. */
function safe_next(string $next): string
{
    if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || strpos($next, '\\') !== false || stripos($next, '/admin/') === false) { return ''; }
    return $next;
}

// ============================================================================
// FILE UPLOAD VALIDATION
// ============================================================================

function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE: case UPLOAD_ERR_FORM_SIZE: return 'The file is larger than the server allows.';
        case UPLOAD_ERR_PARTIAL: return 'The upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_FILE: return 'Please choose a file to upload.';
        default: return 'The file could not be uploaded (error ' . $code . ').';
    }
}

function pd_detect_mime(string $path): string
{
    if (function_exists('finfo_open') && ($f = @finfo_open(FILEINFO_MIME_TYPE))) { $m = (string)@finfo_file($f, $path); finfo_close($f); return $m; }
    if (function_exists('mime_content_type')) { return (string)@mime_content_type($path); }
    return '';
}

/** A .docx is a ZIP that must contain word/document.xml. Also guards against zip bombs. */
function pd_docx_valid(string $path): bool
{
    if (!class_exists('ZipArchive')) { return true; }
    $z = new ZipArchive();
    if ($z->open($path) !== true) { return false; }
    $ok = $z->locateName('[Content_Types].xml') !== false && $z->locateName('word/document.xml') !== false && $z->numFiles <= 3000;
    if ($ok) { $total = 0; for ($i = 0; $i < $z->numFiles; $i++) { $st = $z->statIndex($i); $total += $st['size'] ?? 0; if ($total > 800 * 1048576) { $ok = false; break; } } }
    $z->close();
    return $ok;
}

function safe_display_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}\s._\-()]+/u', '', $name);
    return mb_substr(trim($name), 0, 200) ?: 'document';
}

/**
 * Validates an uploaded file on several independent factors (never trusts the browser):
 * PHP upload status, size, extension whitelist, dangerous names, magic bytes, real MIME type
 * (finfo), structure (docx zip / image decoding / PDF trailer) and script-injection in images.
 * Returns ['ok'=>true, ext, mime, size, hash, name] or ['ok'=>false, 'error'=>...].
 */
function upload_check(array $file, array $allowedExts, int $maxBytes, bool $imagesOnly = false): array
{
    $fail = function ($m) { return ['ok' => false, 'error' => $m]; };
    if (!isset($file['error']) || is_array($file['error'])) { return $fail('Invalid upload.'); }
    if ($file['error'] !== UPLOAD_ERR_OK) { return $fail(upload_error_message((int)$file['error'])); }
    if (!is_uploaded_file($file['tmp_name'])) { return $fail('Invalid upload.'); }
    $size = (int)filesize($file['tmp_name']);
    if ($size <= 0) { return $fail('The file is empty.'); }
    if ($size > $maxBytes) { return $fail('The file is too large. Maximum size is ' . fmt_size($maxBytes) . '.'); }
    $name = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($imagesOnly) { $allowedExts = array_values(array_intersect($allowedExts, ['jpg', 'jpeg', 'png', 'webp'])); }
    if (!in_array($ext, $allowedExts, true)) { return $fail('This file type is not allowed. Allowed: ' . strtoupper(implode(', ', $allowedExts)) . '.'); }
    if (preg_match('/\.(php\d?|phtml|phar|exe|com|bat|cmd|sh|js|html?|htaccess|zip|rar|7z|tar|gz|dll|jar|svg)(\.|$)/i', $name)) { return $fail('This file name is not allowed.'); }

    $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 16);
    $mime = pd_detect_mime($file['tmp_name']);
    $okMagic = false; $okMime = true;
    switch ($ext) {
        case 'pdf':  $okMagic = strncmp($head, '%PDF-', 5) === 0; $okMime = $mime === '' || $mime === 'application/pdf'; break;
        case 'doc':  $okMagic = strncmp($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", 8) === 0;
                     $okMime = $mime === '' || in_array($mime, ['application/msword', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office', 'application/x-cfb', 'application/octet-stream'], true); break;
        case 'docx': $okMagic = strncmp($head, "PK\x03\x04", 4) === 0 && pd_docx_valid($file['tmp_name']);
                     $okMime = $mime === '' || in_array($mime, ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true); break;
        case 'jpg': case 'jpeg': $okMagic = strncmp($head, "\xFF\xD8\xFF", 3) === 0; $okMime = $mime === '' || $mime === 'image/jpeg'; break;
        case 'png':  $okMagic = strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0; $okMime = $mime === '' || $mime === 'image/png'; break;
        case 'webp': $okMagic = substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP'; $okMime = $mime === '' || $mime === 'image/webp'; break;
    }
    if (!$okMagic || !$okMime) { return $fail('The file content does not match its type, or the file is corrupted.'); }

    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $info = @getimagesize($file['tmp_name']);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000) { return $fail('The image is invalid or too large (max 12000×12000 pixels).'); }
        $raw = (string)file_get_contents($file['tmp_name']);
        if (stripos($raw, '<?php') !== false || stripos($raw, '<script') !== false) { return $fail('The image contains disallowed content.'); }
    }
    if ($ext === 'pdf') {
        $tail = (string)file_get_contents($file['tmp_name'], false, null, max(0, $size - 4096));
        if (strpos($tail, '%%EOF') === false) { return $fail('The PDF looks incomplete or corrupted.'); }
    }
    return ['ok' => true, 'ext' => $ext, 'mime' => doc_mime($ext), 'size' => $size, 'hash' => hash_file('sha256', $file['tmp_name']), 'name' => safe_display_name($name)];
}

/** Moves a validated upload into $dir under a random name. Returns the stored file name or null. */
function upload_save(string $tmp, string $ext, string $dir): ?string
{
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return null; }
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = rtrim($dir, '/\\') . '/' . $name;
    if (!@move_uploaded_file($tmp, $dest)) { return null; }
    @chmod($dest, 0640);
    return $name;
}

/** Maximum upload size in bytes from settings, capped by PHP's own limits. */
function max_upload_bytes(string $settingKey = 'max_file_mb'): int
{
    $want = max(1, (int)setting($settingKey, 25)) * 1048576;
    $ini = function ($v) { $v = trim((string)$v); $n = (int)$v; switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; } return $n; };
    $php = min($ini(ini_get('upload_max_filesize')) ?: PHP_INT_MAX, $ini(ini_get('post_max_size')) ?: PHP_INT_MAX);
    return (int)min($want, $php);
}
