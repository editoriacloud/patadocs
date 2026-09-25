<?php
/** Admin: categories — unlimited-depth tree; create, edit, move (change parent), sort, feature, hide, delete. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('categories.manage');

$back = url('admin/categories.php');
if (is_post()) {
    csrf_check();
    $act = post_str('do', 12); $id = post_int('id');
    if ($act === 'delete') {
        $c = cat_get($id);
        if (!$c) { flash('error', 'Category not found.'); }
        elseif (cat_children($id, false)) { flash('error', 'Move or delete the sub-categories first.'); }
        elseif ((int)db_val('SELECT COUNT(*) FROM documents WHERE category_id = ?', [$id]) > 0) { flash('error', 'This category still contains documents. Move them to another category first.'); }
        else { db_exec('DELETE FROM categories WHERE id = ?', [$id]); log_admin('category_deleted', 'category', $id, $c['name']); flash('success', 'Category deleted.'); }
        redirect($back);
    }
    if ($act === 'toggle') { db_exec("UPDATE categories SET featured = 1 - featured WHERE id = ?", [$id]); redirect($back); }
    if ($act === 'visibility') { db_exec("UPDATE categories SET status = IF(status = 'active', 'hidden', 'active') WHERE id = ?", [$id]); redirect($back); }
    if ($act === 'up' || $act === 'down') {
        $c = cat_get($id);
        if ($c) {
            $sibs = array_values(cat_children($c['parent_id'] !== null ? (int)$c['parent_id'] : null, false));
            $i = array_search($id, array_map(function ($x) { return (int)$x['id']; }, $sibs), true);
            $j = $act === 'up' ? $i - 1 : $i + 1;
            if ($i !== false && isset($sibs[$j])) { $t = $sibs[$i]; $sibs[$i] = $sibs[$j]; $sibs[$j] = $t; }
            foreach ($sibs as $n => $s) { db_exec('UPDATE categories SET sort_order = ? WHERE id = ?', [($n + 1) * 10, $s['id']]); }
        }
        redirect($back);
    }
    if ($act === 'save') {
        $name = post_str('name', 150); $parent = post_int('parent'); $slug = slugify(post_str('slug', 120) ?: $name, 100);
        $errors = [];
        if (mb_strlen($name) < 2) { $errors[] = 'Enter a category name.'; }
        if ($parent && !cat_get($parent)) { $errors[] = 'The parent category does not exist.'; }
        if ($id && $parent && in_array($parent, cat_descendant_ids($id), true)) { $errors[] = 'A category cannot be moved inside itself or its own sub-categories.'; }
        if (!$parent && in_array($slug, reserved_slugs(), true)) { $errors[] = '"' . $slug . '" is reserved for a system page. Choose another slug for a top-level category.'; }
        $pp = $parent ? cat_get($parent) : null; $path = ($pp ? $pp['path'] . '/' : '') . $slug;
        if (strlen($path) > 190) { $errors[] = 'The URL path is too long — shorten the slug or the nesting.'; }
        $clash = db_val('SELECT id FROM categories WHERE path = ? AND id <> ?', [$path, $id]);
        if ($clash) { $errors[] = 'Another category already uses the URL /' . $path . '/.'; }
        if ($pp && db_val('SELECT id FROM documents WHERE slug = ? AND category_id = ? LIMIT 1', [$slug, $pp['id']])) { $errors[] = 'A document in the parent category already uses this slug.'; }
        $image = null;
        if (!empty($_FILES['image']) && ($_FILES['image']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            $chk = upload_check($_FILES['image'], allowed_exts(), 2 * 1048576, true);
            if (!$chk['ok']) { $errors[] = 'Image: ' . $chk['error']; }
            else { $dir = rtrim(UPLOAD_DIR, '/\\') . '/categories'; $stored = upload_save($_FILES['image']['tmp_name'], $chk['ext'], $dir); if ($stored) { @chmod($dir . '/' . $stored, 0644); $image = 'uploads/categories/' . $stored; } }
        }
        if ($errors) { foreach ($errors as $er) { flash('error', $er); } redirect($back . ($id ? '?edit=' . $id : '?new=1')); }
        $f = [$name, $slug, $parent ?: null, post_str('description', 3000) ?: null, post_str('icon', 12) ?: null, post_str('seo_title', 190) ?: null,
              post_str('meta_description', 320) ?: null, post_str('status', 6) === 'hidden' ? 'hidden' : 'active', post_str('featured', 1) === '1' ? 1 : 0, post_int('sort_order')];
        if ($id) {
            db_exec('UPDATE categories SET name = ?, slug = ?, parent_id = ?, description = ?, icon = ?, seo_title = ?, meta_description = ?, status = ?, featured = ?, sort_order = ? WHERE id = ?', array_merge($f, [$id]));
            if ($image) { db_exec('UPDATE categories SET image = ? WHERE id = ?', [$image, $id]); }
        } else {
            $id = db_insert("INSERT INTO categories (name, slug, parent_id, description, icon, seo_title, meta_description, status, featured, sort_order, path, depth) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)", array_merge($f, [$path]));
            if ($image) { db_exec('UPDATE categories SET image = ? WHERE id = ?', [$image, $id]); }
        }
        cat_rebuild($id);
        foreach (cat_descendant_ids($id) as $cid) { foreach (db_all('SELECT id FROM documents WHERE category_id = ?', [$cid]) as $d) { doc_rebuild_search((int)$d['id']); } }
        log_admin('category_saved', 'category', $id, $name);
        flash('success', 'Category saved. Its address is /' . cat_get($id)['path'] . '/');
        redirect($back);
    }
}

$edit = get_int('edit') ? cat_get(get_int('edit')) : null;
$showForm = $edit !== null || get_str('new', 1) === '1' || get_int('parent') > 0;
$counts = cat_counts(); $direct = [];
foreach (db_all('SELECT category_id, COUNT(*) n FROM documents WHERE category_id IS NOT NULL GROUP BY category_id') as $r) { $direct[(int)$r['category_id']] = (int)$r['n']; }
$adm = ['title' => 'Categories', 'active' => 'categories'];
include __DIR__ . '/../includes/admin_header.php';
$row = $edit ?: ['id' => 0, 'name' => '', 'slug' => '', 'parent_id' => get_int('parent') ?: null, 'description' => '', 'icon' => '', 'seo_title' => '', 'meta_description' => '', 'status' => 'active', 'featured' => 0, 'sort_order' => 0, 'image' => null];
$walk = function ($nodes) use (&$walk, $counts, $direct) {
    $h = '<ul>';
    foreach ($nodes as $n) {
        $id = (int)$n['id'];
        $h .= '<li><div class="node"><span class="nm">' . e(($n['icon'] ? $n['icon'] . ' ' : '') . $n['name']) . '</span>'
            . '<span class="meta">/' . e($n['path']) . '/ · ' . (int)($direct[$id] ?? 0) . ' doc(s)' . (($counts[$id] ?? 0) > ($direct[$id] ?? 0) ? ' (' . (int)$counts[$id] . ' incl. sub)' : '') . '</span>'
            . ($n['featured'] ? '<span class="badge b-blue">featured</span>' : '') . ($n['status'] === 'hidden' ? '<span class="badge b-gray">hidden</span>' : '') . '<span class="grow"></span>'
            . '<form method="post" style="display:inline-flex; gap:4px; margin:0;">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button class="btn-classic btn-sm" name="do" value="up" title="Move up">▲</button><button class="btn-classic btn-sm" name="do" value="down" title="Move down">▼</button>'
            . '<button class="btn-classic btn-sm" name="do" value="toggle" title="Toggle featured">⭐</button><button class="btn-classic btn-sm" name="do" value="visibility" title="Show / hide">👁</button>'
            . '<a class="btn-classic btn-sm success" href="?parent=' . $id . '" title="Add sub-category">＋</a><a class="btn-classic btn-sm primary" href="?edit=' . $id . '">EDIT</a>'
            . '<button class="btn-classic btn-sm danger" name="do" value="delete" data-confirm="Delete the category “' . e($n['name']) . '”?">🗑</button></form></div>';
        if ($n['children']) { $h .= $walk($n['children']); }
        $h .= '</li>';
    }
    return $h . '</ul>';
};
?>
<?php if ($showForm) { ?>
<div class="panel">
    <div class="panel-header orange"><?= $edit ? 'EDIT CATEGORY' : 'NEW CATEGORY' ?></div>
    <div class="panel-body">
        <form method="post" enctype="multipart/form-data" class="pd-form">
            <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <div class="form-grid">
                <div class="frow"><label>Name <span class="req">*</span></label><input type="text" name="name" maxlength="150" required value="<?= e($row['name']) ?>"></div>
                <div class="frow"><label>Slug (URL)</label><input type="text" name="slug" maxlength="120" value="<?= e($row['slug']) ?>" placeholder="auto from name"></div>
                <div class="frow"><label>Parent category (move)</label><select name="parent"><?= cat_options_html((int)($row['parent_id'] ?? 0), (int)$row['id'], false, '— Top level —') ?></select></div>
                <div class="frow"><label>Icon (emoji)</label><input type="text" name="icon" maxlength="12" value="<?= e($row['icon']) ?>" placeholder="📚"></div>
                <div class="frow full"><label>Description (shown on the category page — write useful text for Google)</label><textarea name="description" style="min-height:110px;"><?= e($row['description']) ?></textarea></div>
                <div class="frow"><label>SEO title</label><input type="text" name="seo_title" maxlength="190" value="<?= e($row['seo_title']) ?>"></div>
                <div class="frow"><label>Meta description</label><input type="text" name="meta_description" maxlength="320" value="<?= e($row['meta_description']) ?>"></div>
                <div class="frow"><label>Image (optional, ≤ 2 MB)</label><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp"><?php if ($row['image']) { echo '<span class="help">Current: ' . e($row['image']) . '</span>'; } ?></div>
                <div class="frow"><label>Sort order</label><input type="number" name="sort_order" value="<?= (int)$row['sort_order'] ?>"></div>
                <div class="frow"><label>Status</label><select name="status"><option value="active"<?= $row['status'] === 'active' ? ' selected' : '' ?>>Active</option><option value="hidden"<?= $row['status'] === 'hidden' ? ' selected' : '' ?>>Hidden</option></select></div>
                <div class="frow"><span class="flabel">Homepage</span><label class="chk"><input type="checkbox" name="featured" value="1" <?= $row['featured'] ? 'checked' : '' ?>><span>⭐ Featured category</span></label></div>
            </div>
            <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE CATEGORY</button><a class="btn-classic" href="categories.php">CANCEL</a></div>
        </form>
    </div>
</div>
<?php } ?>
<div class="panel">
    <div class="panel-header">CATEGORY TREE</div>
    <div class="panel-body">
        <div class="flex" style="margin-bottom:12px;"><a class="btn-classic success" href="?new=1">＋ NEW TOP-LEVEL CATEGORY</a><span class="help">Use ＋ on a row to add a sub-category. Depth is unlimited. Metadata fields attach to a category and are inherited by all its sub-categories.</span></div>
        <div class="tree"><?= ($t = cat_tree(null, false)) ? $walk($t) : '<div class="alert alert-info">No categories yet.</div>' ?></div>
    </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
