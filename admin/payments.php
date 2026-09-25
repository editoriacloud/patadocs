<?php
/** Admin: payments recorded from the Payment Hub (+ webhook events). */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('payments.view');
$q = get_str('q', 60); $status = get_str('status', 10); $page = max(1, get_int('page', 1)); $tab = in_array(get_str('tab', 8), ['events', 'hub'], true) ? get_str('tab', 8) : 'pay';
$where = ['1=1']; $p = [];
if ($q !== '') { $where[] = '(p.hub_reference LIKE ? OR p.mpesa_receipt LIKE ? OR p.phone LIKE ? OR o.order_code LIKE ? OR o.invoice_ref LIKE ?)'; $l = '%' . like_escape($q) . '%'; array_push($p, $l, $l, $l, $l, $l); }
if (in_array($status, ['initiated', 'pending', 'success', 'failed', 'cancelled', 'timeout'], true)) { $where[] = 'p.status = ?'; $p[] = $status; }
$total = (int)db_val('SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE ' . implode(' AND ', $where), $p);
$pg = paginate($total, $page, 30);
$rows = db_all('SELECT p.*, o.order_code, o.invoice_ref, o.hub_invoice_id, o.item_title FROM payments p JOIN orders o ON o.id = p.order_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT ' . (int)$pg['offset'] . ', 30', $p);
$events = $tab === 'events' ? db_all('SELECT * FROM webhook_events ORDER BY id DESC LIMIT 100') : [];
$hq = get_str('order', 100);
$hub = $tab === 'hub' ? db_all('SELECT * FROM hub_log' . ($hq !== '' ? ' WHERE order_code = ?' : '') . ' ORDER BY id DESC LIMIT 150', $hq !== '' ? [$hq] : []) : [];
$hubStat = db_row("SELECT COUNT(*) n, SUM(status = 0) down, SUM(status >= 400) err, ROUND(AVG(duration_ms)) ms FROM hub_log WHERE created_at > NOW() - INTERVAL 1 DAY");
$stat = db_row("SELECT COUNT(*) n, SUM(status = 'success') ok, SUM(status = 'failed') bad, SUM(status IN ('pending','initiated')) wait, COALESCE(SUM(IF(status = 'success', amount, 0)), 0) amt FROM payments");
$adm = ['title' => 'Payments', 'active' => 'payments'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">PAYMENTS (PAYMENT HUB TRANSACTIONS)</div><div class="panel-body">
    <div class="grid-4" style="margin-bottom:14px;">
        <div class="stat-box"><div class="stat-label">Transactions</div><div class="stat-value"><?= num($stat['n']) ?></div></div>
        <div class="stat-box"><div class="stat-label">Successful</div><div class="stat-value"><?= num($stat['ok']) ?></div><div class="stat-sub"><?= e(money($stat['amt'])) ?></div></div>
        <div class="stat-box"><div class="stat-label">Failed</div><div class="stat-value"><?= num($stat['bad']) ?></div></div>
        <div class="stat-box"><div class="stat-label">Waiting</div><div class="stat-value"><?= num($stat['wait']) ?></div></div>
    </div>
    <div class="flex" style="margin-bottom:12px;"><a class="btn-classic btn-sm<?= $tab === 'pay' ? ' active-toggle' : '' ?>" href="?tab=pay">Transactions</a><a class="btn-classic btn-sm<?= $tab === 'events' ? ' active-toggle' : '' ?>" href="?tab=events">Webhook events</a><a class="btn-classic btn-sm<?= $tab === 'hub' ? ' active-toggle' : '' ?>" href="?tab=hub">Hub API log</a></div>
    <?php if ($tab === 'hub') { ?>
        <p class="help" style="margin-top:0;">Every call PATADOCS made to the Payment Hub (last 14 days; tokens and phone numbers masked). Last 24 h: <?= num($hubStat['n']) ?> calls,
            <?= num($hubStat['err']) ?> errors, <?= num($hubStat['down']) ?> unreachable, average <?= num($hubStat['ms']) ?> ms. A red 403 means the client is missing a scope — run <em>Settings → Payment Hub → Test connection</em>.</p>
        <div class="admin-bar"><form method="get"><input type="hidden" name="tab" value="hub"><input type="text" name="order" placeholder="Invoice reference" value="<?= e($hq) ?>"><button class="btn-classic primary btn-sm" type="submit">FILTER</button></form></div>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>When</th><th>Call</th><th>Invoice</th><th>HTTP</th><th>Time</th><th>Hub replied</th></tr></thead><tbody>
        <?php foreach ($hub as $h) { $st = (int)$h['status'];
            echo '<tr><td class="nowrap">' . e(fmt_date($h['created_at'], true)) . '</td><td class="mono">' . e($h['method'] . ' ' . $h['path']) . '</td><td class="mono">' . e($h['order_code'] ?: '—') . '</td>'
                . '<td><span class="badge ' . ($st >= 200 && $st < 300 ? 'b-green' : ($st === 404 || $st === 422 ? 'b-orange' : 'b-red')) . '">' . ($st ?: 'down') . '</span></td><td class="nowrap">' . (int)$h['duration_ms'] . ' ms</td>'
                . '<td><details><summary class="small">' . e($h['error'] ?: 'view') . '</summary><pre class="mono small" style="white-space:pre-wrap; word-break:break-all; max-width:640px;">' . e($h['response']) . '</pre></details></td></tr>'; }
        if (!$hub) { echo '<tr><td colspan="6" class="empty-cell">No Hub calls recorded yet.</td></tr>'; } ?></tbody></table></div>
    <?php } elseif ($tab === 'events') { ?>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Event ID</th><th>Invoice</th><th>Signature</th><th>Result</th><th>IP</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($events as $ev) { echo '<tr><td class="mono">' . e($ev['event_id']) . '</td><td class="mono">' . e($ev['order_code'] ?: '—') . '</td><td>' . ($ev['signature_ok'] ? '<span class="badge b-green">valid</span>' : '<span class="badge b-red">invalid</span>') . '</td><td>' . e($ev['result']) . '</td><td>' . e($ev['ip']) . '</td><td>' . e(fmt_date($ev['created_at'], true)) . '</td></tr>'; }
        if (!$events) { echo '<tr><td colspan="6" class="empty-cell">No webhook events received yet. Hub callback URL: <code>' . e(url('ajax/webhook.php')) . '</code></td></tr>'; } ?></tbody></table></div>
    <?php } else { ?>
        <div class="admin-bar"><form method="get"><input type="text" name="q" placeholder="Invoice, Hub ref, receipt, phone..." value="<?= e($q) ?>">
            <select name="status" data-autosubmit><option value="">All statuses</option><?php foreach (['initiated', 'pending', 'success', 'failed', 'cancelled', 'timeout'] as $s) { echo '<option value="' . $s . '"' . ($status === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>'; } ?></select>
            <button class="btn-classic primary btn-sm" type="submit">FILTER</button></form></div>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Transaction</th><th>Invoice</th><th>Phone</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Hub reference</th><th>Webhook</th></tr></thead><tbody>
        <?php foreach ($rows as $r) { echo '<tr><td>#' . (int)$r['id'] . ($r['mpesa_receipt'] ? '<span class="sub mono">' . e($r['mpesa_receipt']) . '</span>' : '') . '</td><td class="mono"><a href="orders.php?id=' . (int)$r['order_id'] . '">' . e($r['invoice_ref'] ?: ($r['hub_invoice_id'] ?: $r['order_code'])) . '</a></td><td>' . e($r['phone']) . '</td><td class="doc-price">' . e(money($r['amount'])) . '</td><td>' . e($r['method']) . '</td><td>' . status_badge($r['status']) . ($r['result_desc'] ? '<span class="sub">' . e(excerpt($r['result_desc'], 40)) . '</span>' : '') . '</td><td class="nowrap">' . e(fmt_date($r['created_at'], true)) . '</td><td class="mono">' . e($r['hub_reference'] ?: '—') . '</td><td>' . status_badge($r['webhook_status']) . '</td></tr>'; }
        if (!$rows) { echo '<tr><td colspan="9" class="empty-cell">No payments yet.</td></tr>'; } ?></tbody></table></div>
        <?= pager_html($pg, url('admin/payments.php'), array_filter(['q' => $q, 'status' => $status])) ?>
    <?php } ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
