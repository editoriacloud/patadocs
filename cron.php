<?php
/**
 * PATADOCS — scheduled jobs runner (see includes/jobs.php).
 *   cPanel → Cron Jobs, every 5 minutes:   php /home/USER/public_html/patadocs/cron.php
 *   or by URL (if the host only allows wget/curl):   https://YOUR-DOMAIN/cron.php?key=CRON_KEY
 * The key is shown in Admin → Automation. Output is a short plain-text report.
 */
define('PD_NO_SESSION', true);
if (PHP_SAPI !== 'cli') { define('PD_AJAX', true); }
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/jobs.php';

if (PHP_SAPI !== 'cli') {
    if (!hash_equals(cron_key(), (string)get_str('key', 64))) { http_response_code(403); exit('Forbidden'); }
    @ignore_user_abort(true);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
}
$only = PHP_SAPI === 'cli' ? ($argv[1] ?? null) : (get_str('job', 40) ?: null);
if ($only !== null && !isset(jobs_registry()[$only])) { echo "Unknown job: $only\n"; exit(1); }
$trigger = PHP_SAPI === 'cli' || get_str('via', 5) !== 'web' ? 'cron' : 'web';
$res = jobs_run($trigger, $only, PHP_SAPI === 'cli' ? 240 : 50);
if (!$res) { echo "Nothing due.\n"; }
$failed = 0;
foreach ($res as $name => $r) { echo str_pad($name, 10) . ($r['ok'] ? ' ok   ' : ' FAIL ') . ($r['ms'] ?? 0) . 'ms  ' . $r['message'] . "\n"; $failed += $r['ok'] ? 0 : 1; }
if ($failed && PHP_SAPI === 'cli') { exit(2); }
