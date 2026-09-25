<?php
/** Admin: blog categories (with their own SEO title / meta description) and tags (rename, merge, delete). */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('blog.publish');
$back = url('admin/blog-taxonomy.php');

if (is_post()) {
    csrf_check(); $do = post_str('do', 12); $id = post_int('id');
    if ($do === 'cat_save') {
        $name = post_str('name', 120);
        if (mb_strlen($name) < 2) { flash('error', 'Enter a category name.'); redirect($back . ($id ? '?edit=' . $id : '')); }
        $slug = unique_slug('blog_categories', 'slug', slugify(post_str('slug', 140) ?: $name, 120), $id);
        $vals = [$name, $slug, post_str('description', 2000) ?: null, post_str('seo_title', 190) ?: null, post_str('meta_description', 320) ?: null, post_int('sort_order')];
        if ($id) { db_exec('UPDATE blog_categories SET name = ?, slug = ?, description = ?, seo_title = ?, meta_description = ?, sort_order = ? WHERE id = ?', array_merge($vals, [$id])); }
        else { $id = db_insert('INSERT INTO blog_categories (name, slug, description, seo_title, meta_description, sort_order) VALUES (?, ?, ?, ?, ?, ?)', $vals); }
        log_admin('blog_category_saved', 'blog_category', $id, $name); flash('success', 'Category saved.'); redirect($back);
    }
    if ($do === 'cat_delete') {
        db_exec('DELETE FROM blog_categories WHERE id = ?', [$id]);                       // its articles become "uncategorised"
        log_admin('blog_category_deleted', 'blog_category', $id); flash('success', 'Category deleted. Its articles are now uncategorised.'); redirect($back);
    }
    if ($do === 'tag_save') {
        $name = post_str('name', 80); $tag = db_row('SELECT * FROM blog_tags WHERE id = ?', [$id]);
        if (!$tag || mb_strlen($name) < 1) { redirect($back); }
        $slug = slugify($name, 90);
        $other = (int)db_val('SELECT id FROM blog_tags WHERE slug = ? AND id <> ?', [$slug, $id]);
        if ($other) {                                                                      // same name as another tag → merge into it
            db_exec('INSERT IGNORE INTO blog_post_tags (post_id, tag_id) SELECT post_id, ? FROM blog_post_tags WHERE tag_id = ?', [$other, $id]);
            db_exec('DELETE FROM blog_tags WHERE id = ?', [$id]);
            flash('success', 'Tag merged into “' . $name . '”.');
        } else { db_exec('UPDATE blog_tags SET name = ?, slug = ? WHERE id = ?', [$name, $slug, $id]); flash('success', 'Tag renamed.'); }
        log_admin('blog_tag_saved', 'blog_tag', $id, $name); redirect($back . '#tags');
    }
    if ($do === 'tag_delete') { db_exec('DELETE FROM blog_tags WHERE id = ?', [$id]); log_admin('blog_tag_deleted', 'blog_tag', $id); flash('success', 'Tag deleted.'); redirect($back . '#tags'); }
}
$edit = get_int('edit') ? db_row('SELECT * FROM blog_categories WHERE id = ?', [get_int('edit')]) : null;
$c = $edit ?: ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'seo_title' => '', 'meta_description' => '', 'sort_order' => 0];
$cats = db_all("SELECT c.*, (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = c.id AND p.status <> 'trash') AS n FROM blog_categories c ORDER BY c.sort_order, c.name");
$tags = db_all('SELECT t.*, COUNT(pt.post_id) AS n FROM blog_tags t LEFT JOIN blog_post_tags pt ON pt.tag_id = t.id GROUP BY t.id ORDER BY n DESC, t.name LIMIT 300');
$adm = ['title' => 'Blog categories & tags', 'active' => 'blog-taxonomy'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header orange"><?= $edit ? 'EDIT CATEGORY' : 'ADD CATEGORY' ?></div><div class="panel-body">
    <form method="post" class="pd-form"><?= csrf_field() ?><input type="hidden" name="do" value="cat_save"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <div class="form-grid">
            <div class="frow"><label>Name <span class="req">*</span></label><input type="text" name="name" maxlength="120" required value="<?= e($c['name']) ?>"></div>
            <div class="frow"><label>Slug</label><input type="text" name="slug" maxlength="140" value="<?= e($c['slug']) ?>" placeholder="auto"></div>
            <div class="frow full"><label>Description (shown on the category page — 1–3 useful sentences help it rank)</label><textarea name="description" maxlength="2000" style="min-height:70px;"><?= e((string)$c['description']) ?></textarea></div>
            <div class="frow"><label>SEO title</label><input type="text" name="seo_title" maxlength="190" value="<?= e((string)$c['seo_title']) ?>"></div>
            <div class="frow"><label>Meta description</label><input type="text" name="meta_description" maxlength="320" value="<?= e((string)$c['meta_description']) ?>"></div>
            <div class="frow"><label>Sort order</label><input type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>"></div>
        </div>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE CATEGORY</button><?= $edit ? '<a class="btn-classic" href="blog-taxonomy.php">CANCEL</a>' : '' ?></div>
    </form>
</div></div>
<div class="panel"><div class="panel-header">CATEGORIES</div><div class="panel-body">
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Category</th><th>Articles</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($cats as $r) { echo '<tr><td class="doc-title">' . e($r['name']) . '<span class="sub">' . e(blog_cat_url($r)) . '</span></td><td class="num">' . (int)$r['n'] . '</td><td class="actions"><a class="btn-classic btn-sm primary" href="?edit=' . (int)$r['id'] . '">EDIT</a><a class="btn-classic btn-sm" target="_blank" rel="noopener" href="' . e(blog_cat_url($r)) . '">👁</a><form method="post" style="display:inline;">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button class="btn-classic btn-sm danger" name="do" value="cat_delete" data-confirm="Delete this category? Its articles become uncategorised.">🗑</button></form></td></tr>'; }
    if (!$cats) { echo '<tr><td colspan="3" class="empty-cell">No categories yet.</td></tr>'; } ?></tbody></table></div>
</div></div>
<div class="panel" id="tags"><div class="panel-header">TAGS</div><div class="panel-body">
    <p class="help" style="margin-top:0;">Rename a tag to the name of another tag to merge them. Tag pages are <?= setting('blog_index_tags', '0') === '1' ? 'indexed when they have 2+ articles' : 'kept out of Google (noindex) — change this in Blog settings' ?>.</p>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Tag</th><th>Articles</th><th>Rename / merge</th><th></th></tr></thead><tbody>
    <?php foreach ($tags as $t) { echo '<tr><td>#' . e($t['name']) . '</td><td class="num">' . (int)$t['n'] . '</td><td><form method="post" class="flex" style="gap:6px;">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$t['id'] . '"><input type="text" name="name" maxlength="80" value="' . e($t['name']) . '" aria-label="New name for ' . e($t['name']) . '" style="max-width:220px;"><button class="btn-classic btn-sm" name="do" value="tag_save">SAVE</button></form></td><td><form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$t['id'] . '"><button class="btn-classic btn-sm danger" name="do" value="tag_delete" data-confirm="Delete this tag from all articles?">🗑</button></form></td></tr>'; }
    if (!$tags) { echo '<tr><td colspan="4" class="empty-cell">No tags yet — add them while writing an article.</td></tr>'; } ?></tbody></table></div>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
