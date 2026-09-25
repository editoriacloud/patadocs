<?php
/**
 * Admin AJAX actions. Every action: POST + CSRF (header X-CSRF-Token) + logged-in admin + permission.
 * GET is allowed only for read-only helpers (meta_fields).
 */
define('PD_AJAX', true);
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
require_once __DIR__ . '/../includes/payment_hub.php';

$action = post_str('action', 30) ?: get_str('action', 30);
$fail = function ($m, $c = 400) { json_out(['ok' => false, 'message' => $m], $c); };
$ok = function ($m = 'Done.', $extra = []) { json_out(array_merge(['ok' => true, 'message' => $m], $extra)); };

// ---- Read-only helpers ----------------------------------------------------------------------
if ($action === 'meta_fields') {
    require_admin('documents.edit');
    $cid = get_int('cat'); $did = get_int('doc');
    $vals = $did ? meta_values($did) : [];
    json_out(['ok' => true, 'html' => $cid && cat_get($cid) ? meta_fields_html(meta_fields_for($cid), $vals) : '']);
}

if ($action === 'doc_search') {                       // used by the collection document picker
    require_admin('collections.manage');
    $q = get_str('q', 80); $out = [];
    if (mb_strlen($q) >= 2) {
        $l = '%' . like_escape($q) . '%';
        foreach (db_all("SELECT id, title, file_ext, is_free, price FROM documents WHERE status = 'published' AND (title LIKE ? OR search_text LIKE ?) ORDER BY title LIMIT 12", [$l, $l]) as $d) {
            $out[] = ['id' => (int)$d['id'], 'title' => $d['title'], 'meta' => doc_ext_label($d['file_ext']) . ' · ' . price_label($d)];
        }
    }
    json_out(['ok' => true, 'docs' => $out]);
}

if (!is_post()) { $fail('Invalid request.', 405); }
csrf_check(true);
$id = post_int('id'); $val = post_str('value', 40);

switch ($action) {
    case 'seo_suggest':
        require_admin('documents.edit');
        $catId = post_int('category') ?: null; $pairs = [];
        $mv = meta_sanitize($catId, (array)($_POST['meta'] ?? []));
        $seoIds = []; foreach (meta_fields_for($catId) as $f) { if ((int)$f['is_seo'] === 1) { $seoIds[(int)$f['id']] = $f['label']; } }
        foreach ($mv['rows'] as $r) { if (isset($seoIds[(int)$r[0]])) { $pairs[$seoIds[(int)$r[0]]] = str_replace('|', ', ', trim($r[1], '|')); } }   // only "SEO relevant" fields
        $s = seo_suggest(post_str('title', 255), $catId, $pairs, post_str('doc_type', 80), post_str('is_free', 1) !== '0');
        json_out(['ok' => true] + $s);

    // ---- Documents -----------------------------------------------------------------------------
    case 'doc_status':
        require_admin('documents.edit'); $r = doc_set_status($id, $val); $r['ok'] ? $ok($r['message']) : $fail($r['message']);
    case 'doc_feature': case 'doc_popular':
        require_admin('documents.edit'); $col = $action === 'doc_feature' ? 'featured' : 'popular';
        db_exec("UPDATE documents SET $col = 1 - $col WHERE id = ?", [$id]); log_admin('document_' . $col, 'document', $id); $ok('Updated.');
    case 'doc_delete':
        require_admin('documents.delete'); $r = doc_delete($id); $r['ok'] ? $ok($r['message']) : $fail($r['message']);
    case 'doc_duplicate':
        require_admin('documents.edit'); $n = doc_duplicate($id); $n ? $ok('Duplicated as a new draft.', ['id' => $n]) : $fail('Could not duplicate this document.');
    case 'doc_regen_preview':
        require_admin('documents.edit'); $r = preview_generate($id); $r['ok'] ? $ok($r['message']) : $fail($r['message']);
    case 'doc_bulk':
        require_admin('documents.edit');
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $n = 0;
        foreach ($ids as $i) {
            if ($val === 'delete') { if (!admin_can('documents.delete')) { $fail('You cannot delete documents.', 403); } if (doc_delete($i)['ok']) { $n++; } }
            elseif (in_array($val, ['published', 'draft', 'archived'], true)) { if (doc_set_status($i, $val)['ok']) { $n++; } }
            elseif ($val === 'feature') { db_exec('UPDATE documents SET featured = 1 WHERE id = ?', [$i]); $n++; }
            elseif ($val === 'unfeature') { db_exec('UPDATE documents SET featured = 0 WHERE id = ?', [$i]); $n++; }
        }
        $ok($n . ' document(s) updated.');

    // ---- Reports / requests / messages ------------------------------------------------------------
    case 'report_status':
        require_admin('reports.manage');
        if (!in_array($val, ['new', 'reviewing', 'resolved', 'dismissed'], true)) { $fail('Invalid status.'); }
        db_exec('UPDATE document_reports SET status = ?, handled_by = ? WHERE id = ?', [$val, $_SESSION['admin']['id'], $id]); log_admin('report_' . $val, 'report', $id); $ok('Report updated.');
    case 'review_status':
        require_admin('reports.manage');
        if (!in_array($val, ['approved', 'rejected', 'delete'], true)) { $fail('Invalid status.'); }
        $rv = db_row('SELECT * FROM document_reviews WHERE id = ?', [$id]); if (!$rv) { $fail('Review not found.', 404); }
        if ($val === 'delete') { db_exec('DELETE FROM document_reviews WHERE id = ?', [$id]); } else { db_exec('UPDATE document_reviews SET status = ? WHERE id = ?', [$val, $id]); }
        if ($val === 'approved' || $rv['status'] === 'approved') {                 // the page's rating changed: fresh lastmod + tell IndexNow
            db_exec('UPDATE documents SET updated_at = NOW() WHERE id = ?', [$rv['document_id']]);
            if (($d = doc_get((int)$rv['document_id'])) && $d['status'] === 'published') { indexnow_doc($d); }
        }
        log_admin('review_' . $val, 'review', $id); $ok($val === 'delete' ? 'Review deleted.' : 'Review ' . $val . '.');
    case 'topbar_preview':                                     // exact server rendering of unsaved settings (Admin → Top Bar)
        require_admin('homepage.manage');
        require_once __DIR__ . '/../includes/topbar.php';
        $over = [];
        foreach ($_POST as $k => $v) { if (is_string($k) && strpos($k, 'topbar_') === 0 && is_string($v)) { $over[$k] = mb_substr($v, 0, 3000); } }
        foreach (['topbar_dismiss', 'topbar_mobile_contacts'] as $b) { $over[$b] = isset($_POST[$b]) ? '1' : '0'; }
        $ok('Preview', ['html' => topbar_html(array_merge(topbar_config($over), ['on' => true]), true)]);
    case 'job_run':
        require_admin('settings.secure');
        require_once __DIR__ . '/../includes/jobs.php';
        if (!isset(jobs_registry()[$val])) { $fail('Unknown job.'); }
        $r = jobs_run('admin', $val, 55);
        if (isset($r['_locked'])) { $fail('Another automation run is in progress — try again in a minute.'); }
        log_admin('job_run', 'job', null, $val);
        $r[$val]['ok'] ? $ok($r[$val]['message']) : $fail('The job failed: ' . $r[$val]['message']);
    case 'content_reread':
        require_admin('settings.secure');
        $n = db_exec("UPDATE documents SET content_status = 'none', updated_at = updated_at WHERE content_status <> 'none'");
        log_admin('content_reread', 'job'); $ok($n . ' document(s) will be read again by the next “Document text extraction” runs.');
    case 'request_status':
        require_admin('requests.manage');
        if (!in_array($val, ['new', 'reviewing', 'found', 'created', 'rejected'], true)) { $fail('Invalid status.'); }
        db_exec('UPDATE document_requests SET status = ? WHERE id = ?', [$val, $id]); log_admin('request_' . $val, 'request', $id); $ok('Request updated.');
    case 'message_status':
        require_admin('requests.manage');
        if (!in_array($val, ['new', 'read', 'archived'], true)) { $fail('Invalid status.'); }
        db_exec('UPDATE contact_messages SET status = ? WHERE id = ?', [$val, $id]); $ok('Message updated.');

    // ---- Orders / payments / downloads ------------------------------------------------------------
    case 'order_recheck':
        require_admin('orders.manage');
        $o = order_get($id); if (!$o) { $fail('Order not found.', 404); }
        $n = order_refresh($o, true);
        $ok('Status is now: ' . strtoupper($n['status']) . '.');
    case 'order_refund':
        require_admin('orders.manage');
        $o = order_get($id); if (!$o || $o['status'] !== 'paid') { $fail('Only paid orders can be marked as refunded.'); }
        db_exec("UPDATE orders SET status = 'refunded' WHERE id = ?", [$id]);
        db_exec("UPDATE download_tokens SET status = 'revoked' WHERE order_id = ?", [$id]);
        if ($o['document_id']) { db_exec('UPDATE documents SET revenue = GREATEST(0, revenue - ?), purchase_count = GREATEST(0, purchase_count - 1), updated_at = updated_at WHERE id = ?', [$o['amount'], $o['document_id']]); }
        log_admin('order_refunded', 'order', $o['order_code'], money($o['amount']));
        $ok('Order marked as refunded and its download links were revoked. (Refund the money from your M-Pesa/Payment Hub.)');
    case 'token_revoke':
        require_admin('downloads.manage'); db_exec("UPDATE download_tokens SET status = 'revoked' WHERE id = ?", [$id]); log_admin('token_revoked', 'token', $id); $ok('Download link revoked.');
    case 'token_extend':
        require_admin('downloads.manage');
        $t = db_row('SELECT * FROM download_tokens WHERE id = ?', [$id]); if (!$t) { $fail('Token not found.', 404); }
        $hours = max(1, min(24 * 30, (int)$val ?: 24));
        db_exec("UPDATE download_tokens SET status = 'active', expires_at = GREATEST(expires_at, NOW()) + INTERVAL ? HOUR, max_downloads = IF(max_downloads > 0 AND download_count >= max_downloads, download_count + 1, max_downloads) WHERE id = ?", [$hours, $id]);
        log_admin('token_extended', 'token', $id, $hours . 'h'); $ok('Access extended by ' . $hours . ' hours.');
    case 'token_regen':
        require_admin('downloads.manage');
        $t = db_row('SELECT * FROM download_tokens WHERE id = ?', [$id]); if (!$t) { $fail('Token not found.', 404); }
        db_exec("UPDATE download_tokens SET status = 'revoked' WHERE id = ?", [$id]);
        $doc = doc_get((int)$t['document_id']); $max = $doc && $doc['download_limit'] !== null ? (int)$doc['download_limit'] : (int)setting('default_download_limit', 3);
        $n = token_create((int)$t['document_id'], $t['order_id'] ? (int)$t['order_id'] : null, $t['type'], max(1, (int)setting('download_token_hours', 48)) * 60, $max);
        log_admin('token_regenerated', 'token', $id); $ok('New link created: ' . download_url($n['token']), ['url' => download_url($n['token'])]);

    case 'hub_test':
        require_admin('settings.secure');
        if (!hub_configured()) { $fail('Fill in the Payment Hub URL, Client ID and Client secret first (and save).'); }
        $h = hub_http('GET', hub_base() . '/health', [], null);
        if ($h['status'] === 0) { $fail('Could not reach the Payment Hub at ' . hub_base() . ': ' . $h['error']); }
        hub_token_forget();
        if (hub_token() === '') { $fail('The Hub is reachable but rejected the Client ID / Client secret (POST /api/v1/auth/token). Copy them again from the Hub\'s Applications page.'); }
        // Permission probes with requests that cannot change anything: an empty invoice (rejected by validation) and a
        // payment intent / receipt that does not exist. 403 = the scope is missing; 4xx other than 401/403 = allowed.
        $probe = function ($method, $path, $payload, $label, $scope, $why) {
            $r = hub_request($method, $path, $payload, '', 'DIAGNOSTIC', 12);
            $state = $r['status'] === 403 ? 'missing' : (($r['status'] >= 200 && $r['status'] < 500 && $r['status'] !== 401) ? 'ok' : 'unknown');
            return ['label' => $label, 'scope' => $scope, 'state' => $state, 'status' => $r['status'], 'why' => $why];
        };
        $checks = [
            $probe('POST', '/invoices', [], 'Create invoices', 'invoices.write', 'needed to start any payment'),
            $probe('GET', '/payment-intents/patadocs-diagnostic/status', null, 'Read payment status', 'payments.read', 'confirming payments (GET /payment-intents/{id}/status)'),
        ];
        $lines = []; $missing = 0;
        foreach ($checks as $c) {
            $lines[] = ($c['state'] === 'ok' ? '✔ ' : ($c['state'] === 'missing' ? '✖ ' : '? ')) . $c['label'] . ' (' . $c['scope'] . ')' . ($c['state'] === 'ok' ? '' : ' — HTTP ' . $c['status'] . ($c['state'] === 'missing' ? ': ask the Hub admin to grant this scope — ' . $c['why'] : ''));
            if ($c['state'] === 'missing') { $missing++; }
        }
        if (setting('hub_webhook_secret') === '') { $lines[] = '⚠ No webhook secret: payments are only confirmed by status checks (slower). Add it from the Hub → Webhooks.'; }
        $lastBad = db_val("SELECT result FROM webhook_events WHERE signature_ok = 0 AND created_at > NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1");
        if ($lastBad) { $lines[] = '⚠ Webhooks were rejected in the last 24 h (' . $lastBad . '): the webhook secret here must match the one on the Hub.'; }
        $msg = 'Connected: the Hub is reachable and accepted your credentials.' . "\n" . implode("\n", $lines);
        $missing ? $fail($msg) : $ok($msg);

    // ---- System ----------------------------------------------------------------------------------------
    case 'rebuild_search':
        require_admin('settings.manage'); $n = docs_rebuild_all(); log_admin('search_index_rebuilt', 'system', null, $n . ' documents'); $ok('Search index rebuilt for ' . $n . ' documents.');
}
$fail('Unknown action.');
