<?php
/**
 * PATADOCS — the automated jobs (scheduled by includes/jobs.php). Each returns a one-line summary for the log.
 * Every job works in small batches so a run never takes long; whatever is left is picked up on the next run.
 */
require_once __DIR__ . '/preview.php';
require_once __DIR__ . '/payment_hub.php';
require_once __DIR__ . '/indexnow.php';
require_once __DIR__ . '/reviews.php';

// ============================================================================
// PAYMENTS
// ============================================================================

function job_payments(): string
{
    if (!hub_configured()) { return 'Payment Hub not configured — skipped.'; }
    // Unpaid orders from the last 2 days (+ orders that expired in the last 24 h: a PayBill payment can arrive late)
    $rows = db_all("SELECT * FROM orders WHERE hub_reference IS NOT NULL AND created_at < (NOW() - INTERVAL 1 MINUTE)
                    AND ((status = 'pending' AND created_at > (NOW() - INTERVAL 2 DAY)) OR (status IN ('expired', 'failed') AND expires_at > (NOW() - INTERVAL 1 DAY)))
                    ORDER BY id DESC LIMIT 30");
    $paid = 0; $expired = 0;
    foreach ($rows as $o) {
        $n = order_refresh($o, true);
        if ($n['status'] === 'paid') { $paid++; } elseif ($n['status'] === 'expired' && $o['status'] === 'pending') { $expired++; }
    }
    // Orders whose invoice was never created can't be paid: close them once they expire
    $expired += db_exec("UPDATE orders SET status = 'expired' WHERE status = 'pending' AND hub_reference IS NULL AND expires_at < NOW()");
    return 'Checked ' . count($rows) . ' order(s): ' . $paid . ' confirmed paid, ' . $expired . ' expired.';
}

// ============================================================================
// DOCUMENT TEXT EXTRACTION
// ============================================================================

/** Runs a CLI tool (with a timeout when available) and returns its stdout, or null. */
function auto_cmd_output(string $cmd, int $timeout = 60): ?string
{
    if (!preview_exec_ok()) { return null; }
    $to = preview_which('timeout');
    $out = []; $rc = 1;
    @exec(($to !== '' ? escapeshellarg($to) . ' ' . $timeout . ' ' : '') . $cmd . ' 2>/dev/null', $out, $rc);
    return $rc === 0 ? implode("\n", $out) : null;
}

/** Text inside a document file: ['status' => ok|empty|unsupported|failed, 'text' => string]. */
function doc_extract_text(array $d): array
{
    $path = doc_private_path($d);
    if (empty($d['file_name']) || !is_file($path)) { return ['status' => 'failed', 'text' => '']; }
    $ext = strtolower((string)$d['file_ext']); $text = null;
    if ($ext === 'pdf' && ($bin = preview_which('pdftotext')) !== '') {
        $text = auto_cmd_output(escapeshellarg($bin) . ' -l 10 -enc UTF-8 -q ' . escapeshellarg($path) . ' -');
    } elseif ($ext === 'docx' && class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path) === true) {
            $xml = (string)$z->getFromName('word/document.xml'); $z->close();
            $xml = preg_replace('#</w:p>|<w:br[^>]*/>|<w:tab[^>]*/>#', "\n", $xml);
            $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
    } elseif ($ext === 'doc' && ($bin = preview_which('antiword')) !== '') {
        $text = auto_cmd_output(escapeshellarg($bin) . ' ' . escapeshellarg($path));
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && ($bin = preview_which('tesseract')) !== '') {
        $text = auto_cmd_output(escapeshellarg($bin) . ' ' . escapeshellarg($path) . ' - -l eng', 120);   // OCR
    } else {
        return ['status' => 'unsupported', 'text' => ''];
    }
    if ($text === null) { return ['status' => 'failed', 'text' => '']; }
    $text = preg_replace('/[^\P{C}\n]+/u', ' ', (string)$text);                 // control characters
    $text = trim(preg_replace(['/[ \t\x{00A0}]+/u', '/\n\s*\n+/u'], [' ', "\n"], $text));
    $text = mb_substr($text, 0, 20000);
    return ['status' => mb_strlen(preg_replace('/\s+/u', '', $text)) >= 40 ? 'ok' : 'empty', 'text' => $text];
}

function job_content(): string
{
    $rows = db_all("SELECT * FROM documents WHERE content_status = 'none' AND file_name IS NOT NULL ORDER BY (status = 'published') DESC, id DESC LIMIT 5");
    $ok = 0; $other = [];
    foreach ($rows as $d) {
        $r = doc_extract_text($d);
        db_exec('UPDATE documents SET content_text = ?, content_status = ?, updated_at = updated_at WHERE id = ?', [$r['text'] !== '' ? $r['text'] : null, $r['status'], $d['id']]);
        doc_rebuild_search((int)$d['id']);
        if ($r['status'] === 'ok') { $ok++; } else { $other[$r['status']] = ($other[$r['status']] ?? 0) + 1; }
    }
    $left = (int)db_val("SELECT COUNT(*) FROM documents WHERE content_status = 'none' AND file_name IS NOT NULL");
    $o = []; foreach ($other as $k => $n) { $o[] = $n . ' ' . $k; }
    return 'Read ' . $ok . ' document(s)' . ($o ? ' (' . implode(', ', $o) . ')' : '') . '; ' . $left . ' waiting.';
}

// ============================================================================
// AUTO-SEO
// ============================================================================

function job_seo(): string
{
    if (setting('auto_seo', '1') !== '1') { return 'Auto-SEO is off.'; }
    // Only fills EMPTY fields — anything an admin typed is never overwritten.
    $rows = db_all("SELECT * FROM documents WHERE status = 'published' AND (
                        ((meta_description IS NULL OR meta_description = '') AND CHAR_LENGTH(COALESCE(description, '')) < 120)
                        OR seo_keywords IS NULL OR seo_keywords = '')
                    ORDER BY id DESC LIMIT 50");
    $changed = [];
    foreach ($rows as $d) {
        $pairs = [];
        foreach (meta_display((int)$d['id']) as $m) { $pairs[$m['label']] = $m['value']; }
        $s = seo_suggest((string)$d['title'], $d['category_id'] ? (int)$d['category_id'] : null, $pairs, (string)$d['doc_type'], (int)$d['is_free'] === 1);
        $set = []; $vals = [];
        if (trim((string)$d['meta_description']) === '' && mb_strlen(trim((string)$d['description'])) < 120) {
            $ex = doc_content_excerpt($d, 45);
            $t = trim((string)$d['title']);
            if ($ex !== '' && mb_strlen($ex) > 40) {
                // the file usually opens with its own title: then its first lines already read well on their own
                $ex = excerpt(mb_stripos($ex, $t) === 0 ? $ex : $t . ' — ' . $ex, 158);
            }
            $set[] = 'meta_description = ?';
            $vals[] = $ex !== '' && mb_strlen($ex) > 40 ? $ex : $s['meta_description'];
        }
        if (trim((string)$d['seo_keywords']) === '') { $set[] = 'seo_keywords = ?'; $vals[] = $s['keywords']; }
        if (!$set) { continue; }
        $vals[] = $d['id'];
        db_exec('UPDATE documents SET ' . implode(', ', $set) . ' WHERE id = ?', $vals);
        doc_rebuild_search((int)$d['id']);
        $changed[] = doc_url($d);
    }
    if ($changed) { indexnow_ping($changed); }
    return count($changed) . ' document(s) given meta descriptions / keywords.';
}

// ============================================================================
// PREVIEWS
// ============================================================================

function job_previews(): string
{
    if (setting('preview_enabled', '1') !== '1') { return 'Automatic previews are off.'; }
    $rows = db_all("SELECT id FROM documents WHERE status = 'published' AND preview_status = 'none' AND file_name IS NOT NULL ORDER BY id DESC LIMIT 3");
    $ok = 0;
    foreach ($rows as $r) {
        $res = preview_generate((int)$r['id']);
        if (!empty($res['ok'])) { $ok++; }
        // some failures (missing file, no GD, unwritable folder) return early without a status: mark them so the
        // same documents are not retried forever while newer ones wait behind them
        else { db_exec("UPDATE documents SET preview_status = 'failed' WHERE id = ? AND preview_status = 'none'", [$r['id']]); }
    }
    return 'Generated ' . $ok . ' of ' . count($rows) . ' missing preview(s).';
}

// ============================================================================
// REVIEW REQUESTS
// ============================================================================

function job_review_requests(): string
{
    $days = max(1, min(30, (int)setting('reviews_ask_days', 2)));
    if (setting('reviews_ask', '1') !== '1') { return 'Review requests are off.'; }
    $rows = db_all("SELECT * FROM orders WHERE status = 'paid' AND email IS NOT NULL AND email <> '' AND review_asked_at IS NULL
                    AND paid_at < (NOW() - INTERVAL $days DAY) AND paid_at > (NOW() - INTERVAL " . ($days + 14) . " DAY) ORDER BY id LIMIT 20");
    $sent = 0; $failed = 0;
    foreach ($rows as $o) {
        db_exec('UPDATE orders SET review_asked_at = NOW() WHERE id = ?', [$o['id']]);    // mark first: never email twice
        $docs = review_pending_docs($o);
        if (!$docs) { continue; }
        $link = url('payment-success.php?' . order_qs($o)) . '#rate';
        $body = "Hello" . ($o['customer_name'] ? ' ' . $o['customer_name'] : '') . ",\n\nThank you for your purchase on " . setting('site_name', 'PATADOCS') . ".\n\n"
            . 'How did you find ' . (count($docs) === 1 ? '"' . $docs[0]['title'] . '"' : 'your documents') . "? A quick star rating helps other teachers, students and businesses choose the right document.\n\n"
            . "Rate it here (takes 10 seconds):\n" . $link . "\n\nInvoice: " . order_ref($o) . "\n\n" . setting('site_name', 'PATADOCS');
        if (send_mail((string)$o['email'], 'How was your document? ⭐', $body)) { $sent++; } else { $failed++; }
    }
    if ($failed && !$sent) { throw new RuntimeException($failed . ' review request email(s) could not be sent — check that this server can send mail (PHP mail()).'); }
    return 'Asked ' . $sent . ' buyer(s) for a review.' . ($failed ? ' ' . $failed . ' email(s) failed.' : '');
}

// ============================================================================
// SEARCH VOCABULARY ("Did you mean…")
// ============================================================================

function job_vocab(): string
{
    $freq = [];
    $add = function ($text, int $w = 1) use (&$freq) {
        foreach (preg_split('/[\s\/\-]+/u', search_norm((string)$text)) as $word) {
            $word = trim($word, " .'&");
            if (mb_strlen($word) >= 3 && mb_strlen($word) <= 60 && preg_match('/^\p{L}+$/u', $word)) { $freq[$word] = ($freq[$word] ?? 0) + $w; }
        }
    };
    foreach (db_all("SELECT title, doc_type FROM documents WHERE status = 'published'") as $r) { $add($r['title'], 3); $add($r['doc_type'], 2); }
    foreach (db_all('SELECT name FROM tags') as $r) { $add($r['name'], 2); }
    foreach (cats_all() as $c) { if ($c['status'] === 'active') { $add($c['name'], 4); } }
    foreach (db_all("SELECT title FROM collections WHERE status = 'published'") as $r) { $add($r['title'], 2); }
    foreach (db_all('SELECT term, canonical FROM search_synonyms') as $r) { $add($r['term'], 2); $add($r['canonical'], 2); }
    // Words from inside documents count only once they are common (avoids learning typos from the files)
    foreach (db_all("SELECT content_text FROM documents WHERE status = 'published' AND content_status = 'ok' ORDER BY id DESC LIMIT 2000") as $r) {
        $local = [];
        foreach (preg_split('/[\s\/\-]+/u', search_norm(mb_substr((string)$r['content_text'], 0, 4000))) as $w) { $w = trim($w, " .'&"); if (mb_strlen($w) >= 4 && mb_strlen($w) <= 60 && preg_match('/^\p{L}+$/u', $w)) { $local[$w] = true; } }
        foreach (array_keys($local) as $w) { $freq['~' . $w] = ($freq['~' . $w] ?? 0) + 1; }
    }
    foreach ($freq as $k => $n) { if ($k[0] === '~') { unset($freq[$k]); $w = substr($k, 1); if ($n >= 3) { $freq[$w] = ($freq[$w] ?? 0) + $n; } } }

    $pdo = db(); $pdo->beginTransaction();
    try {
        db_exec('DELETE FROM search_vocab');
        foreach (array_chunk($freq, 400, true) as $chunk) {
            $ph = []; $vals = [];
            foreach ($chunk as $w => $n) { $ph[] = '(?, ?, ?, ?)'; array_push($vals, $w, $n, min(255, mb_strlen($w)), mb_substr($w, 0, 1)); }
            // words that only differ by accents are equal under the table's collation: merge them instead of failing
            db_exec('INSERT INTO search_vocab (word, freq, len, first) VALUES ' . implode(', ', $ph) . ' ON DUPLICATE KEY UPDATE freq = freq + VALUES(freq)', $vals);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return 'Learned ' . count($freq) . ' search words.';
}

// ============================================================================
// DAILY REPORT
// ============================================================================

function digest_recipient(): string
{
    foreach ([setting('digest_email'), setting('site_email')] as $e) { if (filter_var($e, FILTER_VALIDATE_EMAIL)) { return (string)$e; } }
    return (string)db_val("SELECT email FROM admin_users WHERE role = 'SUPER_ADMIN' AND status = 'active' ORDER BY id LIMIT 1");
}

function job_digest(): string
{
    if (setting('digest_on', '1') !== '1') { return 'Daily report is off.'; }
    $to = digest_recipient();
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return 'No recipient: set a contact email in Settings → General.'; }
    $site = setting('site_name', 'PATADOCS');
    $y = db_row("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) s FROM orders WHERE status = 'paid' AND paid_at >= CURDATE() - INTERVAL 1 DAY AND paid_at < CURDATE()");
    $w = db_row("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) s FROM orders WHERE status = 'paid' AND paid_at >= CURDATE() - INTERVAL 7 DAY");
    $top = db_all("SELECT norm_query q, COUNT(*) c FROM search_logs WHERE created_at >= NOW() - INTERVAL 7 DAY GROUP BY norm_query ORDER BY c DESC LIMIT 10");
    $zero = db_all("SELECT norm_query q, COUNT(*) c FROM search_logs WHERE results = 0 AND created_at >= NOW() - INTERVAL 7 DAY GROUP BY norm_query HAVING c >= 2 ORDER BY c DESC LIMIT 10");
    $best = db_all("SELECT item_title t, COUNT(*) c, SUM(amount) s FROM orders WHERE status = 'paid' AND paid_at >= NOW() - INTERVAL 7 DAY GROUP BY item_title ORDER BY s DESC LIMIT 5");
    $q = [
        'contributions to review' => (int)db_val("SELECT COUNT(*) FROM contributions WHERE status IN ('pending','under_review')"),
        'document requests' => (int)db_val("SELECT COUNT(*) FROM document_requests WHERE status = 'new'"),
        'document reports' => (int)db_val("SELECT COUNT(*) FROM document_reports WHERE status = 'new'"),
        'reviews to approve' => (int)db_val("SELECT COUNT(*) FROM document_reviews WHERE status = 'pending'"),
    ];
    $failing = [];
    foreach (jobs_state() as $n => $st) { if (empty($st['ok'])) { $failing[] = $n . ': ' . ($st['msg'] ?? ''); } }

    $L = [$site . ' — daily report for ' . date('l j F Y', strtotime('-1 day')), str_repeat('=', 50), '',
        'SALES', '  Yesterday: ' . (int)$y['n'] . ' paid order(s), ' . money($y['s']), '  Last 7 days: ' . (int)$w['n'] . ' paid order(s), ' . money($w['s']), ''];
    if ($best) { $L[] = 'BEST SELLERS (7 days)'; foreach ($best as $b) { $L[] = '  ' . $b['t'] . ' — ' . (int)$b['c'] . ' sold, ' . money($b['s']); } $L[] = ''; }
    if ($top) { $L[] = 'TOP SEARCHES (7 days)'; foreach ($top as $t) { $L[] = '  ' . $t['q'] . ' (' . (int)$t['c'] . ')'; } $L[] = ''; }
    if ($zero) { $L[] = 'SEARCHES WITH NO RESULTS — documents people want that you don\'t have yet'; foreach ($zero as $t) { $L[] = '  ' . $t['q'] . ' (' . (int)$t['c'] . ' searches)'; } $L[] = ''; }
    $L[] = 'WAITING FOR YOU'; foreach ($q as $k => $n) { $L[] = '  ' . $n . ' ' . $k; } $L[] = '';
    if ($failing) { $L[] = 'AUTOMATION PROBLEMS'; foreach ($failing as $f) { $L[] = '  ' . $f; } $L[] = ''; }
    $L[] = 'Admin: ' . url('admin/');
    $L[] = 'Switch this email off in Admin → Automation.';
    if (!send_mail($to, $site . ' daily report: ' . money($y['s']) . ' yesterday', implode("\n", $L))) { throw new RuntimeException('Could not email the report to ' . $to . ' — check that this server can send mail (PHP mail()).'); }
    return 'Report sent to ' . $to . '.';
}

// ============================================================================
// HOUSEKEEPING
// ============================================================================

function job_cleanup(): string
{
    $n = [];
    $n['rate limits'] = db_exec('DELETE FROM rate_limits WHERE created_at < (NOW() - INTERVAL 1 DAY)');
    $n['expired links'] = db_exec("UPDATE download_tokens SET status = 'expired' WHERE status = 'active' AND expires_at < NOW()");
    $n['old webhook events'] = db_exec('DELETE FROM webhook_events WHERE created_at < (NOW() - INTERVAL 180 DAY)');
    $n['old job logs'] = db_exec('DELETE FROM job_runs WHERE created_at < (NOW() - INTERVAL 30 DAY)');
    $n['old Hub API log entries'] = db_exec('DELETE FROM hub_log WHERE created_at < (NOW() - INTERVAL 14 DAY)');
    $files = 0;
    $tmp = rtrim(UPLOAD_DIR, '/\\') . '/temporary';
    if (is_dir($tmp)) {
        foreach (new DirectoryIterator($tmp) as $f) {
            if ($f->isDot() || in_array($f->getFilename(), ['.htaccess', 'index.html', 'cache'], true)) { continue; }
            if ($f->getMTime() < time() - 86400) { $f->isDir() ? preview_rrmdir($f->getPathname()) : @unlink($f->getPathname()); $files++; }
        }
    }
    $n['temporary files'] = $files;
    $out = []; foreach ($n as $k => $v) { if ($v) { $out[] = $v . ' ' . $k; } }
    return $out ? 'Removed/updated: ' . implode(', ', $out) . '.' : 'Nothing to clean.';
}
