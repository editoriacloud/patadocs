<?php
/** Admin: orders — list with filters + order detail (payments, webhook events, download links, downloads). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';
$admin = require_admin('orders.view');

// ---- Order detail -------------------------------------------------------------------------------
if (get_int('id') > 0) {
    $o = order_get(get_int('id'));
    if (!$o) { abort_page(404, 'Order not found', 'This order does not exist.', [['↩ ORDERS', url('admin/orders.php')]]); }
    $pays = db_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC', [$o['id']]);
    $tokens = db_all('SELECT t.*, d.title FROM download_tokens t JOIN documents d ON d.id = t.document_id WHERE t.order_id = ? ORDER BY t.id DESC', [$o['id']]);
    $events = db_all('SELECT * FROM webhook_events WHERE order_code = ? ORDER BY id DESC', [$o['order_code']]);
    $logs = db_all('SELECT * FROM download_logs WHERE order_id = ? ORDER BY id DESC LIMIT 30', [$o['id']]);
    $adm = ['title' => 'Order ' . $o['order_code'], 'active' => 'orders'];
    include __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="panel"><div class="panel-header orange">ORDER <?= e($o['order_code']) ?> <?= e('· ' . strtoupper($o['status'])) ?></div><div class="panel-body">
        <div class="flex" style="margin-bottom:12px;"><a class="btn-classic btn-sm" href="orders.php">↩ ALL ORDERS</a>
            <?php if (admin_can('orders.manage')) {
                if (in_array($o['status'], ['pending', 'expired'], true)) { echo '<button class="btn-classic btn-sm warning" data-act="order_recheck" data-id="' . $o['id'] . '">🔄 RE-CHECK WITH PAYMENT HUB</button>'; }
                if ($o['status'] === 'paid') { echo '<button class="btn-classic btn-sm danger" data-act="order_refund" data-id="' . $o['id'] . '" data-confirm="Mark this order as refunded and revoke its download links?">↩ MARK REFUNDED</button>'; }
            } ?></div>
        <div class="gv-wrap"><table class="gv-table kv"><tbody>
            <tr><td>Item</td><td><?= e($o['item_title']) ?> <?= $o['document_id'] ? '<a href="edit-document.php?id=' . (int)$o['document_id'] . '">(document)</a>' : ($o['collection_id'] ? '(bundle)' : '') ?></td></tr>
            <tr><td>Amount</td><td><?= e(money($o['amount'])) ?> <?= e($o['currency']) ?></td></tr>
            <tr><td>Phone</td><td><?= e($o['phone']) ?></td></tr><tr><td>Email / name</td><td><?= e(($o['email'] ?: '—') . ' · ' . ($o['customer_name'] ?: '—')) ?></td></tr>
            <tr><td>M-Pesa receipt</td><td class="mono"><?= e($o['mpesa_receipt'] ?: '—') ?></td></tr><tr><td>Hub reference</td><td class="mono"><?= e($o['hub_reference'] ?: '—') ?></td></tr>
            <tr><td>Created / paid / expires</td><td><?= e(fmt_date($o['created_at'], true)) ?> · <?= e($o['paid_at'] ? fmt_date($o['paid_at'], true) : '—') ?> · <?= e(fmt_date($o['expires_at'], true)) ?></td></tr>
            <tr><td>IP</td><td><?= e($o['ip']) ?></td></tr></tbody></table></div>
        <div class="section-title">PAYMENT ATTEMPTS</div>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>#</th><th>Status</th><th>Hub ref</th><th>Receipt</th><th>Webhook</th><th>Note</th><th>Date</th></tr></thead><tbody>
            <?php foreach ($pays as $p) { echo '<tr><td>' . (int)$p['id'] . '</td><td>' . status_badge($p['status']) . '</td><td class="mono">' . e($p['hub_reference'] ?: '—') . '</td><td class="mono">' . e($p['mpesa_receipt'] ?: '—') . '</td><td>' . status_badge($p['webhook_status']) . '</td><td>' . e($p['result_desc'] ?: '') . '</td><td>' . e(fmt_date($p['created_at'], true)) . '</td></tr>'; } if (!$pays) { echo '<tr><td colspan="7" class="empty-cell">None</td></tr>'; } ?></tbody></table></div>
        <div class="section-title">DOWNLOAD LINKS</div>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Document</th><th>Downloads</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($tokens as $t) { echo '<tr><td>' . e($t['title']) . '</td><td>' . (int)$t['download_count'] . ' / ' . ((int)$t['max_downloads'] ?: '∞') . '</td><td>' . e(fmt_date($t['expires_at'], true)) . '</td><td>' . status_badge($t['status']) . '</td><td class="actions">'
                . (admin_can('downloads.manage') ? '<button class="btn-classic btn-sm" data-act="token_extend" data-id="' . $t['id'] . '" data-value="48">+48h</button><button class="btn-classic btn-sm warning" data-act="token_regen" data-id="' . $t['id'] . '" data-reload="0">NEW LINK</button><button class="btn-classic btn-sm danger" data-act="token_revoke" data-id="' . $t['id'] . '" data-confirm="Revoke this link?">REVOKE</button>' : '') . '</td></tr>'; } if (!$tokens) { echo '<tr><td colspan="5" class="empty-cell">No links yet (order not paid).</td></tr>'; } ?></tbody></table></div>
        <?php if ($events) { echo '<div class="section-title">WEBHOOK EVENTS</div><div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Event</th><th>Result</th><th>IP</th><th>Date</th></tr></thead><tbody>'; foreach ($events as $ev) { echo '<tr><td class="mono">' . e($ev['event_id']) . '</td><td>' . e($ev['result']) . '</td><td>' . e($ev['ip']) . '</td><td>' . e(fmt_date($ev['created_at'], true)) . '</td></tr>'; } echo '</tbody></table></div>'; } ?>
        <?php if ($logs) { echo '<div class="section-title">DOWNLOAD LOG</div><div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Date</th><th>IP</th><th>Browser</th></tr></thead><tbody>'; foreach ($logs as $l) { echo '<tr><td>' . e(fmt_date($l['created_at'], true)) . '</td><td>' . e($l['ip']) . '</td><td class="small">' . e(excerpt((string)$l['user_agent'], 70)) . '</td></tr>'; } echo '</tbody></table></div>'; } ?>
    </div></div>
    <?php include __DIR__ . '/../includes/admin_footer.php'; exit;
}

// ---- List --------------------------------------------------------------------------------------------
db_exec("UPDATE orders SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()");
$q = get_str('q', 60); $status = get_str('status', 10); $from = get_str('from', 10); $to = get_str('to', 10); $page = max(1, get_int('page', 1));
$where = ['1=1']; $p = [];
if ($q !== '') { $where[] = '(o.order_code LIKE ? OR o.phone LIKE ? OR o.mpesa_receipt LIKE ? OR o.item_title LIKE ? OR o.email LIKE ?)'; $l = '%' . like_escape($q) . '%'; array_push($p, $l, $l, $l, $l, $l); }
if (in_array($status, ['pending', 'paid', 'failed', 'expired', 'refunded'], true)) { $where[] = 'o.status = ?'; $p[] = $status; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'o.created_at >= ?'; $p[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'o.created_at <= ?'; $p[] = $to . ' 23:59:59'; }
$total = (int)db_val('SELECT COUNT(*) FROM orders o WHERE ' . implode(' AND ', $where), $p);
$sum = (float)db_val("SELECT COALESCE(SUM(o.amount), 0) FROM orders o WHERE o.status = 'paid' AND " . implode(' AND ', $where), $p);
$pg = paginate($total, $page, 25);
$rows = db_all('SELECT o.*, (SELECT p.status FROM payments p WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1) AS pay_status, (SELECT COUNT(*) FROM download_logs l WHERE l.order_id = o.id) AS dls
                FROM orders o WHERE ' . implode(' AND ', $where) . ' ORDER BY o.id DESC LIMIT ' . (int)$pg['offset'] . ', 25', $p);
$adm = ['title' => 'Orders', 'active' => 'orders'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">ORDERS <span style="font-weight:normal; font-size:.9rem;">(<?= num($total) ?> · paid total <?= e(money($sum)) ?>)</span></div><div class="panel-body">
    <div class="admin-bar"><form method="get">
        <input type="text" name="q" placeholder="Order ID, phone, receipt, title..." value="<?= e($q) ?>">
        <select name="status" data-autosubmit><option value="">All statuses</option><?php foreach (['pending', 'paid', 'failed', 'expired', 'refunded'] as $s) { echo '<option value="' . $s . '"' . ($status === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>'; } ?></select>
        <input type="date" name="from" value="<?= e($from) ?>" style="width:auto;"><input type="date" name="to" value="<?= e($to) ?>" style="width:auto;">
        <button class="btn-classic primary btn-sm" type="submit">FILTER</button></form></div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Order ID</th><th>Document</th><th>Phone</th><th>Amount</th><th>Status</th><th>Payment</th><th>Download</th><th>Date</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $o) { echo '<tr><td class="mono"><a href="?id=' . (int)$o['id'] . '">' . e($o['order_code']) . '</a></td><td class="doc-title">' . e(excerpt($o['item_title'], 46)) . '</td><td>' . e($o['phone']) . '</td><td class="doc-price">' . e(money($o['amount'])) . '</td><td>' . status_badge($o['status']) . '</td><td>' . ($o['pay_status'] ? status_badge($o['pay_status']) : '—') . ($o['mpesa_receipt'] ? '<span class="sub mono">' . e($o['mpesa_receipt']) . '</span>' : '') . '</td><td class="num">' . (int)$o['dls'] . '</td><td class="nowrap">' . e(fmt_date($o['created_at'], true)) . '</td><td class="actions"><a class="btn-classic btn-sm primary" href="?id=' . (int)$o['id'] . '">OPEN</a>'
        . (admin_can('orders.manage') && in_array($o['status'], ['pending', 'expired'], true) ? '<button class="btn-classic btn-sm warning" data-act="order_recheck" data-id="' . $o['id'] . '" title="Ask the Payment Hub">🔄</button>' : '') . '</td></tr>'; }
    if (!$rows) { echo '<tr><td colspan="9" class="empty-cell">No orders yet.</td></tr>'; } ?></tbody></table></div>
    <?= pager_html($pg, url('admin/orders.php'), array_filter(['q' => $q, 'status' => $status, 'from' => $from, 'to' => $to])) ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
