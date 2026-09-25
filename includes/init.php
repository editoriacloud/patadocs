<?php
/**
 * PATADOCS bootstrap — every public, admin and AJAX script starts with:
 *     require __DIR__ . '/includes/init.php';
 * (AJAX scripts define('PD_AJAX', true) first so errors are returned as JSON.)
 */
if (defined('PD_INIT')) { return; }
define('PD_INIT', true);
define('ROOT_DIR', dirname(__DIR__));

if (!is_file(__DIR__ . '/config.php')) {
    // Not installed yet → send the visitor to the installer.
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (preg_match('#/(admin|ajax)$#', $dir)) { $dir = dirname($dir); }
    header('Location: ' . rtrim($dir, '/') . '/install.php');
    exit;
}

require_once __DIR__ . '/config.php';
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Africa/Nairobi');
error_reporting(E_ALL);
if (APP_ENV === 'development') { ini_set('display_errors', '1'); } else { ini_set('display_errors', '0'); ini_set('log_errors', '1'); }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/view.php';

const PD_SCHEMA_VERSION = 5;
if ((int)setting('schema_version', 1) < PD_SCHEMA_VERSION) { require_once __DIR__ . '/migrate.php'; db_migrate(); }

security_boot();
