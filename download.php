<?php
/**
 * PATADOCS — controlled download delivery. The original file is NEVER linked directly:
 *   • free documents: POST (CSRF) creates a short-lived random token, then we redirect to ?token=...
 *   • paid documents: a token is created only after the Payment Hub confirmed the payment.
 * Tokens are 64 hex chars (random_bytes), linked to document + order, time-limited, counted and logged.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/payment_hub.php';

$back = [['🧾 RECOVER PURCHASE', page_url('recover')], ['🔍 SEARCH DOCUMENTS', page_url('search')], ['🏠 HOME', url('')]];

// ---- A) Free download request → create token → redirect ---------------------------------
if (is_post() && post_str('action', 10) === 'free') {
    csrf_check();
    if (!rate_limit('free:' . client_ip(), 40, 3600)) { abort_page(429, 'Slow down', 'Too many downloads from your connection. Please try again in a little while.', $back); }
    $mins = max(1, (int)setting('free_token_minutes', 30)); $max = max(1, (int)setting('free_token_max', 3));

    if (post_int('col') > 0) {                                   // free bundle → one link per document
        $col = db_row("SELECT * FROM collections WHERE id = ? AND status = 'published'", [post_int('col')]);
        if (!$col || !((int)$col['is_free'] === 1 || (float)$col['price'] <= 0)) { abort_page(403, 'Not a free bundle', 'This bundle is not free.', $back); }
        $docs = db_all("SELECT d.* FROM collection_documents cd JOIN documents d ON d.id = cd.document_id WHERE cd.collection_id = ? AND d.status = 'published' AND d.file_name IS NOT NULL ORDER BY cd.sort_order, d.title LIMIT 60", [$col['id']]);
        $links = [];
        foreach ($docs as $d) { $t = token_create((int)$d['id'], null, 'free', $mins, $max); $links[] = ['title' => $d['title'], 'format' => doc_ext_label($d['file_ext']), 'url' => download_url($t['token'])]; }
        $meta = ['title' => 'Your downloads', 'robots' => 'noindex,nofollow', 'nav' => ''];
        include __DIR__ . '/includes/header.php';
        echo '<section class="page-section active"><div class="panel"><div class="panel-header green">⬇ ' . e(strtoupper($col['title'])) . '</div><div class="panel-body">';
        echo '<p class="help" style="margin-bottom:12px;">Click each document to download it. These links expire in ' . (int)$mins . ' minutes.</p>';
        foreach ($links as $l) { echo '<div class="result-area" style="margin-bottom:8px;"><div class="result-row"><strong>' . e($l['title']) . '</strong> <span class="muted">' . e($l['format']) . '</span><a class="btn-classic success btn-sm" style="margin-left:auto;" href="' . e($l['url']) . '">⬇ DOWNLOAD</a></div></div>'; }
        echo '</div></div></section>';
        include __DIR__ . '/includes/footer.php'; exit;
    }

    $doc = doc_get(post_int('doc'));
    if (!$doc || $doc['status'] !== 'published' || empty($doc['file_name'])) { abort_page(404, 'Document not found', 'This document is not available.', $back); }
    if (!((int)$doc['is_free'] === 1 || (float)$doc['price'] <= 0)) { abort_page(403, 'Paid document', 'This document is not free. Please use the Buy & Download button.', [['↩ BACK TO DOCUMENT', doc_url($doc)]]); }
    $t = token_create((int)$doc['id'], null, 'free', $mins, $max);
    redirect(download_url($t['token']), 303);
}

// ---- B) Deliver the file for a valid token --------------------------------------------
$chk = token_check(get_str('token', 64));
if (!$chk['ok']) {
    [$title, $msg] = download_fail_message($chk['reason']);
    abort_page(in_array($chk['reason'], ['expired', 'exhausted', 'revoked'], true) ? 410 : ($chk['reason'] === 'refunded' ? 403 : 404), $title, $msg, $back);
}
$t = $chk['token']; $doc = $chk['doc'];
if (!token_consume((int)$t['id'])) { abort_page(410, 'Download limit reached', 'This link has just reached its download limit.', $back); }

db_insert('INSERT INTO download_logs (token_id, document_id, order_id, type, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)',
    [$t['id'], $doc['id'], $t['order_id'], $t['type'], client_ip(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
db_exec('UPDATE documents SET ' . ($t['type'] === 'paid' ? 'paid_downloads = paid_downloads + 1' : 'free_downloads = free_downloads + 1') . ', updated_at = updated_at WHERE id = ?', [$doc['id']]);

$path = doc_private_path($doc); $size = (int)filesize($path); $name = doc_download_name($doc);
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: ' . doc_mime($doc['file_ext']));
header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
@set_time_limit(0); ignore_user_abort(true);
$fh = fopen($path, 'rb');
while ($fh && !feof($fh)) { echo fread($fh, 1048576); flush(); if (connection_aborted()) { break; } }
if ($fh) { fclose($fh); }
exit;
