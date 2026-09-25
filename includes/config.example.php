<?php
/**
 * PATADOCS configuration.
 *
 * Copy this file to includes/config.php and edit the values
 * (the web installer, install.php, does this for you).
 *
 * Everything else (Payment Hub, watermark, SEO, limits, homepage...) is managed
 * from Admin → Settings and stored in the database.
 */

// ---- Database -------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'patadocs');
define('DB_USER', 'root');
define('DB_PASS', '');

// ---- Application ----------------------------------------------------------
define('APP_ENV', 'production');          // 'development' shows PHP errors on screen
define('BASE_URL', '');                   // e.g. 'https://patadocs.co.ke' (no trailing slash). Empty = auto-detect
define('APP_SECRET', 'CHANGE-ME-TO-A-LONG-RANDOM-STRING');   // used to hash IPs / sign values
define('APP_TIMEZONE', 'Africa/Nairobi');
define('TRUST_PROXY', false);             // true only if you are behind Cloudflare / a reverse proxy

// ---- Storage --------------------------------------------------------------
// ORIGINAL documents live here. This folder must NOT be reachable from the web.
// On cPanel, the safest place is ABOVE public_html, e.g. '/home/USERNAME/patadocs_private'
define('PRIVATE_DIR', dirname(__DIR__) . '/private_documents');
// Watermarked previews + public assets. Must be writable by PHP.
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
