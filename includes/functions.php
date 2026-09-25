<?php
/**
 * PATADOCS — core helpers: output, URLs, settings, formatting, categories.
 * (Documents / metadata / search live in catalog.php, security in security.php,
 *  HTML partials in view.php.)
 */

// ---- Polyfills for PHP 7.4 -------------------------------------------------
if (!function_exists('str_contains'))    { function str_contains($h, $n)    { return $n === '' || strpos($h, $n) !== false; } }
if (!function_exists('str_starts_with')) { function str_starts_with($h, $n) { return $n === '' || strncmp($h, $n, strlen($n)) === 0; } }
if (!function_exists('str_ends_with'))   { function str_ends_with($h, $n)   { return $n === '' || substr($h, -strlen($n)) === $n; } }

// ============================================================================
// OUTPUT / REQUEST HELPERS
// ============================================================================

/** Escape for HTML output (XSS protection). Use on EVERYTHING user-supplied. */
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { return true; }
    if (defined('TRUST_PROXY') && TRUST_PROXY && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') { return true; }
    return (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/** URL path of the application root, e.g. '' or '/patadocs'. */
function site_root_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (preg_match('#/(admin|ajax)$#', $dir)) { $dir = dirname($dir); }
    $dir = rtrim($dir, '/');
    return $dir === '.' ? '' : $dir;
}

function base_url(): string
{
    if (defined('BASE_URL') && BASE_URL !== '') { return rtrim(BASE_URL, '/'); }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) { $host = 'localhost'; }
    return (is_https() ? 'https' : 'http') . '://' . $host . site_root_path();
}

function url(string $path = ''): string { return base_url() . '/' . ltrim($path, '/'); }

/** URL of a top-level page ('search', 'about'...), honouring the clean-URL setting. */
function page_url(string $name, string $query = ''): string
{
    $u = setting('clean_urls', '1') === '1' ? url($name) : url($name . '.php');
    return $query === '' ? $u : $u . '?' . $query;
}

/** Cache-busted asset URL: asset('css/style.css'). */
function asset(string $path): string
{
    $file = ROOT_DIR . '/assets/' . ltrim($path, '/');
    return url('assets/' . ltrim($path, '/')) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function redirect(string $to, int $code = 302): void
{
    if (!headers_sent()) { header('Location: ' . $to, true, $code); }
    exit;
}

function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

function wants_json(): bool
{
    return defined('PD_AJAX')
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
}

function is_xhr(): bool { return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '' || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false; }

/**
 * Answers an AJAX form post with JSON, or (for non-JS browsers) flashes the message and redirects back.
 * $extra is merged into the JSON (e.g. ['errors' => [...], 'redirect' => '...']).
 */
function respond(bool $ok, string $message, array $extra = [], int $status = 400): void
{
    if (is_xhr()) { json_out(array_merge(['ok' => $ok, 'message' => $message], $extra), $ok ? 200 : $status); }
    flash($ok ? 'success' : 'error', $message);
    $back = post_str('return', 400);
    if ($back === '' || strpos($back, base_url()) !== 0) { $back = (string)($_SERVER['HTTP_REFERER'] ?? ''); }
    if ($back === '' || strpos($back, base_url()) !== 0) { $back = url(''); }
    redirect($extra['redirect'] ?? $back);
}

function json_out(array $data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP);   // HEX flags: even if sniffed as HTML, no live tags
    exit;
}

/** Trimmed, length-limited string from an input array ($_GET / $_POST). */
function input_str(array $src, string $key, int $max = 255): string
{
    $v = $src[$key] ?? '';
    if (!is_scalar($v)) { return ''; }
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim((string)$v));
    return mb_substr((string)$v, 0, $max, 'UTF-8');
}
function input_int(array $src, string $key, int $default = 0): int
{
    return isset($src[$key]) && is_scalar($src[$key]) && preg_match('/^-?\d{1,12}$/', (string)$src[$key]) ? (int)$src[$key] : $default;
}
function get_str(string $k, int $max = 255): string { return input_str($_GET, $k, $max); }
function post_str(string $k, int $max = 255): string { return input_str($_POST, $k, $max); }
function get_int(string $k, int $d = 0): int { return input_int($_GET, $k, $d); }
function post_int(string $k, int $d = 0): int { return input_int($_POST, $k, $d); }

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (defined('TRUST_PROXY') && TRUST_PROXY) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $cand = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($cand, FILTER_VALIDATE_IP)) { return $cand; }
            }
        }
    }
    return $ip;
}

/** Short, non-reversible IP fingerprint for anonymous analytics. */
function ip_hash(string $ip): string { return substr(hash_hmac('sha256', $ip, defined('APP_SECRET') ? APP_SECRET : 'x'), 0, 16); }

function is_bot(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|curl|wget|python-requests|headless|lighthouse|pingdom|uptime|monitor/i', $ua);
}

// ---- Flash messages (shown once, styled like the design's notes) ------------
function flash(string $type, string $msg): void { if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION['flash'][] = [$type, $msg]; } }
function flash_html(): string
{
    if (empty($_SESSION['flash'])) { return ''; }
    $out = '';
    foreach ($_SESSION['flash'] as $f) { $out .= '<div class="alert alert-' . e($f[0]) . '">' . e($f[1]) . '</div>'; }
    unset($_SESSION['flash']);
    return $out;
}

// ============================================================================
// SETTINGS (database-backed, with code defaults)
// ============================================================================

function default_settings(): array
{
    return [
        // General
        'site_name' => 'PATADOCS', 'tagline' => 'Find the Document You Need.',
        'site_email' => '', 'site_phone' => '', 'site_logo' => '', 'site_favicon' => '',
        'about_text' => "PATADOCS is Kenya's powerful document discovery and download hub. We connect people with the documents they need for education, business, careers, and more. No account required. Search, preview, pay via M-Pesa, and download instantly.",
        'footer_text' => "PATADOCS · KENYA'S POWERFUL DOCUMENT DISCOVERY AND DOWNLOAD HUB · POWERED BY EDITORIA CLOUD SYSTEMS",
        'currency' => 'KES', 'currency_symbol' => 'KSh', 'clean_urls' => '1', 'show_admin_link' => '1',
        // Documents
        'allowed_types' => 'pdf,doc,docx,jpg,jpeg,png,webp', 'max_file_mb' => '25',
        'default_download_limit' => '3', 'default_preview_pages' => '3',
        // Preview / watermark
        'preview_watermark' => 'PATADOCS PREVIEW', 'preview_watermark2' => 'PREVIEW ONLY — NOT FOR DISTRIBUTION',
        'preview_opacity' => '35', 'preview_quality' => '60', 'preview_width' => '900', 'preview_enabled' => '1',
        // SEO
        'seo_default_title' => 'PATADOCS | Find the Document You Need',
        'seo_default_description' => "Kenya's document discovery, sharing and download hub. Search schemes of work, exams, CV templates, business plans and more. Preview, pay via M-Pesa and download instantly.",
        'seo_title_suffix' => ' | PATADOCS', 'canonical_urls' => '1', 'sitemap_enabled' => '1', 'og_image' => '', 'force_canonical_host' => '1', 'indexnow_enabled' => '1', 'reviews_auto_approve' => '0',
        // Payment Hub
        'hub_url' => 'https://payments.editoriaweb.co.ke', 'hub_client_id' => '', 'hub_client_secret' => '', 'hub_webhook_secret' => '',
        'hub_timeout' => '20', 'hub_token' => '', 'hub_token_expires' => '0',   // hub_token*: cached bearer token, managed by the code
        // Security / limits
        'session_timeout' => '60', 'max_login_attempts' => '5', 'lockout_minutes' => '15',
        'download_token_hours' => '48', 'free_token_minutes' => '30', 'free_token_max' => '3',
        'order_expiry_minutes' => '15', 'contrib_max_mb' => '10', 'contrib_per_hour' => '5',
        'contributions_enabled' => '1', 'requests_enabled' => '1', 'attribution_default' => '1',
        // Homepage
        'home_hero_title' => 'Find the Document You Need.',
        'home_hero_subtitle' => 'Search and access useful documents for Education, Business, Careers, Government, NGOs, Agriculture and more.',
        'home_popular_searches' => "Grade 7 Mathematics\nCV Template\nPoultry Business Plan\nCBO Constitution\nGrade 5 Schemes",
        'home_banner_on' => '0', 'home_banner_text' => '', 'home_banner_link' => '',
        'home_tab_size' => '8', 'home_show_categories' => '1', 'home_show_tabs' => '1', 'home_show_collections' => '1',
        'home_show_features' => '1', 'home_show_how' => '1', 'home_testimonials' => '',
        'home_cta_title' => "Can't Find What You're Looking For?",
        'home_cta_text' => 'Request any document and our team will source it for you within 24 hours.',
    ];
}

function settings_all(bool $reload = false): array
{
    static $s = null;
    if ($s === null || $reload) {
        $s = default_settings();
        try {
            foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) { $s[$r['setting_key']] = (string)$r['setting_value']; }
        } catch (Throwable $e) { /* table missing during install */ }
    }
    return $s;
}
function setting(string $key, $default = '') { $s = settings_all(); return array_key_exists($key, $s) ? $s[$key] : $default; }
function set_setting(string $key, string $value): void
{
    db_exec('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
    settings_all(true);
}

// ============================================================================
// FORMATTING
// ============================================================================

function money($amount): string
{
    $a = (float)$amount;
    return setting('currency_symbol', 'KSh') . ' ' . (floor($a) == $a ? number_format($a, 0) : number_format($a, 2));
}
function num($n): string { return number_format((int)$n); }
function pages_label($n): string { $n = (int)$n; return $n . ' page' . ($n === 1 ? '' : 's'); }
function fmt_size($bytes): string
{
    $b = (float)$bytes;
    if ($b >= 1048576) { return round($b / 1048576, 1) . ' MB'; }
    if ($b >= 1024) { return round($b / 1024) . ' KB'; }
    return (int)$b . ' B';
}
function fmt_date($d, bool $time = false): string
{
    if (!$d) { return '—'; }
    $t = is_numeric($d) ? (int)$d : strtotime((string)$d);
    return $t ? date($time ? 'd M Y, H:i' : 'd M Y', $t) : '—';
}
function time_ago($d): string
{
    $t = is_numeric($d) ? (int)$d : strtotime((string)$d);
    if (!$t) { return '—'; }
    $s = max(0, time() - $t);
    if ($s < 60) { return 'just now'; }
    if ($s < 3600) { return floor($s / 60) . ' min ago'; }
    if ($s < 86400) { return floor($s / 3600) . ' hr ago'; }
    if ($s < 86400 * 30) { return floor($s / 86400) . ' day' . (floor($s / 86400) > 1 ? 's' : '') . ' ago'; }
    return fmt_date($t);
}
function excerpt(string $text, int $len = 160): string
{
    $t = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    return mb_strlen($t) > $len ? rtrim(mb_substr($t, 0, $len - 1), " ,.;:-") . '…' : $t;
}
function trend_html($cur, $prev, string $suffix = ' vs last month'): string
{
    $cur = (float)$cur; $prev = (float)$prev;
    if ($prev <= 0) { return $cur > 0 ? '<div class="stat-trend">▲ new</div>' : '<div class="stat-trend" style="color:var(--text-secondary)">—</div>'; }
    $pct = round(($cur - $prev) / $prev * 100);
    return '<div class="stat-trend' . ($pct < 0 ? ' down' : '') . '">' . ($pct < 0 ? '▼ ' : '▲ ') . abs($pct) . '%' . e($suffix) . '</div>';
}

// ---- Slugs -------------------------------------------------------------------
function slugify(string $text, int $max = 90): string
{
    $s = mb_strtolower(trim($text), 'UTF-8');
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false && $t !== '') { $s = $t; } }
    $s = preg_replace('/[^a-z0-9]+/', '-', strtolower($s));
    $s = trim($s, '-');
    if (strlen($s) > $max) { $s = rtrim(substr($s, 0, $max), '-'); if (($p = strrpos($s, '-')) > $max * 0.6) { $s = substr($s, 0, $p); } }
    return $s !== '' ? $s : 'item';
}

/** Returns $slug, or $slug-2, $slug-3... until unique in $table.$col. Table/col are code constants, never user input. */
function unique_slug(string $table, string $col, string $slug, int $ignoreId = 0): string
{
    $base = $slug; $i = 1;
    while ((int)db_val("SELECT COUNT(*) FROM `$table` WHERE `$col` = ? AND id <> ?", [$slug, $ignoreId]) > 0) {
        $i++; $slug = substr($base, 0, 180) . '-' . $i;
    }
    return $slug;
}

/** Top-level names that categories may not use (they are real pages/folders). */
function reserved_slugs(): array
{
    return ['admin', 'ajax', 'assets', 'includes', 'uploads', 'private_documents', 'search', 'contribute', 'about', 'contact',
        'download', 'payment', 'payment-success', 'collection', 'collections', 'recover', 'saved', 'popular', 'categories',
        'request-document', 'sitemap', 'sitemap.xml', 'robots.txt', 'install', 'index', 'router', 'document', 'category', 'tag', 'error'];
}

// ---- Phone numbers (Kenya) -------------------------------------------------------
/** Normalises 07XX / 01XX / +2547XX / 2547XX to 2547XXXXXXXX. Returns '' when invalid. */
function normalize_phone(string $p): string
{
    $p = preg_replace('/[^\d+]/', '', $p);
    $p = ltrim($p, '+');
    if (preg_match('/^0([17]\d{8})$/', $p, $m)) { return '254' . $m[1]; }
    if (preg_match('/^(254[17]\d{8})$/', $p, $m)) { return $m[1]; }
    if (preg_match('/^([17]\d{8})$/', $p, $m)) { return '254' . $m[1]; }
    return '';
}

// ---- Mail (best effort; PHP mail()) ----------------------------------------------
function send_mail(string $to, string $subject, string $body, string $replyTo = ''): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }
    $host = preg_replace('/^www\./', '', (string)(parse_url(base_url(), PHP_URL_HOST) ?: 'localhost'));
    $from = filter_var(setting('site_email'), FILTER_VALIDATE_EMAIL) ? setting('site_email') : 'noreply@' . $host;
    $name = preg_replace('/[\r\n"<>]+/', '', setting('site_name', 'PATADOCS'));
    $headers = ['From: "' . $name . '" <' . $from . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8'];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) { $headers[] = 'Reply-To: ' . $replyTo; }
    $subject = '=?UTF-8?B?' . base64_encode(preg_replace('/[\r\n]+/', ' ', $subject)) . '?=';
    return (bool)@mail($to, $subject, $body, implode("\r\n", $headers));
}

// ============================================================================
// SIMPLE FILE CACHE (public counters / lists). Flushed automatically after any admin action.
// ============================================================================

function cache_dir(): string { return rtrim(UPLOAD_DIR, '/\\') . '/temporary/cache'; }
/** Returns the cached value for $key or computes it with $fn and stores it for $ttl seconds. */
function cache_remember(string $key, int $ttl, callable $fn)
{
    $file = cache_dir() . '/' . preg_replace('/[^a-z0-9_\-]/i', '_', $key) . '.json';
    if (is_file($file) && time() - (int)filemtime($file) < $ttl) {
        $d = json_decode((string)@file_get_contents($file), true);
        if (is_array($d) && array_key_exists('v', $d)) { return $d['v']; }
    }
    $v = $fn();
    if (is_dir(cache_dir()) || @mkdir(cache_dir(), 0755, true)) { @file_put_contents($file, json_encode(['v' => $v]), LOCK_EX); }
    return $v;
}
function cache_flush(): void
{
    foreach (glob(cache_dir() . '/*.json') ?: [] as $f) { @unlink($f); }
}

// ============================================================================
// PAGINATION
// ============================================================================

function paginate(int $total, int $page, int $per): array
{
    $pages = max(1, (int)ceil($total / max(1, $per)));
    $page = min(max(1, $page), $pages);
    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => ($page - 1) * $per];
}

/** Pager markup identical to the design's .gv-pager. $base is a URL, $params extra query args. */
function pager_html(array $p, string $base, array $params = [], string $key = 'page'): string
{
    if ($p['pages'] <= 1) { return ''; }
    $link = function ($n, $label, $cls = '') use ($base, $params, $key) {
        $q = $params; if ($n > 1) { $q[$key] = $n; } else { unset($q[$key]); }
        $u = $base . (strpos($base, '?') === false ? '?' : '&') . http_build_query($q);
        $u = rtrim($u, '?&');
        return '<a href="' . e($u) . '" data-page="' . (int)$n . '">' . $label . '</a>';
    };
    $cur = $p['page']; $last = $p['pages'];
    $h = '<div class="gv-pager">';
    if ($cur > 1) { $h .= $link(1, '&lt;&lt; First') . $link($cur - 1, '&lt; Prev'); }
    for ($i = max(1, $cur - 2); $i <= min($last, $cur + 2); $i++) {
        $h .= $i === $cur ? '<span class="current">' . $i . '</span>' : $link($i, (string)$i);
    }
    if ($cur < $last) { $h .= $link($cur + 1, 'Next &gt;') . $link($last, 'Last &gt;&gt;'); }
    return $h . '</div>';
}

// ============================================================================
// CATEGORIES (unlimited depth)
// ============================================================================

/** All categories keyed by id (cached per request). */
function cats_all(bool $reload = false): array
{
    static $c = null;
    if ($c === null || $reload) {
        $c = [];
        foreach (db_all('SELECT * FROM categories ORDER BY sort_order, name') as $r) { $c[(int)$r['id']] = $r; }
    }
    return $c;
}
function cat_get(int $id): ?array { return cats_all()[$id] ?? null; }
function cat_by_path(string $path): ?array
{
    foreach (cats_all() as $c) { if ($c['path'] === $path) { return $c; } }
    return null;
}
function cat_children(?int $parentId, bool $onlyActive = true): array
{
    $out = [];
    foreach (cats_all() as $c) {
        if (($c['parent_id'] === null ? null : (int)$c['parent_id']) === $parentId && (!$onlyActive || $c['status'] === 'active')) { $out[] = $c; }
    }
    return $out;
}
/** Nested tree: each node has ['children' => [...]]. */
function cat_tree(?int $parentId = null, bool $onlyActive = false): array
{
    $out = [];
    foreach (cat_children($parentId, $onlyActive) as $c) { $c['children'] = cat_tree((int)$c['id'], $onlyActive); $out[] = $c; }
    return $out;
}
/** Root → leaf list of category rows. */
function cat_breadcrumb(?int $id): array
{
    $out = []; $all = cats_all(); $guard = 0;
    while ($id && isset($all[$id]) && $guard++ < 30) { array_unshift($out, $all[$id]); $id = $all[$id]['parent_id'] === null ? 0 : (int)$all[$id]['parent_id']; }
    return $out;
}
function cat_chain_ids(?int $id): array { return array_map(function ($c) { return (int)$c['id']; }, cat_breadcrumb($id)); }
/** The category itself + every descendant id. */
function cat_descendant_ids(int $id): array
{
    $all = cats_all(); if (!isset($all[$id])) { return []; }
    $ids = [$id]; $queue = [$id];
    while ($queue) {
        $cur = array_shift($queue);
        foreach ($all as $c) { if ((int)$c['parent_id'] === $cur && !in_array((int)$c['id'], $ids, true)) { $ids[] = (int)$c['id']; $queue[] = (int)$c['id']; } }
    }
    return $ids;
}
/** Published-document counts per category, including sub-categories. */
function cat_counts(): array
{
    static $counts = null;
    if ($counts !== null) { return $counts; }
    $counts = []; $all = cats_all();
    foreach (db_all("SELECT category_id, COUNT(*) n FROM documents WHERE status='published' AND category_id IS NOT NULL GROUP BY category_id") as $r) {
        $id = (int)$r['category_id']; $guard = 0;
        while ($id && isset($all[$id]) && $guard++ < 30) { $counts[$id] = ($counts[$id] ?? 0) + (int)$r['n']; $id = $all[$id]['parent_id'] === null ? 0 : (int)$all[$id]['parent_id']; }
    }
    return $counts;
}
function cat_url(array $c): string
{
    return setting('clean_urls', '1') === '1' ? url($c['path'] . '/') : url('category.php?path=' . rawurlencode($c['path']));
}
/** <option> list of the whole tree, indented by depth. $except removes a category and its descendants. */
function cat_options_html(int $selected = 0, int $except = 0, bool $onlyActive = false, string $placeholder = ''): string
{
    $h = $placeholder !== '' ? '<option value="0">' . e($placeholder) . '</option>' : '';
    $skip = $except ? cat_descendant_ids($except) : [];
    $walk = function ($nodes) use (&$walk, &$h, $selected, $skip, $onlyActive) {
        foreach ($nodes as $n) {
            if (in_array((int)$n['id'], $skip, true)) { continue; }
            if ($onlyActive && $n['status'] !== 'active') { continue; }
            $h .= '<option value="' . (int)$n['id'] . '"' . ((int)$n['id'] === $selected ? ' selected' : '') . '>'
                . str_repeat('— ', (int)$n['depth']) . e($n['name']) . '</option>';
            $walk($n['children']);
        }
    };
    $walk(cat_tree(null, $onlyActive));
    return $h;
}
/** Recomputes path/depth for a category and all descendants (after rename or move). */
function cat_rebuild(int $id): void
{
    $all = cats_all(true);
    if (!isset($all[$id])) { return; }
    $cat = $all[$id];
    $parent = $cat['parent_id'] !== null ? ($all[(int)$cat['parent_id']] ?? null) : null;
    db_exec('UPDATE categories SET path = ?, depth = ? WHERE id = ?', [($parent ? $parent['path'] . '/' : '') . $cat['slug'], $parent ? (int)$parent['depth'] + 1 : 0, $id]);
    foreach ($all as $ch) { if ((int)$ch['parent_id'] === $id) { cat_rebuild((int)$ch['id']); } }
}
