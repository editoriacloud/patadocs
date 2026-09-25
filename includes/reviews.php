<?php
/**
 * PATADOCS — verified-buyer reviews.
 * Only someone holding a PAID order (order code + its secret access key) can review the documents in that order,
 * once per document. Reviews are moderated (Admin → Reviews) before they appear on the document page and in the
 * Product structured data (star ratings in search results).
 */

/** ['count' => approved reviews, 'avg' => average rating] for a document. */
function review_summary(int $docId): array
{
    $r = db_row("SELECT COUNT(*) AS c, AVG(rating) AS a FROM document_reviews WHERE document_id = ? AND status = 'approved'", [$docId]);
    return ['count' => (int)($r['c'] ?? 0), 'avg' => $r && $r['a'] !== null ? round((float)$r['a'], 1) : 0.0];
}

/** Approved reviews, newest first. */
function reviews_for(int $docId, int $limit = 10): array
{
    return db_all("SELECT rating, name, comment, created_at FROM document_reviews WHERE document_id = ? AND status = 'approved'
                   ORDER BY created_at DESC LIMIT " . max(1, min(50, $limit)), [$docId]);
}

/** Document ids of a paid order that have not been reviewed yet. */
function review_pending_docs(array $order): array
{
    if ($order['status'] !== 'paid') { return []; }
    $done = array_map('intval', array_column(db_all('SELECT document_id FROM document_reviews WHERE order_id = ?', [$order['id']]), 'document_id'));
    $out = [];
    foreach (order_documents($order) as $d) { if (!in_array((int)$d['id'], $done, true)) { $out[] = $d; } }
    return $out;
}

/** Saves a review from the holder of a paid order. Returns ['ok', 'message']. */
function review_submit(array $order, int $docId, int $rating, string $name, string $comment): array
{
    if ($order['status'] !== 'paid') { return ['ok' => false, 'message' => 'Only completed purchases can be reviewed.']; }
    if (!in_array($docId, array_map(function ($d) { return (int)$d['id']; }, order_documents($order)), true)) { return ['ok' => false, 'message' => 'This document is not part of your order.']; }
    if ($rating < 1 || $rating > 5) { return ['ok' => false, 'message' => 'Choose a rating from 1 to 5 stars.']; }
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($name))), 0, 80);
    if ($name === '') { $name = $order['customer_name'] ? mb_substr((string)$order['customer_name'], 0, 80) : 'Verified buyer'; }
    $comment = mb_substr(trim(strip_tags($comment)), 0, 1000);
    if (!rate_limit('review:ip:' . client_ip(), 10, 3600)) { return ['ok' => false, 'message' => 'Too many reviews from your connection. Please try again later.']; }
    try {
        db_insert('INSERT INTO document_reviews (document_id, order_id, rating, name, comment, status, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$docId, $order['id'], $rating, $name, $comment !== '' ? $comment : null, setting('reviews_auto_approve', '0') === '1' ? 'approved' : 'pending', client_ip()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') { return ['ok' => false, 'message' => 'You have already reviewed this document.']; }
        throw $e;
    }
    return ['ok' => true, 'message' => setting('reviews_auto_approve', '0') === '1' ? 'Thank you! Your review is now live.' : 'Thank you! Your review will appear once it has been checked.'];
}

/** "★★★★☆" with an accessible label. */
function stars_html(float $avg, string $extra = ''): string
{
    $full = (int)round($avg);
    return '<span class="stars" role="img" aria-label="Rated ' . e(number_format($avg, 1)) . ' out of 5">' . str_repeat('★', $full) . '<span class="off">' . str_repeat('★', 5 - $full) . '</span></span>' . $extra;
}

/** Review form for one document of a paid order (payment-success / download pages). */
function review_form_html(array $order, array $doc): string
{
    $opts = '';
    for ($i = 5; $i >= 1; $i--) { $opts .= '<label class="star-opt"><input type="radio" name="rating" value="' . $i . '"' . ($i === 5 ? ' required' : '') . '><span>' . str_repeat('★', $i) . '</span></label>'; }
    return '<form class="pd-form review-form" method="post" action="' . e(url('ajax/review.php')) . '" data-review-form>'
        . csrf_field() . '<input type="hidden" name="ref" value="' . e(order_ref($order)) . '"><input type="hidden" name="k" value="' . e($order['access_key']) . '">'
        . '<input type="hidden" name="doc" value="' . (int)$doc['id'] . '"><input type="hidden" name="return" value="' . e(url('payment-success.php?' . order_qs($order))) . '">'
        . '<div class="section-title" style="margin-top:0;">RATE “' . e(mb_strtoupper(excerpt($doc['title'], 60))) . '”</div>'
        . '<div class="star-pick">' . $opts . '</div>'
        . '<div class="form-grid"><div class="frow"><label>Your name (shown publicly)</label><input type="text" name="name" maxlength="80" placeholder="e.g. Jane W."></div>'
        . '<div class="frow full"><label>Your review (optional)</label><textarea name="comment" maxlength="1000" placeholder="Was the document useful? Was it what you expected?"></textarea></div></div>'
        . '<div class="form-actions"><button type="submit" class="btn-classic success">SUBMIT REVIEW</button><span class="review-msg help"></span></div></form>';
}
