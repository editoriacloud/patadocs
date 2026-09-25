<?php
/**
 * Blog admin AJAX (the article editor and media library). Writes: POST + CSRF (X-CSRF-Token header or csrf field).
 * Read-only lookups (GET): media list, document search, internal link list, focus-keyword check.
 */
define('PD_AJAX', true);
require __DIR__ . '/../includes/init.php';

$action = post_str('action', 30) ?: get_str('action', 30);
$fail = function ($m, $c = 400) { json_out(['ok' => false, 'message' => $m], $c); };
$admin = require_admin('blog.write');
$mediaJson = function (array $m) {
    return ['id' => (int)$m['id'], 'url' => url($m['file_path']), 'path' => $m['file_path'], 'thumb' => url($m['thumb_path'] ?: $m['file_path']),
        'name' => $m['file_name'], 'alt' => (string)$m['alt'], 'width' => (int)$m['width'], 'height' => (int)$m['height'], 'size' => fmt_size((int)$m['size'])];
};

// ---- Read-only --------------------------------------------------------------------------------
if ($action === 'media_list') {
    $q = get_str('q', 80); $page = max(1, get_int('page', 1)); $per = 40;
    $where = '1 = 1'; $p = [];
    if ($q !== '') { $where = '(file_name LIKE ? OR alt LIKE ?)'; $p = ['%' . like_escape($q) . '%', '%' . like_escape($q) . '%']; }
    $total = (int)db_val("SELECT COUNT(*) FROM blog_media WHERE $where", $p);
    $rows = db_all("SELECT * FROM blog_media WHERE $where ORDER BY id DESC LIMIT " . (($page - 1) * $per) . ", $per", $p);
    json_out(['ok' => true, 'items' => array_map($mediaJson, $rows), 'more' => $total > $page * $per]);
}
if ($action === 'doc_search') {
    $q = get_str('q', 80); $out = [];
    if (mb_strlen($q) >= 2) {
        $l = '%' . like_escape($q) . '%';
        foreach (db_all("SELECT d.id, d.slug, d.title, d.file_ext, d.is_free, d.price, d.category_id, c.path AS category_path FROM documents d LEFT JOIN categories c ON c.id = d.category_id
                         WHERE d.status = 'published' AND (d.title LIKE ? OR d.search_text LIKE ?) ORDER BY d.title LIMIT 15", [$l, $l]) as $d) {
            $out[] = ['id' => (int)$d['id'], 'title' => $d['title'], 'url' => doc_url($d), 'meta' => doc_ext_label($d['file_ext']) . ' · ' . price_label($d)];
        }
    }
    json_out(['ok' => true, 'docs' => $out]);
}
if ($action === 'link_list') {                        // TinyMCE "Link list": internal links one click away (internal linking = SEO)
    $out = [];
    $posts = blog_posts(blog_live_sql(), [], 40);
    if ($posts) { $out[] = ['title' => '— Articles —', 'menu' => array_map(function ($p) { return ['title' => $p['title'], 'value' => post_url($p)]; }, $posts)]; }
    $docs = db_all("SELECT d.id, d.slug, d.title, d.category_id, c.path AS category_path FROM documents d LEFT JOIN categories c ON c.id = d.category_id WHERE d.status = 'published' ORDER BY d.view_count DESC LIMIT 40");
    if ($docs) { $out[] = ['title' => '— Popular documents —', 'menu' => array_map(function ($d) { return ['title' => $d['title'], 'value' => doc_url($d)]; }, $docs)]; }
    $cats = array_filter(cats_all(), function ($c) { return $c['status'] === 'active' && (int)$c['depth'] <= 1; });
    if ($cats) { $out[] = ['title' => '— Categories —', 'menu' => array_values(array_map(function ($c) { return ['title' => $c['name'], 'value' => cat_url($c)]; }, $cats))]; }
    $out[] = ['title' => '— Pages —', 'menu' => [['title' => 'Browse documents', 'value' => page_url('search')], ['title' => 'Request a document', 'value' => page_url('request-document')], ['title' => 'Contact', 'value' => page_url('contact')], ['title' => 'Blog home', 'value' => blog_url()]]];
    json_out($out);
}
if ($action === 'kw_check') {                         // is this focus keyword already the target of another article?
    $kw = mb_strtolower(get_str('kw', 120)); $id = get_int('id');
    $rows = $kw !== '' ? db_all("SELECT id, title, slug FROM blog_posts WHERE LOWER(focus_keyword) = ? AND id <> ? AND status <> 'trash' LIMIT 3", [$kw, $id]) : [];
    json_out(['ok' => true, 'used' => array_map(function ($p) { return ['title' => $p['title'], 'url' => url('admin/post-edit.php?id=' . (int)$p['id'])]; }, $rows)]);
}
if ($action === 'slug_check') {
    $slug = slugify(get_str('slug', 191) ?: get_str('title', 255), 120); $id = get_int('id');
    json_out(['ok' => true, 'slug' => unique_slug('blog_posts', 'slug', $slug, $id)]);
}

// ---- Writes -----------------------------------------------------------------------------------
if (!is_post()) { $fail('Invalid request.', 405); }
csrf_check(true);

switch ($action) {
    case 'upload':                                    // editor image upload / paste / drag-and-drop, media library upload
        $f = $_FILES['file'] ?? null;
        if (!$f) { $fail('No file received.'); }
        if (!rate_limit('blogup:' . $admin['id'], 120, 3600)) { $fail('Too many uploads. Please wait a while.', 429); }
        $m = blog_media_store($f, post_str('alt', 255));
        if (isset($m['error'])) { $fail($m['error'], 422); }
        log_admin('media_uploaded', 'media', $m['id'], $m['file_name']);
        json_out(['ok' => true, 'location' => url($m['file_path']), 'media' => $mediaJson($m)]);
    case 'media_alt':
        $m = db_row('SELECT * FROM blog_media WHERE id = ?', [post_int('id')]);
        if (!$m) { $fail('Not found.', 404); }
        db_exec('UPDATE blog_media SET alt = ? WHERE id = ?', [post_str('alt', 255) ?: null, $m['id']]);
        json_out(['ok' => true, 'message' => 'Saved.']);
    case 'media_delete':
        if (!admin_can('blog.publish')) { $fail('Only editors who can publish may delete images.', 403); }
        $m = db_row('SELECT * FROM blog_media WHERE id = ?', [post_int('id')]);
        if (!$m) { $fail('Not found.', 404); }
        $like = '%' . like_escape($m['file_path']) . '%';
        $used = (int)db_val("SELECT COUNT(*) FROM blog_posts WHERE status <> 'trash' AND (cover_image = ? OR content LIKE ?)", [$m['file_path'], $like]);
        if ($used && post_str('force', 1) !== '1') { json_out(['ok' => false, 'in_use' => $used, 'message' => 'This image is used in ' . $used . ' article(s). Delete anyway?'], 409); }
        foreach ([$m['file_path'], $m['thumb_path']] as $p) { if ($p && strpos($p, 'uploads/blog/') === 0 && strpos($p, '..') === false) { @unlink(ROOT_DIR . '/' . $p); } }
        db_exec('DELETE FROM blog_media WHERE id = ?', [$m['id']]);
        log_admin('media_deleted', 'media', $m['id'], $m['file_name']);
        json_out(['ok' => true, 'message' => 'Image deleted.']);
    case 'autosave':                                  // every minute while typing: an "autosave" revision (never touches the live article)
        $post = db_row('SELECT * FROM blog_posts WHERE id = ?', [post_int('id')]);
        if (!$post || !blog_can_edit($post)) { $fail('You cannot edit this article.', 403); }
        $content = blog_sanitize_html((string)($_POST['content'] ?? ''));
        blog_revision_add((int)$post['id'], ['title' => post_str('title', 255) ?: $post['title'], 'excerpt' => post_str('excerpt', 1000), 'content' => $content], 'autosave');
        json_out(['ok' => true, 'message' => 'Draft autosaved at ' . date('H:i'), 'time' => date('H:i')]);
    case 'category_add':
        if (!admin_can('blog.publish')) { $fail('You cannot add categories.', 403); }
        $name = post_str('name', 120);
        if (mb_strlen($name) < 2) { $fail('Enter a category name.'); }
        $slug = unique_slug('blog_categories', 'slug', slugify($name, 120));
        $id = db_insert('INSERT INTO blog_categories (name, slug) VALUES (?, ?)', [$name, $slug]);
        log_admin('blog_category_added', 'blog_category', $id, $name);
        json_out(['ok' => true, 'id' => $id, 'name' => $name]);
}
$fail('Unknown action.');
