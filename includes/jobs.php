<?php
/**
 * PATADOCS — automation engine.
 *
 * Every job below runs on its own schedule. Two ways to drive it (both are safe together):
 *   1. A real cron job (recommended):  php /path/to/patadocs/cron.php          (every 5 minutes)
 *      or, if the host only allows URLs:  https://YOUR-DOMAIN/cron.php?key=CRON_KEY
 *   2. Built-in "web cron": when no cron has run for a while, ordinary page views run the due jobs
 *      after the page has been sent to the visitor (Admin → Automation → "Run jobs from site traffic").
 * A MySQL named lock guarantees only one runner at a time; every run is logged in job_runs.
 */

/** Job registry: name => [title, what it does, interval in minutes, callable]. */
function jobs_registry(): array
{
    require_once __DIR__ . '/automation.php';
    return [
        'payments' => ['Payment reconciliation', 'Re-checks unpaid orders with the Payment Hub (catches missed webhooks) and expires abandoned ones.', 5, 'job_payments'],
        'content'  => ['Document text extraction', 'Reads the text inside PDF / Word files (and images when OCR is installed) for full-text search and page content.', 15, 'job_content'],
        'seo'      => ['Auto-SEO', 'Fills missing meta descriptions and keywords from the document itself, and rebuilds the search index.', 60, 'job_seo'],
        'previews' => ['Preview generation', 'Builds missing watermarked previews for published documents.', 60, 'job_previews'],
        'reviews'  => ['Review requests', 'Emails buyers two days after purchase asking them to rate their documents.', 60, 'job_review_requests'],
        'vocab'    => ['Search vocabulary', 'Learns the words used in titles, tags and categories so search can fix spelling mistakes ("Did you mean…").', 720, 'job_vocab'],
        'digest'   => ['Daily report email', 'Sends the admin yesterday\'s sales, top searches, searches with no results (demand you can fill) and pending work.', 1440, 'job_digest'],
        'cleanup'  => ['Housekeeping', 'Removes expired rate limits, old logs and temporary files; expires old download links.', 1440, 'job_cleanup'],
    ];
}

function jobs_state(): array
{
    $s = json_decode((string)setting('jobs_state'), true);
    return is_array($s) ? $s : [];
}

function job_enabled(string $name): bool { return setting('job_' . $name . '_on', '1') === '1'; }

/** Is the job due? (never ran, or its interval has passed) */
function job_due(string $name, array $state): bool
{
    $reg = jobs_registry();
    if (!isset($reg[$name]) || !job_enabled($name)) { return false; }
    return time() - (int)($state[$name]['last'] ?? 0) >= $reg[$name][2] * 60;
}

/**
 * Runs due jobs (or the $only job, even if not due) within a time budget. Returns [name => result] of what ran.
 * $trigger: cron | web | admin.
 */
function jobs_run(string $trigger, ?string $only = null, int $budget = 25): array
{
    $lock = (int)db_val("SELECT GET_LOCK('patadocs_jobs', 0)");
    if ($lock !== 1) { return ['_locked' => ['ok' => false, 'message' => 'Another run is in progress.']]; }
    $t0 = microtime(true); $done = [];
    @set_time_limit(max(60, $budget + 30));
    @ignore_user_abort(true);
    try {
        set_setting('jobs_last_tick', (string)time());
        if ($trigger === 'cron') { set_setting('jobs_last_cron', (string)time()); }
        foreach (jobs_registry() as $name => [$title, , , $fn]) {
            $state = jobs_state();
            if ($only !== null ? $name !== $only : !job_due($name, $state)) { continue; }
            if ($only === null && microtime(true) - $t0 > $budget) { break; }            // leave the rest for the next tick
            $j0 = microtime(true);
            try { $msg = (string)$fn(); $ok = true; }
            catch (Throwable $e) { $msg = $e->getMessage(); $ok = false; log_error('Job ' . $name . ' failed: ' . $msg); }
            $ms = (int)round((microtime(true) - $j0) * 1000);
            $state = jobs_state();
            $state[$name] = ['last' => time(), 'ok' => $ok, 'msg' => mb_substr($msg, 0, 300), 'ms' => $ms];
            set_setting('jobs_state', json_encode($state, JSON_UNESCAPED_UNICODE));
            db_insert('INSERT INTO job_runs (job, trigger_by, status, message, duration_ms) VALUES (?, ?, ?, ?, ?)', [$name, $trigger, $ok ? 'ok' : 'error', mb_substr($msg, 0, 500), $ms]);
            $done[$name] = ['ok' => $ok, 'message' => $msg, 'ms' => $ms];
        }
    } finally {
        db_val("SELECT RELEASE_LOCK('patadocs_jobs')");
    }
    return $done;
}

/**
 * Web cron: called on public page views. If a job is due and no real cron ran recently, the jobs run after the
 * response has been sent (PHP-FPM), so visitors never wait. At most one attempt per minute site-wide.
 */
function jobs_web_tick(): void
{
    if (PHP_SAPI === 'cli' || setting('jobs_webcron', '1') !== '1' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { return; }
    if (time() - (int)setting('jobs_last_cron', 0) < 900) { return; }                  // a real cron is doing the work
    if (time() - (int)setting('jobs_last_tick', 0) < 60) { return; }
    $state = jobs_state(); $due = false;
    foreach (array_keys(jobs_registry()) as $n) { if (job_due($n, $state)) { $due = true; break; } }
    if (!$due) { return; }
    set_setting('jobs_last_tick', (string)time());                                   // claim this minute before the page is sent
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
        else { jobs_spawn(); return; }                                                 // mod_php: hand the work to a background request
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }      // release the visitor's session lock while jobs run
        try { jobs_run('web', null, 20); } catch (Throwable $e) { log_error('Web cron: ' . $e->getMessage()); }
    });
}

function cron_key(): string
{
    $k = (string)setting('cron_key');
    if (!preg_match('/^[a-f0-9]{32}$/', $k)) { $k = bin2hex(random_bytes(16)); set_setting('cron_key', $k); }
    return $k;
}

/** Fire-and-forget request to cron.php (used when this PHP cannot finish the response early). Costs the visitor ≤ 1 s once a minute. */
function jobs_spawn(): void
{
    if (!function_exists('curl_init')) { return; }
    $ch = curl_init(url('cron.php?key=' . cron_key() . '&via=web'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 800, CURLOPT_NOSIGNAL => true, CURLOPT_CONNECTTIMEOUT_MS => 500]);
    @curl_exec($ch); curl_close($ch);
}
