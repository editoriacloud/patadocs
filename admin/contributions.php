<?php
/**
 * Admin: community contributions — review queue + review screen.
 * Flow: Pending → Under review → Approved (creates a DRAFT document) → edit / generate preview → Published.
 * Nothing is ever published automatically.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('contributions.review');

/** Creates a draft document from a contribution (same private file, free, attributed if the contributor agreed). */
function contribution_to_document(array $c): int
{
    if ($c['document_id'] && doc_get((int)$c['document_id'])) { return (int)$c['document_id']; }
    $path = rtrim(PRIVATE_DIR, '/\\') . '/' . basename($c['file_name']);
    if (!is_file($path)) { return 0; }
    $slug = doc_unique_slug(slugify($c['title']), $c['category_id'] ? (int)$c['category_id'] : null);
    $id = db_insert("INSERT INTO documents (title, slug, category_id, description, file_name, original_name, file_ext, file_mime, file_size, file_hash, pages, is_free, price, currency, status, source,
            contribution_id, contributor_name, contributor_badge, show_contributor, author, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, 'draft', 'community', ?, ?, ?, ?, ?, ?)",
        [$c['title'], $slug, $c['category_id'], $c['description'], $c['file_name'], $c['original_name'], $c['file_ext'], $c['file_mime'], $c['file_size'], hash_file('sha256', $path),
         doc_detect_pages($path, $c['file_ext']), setting('currency', 'KES'), $c['id'], $c['contributor_name'], $c['badge'], (int)$c['show_attribution'], $c['source_info'], $_SESSION['admin']['id'] ?? null]);
    tags_save($id, (string)$c['tags']);
    if ($c['metadata_json'] && ($m = json_decode($c['metadata_json'], true)) && is_array($m)) {
        foreach (meta_sanitize($c['category_id'] ? (int)$c['category_id'] : null, $m)['rows'] as $r) { db_exec('INSERT INTO document_meta (document_id, field_id, meta_value) VALUES (?, ?, ?)', [$id, $r[0], $r[1]]); }
    }
    doc_rebuild_search($id);
    if ($c['preview_image'] && is_file(rtrim(PRIVATE_DIR, '/\\') . '/' . basename($c['preview_image']))) { preview_generate($id, [rtrim(PRIVATE_DIR, '/\\') . '/' . basename($c['preview_image'])]); }
    elseif (setting('preview_enabled', '1') === '1') { preview_generate($id); }
    return $id;
}

$id = get_int('id');
if (is_post()) {
    csrf_check();
    $id = post_int('id'); $act = post_str('do', 12);
    $c = db_row('SELECT * FROM contributions WHERE id = ?', [$id]);
    if (!$c) { flash('error', 'Contribution not found.'); redirect(url('admin/contributions.php')); }
    $back = url('admin/contributions.php?id=' . $id);
    $notes = post_str('admin_notes', 3000);
    $me = $_SESSION['admin']['id'];
    db_exec('UPDATE contributions SET admin_notes = ?, show_attribution = ?, badge = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
        [$notes !== '' ? $notes : $c['admin_notes'], post_str('show_attribution', 1) === '1' ? 1 : 0, in_array(post_str('badge', 10), ['none', 'community', 'verified'], true) ? post_str('badge', 10) : 'community', $me, $id]);
    switch ($act) {
        case 'notes': flash('success', 'Notes and attribution saved.'); break;
        case 'review': db_exec("UPDATE contributions SET status = 'under_review' WHERE id = ?", [$id]); flash('success', 'Marked as under review.'); break;
        case 'approve':
            $c = db_row('SELECT * FROM contributions WHERE id = ?', [$id]);
            $doc = contribution_to_document($c);
            if (!$doc) { flash('error', 'Could not create the document (is the uploaded file still on the server?).'); break; }
            db_exec("UPDATE contributions SET status = 'approved', document_id = ? WHERE id = ?", [$doc, $id]);
            log_admin('contribution_approved', 'contribution', $id, $c['title']);
            flash('success', 'Approved. A draft document was created — review it, then publish.');
            redirect(url('admin/edit-document.php?id=' . $doc));
        case 'publish':
            $c = db_row('SELECT * FROM contributions WHERE id = ?', [$id]);
            $doc = contribution_to_document($c);
            $r = $doc ? doc_set_status($doc, 'published') : ['ok' => false, 'message' => 'Could not create the document.'];
            if ($r['ok']) { db_exec("UPDATE contributions SET status = 'published', document_id = ? WHERE id = ?", [$doc, $id]); log_admin('contribution_published', 'contribution', $id, $c['title']); flash('success', 'Published! The document is now live.'); }
            else { flash('error', $r['message'] . ' Open the document to complete it.'); if ($doc) { db_exec("UPDATE contributions SET status = 'approved', document_id = ? WHERE id = ?", [$doc, $id]); redirect(url('admin/edit-document.php?id=' . $doc)); } }
            break;
        case 'reject':
            db_exec("UPDATE contributions SET status = 'rejected' WHERE id = ?", [$id]);
            if (post_str('notify', 1) === '1') { send_mail($c['contributor_email'], 'About your contribution "' . $c['title'] . '"', "Hello " . $c['contributor_name'] . ",\n\nThank you for contributing to " . setting('site_name') . ". Unfortunately we could not publish \"" . $c['title'] . "\"." . ($notes !== '' ? "\n\nReason: " . $notes : '') . "\n\n" . setting('site_name')); }
            log_admin('contribution_rejected', 'contribution', $id, $c['title']); flash('success', 'Contribution rejected.'); break;
        case 'changes':
            db_exec("UPDATE contributions SET status = 'under_review' WHERE id = ?", [$id]);
            $sent = send_mail($c['contributor_email'], 'Changes requested for "' . $c['title'] . '"', "Hello " . $c['contributor_name'] . ",\n\nThank you for your contribution to " . setting('site_name') . ". Before we can publish \"" . $c['title'] . "\" we need a few changes:\n\n" . ($notes !== '' ? $notes : '(see our note)') . "\n\nYou can submit the corrected file again at " . page_url('contribute') . "\n\n" . setting('site_name'));
            log_admin('contribution_changes_requested', 'contribution', $id, $c['title']); flash($sent ? 'success' : 'warn', $sent ? 'Change request emailed to the contributor.' : 'Saved, but the email could not be sent — contact them at ' . $c['contributor_email']); break;
        case 'archive':
            db_exec("UPDATE contributions SET status = 'archived' WHERE id = ?", [$id]); log_admin('contribution_archived', 'contribution', $id, $c['title']); flash('success', 'Archived.'); break;
        case 'preview':
            $c = db_row('SELECT * FROM contributions WHERE id = ?', [$id]);
            if (!$c['document_id']) { flash('warn', 'Approve first — the preview is generated for the draft document.'); break; }
            $r = preview_generate((int)$c['document_id']); flash($r['ok'] ? 'success' : 'error', $r['message']); break;
    }
    redirect($back);
}

// ---- Detail ----------------------------------------------------------------------------------
if ($id > 0) {
    $c = db_row('SELECT c.*, cat.name AS cat_name FROM contributions c LEFT JOIN categories cat ON cat.id = c.category_id WHERE c.id = ?', [$id]);
    if (!$c) { abort_page(404, 'Not found', 'This contribution does not exist.', [['↩ CONTRIBUTIONS', url('admin/contributions.php')]]); }
    $meta = ($c['metadata_json'] && ($m = json_decode($c['metadata_json'], true))) ? $m : [];
    $adm = ['title' => 'Contribution ' . $c['id'], 'active' => 'contributions'];
    include __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="panel"><div class="panel-header orange">REVIEW CONTRIBUTION CON-<?= str_pad((string)$c['id'], 5, '0', STR_PAD_LEFT) ?> · <?= e(strtoupper(str_replace('_', ' ', $c['status']))) ?></div><div class="panel-body">
        <div class="flex" style="margin-bottom:12px;"><a class="btn-classic btn-sm" href="contributions.php">↩ ALL CONTRIBUTIONS</a>
            <a class="btn-classic btn-sm primary" target="_blank" href="file.php?type=contrib&id=<?= (int)$c['id'] ?>">👁 OPEN SUBMITTED FILE</a>
            <?php if ($c['preview_image']) { echo '<a class="btn-classic btn-sm" target="_blank" href="file.php?type=contrib&id=' . (int)$c['id'] . '&f=preview">🖼 SUBMITTED PREVIEW IMAGE</a>'; } ?>
            <?php if ($c['document_id']) { echo '<a class="btn-classic btn-sm success" href="edit-document.php?id=' . (int)$c['document_id'] . '">✏ EDIT DOCUMENT #' . (int)$c['document_id'] . '</a>'; } ?></div>
        <div class="grid-2">
            <div class="gv-wrap"><table class="gv-table kv"><tbody>
                <tr><td>Title</td><td><strong><?= e($c['title']) ?></strong></td></tr><tr><td>Description</td><td><?= nl2br(e($c['description'] ?: '—')) ?></td></tr>
                <tr><td>Category</td><td><?= e($c['cat_name'] ?: '—') ?></td></tr><tr><td>Tags</td><td><?= e($c['tags'] ?: '—') ?></td></tr>
                <tr><td>File</td><td><?= e(strtoupper($c['file_ext'])) ?> · <?= e(fmt_size($c['file_size'])) ?> · <span class="small"><?= e($c['original_name']) ?></span></td></tr>
                <?php foreach ($meta as $fid => $val) { $f = db_row('SELECT label FROM metadata_fields WHERE id = ?', [(int)$fid]); if ($f) { echo '<tr><td>' . e($f['label']) . '</td><td>' . e(str_replace('|', ', ', trim((string)$val, '|'))) . '</td></tr>'; } } ?>
                <tr><td>Source / author</td><td><?= e($c['source_info'] ?: '—') ?></td></tr></tbody></table></div>
            <div class="gv-wrap"><table class="gv-table kv"><tbody>
                <tr><td>Contributor</td><td><?= e($c['contributor_name']) ?></td></tr><tr><td>Email</td><td><a href="mailto:<?= e($c['contributor_email']) ?>"><?= e($c['contributor_email']) ?></a></td></tr>
                <tr><td>Phone</td><td><?= e($c['contributor_phone'] ?: '—') ?></td></tr><tr><td>Submitted</td><td><?= e(fmt_date($c['created_at'], true)) ?> · IP <?= e($c['ip']) ?></td></tr>
                <tr><td>Copyright declaration</td><td><?= $c['copyright_confirmed'] ? '<span class="badge b-green">confirmed</span> ' . e(fmt_date($c['copyright_confirmed_at'], true)) : '<span class="badge b-red">missing</span>' ?></td></tr>
                <tr><td>Wants credit</td><td><?= $c['show_attribution'] ? 'Yes' : 'No' ?></td></tr><tr><td>Reviewed</td><td><?= e($c['reviewed_at'] ? fmt_date($c['reviewed_at'], true) : '—') ?></td></tr></tbody></table></div>
        </div>
        <form method="post" class="pd-form" style="margin-top:16px;"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <div class="form-grid">
                <div class="frow full"><label>Admin notes (also used as the message for “Reject” / “Request changes”)</label><textarea name="admin_notes"><?= e($c['admin_notes']) ?></textarea></div>
                <div class="frow"><span class="flabel">Attribution on the published page</span>
                    <label class="chk"><input type="checkbox" name="show_attribution" value="1" <?= $c['show_attribution'] ? 'checked' : '' ?>><span>Show “Contributed by <?= e($c['contributor_name']) ?>”</span></label></div>
                <div class="frow"><label>Badge</label><select name="badge"><?php foreach (['none' => 'No badge', 'community' => 'Community Contributor', 'verified' => 'Verified Contributor'] as $k => $lab) { echo '<option value="' . $k . '"' . ($c['badge'] === $k ? ' selected' : '') . '>' . e($lab) . '</option>'; } ?></select></div>
            </div>
            <div class="form-actions">
                <button class="btn-classic" name="do" value="notes">💾 SAVE NOTES</button>
                <?php if ($c['status'] === 'pending') { echo '<button class="btn-classic warning" name="do" value="review">🔍 START REVIEW</button>'; } ?>
                <?php if (!in_array($c['status'], ['published', 'rejected', 'archived'], true)) { ?>
                    <button class="btn-classic primary" name="do" value="approve">✅ APPROVE → CREATE DRAFT</button>
                    <button class="btn-classic success" name="do" value="publish" data-confirm="Approve and publish this contribution now?">🚀 APPROVE &amp; PUBLISH</button>
                    <button class="btn-classic warning" name="do" value="changes">✉ REQUEST CHANGES</button>
                    <button class="btn-classic danger" name="do" value="reject" data-confirm="Reject this contribution?">✕ REJECT</button>
                <?php } ?>
                <?php if ($c['document_id']) { echo '<button class="btn-classic" name="do" value="preview">♻ GENERATE PREVIEW</button>'; } ?>
                <?php if ($c['status'] !== 'archived') { echo '<button class="btn-classic" name="do" value="archive" data-confirm="Archive this contribution?">🗄 ARCHIVE</button>'; } ?>
            </div>
            <label class="chk" style="margin-top:8px;"><input type="checkbox" name="notify" value="1"><span>Email the contributor when rejecting</span></label>
        </form>
    </div></div>
    <?php include __DIR__ . '/../includes/admin_footer.php'; exit;
}

// ---- List ---------------------------------------------------------------------------------------
$status = get_str('status', 14); $q = get_str('q', 60); $page = max(1, get_int('page', 1));
$where = ['1=1']; $p = [];
if (in_array($status, ['pending', 'under_review', 'approved', 'rejected', 'published', 'archived'], true)) { $where[] = 'c.status = ?'; $p[] = $status; }
if ($q !== '') { $where[] = '(c.title LIKE ? OR c.contributor_name LIKE ? OR c.contributor_email LIKE ?)'; $l = '%' . like_escape($q) . '%'; array_push($p, $l, $l, $l); }
$total = (int)db_val('SELECT COUNT(*) FROM contributions c WHERE ' . implode(' AND ', $where), $p);
$pg = paginate($total, $page, 25);
$rows = db_all('SELECT c.*, cat.name AS cat_name FROM contributions c LEFT JOIN categories cat ON cat.id = c.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(c.status, \'pending\', \'under_review\', \'approved\') DESC, c.id DESC LIMIT ' . (int)$pg['offset'] . ', 25', $p);
$counts = []; foreach (db_all('SELECT status, COUNT(*) n FROM contributions GROUP BY status') as $r) { $counts[$r['status']] = (int)$r['n']; }
$adm = ['title' => 'Contributions', 'active' => 'contributions'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">COMMUNITY CONTRIBUTIONS</div><div class="panel-body">
    <div class="flex" style="margin-bottom:12px;">
        <?php foreach ([['', 'All'], ['pending', 'Pending'], ['under_review', 'Under review'], ['approved', 'Approved'], ['published', 'Published'], ['rejected', 'Rejected'], ['archived', 'Archived']] as $s) {
            echo '<a class="btn-classic btn-sm' . ($status === $s[0] ? ' active-toggle' : '') . '" href="?status=' . e($s[0]) . '">' . e($s[1]) . ' (' . ($s[0] === '' ? array_sum($counts) : ($counts[$s[0]] ?? 0)) . ')</a>'; } ?></div>
    <div class="admin-bar"><form method="get"><input type="text" name="q" placeholder="Title, contributor, email..." value="<?= e($q) ?>"><input type="hidden" name="status" value="<?= e($status) ?>"><button class="btn-classic primary btn-sm" type="submit">SEARCH</button></form></div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Document</th><th>Contributor</th><th>Category</th><th>File type</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $c) { echo '<tr><td class="doc-title"><a href="?id=' . (int)$c['id'] . '">' . e($c['title']) . '</a><span class="sub">CON-' . str_pad((string)$c['id'], 5, '0', STR_PAD_LEFT) . '</span></td><td>' . e($c['contributor_name']) . '<span class="sub">' . e($c['contributor_email']) . '</span></td><td>' . e($c['cat_name'] ?: '—') . '</td><td><span class="doc-format">' . e(strtoupper($c['file_ext'])) . '</span> ' . e(fmt_size($c['file_size'])) . '</td><td class="nowrap">' . e(fmt_date($c['created_at'], true)) . '</td><td>' . status_badge($c['status']) . '</td><td class="actions"><a class="btn-classic btn-sm primary" href="?id=' . (int)$c['id'] . '">OPEN</a><a class="btn-classic btn-sm" target="_blank" href="file.php?type=contrib&id=' . (int)$c['id'] . '" title="Preview submitted file">👁</a></td></tr>'; }
    if (!$rows) { echo '<tr><td colspan="7" class="empty-cell">No contributions yet.</td></tr>'; } ?></tbody></table></div>
    <?= pager_html($pg, url('admin/contributions.php'), array_filter(['status' => $status, 'q' => $q])) ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
