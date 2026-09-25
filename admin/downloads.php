<?php
/** Admin: secure download tokens — track counts/dates/expiry; revoke, regenerate, extend. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';
$admin = require_admin('downloads.manage');
$q = get_str('q', 60); $status = get_str('status', 8); $type = get_str('type', 4); $page = max(1, get_int('page', 1));
$where = ['1=1']; $p = [];
if ($q !== '') { $where[] = '(d.title LIKE ? OR o.order_code LIKE ? OR t.token LIKE ?)'; $l = '%' . like_escape($q) . '%'; array_push($p, $l, $l, $l); }
if ($status === 'active') { $where[] = "t.status = 'active' AND t.expires_at > NOW()"; } elseif ($status === 'expired') { $where[] = "(t.status = 'expired' OR (t.status = 'active' AND t.expires_at <= NOW()))"; } elseif ($status === 'revoked') { $where[] = "t.status = 'revoked'"; }
if (in_array($type, ['free', 'paid'], true)) { $where[] = 't.type = ?'; $p[] = $type; }
$from = 'FROM download_tokens t JOIN documents d ON d.id = t.document_id LEFT JOIN orders o ON o.id = t.order_id WHERE ' . implode(' AND ', $where);
$total = (int)db_val('SELECT COUNT(*) ' . $from, $p);
$pg = paginate($total, $page, 30);
$rows = db_all('SELECT t.*, d.title, o.order_code ' . $from . ' ORDER BY t.id DESC LIMIT ' . (int)$pg['offset'] . ', 30', $p);
$sum = db_row("SELECT COUNT(*) tokens, COALESCE(SUM(download_count), 0) dl, SUM(type = 'paid') paid FROM download_tokens");
$adm = ['title' => 'Downloads', 'active' => 'downloads'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">DOWNLOAD TOKENS</div><div class="panel-body">
    <p class="help" style="margin-bottom:10px;"><?= num($sum['tokens']) ?> tokens · <?= num($sum['paid']) ?> paid · <?= num($sum['dl']) ?> downloads. Tokens are 64-character random secrets — files are never linked directly.</p>
    <div class="admin-bar"><form method="get"><input type="text" name="q" placeholder="Document, order ID or token..." value="<?= e($q) ?>">
        <select name="status" data-autosubmit><option value="">All</option><option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option><option value="expired"<?= $status === 'expired' ? ' selected' : '' ?>>Expired</option><option value="revoked"<?= $status === 'revoked' ? ' selected' : '' ?>>Revoked</option></select>
        <select name="type" data-autosubmit><option value="">Free &amp; paid</option><option value="paid"<?= $type === 'paid' ? ' selected' : '' ?>>Paid</option><option value="free"<?= $type === 'free' ? ' selected' : '' ?>>Free</option></select>
        <button class="btn-classic primary btn-sm" type="submit">FILTER</button></form></div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Document</th><th>Order</th><th>Token</th><th>Downloads</th><th>First</th><th>Last</th><th>Expiry</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $t) {
        $live = $t['status'] === 'active' && strtotime($t['expires_at']) > time(); $st = $t['status'] === 'active' && !$live ? 'expired' : $t['status'];
        echo '<tr><td class="doc-title">' . e(excerpt($t['title'], 40)) . '<span class="sub">' . e($t['type']) . '</span></td><td class="mono">' . ($t['order_code'] ? '<a href="orders.php?id=' . (int)$t['order_id'] . '">' . e($t['order_code']) . '</a>' : '—') . '</td>'
            . '<td class="mono">' . e(substr($t['token'], 0, 10)) . '… <button type="button" class="btn-classic btn-sm" data-copy="' . e(download_url($t['token'])) . '">Copy link</button></td>'
            . '<td class="num">' . (int)$t['download_count'] . ' / ' . ((int)$t['max_downloads'] ?: '∞') . '</td><td class="nowrap">' . e($t['first_download_at'] ? fmt_date($t['first_download_at'], true) : '—') . '</td><td class="nowrap">' . e($t['last_download_at'] ? fmt_date($t['last_download_at'], true) : '—') . '</td><td class="nowrap">' . e(fmt_date($t['expires_at'], true)) . '</td><td>' . status_badge($st) . '</td>'
            . '<td class="actions"><button class="btn-classic btn-sm" data-act="token_extend" data-id="' . $t['id'] . '" data-value="48" title="Extend access by 48 hours">+48h</button><button class="btn-classic btn-sm warning" data-act="token_regen" data-id="' . $t['id'] . '" data-reload="0" title="Revoke and create a new link">NEW</button><button class="btn-classic btn-sm danger" data-act="token_revoke" data-id="' . $t['id'] . '" data-confirm="Revoke this download link?">REVOKE</button></td></tr>';
    } if (!$rows) { echo '<tr><td colspan="9" class="empty-cell">No tokens yet.</td></tr>'; } ?></tbody></table></div>
    <?= pager_html($pg, url('admin/downloads.php'), array_filter(['q' => $q, 'status' => $status, 'type' => $type])) ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
