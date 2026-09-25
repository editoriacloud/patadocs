<?php
/** Admin: document requests (most requested first) + contact messages. */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('requests.manage');
$tab = in_array(get_str('tab', 8), ['most', 'messages'], true) ? get_str('tab', 8) : 'list';
$status = get_str('status', 10); $page = max(1, get_int('page', 1));
$where = ['1=1']; $p = [];
if (in_array($status, ['new', 'reviewing', 'found', 'created', 'rejected'], true)) { $where[] = 'r.status = ?'; $p[] = $status; }
$total = (int)db_val('SELECT COUNT(*) FROM document_requests r WHERE ' . implode(' AND ', $where), $p);
$pg = paginate($total, $page, 30);
$rows = $tab === 'list' ? db_all('SELECT r.*, c.name AS cat_name, (SELECT COUNT(*) FROM document_requests x WHERE x.norm_title = r.norm_title) AS same FROM document_requests r LEFT JOIN categories c ON c.id = r.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id DESC LIMIT ' . (int)$pg['offset'] . ', 30', $p) : [];
$most = $tab === 'most' ? db_all("SELECT MAX(title) t, norm_title, COUNT(*) n, MAX(created_at) last, SUM(status IN ('new','reviewing')) open_n, MIN(id) first_id FROM document_requests GROUP BY norm_title ORDER BY open_n DESC, n DESC LIMIT 50") : [];
$msgs = $tab === 'messages' ? db_all('SELECT * FROM contact_messages ORDER BY (status = \'new\') DESC, id DESC LIMIT 100') : [];
$newMsgs = (int)db_val("SELECT COUNT(*) FROM contact_messages WHERE status = 'new'");
$adm = ['title' => 'Requests & Messages', 'active' => 'requests'];
include __DIR__ . '/../includes/admin_header.php';
$sel = function ($id, $cur) { $h = '<select data-status-select data-id="' . (int)$id . '" style="padding:4px 6px; font-size:.85rem; background:var(--bg-input); color:var(--text-primary); border:none; box-shadow:inset 2px 2px 0 var(--shadow); border-radius:4px;">'; foreach (['new', 'reviewing', 'found', 'created', 'rejected'] as $s) { $h .= '<option value="' . $s . '"' . ($cur === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>'; } return $h . '</select>'; };
?>
<div class="panel"><div class="panel-header">DOCUMENT REQUESTS &amp; MESSAGES</div><div class="panel-body">
    <div class="flex" style="margin-bottom:12px;"><a class="btn-classic btn-sm<?= $tab === 'list' ? ' active-toggle' : '' ?>" href="?tab=list">All requests</a><a class="btn-classic btn-sm<?= $tab === 'most' ? ' active-toggle' : '' ?>" href="?tab=most">Most requested</a><a class="btn-classic btn-sm<?= $tab === 'messages' ? ' active-toggle' : '' ?>" href="?tab=messages">Contact messages<?= $newMsgs ? ' (' . $newMsgs . ' new)' : '' ?></a></div>
    <?php if ($tab === 'list') { ?>
        <div class="flex" style="margin-bottom:10px;"><?php foreach (['' => 'All', 'new' => 'New', 'reviewing' => 'Reviewing', 'found' => 'Found', 'created' => 'Created', 'rejected' => 'Rejected'] as $k => $lab) { echo '<a class="btn-classic btn-sm' . ($status === $k ? ' active-toggle' : '') . '" href="?tab=list&status=' . $k . '">' . $lab . '</a>'; } ?></div>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Request</th><th>Category</th><th>Contact</th><th>Same asked</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($rows as $r) { echo '<tr><td class="doc-title">' . e($r['title']) . ($r['description'] ? '<span class="sub">' . e(excerpt($r['description'], 90)) . '</span>' : '') . '</td><td>' . e($r['cat_name'] ?: '—') . '</td><td class="small">' . e(trim(($r['phone'] ?: '') . ' ' . ($r['email'] ?: '')) ?: '—') . '</td><td class="num">' . (int)$r['same'] . '×</td><td>' . $sel($r['id'], $r['status']) . '</td><td class="nowrap">' . e(fmt_date($r['created_at'])) . '</td><td class="actions"><a class="btn-classic btn-sm success" href="upload.php?title=' . rawurlencode($r['title']) . '&request=' . (int)$r['id'] . '">CREATE</a><a class="btn-classic btn-sm" target="_blank" href="' . e(page_url('search', 'q=' . rawurlencode($r['title']))) . '" title="Search the site">🔍</a></td></tr>'; }
        if (!$rows) { echo '<tr><td colspan="7" class="empty-cell">No requests.</td></tr>'; } ?></tbody></table></div>
        <?= pager_html($pg, url('admin/requests.php'), ['tab' => 'list'] + array_filter(['status' => $status])) ?>
    <?php } elseif ($tab === 'most') { ?>
        <p class="help" style="margin-bottom:10px;">Similar requests are grouped. Use this list to plan which documents to create next.</p>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Most requested document</th><th>Requests</th><th>Still open</th><th>Last asked</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($most as $m) { echo '<tr><td class="doc-title">' . e($m['t']) . '</td><td class="num"><strong>' . (int)$m['n'] . '</strong></td><td class="num">' . (int)$m['open_n'] . '</td><td class="nowrap">' . e(fmt_date($m['last'])) . '</td><td><a class="btn-classic btn-sm success" href="upload.php?title=' . rawurlencode($m['t']) . '&request=' . (int)$m['first_id'] . '">CREATE DOCUMENT</a></td></tr>'; }
        if (!$most) { echo '<tr><td colspan="5" class="empty-cell">No requests yet.</td></tr>'; } ?></tbody></table></div>
    <?php } else { ?>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>From</th><th>Message</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($msgs as $m) { echo '<tr><td>' . e($m['name']) . '<span class="sub"><a href="mailto:' . e($m['email']) . '">' . e($m['email']) . '</a></span></td><td>' . nl2br(e($m['message'])) . '</td><td>' . status_badge($m['status']) . '</td><td class="nowrap">' . e(fmt_date($m['created_at'], true)) . '</td><td class="actions"><button class="btn-classic btn-sm" data-act="message_status" data-id="' . $m['id'] . '" data-value="read">READ</button><button class="btn-classic btn-sm" data-act="message_status" data-id="' . $m['id'] . '" data-value="archived">ARCHIVE</button></td></tr>'; }
        if (!$msgs) { echo '<tr><td colspan="5" class="empty-cell">No messages.</td></tr>'; } ?></tbody></table></div>
    <?php } ?>
</div></div>
<script>document.addEventListener('change',function(e){var s=e.target.closest('[data-status-select]');if(!s)return;PDUI.api(PD.base+'ajax/admin.php',{data:{action:'request_status',id:s.getAttribute('data-id'),value:s.value}}).then(function(r){if(!r.ok)PDUI.showMessage(r.message||'Failed','ERROR');});});</script>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
