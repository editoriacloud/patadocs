<?php
/** Admin: reported documents (copyright, incorrect, broken, duplicate, inappropriate, other). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('reports.manage');
if (is_post()) {
    csrf_check(); $id = post_int('id');
    db_exec('UPDATE document_reports SET admin_notes = ?, handled_by = ? WHERE id = ?', [post_str('admin_notes', 2000) ?: null, $_SESSION['admin']['id'], $id]);
    flash('success', 'Notes saved.'); redirect(url('admin/reports.php?' . http_build_query(array_filter(['status' => post_str('fs', 10)]))));
}
$status = get_str('status', 10); $page = max(1, get_int('page', 1));
$where = ['1=1']; $p = [];
if (in_array($status, ['new', 'reviewing', 'resolved', 'dismissed'], true)) { $where[] = 'r.status = ?'; $p[] = $status; }
$total = (int)db_val('SELECT COUNT(*) FROM document_reports r WHERE ' . implode(' AND ', $where), $p);
$pg = paginate($total, $page, 25);
$rows = db_all('SELECT r.*, d.title, d.status AS doc_status FROM document_reports r JOIN documents d ON d.id = r.document_id WHERE ' . implode(' AND ', $where) . ' ORDER BY (r.status = \'new\') DESC, r.id DESC LIMIT ' . (int)$pg['offset'] . ', 25', $p);
$adm = ['title' => 'Document Reports', 'active' => 'reports'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">REPORTED DOCUMENTS</div><div class="panel-body">
    <div class="flex" style="margin-bottom:12px;"><?php foreach (['' => 'All', 'new' => 'New', 'reviewing' => 'Reviewing', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'] as $k => $lab) { echo '<a class="btn-classic btn-sm' . ($status === $k ? ' active-toggle' : '') . '" href="?status=' . $k . '">' . $lab . '</a>'; } ?></div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Document</th><th>Reason</th><th>Details</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r) { echo '<tr><td class="doc-title"><a href="edit-document.php?id=' . (int)$r['document_id'] . '">' . e($r['title']) . '</a><span class="sub">' . status_badge($r['doc_status']) . '</span></td><td><span class="badge b-orange">' . e($r['reason']) . '</span></td><td>' . e($r['message'] ?: '—') . ($r['email'] ? '<span class="sub">' . e($r['email']) . '</span>' : '')
        . '<form method="post" style="margin-top:6px;">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="fs" value="' . e($status) . '"><input type="text" name="admin_notes" placeholder="Admin notes" value="' . e($r['admin_notes']) . '" style="width:100%; padding:6px 8px; font-size:.85rem; background:var(--bg-input); color:var(--text-primary); border:none; box-shadow:inset 2px 2px 0 var(--shadow); border-radius:4px;"></form></td>'
        . '<td>' . status_badge($r['status']) . '</td><td class="nowrap">' . e(fmt_date($r['created_at'], true)) . '</td><td class="actions">'
        . '<button class="btn-classic btn-sm warning" data-act="report_status" data-id="' . $r['id'] . '" data-value="reviewing">REVIEW</button><button class="btn-classic btn-sm success" data-act="report_status" data-id="' . $r['id'] . '" data-value="resolved">RESOLVED</button><button class="btn-classic btn-sm" data-act="report_status" data-id="' . $r['id'] . '" data-value="dismissed">DISMISS</button>'
        . (admin_can('documents.edit') && $r['doc_status'] === 'published' ? '<button class="btn-classic btn-sm danger" data-act="doc_status" data-id="' . $r['document_id'] . '" data-value="draft" data-confirm="Unpublish this document now?">UNPUBLISH DOC</button>' : '') . '</td></tr>'; }
    if (!$rows) { echo '<tr><td colspan="6" class="empty-cell">No reports.</td></tr>'; } ?></tbody></table></div>
    <?= pager_html($pg, url('admin/reports.php'), array_filter(['status' => $status])) ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
