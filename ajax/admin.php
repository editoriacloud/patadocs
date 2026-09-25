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
        if ($o['document_id']) { db_exec('UPDATE documents SET revenue = GREATEST(0, revenue - ?), purchase_count = GREATEST(0, purchase_count - 1) WHERE id = ?', [$o['amount'], $o['document_id']]); }
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
        if (!hub_configured()) { $fail('Fill in the Payment Hub URL, Platform ID and API key first (and save).'); }
        $r = hub_request('GET', str_replace(['{reference}', '{order_code}'], 'PATADOCS-CONNECTION-TEST', setting('hub_status_path', '/api/v1/payments/{reference}')));
        if ($r['status'] === 0) { $fail('Could not reach the Payment Hub: ' . $r['error']); }
        if (in_array($r['status'], [401, 403], true)) { $fail('The Hub answered HTTP ' . $r['status'] . ' — check the API key, Platform ID and authentication mode.'); }
        if ($r['status'] === 404 || $r['ok']) { $ok('Connected: the Payment Hub is reachable and accepted your credentials (HTTP ' . $r['status'] . ' for a test reference).'); }
        $fail('The Hub answered HTTP ' . $r['status'] . '. Check the status path in the settings.');

    // ---- System ----------------------------------------------------------------------------------------
    case 'rebuild_search':
        require_admin('settings.manage'); $n = docs_rebuild_all(); log_admin('search_index_rebuilt', 'system', null, $n . ' documents'); $ok('Search index rebuilt for ' . $n . ' documents.');
}
$fail('Unknown action.');
