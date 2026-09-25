<?php
/** Admin: moderate verified-buyer reviews (only approved reviews appear on the site and in the star-rating markup). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
require_once __DIR__ . '/../includes/reviews.php';
$admin = require_admin('reports.manage');
$status = in_array(get_str('status', 10), ['pending', 'approved', 'rejected', 'all'], true) ? get_str('status', 10) : 'pending';
$page = max(1, get_int('page', 1));
$where = $status === 'all' ? '1=1' : 'r.status = ?'; $p = $status === 'all' ? [] : [$status];
$total = (int)db_val('SELECT COUNT(*) FROM document_reviews r WHERE ' . $where, $p);
$pg = paginate($total, $page, 25);
$rows = db_all('SELECT r.*, d.title, o.order_code, o.invoice_ref FROM document_reviews r JOIN documents d ON d.id = r.document_id JOIN orders o ON o.id = r.order_id
                WHERE ' . $where . ' ORDER BY r.id DESC LIMIT ' . (int)$pg['offset'] . ', 25', $p);
$adm = ['title' => 'Reviews', 'active' => 'reviews'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">VERIFIED-BUYER REVIEWS</div><div class="panel-body">
    <p class="help" style="margin-top:0;">Buyers can review a document from their payment-success page (their order proves the purchase). Approve genuine reviews — they show on the document page and as star ratings in Google. Never edit or invent reviews: fake reviews break Google's rules and consumer law.</p>
    <div class="flex" style="margin-bottom:12px;"><?php foreach (['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $lab) { echo '<a class="btn-classic btn-sm' . ($status === $k ? ' active-toggle' : '') . '" href="?status=' . $k . '">' . $lab . '</a>'; } ?></div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Document</th><th>Rating</th><th>Review</th><th>Invoice</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r) {
        echo '<tr><td class="doc-title"><a href="edit-document.php?id=' . (int)$r['document_id'] . '">' . e($r['title']) . '</a></td><td class="nowrap">' . stars_html((float)$r['rating']) . '</td>'
            . '<td><strong>' . e($r['name']) . '</strong><span class="sub">' . e($r['comment'] ?: '—') . '</span></td><td class="mono"><a href="orders.php?q=' . e(rawurlencode($r['invoice_ref'] ?: $r['order_code'])) . '">' . e($r['invoice_ref'] ?: $r['order_code']) . '</a></td>'
            . '<td>' . status_badge($r['status']) . '</td><td class="nowrap">' . e(fmt_date($r['created_at'], true)) . '</td><td class="actions">'
            . ($r['status'] !== 'approved' ? '<button class="btn-classic btn-sm success" data-act="review_status" data-id="' . (int)$r['id'] . '" data-value="approved">APPROVE</button>' : '')
            . ($r['status'] !== 'rejected' ? '<button class="btn-classic btn-sm" data-act="review_status" data-id="' . (int)$r['id'] . '" data-value="rejected">REJECT</button>' : '')
            . '<button class="btn-classic btn-sm danger" data-act="review_status" data-id="' . (int)$r['id'] . '" data-value="delete" data-confirm="Delete this review permanently?">DELETE</button></td></tr>';
    }
    if (!$rows) { echo '<tr><td colspan="7" class="empty-cell">No reviews here.</td></tr>'; } ?></tbody></table></div>
    <?= pager_html($pg, url('admin/reviews.php'), ['status' => $status]) ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
