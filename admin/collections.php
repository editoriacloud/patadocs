<?php
/** Admin: collections (bundles) — free or paid, sold as one M-Pesa payment; documents ordered by hand. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('collections.manage');
$back = url('admin/collections.php');

if (is_post()) {
    csrf_check(); $act = post_str('do', 8); $id = post_int('id');
    if ($act === 'delete') { db_exec('DELETE FROM collections WHERE id = ?', [$id]); log_admin('collection_deleted', 'collection', $id); flash('success', 'Collection deleted.'); redirect($back); }
    if ($act === 'save') {
        $title = post_str('title', 255); $isFree = post_str('is_free', 1) === '1' ? 1 : 0; $price = $isFree ? 0.0 : (float)post_str('price', 12);
        $status = in_array(post_str('status', 10), ['draft', 'published', 'archived'], true) ? post_str('status', 10) : 'draft';
        $docIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['doc_ids'] ?? [])))));
        $errors = [];
        if (mb_strlen($title) < 3) { $errors[] = 'Enter a title.'; }
        if (!$isFree && $price <= 0) { $errors[] = 'A paid collection needs a price above zero.'; }
        if ($status === 'published' && !$docIds) { $errors[] = 'Add at least one document before publishing.'; }
        $cover = null;
        if (!empty($_FILES['cover']) && ($_FILES['cover']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            $chk = upload_check($_FILES['cover'], allowed_exts(), 3 * 1048576, true);
            if (!$chk['ok']) { $errors[] = 'Cover: ' . $chk['error']; } else { $dir = rtrim(UPLOAD_DIR, '/\\') . '/collections'; $st = upload_save($_FILES['cover']['tmp_name'], $chk['ext'], $dir); if ($st) { @chmod($dir . '/' . $st, 0644); $cover = 'uploads/collections/' . $st; } }
        }
        if ($errors) { foreach ($errors as $er) { flash('error', $er); } redirect($back . ($id ? '?edit=' . $id : '?new=1')); }
        $slug = unique_slug('collections', 'slug', slugify(post_str('slug', 120) ?: $title), $id);
        $vals = [$title, $slug, post_str('description', 5000) ?: null, $isFree, $price, post_str('seo_title', 190) ?: null, post_str('meta_description', 320) ?: null, post_str('featured', 1) === '1' ? 1 : 0, $status, post_int('sort_order')];
        if ($id) { db_exec('UPDATE collections SET title = ?, slug = ?, description = ?, is_free = ?, price = ?, seo_title = ?, meta_description = ?, featured = ?, status = ?, sort_order = ? WHERE id = ?', array_merge($vals, [$id])); }
        else { $id = db_insert('INSERT INTO collections (title, slug, description, is_free, price, seo_title, meta_description, featured, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals); }
        if ($cover) { db_exec('UPDATE collections SET cover_image = ? WHERE id = ?', [$cover, $id]); }
        db_exec('DELETE FROM collection_documents WHERE collection_id = ?', [$id]);
        foreach ($docIds as $i => $did) { db_exec('INSERT IGNORE INTO collection_documents (collection_id, document_id, sort_order) VALUES (?, ?, ?)', [$id, $did, $i + 1]); }
        log_admin('collection_saved', 'collection', $id, $title); flash('success', 'Collection saved.'); redirect($back);
    }
}
$edit = get_int('edit') ? db_row('SELECT * FROM collections WHERE id = ?', [get_int('edit')]) : null;
$showForm = $edit !== null || get_str('new', 1) === '1';
$row = $edit ?: ['id' => 0, 'title' => '', 'slug' => '', 'description' => '', 'cover_image' => null, 'is_free' => 0, 'price' => '', 'seo_title' => '', 'meta_description' => '', 'featured' => 0, 'status' => 'draft', 'sort_order' => 0];
$colDocs = $edit ? db_all('SELECT d.id, d.title FROM collection_documents cd JOIN documents d ON d.id = cd.document_id WHERE cd.collection_id = ? ORDER BY cd.sort_order', [$edit['id']]) : [];
$list = db_all('SELECT c.*, (SELECT COUNT(*) FROM collection_documents cd WHERE cd.collection_id = c.id) AS n FROM collections c ORDER BY c.id DESC');
$adm = ['title' => 'Collections', 'active' => 'collections', 'foot_extra' => '<script>window.PD_COL_DOCS = ' . json_encode($colDocs, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'];
if ($showForm) { $adm['foot_extra'] = '<script>window.PD_COL_DOCS = ' . json_encode($colDocs, JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>'; }
include __DIR__ . '/../includes/admin_header.php';
?>
<?php if ($showForm) { ?>
<div class="panel"><div class="panel-header orange"><?= $edit ? 'EDIT COLLECTION' : 'NEW COLLECTION' ?></div><div class="panel-body">
    <form method="post" enctype="multipart/form-data" class="pd-form"><?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <div class="form-grid">
            <div class="frow full"><label>Title <span class="req">*</span></label><input type="text" name="title" maxlength="255" required value="<?= e($row['title']) ?>" placeholder="GRADE 7 MATHEMATICS COMPLETE PACK"></div>
            <div class="frow"><label>Slug</label><input type="text" name="slug" maxlength="120" value="<?= e($row['slug']) ?>"></div>
            <div class="frow"><label>Cover image (optional, ≤ 3 MB)</label><input type="file" name="cover" accept=".jpg,.jpeg,.png,.webp"><?= $row['cover_image'] ? '<span class="help">Current: ' . e($row['cover_image']) . '</span>' : '' ?></div>
            <div class="frow full"><label>Description</label><textarea name="description" style="min-height:120px;"><?= e($row['description']) ?></textarea></div>
            <div class="frow"><span class="flabel">Access</span><div class="chk-group"><label class="chk"><input type="radio" name="is_free" value="1" <?= $row['is_free'] ? 'checked' : '' ?>><span>FREE bundle</span></label><label class="chk"><input type="radio" name="is_free" value="0" <?= !$row['is_free'] ? 'checked' : '' ?>><span>PAID bundle</span></label></div></div>
            <div class="frow"><label>Bundle price (<?= e(setting('currency')) ?>)</label><input type="number" name="price" min="0" step="1" value="<?= e($row['price'] > 0 ? (float)$row['price'] : '') ?>"></div>
            <div class="frow"><label>SEO title</label><input type="text" name="seo_title" maxlength="190" value="<?= e($row['seo_title']) ?>"></div>
            <div class="frow"><label>Meta description</label><input type="text" name="meta_description" maxlength="320" value="<?= e($row['meta_description']) ?>"></div>
            <div class="frow"><label>Status</label><select name="status"><?php foreach (['draft', 'published', 'archived'] as $s) { echo '<option value="' . $s . '"' . ($row['status'] === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>'; } ?></select></div>
            <div class="frow"><label>Sort order</label><input type="number" name="sort_order" value="<?= (int)$row['sort_order'] ?>"><label class="chk" style="margin-top:8px;"><input type="checkbox" name="featured" value="1" <?= $row['featured'] ? 'checked' : '' ?>><span>⭐ Featured on the homepage</span></label></div>
        </div>
        <div class="section-title">DOCUMENTS IN THIS BUNDLE</div>
        <div class="frow" style="max-width:560px;"><label for="colSearch">Search published documents to add</label><input type="text" id="colSearch" placeholder="Type a title..." autocomplete="off"></div>
        <div id="colResults" class="tree" style="margin:8px 0 14px;"></div>
        <div id="colDocs" class="tree"></div>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE COLLECTION</button><a class="btn-classic" href="collections.php">CANCEL</a></div>
    </form></div></div>
<?php } ?>
<div class="panel"><div class="panel-header">COLLECTIONS</div><div class="panel-body">
    <div class="flex" style="margin-bottom:12px;"><a class="btn-classic success" href="?new=1">＋ NEW COLLECTION</a></div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Collection</th><th>Documents</th><th>Price</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($list as $c) { echo '<tr><td class="doc-title">' . e($c['title']) . ($c['featured'] ? ' ⭐' : '') . '<span class="sub">/collection/' . e($c['slug']) . '</span></td><td class="num">' . (int)$c['n'] . '</td><td class="doc-price">' . ($c['is_free'] ? 'FREE' : e(money($c['price']))) . '</td><td>' . status_badge($c['status']) . '</td><td class="actions"><a class="btn-classic btn-sm primary" href="?edit=' . (int)$c['id'] . '">EDIT</a><a class="btn-classic btn-sm" target="_blank" href="' . e(collection_url($c)) . '">👁</a><form method="post" style="display:inline;">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$c['id'] . '"><button class="btn-classic btn-sm danger" name="do" value="delete" data-confirm="Delete this collection? (Documents are not deleted.)">🗑</button></form></td></tr>'; }
    if (!$list) { echo '<tr><td colspan="5" class="empty-cell">No collections yet.</td></tr>'; } ?></tbody></table></div>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
