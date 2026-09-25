<?php
/** Admin: automation engine — job status, run now, switches, run log and cron setup. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/jobs.php';
require_once __DIR__ . '/../includes/preview.php';
$admin = require_admin('settings.manage');
$canChange = admin_can('settings.secure');
$reg = jobs_registry();

$fields = [
    ['jobs_webcron', 'Run jobs from site traffic (no cron needed)', 'bool', 'Due jobs run after a visitor\'s page has been sent. Keep it on even with a real cron — it only acts when cron has been silent for 15 minutes.'],
    ['auto_seo', 'Auto-SEO: fill empty meta descriptions and keywords', 'bool', 'Never overwrites what you typed.'],
    ['content_excerpt_words', 'Words from inside the document shown on its page', 'number', 'Unique text for Google (0 = off). The first page is already visible in the preview.', [0, 200]],
    ['reviews_ask', 'Email buyers asking for a review', 'bool', 'Only buyers who gave an email address.'],
    ['reviews_ask_days', 'Days after purchase to ask', 'number', '', [1, 30]],
    ['digest_on', 'Send me a daily report email', 'bool', ''],
    ['digest_email', 'Daily report goes to', 'email', 'Empty = the contact email in Settings → General.'],
];
if (is_post()) {
    csrf_check();
    if (!$canChange) { abort_page(403, 'Access denied', 'Only Super Admins can change automation settings.'); }
    foreach ($fields as $f) {
        [$key, , $type] = $f; $raw = $_POST[$key] ?? '';
        if ($type === 'bool') { set_setting($key, !empty($_POST[$key]) ? '1' : '0'); }
        elseif ($type === 'number') { set_setting($key, (string)max($f[4][0], min($f[4][1], (int)$raw))); }
        elseif ($type === 'email') { $v = trim((string)$raw); if ($v === '' || filter_var($v, FILTER_VALIDATE_EMAIL)) { set_setting($key, $v); } else { flash('error', 'The report email address is invalid.'); } }
    }
    foreach (array_keys($reg) as $n) { set_setting('job_' . $n . '_on', !empty($_POST['job'][$n]) ? '1' : '0'); }
    log_admin('automation_saved', 'settings');
    flash('success', 'Automation settings saved.');
    redirect(url('admin/automation.php'));
}

$state = jobs_state();
$log = db_all('SELECT * FROM job_runs ORDER BY id DESC LIMIT 40');
$lastCron = (int)setting('jobs_last_cron', 0);
$root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$ago = function ($ts) { return $ts ? time_ago(date('Y-m-d H:i:s', (int)$ts)) : 'never'; };
$adm = ['title' => 'Automation', 'active' => 'automation'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">AUTOMATION ENGINE</div><div class="panel-body">
    <div class="result-area" style="margin-bottom:16px;">
        <div class="result-row"><strong>Scheduler:</strong>
            <?php if ($lastCron && time() - $lastCron < 1800) { echo '<span class="badge b-green">server cron active</span> last run ' . e($ago($lastCron)); }
            elseif (setting('jobs_webcron', '1') === '1') { echo '<span class="badge b-orange">running from site traffic</span> — works, but a server cron is more reliable (see below). Last tick: ' . e($ago(setting('jobs_last_tick', 0))); }
            else { echo '<span class="badge b-red">not running</span> — turn on “Run jobs from site traffic” or add the cron job below.'; } ?></div>
    </div>

    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Job</th><th>Every</th><th>Last run</th><th>Result</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($reg as $name => [$title, $desc, $every]) { $st = $state[$name] ?? null;
        $everyTxt = $every >= 1440 ? ($every / 1440) . ' day' . ($every > 1440 ? 's' : '') : ($every >= 60 ? ($every / 60) . ' h' : $every . ' min');
        echo '<tr><td class="doc-title">' . e($title) . (job_enabled($name) ? '' : ' <span class="badge b-gray">off</span>') . '<span class="sub">' . e($desc) . '</span></td>'
            . '<td class="nowrap">' . e($everyTxt) . '</td><td class="nowrap">' . e($ago($st['last'] ?? 0)) . ($st ? '<span class="sub">' . (int)$st['ms'] . ' ms</span>' : '') . '</td>'
            . '<td>' . ($st ? ($st['ok'] ? '<span class="badge b-green">ok</span> ' : '<span class="badge b-red">error</span> ') . e($st['msg']) : '—') . '</td>'
            . '<td class="actions">' . ($canChange ? '<button class="btn-classic btn-sm primary" data-act="job_run" data-value="' . e($name) . '" data-reload="1">▶ RUN NOW</button>' : '') . '</td></tr>'; } ?>
    </tbody></table></div>

    <?php if ($canChange) { ?>
    <div class="section-title">SWITCHES</div>
    <form method="post" class="pd-form"><?= csrf_field() ?>
        <div class="form-grid">
        <?php foreach ($reg as $name => [$title]) { echo '<div class="frow"><span class="flabel">' . e($title) . '</span><label class="chk"><input type="checkbox" name="job[' . e($name) . ']" value="1"' . (job_enabled($name) ? ' checked' : '') . '><span>Enabled</span></label></div>'; } ?>
        <?php foreach ($fields as $f) { [$key, $label, $type] = $f; $help = $f[3] ?? ''; $val = setting($key);
            echo '<div class="frow">';
            if ($type === 'bool') { echo '<span class="flabel">' . e($label) . '</span><label class="chk"><input type="checkbox" name="' . e($key) . '" value="1"' . ($val === '1' ? ' checked' : '') . '><span>Enabled</span></label>'; }
            else { echo '<label for="a_' . e($key) . '">' . e($label) . '</label><input type="' . ($type === 'number' ? 'number' : 'email') . '" id="a_' . e($key) . '" name="' . e($key) . '" value="' . e($val) . '"' . ($type === 'number' ? ' min="' . $f[4][0] . '" max="' . $f[4][1] . '"' : '') . '>'; }
            if ($help) { echo '<span class="help">' . e($help) . '</span>'; }
            echo '</div>'; } ?>
        </div>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE</button></div>
    </form>

    <div class="section-title">SERVER CRON (RECOMMENDED)</div>
    <div class="result-area">
        <div class="result-row">cPanel → <strong>Cron Jobs</strong> → “Once per five minutes” (<code>*/5 * * * *</code>) → command:</div>
        <div class="result-row"><code>php <?= e($root) ?>/cron.php &gt;/dev/null 2&gt;&amp;1</code> <button type="button" class="btn-classic btn-sm" data-copy="php <?= e($root) ?>/cron.php >/dev/null 2>&1">Copy</button></div>
        <div class="result-row">If your host only allows a URL: <code>wget -q -O /dev/null "<?= e(url('cron.php?key=' . cron_key())) ?>"</code> <button type="button" class="btn-classic btn-sm" data-copy="wget -q -O /dev/null &quot;<?= e(url('cron.php?key=' . cron_key())) ?>&quot;">Copy</button></div>
        <div class="result-row help">Keep the key secret. Run one job by hand: <code>php cron.php seo</code></div>
    </div>
    <div class="section-title">DOCUMENT TEXT</div>
    <div class="result-area">
        <?php $cs = []; foreach (db_all('SELECT content_status s, COUNT(*) n FROM documents GROUP BY content_status') as $r) { $cs[$r['s']] = (int)$r['n']; } ?>
        <div class="result-row">Read: <strong><?= num($cs['ok'] ?? 0) ?></strong> · waiting: <?= num($cs['none'] ?? 0) ?> · no text found (scans): <?= num($cs['empty'] ?? 0) ?> · format not readable here: <?= num($cs['unsupported'] ?? 0) ?> · failed: <?= num($cs['failed'] ?? 0) ?></div>
        <div class="result-row help">Tools on this server: PDF <?= preview_which('pdftotext') !== '' ? '<span class="badge b-green">pdftotext</span>' : '<span class="badge b-orange">missing — ask your host for poppler-utils</span>' ?> · Word (.docx) <?= class_exists('ZipArchive') ? '<span class="badge b-green">built in</span>' : '<span class="badge b-orange">needs PHP zip</span>' ?> · old .doc <?= preview_which('antiword') !== '' ? '<span class="badge b-green">antiword</span>' : '<span class="badge b-gray">antiword not installed</span>' ?> · scanned images <?= preview_which('tesseract') !== '' ? '<span class="badge b-green">tesseract OCR</span>' : '<span class="badge b-gray">tesseract not installed</span>' ?></div>
        <div class="result-row"><button type="button" class="btn-classic btn-sm warning" data-act="content_reread" data-confirm="Read the text of every document again? (use after installing a new tool)">↻ RE-READ ALL DOCUMENTS</button></div>
    </div>
    <?php } ?>

    <div class="section-title">RECENT RUNS</div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>When</th><th>Job</th><th>By</th><th>Status</th><th>Result</th><th>Time</th></tr></thead><tbody>
    <?php foreach ($log as $r) { echo '<tr><td class="nowrap">' . e(fmt_date($r['created_at'], true)) . '</td><td>' . e($reg[$r['job']][0] ?? $r['job']) . '</td><td>' . e($r['trigger_by']) . '</td><td>' . ($r['status'] === 'ok' ? '<span class="badge b-green">ok</span>' : '<span class="badge b-red">error</span>') . '</td><td>' . e($r['message']) . '</td><td class="nowrap">' . (int)$r['duration_ms'] . ' ms</td></tr>'; }
    if (!$log) { echo '<tr><td colspan="6" class="empty-cell">No runs yet.</td></tr>'; } ?></tbody></table></div>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
