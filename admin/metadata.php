<?php
/** Admin: category-specific metadata fields (text, textarea, number, year, dropdown, checkbox, radio). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('metadata.manage');

$back = url('admin/metadata.php');
if (is_post()) {
    csrf_check();
    $act = post_str('do', 10); $id = post_int('id');
    if ($act === 'delete') {
        $f = db_row('SELECT * FROM metadata_fields WHERE id = ?', [$id]);
        if ($f) { db_exec('DELETE FROM metadata_fields WHERE id = ?', [$id]); log_admin('metadata_deleted', 'metadata', $id, $f['label']); docs_rebuild_all(); flash('success', 'Field deleted (its values were removed from documents).'); }
        redirect($back);
    }
    if ($act === 'save') {
        $label = post_str('label', 100); $type = post_str('field_type', 10); $catId = post_int('category') ?: null;
        $key = preg_replace('/[^a-z0-9_]+/', '_', strtolower(post_str('field_key', 60) ?: slugify($label))); $key = trim($key, '_') ?: 'field';
        $errors = [];
        if (mb_strlen($label) < 2) { $errors[] = 'Enter a label.'; }
        if (!in_array($type, ['text', 'textarea', 'number', 'year', 'dropdown', 'checkbox', 'radio'], true)) { $errors[] = 'Choose a field type.'; }
        if ($catId && !cat_get($catId)) { $errors[] = 'Category not found.'; }
        $opts = implode("\n", array_filter(array_map('trim', preg_split('/\R/u', (string)($_POST['options'] ?? ''))), 'strlen'));
        if (in_array($type, ['dropdown', 'checkbox', 'radio'], true) && $opts === '') { $errors[] = 'Add at least one option (one per line).'; }
        if (db_val('SELECT id FROM metadata_fields WHERE field_key = ? AND category_id <=> ? AND id <> ?', [$key, $catId, $id])) { $errors[] = 'This category already has a field with the key "' . $key . '".'; }
        if ($errors) { foreach ($errors as $er) { flash('error', $er); } redirect($back . ($id ? '?edit=' . $id : '?new=1')); }
        $vals = [$catId, $label, $key, $type, in_array($type, ['dropdown', 'checkbox', 'radio'], true) ? $opts : null,
            post_str('is_required', 1) === '1' ? 1 : 0, post_str('is_searchable', 1) === '1' ? 1 : 0, post_str('is_filterable', 1) === '1' ? 1 : 0, post_str('is_seo', 1) === '1' ? 1 : 0,
            post_int('sort_order'), post_str('status', 6) === 'hidden' ? 'hidden' : 'active'];
        if ($id) { db_exec('UPDATE metadata_fields SET category_id = ?, label = ?, field_key = ?, field_type = ?, options = ?, is_required = ?, is_searchable = ?, is_filterable = ?, is_seo = ?, sort_order = ?, status = ? WHERE id = ?', array_merge($vals, [$id])); }
        else { $id = db_insert('INSERT INTO metadata_fields (category_id, label, field_key, field_type, options, is_required, is_searchable, is_filterable, is_seo, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals); }
        docs_rebuild_all();
        log_admin('metadata_saved', 'metadata', $id, $label); flash('success', 'Field saved.'); redirect($back);
    }
}
$edit = get_int('edit') ? db_row('SELECT * FROM metadata_fields WHERE id = ?', [get_int('edit')]) : null;
$showForm = $edit !== null || get_str('new', 1) === '1';
$fields = db_all('SELECT f.*, c.name AS cat_name, c.path AS cat_path, (SELECT COUNT(*) FROM document_meta m WHERE m.field_id = f.id) AS used FROM metadata_fields f LEFT JOIN categories c ON c.id = f.category_id ORDER BY (f.category_id IS NULL) DESC, c.path, f.sort_order, f.id');
$row = $edit ?: ['id' => 0, 'category_id' => get_int('cat') ?: null, 'label' => '', 'field_key' => '', 'field_type' => 'text', 'options' => '', 'is_required' => 0, 'is_searchable' => 1, 'is_filterable' => 0, 'is_seo' => 0, 'sort_order' => 0, 'status' => 'active'];
$adm = ['title' => 'Metadata Fields', 'active' => 'metadata'];
include __DIR__ . '/../includes/admin_header.php';
?>
<?php if ($showForm) { ?>
<div class="panel">
    <div class="panel-header orange"><?= $edit ? 'EDIT FIELD' : 'NEW METADATA FIELD' ?></div>
    <div class="panel-body">
        <form method="post" class="pd-form">
            <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <div class="form-grid">
                <div class="frow"><label>Applies to category</label><select name="category"><?= cat_options_html((int)($row['category_id'] ?? 0), 0, false, '— All categories (global) —') ?></select><span class="help">Also applies to every sub-category.</span></div>
                <div class="frow"><label>Label <span class="req">*</span></label><input type="text" name="label" maxlength="100" required value="<?= e($row['label']) ?>" placeholder="e.g. Grade"></div>
                <div class="frow"><label>Key (used in filters)</label><input type="text" name="field_key" maxlength="60" value="<?= e($row['field_key']) ?>" placeholder="auto from label, e.g. grade"></div>
                <div class="frow"><label>Field type</label><select name="field_type"><?php foreach (['text' => 'Text', 'textarea' => 'Textarea', 'number' => 'Number', 'year' => 'Year', 'dropdown' => 'Dropdown', 'checkbox' => 'Checkbox (multiple)', 'radio' => 'Radio (one)'] as $k => $lab) { echo '<option value="' . $k . '"' . ($row['field_type'] === $k ? ' selected' : '') . '>' . e($lab) . '</option>'; } ?></select></div>
                <div class="frow full"><label>Options (dropdown / checkbox / radio — one per line)</label><textarea name="options" style="min-height:120px;"><?= e($row['options']) ?></textarea></div>
                <div class="frow"><span class="flabel">Behaviour</span>
                    <label class="chk"><input type="checkbox" name="is_required" value="1" <?= $row['is_required'] ? 'checked' : '' ?>><span>Required (to publish)</span></label>
                    <label class="chk"><input type="checkbox" name="is_searchable" value="1" <?= $row['is_searchable'] ? 'checked' : '' ?>><span>Searchable</span></label>
                    <label class="chk"><input type="checkbox" name="is_filterable" value="1" <?= $row['is_filterable'] ? 'checked' : '' ?>><span>Filterable (search filter)</span></label>
                    <label class="chk"><input type="checkbox" name="is_seo" value="1" <?= $row['is_seo'] ? 'checked' : '' ?>><span>SEO relevant (used in suggestions)</span></label></div>
                <div class="frow"><label>Sort order</label><input type="number" name="sort_order" value="<?= (int)$row['sort_order'] ?>"><label style="margin-top:10px;">Status</label><select name="status"><option value="active"<?= $row['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="hidden"<?= $row['status'] === 'hidden' ? ' selected' : '' ?>>Hidden</option></select></div>
            </div>
            <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE FIELD</button><a class="btn-classic" href="metadata.php">CANCEL</a></div>
        </form>
    </div>
</div>
<?php } ?>
<div class="panel">
    <div class="panel-header">METADATA FIELDS</div>
    <div class="panel-body">
        <div class="flex" style="margin-bottom:12px;"><a class="btn-classic success" href="?new=1">＋ NEW FIELD</a><span class="help">Different categories can have different fields (Education: Grade, Subject, Term… · Business: Industry… · Government: Agency, County…).</span></div>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Category</th><th>Label</th><th>Key</th><th>Type</th><th>Flags</th><th>Used</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($fields as $f) { ?>
            <tr><td><?= $f['category_id'] ? e($f['cat_name']) . '<span class="sub">/' . e($f['cat_path']) . '/</span>' : '<em>All categories</em>' ?></td>
                <td class="doc-title"><?= e($f['label']) ?><?= $f['status'] === 'hidden' ? ' <span class="badge b-gray">hidden</span>' : '' ?></td><td class="mono"><?= e($f['field_key']) ?></td><td><?= e($f['field_type']) ?></td>
                <td><?= $f['is_required'] ? '<span class="badge b-red">required</span> ' : '' ?><?= $f['is_searchable'] ? '<span class="badge b-blue">search</span> ' : '' ?><?= $f['is_filterable'] ? '<span class="badge b-green">filter</span> ' : '' ?><?= $f['is_seo'] ? '<span class="badge b-orange">seo</span>' : '' ?></td>
                <td class="num"><?= num($f['used']) ?></td>
                <td class="actions"><a class="btn-classic btn-sm primary" href="?edit=<?= (int)$f['id'] ?>">EDIT</a>
                    <form method="post" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn-classic btn-sm danger" name="do" value="delete" data-confirm="Delete this field and its <?= (int)$f['used'] ?> stored value(s)?">🗑</button></form></td></tr>
        <?php } if (!$fields) { echo '<tr><td colspan="7" class="empty-cell">No metadata fields yet.</td></tr>'; } ?>
        </tbody></table></div>
    </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
