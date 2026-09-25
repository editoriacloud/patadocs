<?php
/**
 * PATADOCS — admin library shared by upload.php, edit-document.php, contributions.php and ajax/admin.php.
 * All writes use prepared statements; every file goes through upload_check().
 */
require_once __DIR__ . '/preview.php';
require_once __DIR__ . '/indexnow.php';

/** Problems that prevent a document from being published (empty = OK). */
function doc_publish_errors(array $d): array
{
    $e = [];
    if (trim((string)$d['title']) === '') { $e[] = 'A title is required.'; }
    if (empty($d['category_id']) || !cat_get((int)$d['category_id'])) { $e[] = 'Choose a category.'; }
    if (empty($d['file_name']) || !is_file(doc_private_path($d))) { $e[] = 'The document file is missing.'; }
    if (!(int)$d['is_free'] && (float)$d['price'] <= 0) { $e[] = 'A paid document needs a price above zero.'; }
    if (trim((string)$d['slug']) === '') { $e[] = 'A URL slug is required.'; }
    return $e;
}

/** Deletes the private file + previews of a document (only if nothing else references the file). */
function doc_remove_files(array $d): void
{
    if (!empty($d['file_name'])) {
        $others = (int)db_val('SELECT (SELECT COUNT(*) FROM documents WHERE file_name = ? AND id <> ?) + (SELECT COUNT(*) FROM contributions WHERE file_name = ? AND (document_id IS NULL OR document_id <> ?))', [$d['file_name'], $d['id'], $d['file_name'], $d['id']]);
        $p = doc_private_path($d);
        if ($others === 0 && is_file($p)) { @unlink($p); }
    }
    preview_delete_dir($d['preview_dir'] ?? null);
}

/** Slug that is unique AND does not collide with a category path (which the router would match first). */
function doc_unique_slug(string $slug, ?int $catId, int $ignoreId = 0): string
{
    $slug = unique_slug('documents', 'slug', $slug !== '' ? $slug : 'document', $ignoreId);
    $cat = $catId ? cat_get($catId) : null;
    while ($cat && cat_by_path($cat['path'] . '/' . $slug)) { $slug = unique_slug('documents', 'slug', $slug . '-doc', $ignoreId); }
    if (!$cat && cat_by_path($slug)) { $slug = unique_slug('documents', 'slug', $slug . '-doc', $ignoreId); }
    return $slug;
}

/** Changes status with validation. Returns ['ok', 'message']. */
function doc_set_status(int $id, string $status): array
{
    if (!in_array($status, ['draft', 'pending_review', 'published', 'archived', 'rejected'], true)) { return ['ok' => false, 'message' => 'Invalid status.']; }
    $d = doc_get($id);
    if (!$d) { return ['ok' => false, 'message' => 'Document not found.']; }
    if ($status === 'published') {
        $errs = doc_publish_errors($d);
        if ($errs) { return ['ok' => false, 'message' => 'Cannot publish: ' . implode(' ', $errs)]; }
        db_exec("UPDATE documents SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = ?", [$id]);
        db_exec("UPDATE contributions SET status = 'published' WHERE document_id = ? AND status IN ('approved','under_review','pending')", [$id]);
    } else {
        db_exec('UPDATE documents SET status = ? WHERE id = ?', [$status, $id]);
    }
    if ($status === 'published' || $d['status'] === 'published') { indexnow_doc(doc_get($id) ?: $d); }   // appeared or disappeared
    log_admin('document_' . $status, 'document', $id, $d['title']);
    return ['ok' => true, 'message' => 'Status changed to ' . str_replace('_', ' ', $status) . '.'];
}

/** Deletes a document (blocked when customers have paid for it — archive instead). */
function doc_delete(int $id): array
{
    $d = doc_get($id);
    if (!$d) { return ['ok' => false, 'message' => 'Document not found.']; }
    if ((int)db_val("SELECT COUNT(*) FROM orders WHERE document_id = ? AND status = 'paid'", [$id]) > 0) {
        return ['ok' => false, 'message' => 'This document has paid orders, so it cannot be deleted (customers could lose access). Archive it instead.'];
    }
    doc_remove_files($d);
    db_exec('DELETE FROM documents WHERE id = ?', [$id]);
    if ($d['status'] === 'published') { indexnow_ping([doc_url($d)]); }
    log_admin('document_deleted', 'document', $id, $d['title']);
    return ['ok' => true, 'message' => 'Document deleted.'];
}

/** Copies a document (file, tags, metadata) as a new draft. Returns the new id or 0. */
function doc_duplicate(int $id): int
{
    $d = doc_get($id);
    if (!$d) { return 0; }
    $newFile = null;
    if (!empty($d['file_name']) && is_file(doc_private_path($d))) {
        $newFile = bin2hex(random_bytes(16)) . '.' . $d['file_ext'];
        if (!@copy(doc_private_path($d), rtrim(PRIVATE_DIR, '/\\') . '/' . $newFile)) { return 0; }
        @chmod(rtrim(PRIVATE_DIR, '/\\') . '/' . $newFile, 0640);
    }
    $title = mb_substr($d['title'], 0, 240) . ' (Copy)';
    $slug = doc_unique_slug(slugify($title), $d['category_id'] ? (int)$d['category_id'] : null);
    $new = db_insert("INSERT INTO documents (title, slug, category_id, description, doc_type, file_name, original_name, file_ext, file_mime, file_size, file_hash, pages, is_free, price, currency,
            status, seo_title, meta_description, seo_keywords, author, download_limit, preview_limit, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?)",
        [$title, $slug, $d['category_id'], $d['description'], $d['doc_type'], $newFile, $d['original_name'], $d['file_ext'], $d['file_mime'], $d['file_size'], $d['file_hash'], $d['pages'],
         $d['is_free'], $d['price'], $d['currency'], $d['seo_title'], $d['meta_description'], $d['seo_keywords'], $d['author'], $d['download_limit'], $d['preview_limit'], $_SESSION['admin']['id'] ?? null]);
    db_exec('INSERT INTO document_tags (document_id, tag_id) SELECT ?, tag_id FROM document_tags WHERE document_id = ?', [$new, $id]);
    db_exec('INSERT INTO document_meta (document_id, field_id, meta_value) SELECT ?, field_id, meta_value FROM document_meta WHERE document_id = ?', [$new, $id]);
    doc_rebuild_search($new);
    log_admin('document_duplicated', 'document', $new, 'from #' . $id);
    return $new;
}

/**
 * Creates or updates a document from the admin form.
 * $post = $_POST, $files = $_FILES, $id = 0 for a new document.
 * $intent: 'draft' (save as draft), 'publish', or 'save' (keep the current status).
 * Returns ['ok' => bool, 'id' => int, 'errors' => [], 'notes' => []].
 */
function doc_save(array $post, array $files, int $id, string $intent): array
{
    $errors = []; $notes = [];
    $old = $id ? doc_get($id) : null;
    if ($id && !$old) { return ['ok' => false, 'id' => 0, 'errors' => ['Document not found.'], 'notes' => []]; }

    $title = input_str($post, 'title', 255);
    if (mb_strlen($title) < 3) { $errors[] = 'Enter a title (at least 3 characters).'; }
    $catId = input_int($post, 'category', 0); $cat = $catId ? cat_get($catId) : null;
    if ($catId && !$cat) { $errors[] = 'The selected category does not exist.'; }
    $isFree = (input_str($post, 'is_free', 1) === '0') ? 0 : 1;
    $price = $isFree ? 0.0 : (float)str_replace(',', '', input_str($post, 'price', 12));
    if (!$isFree && $price <= 0) { $errors[] = 'Enter a price above zero for a paid document.'; }
    if ($price > 1000000) { $errors[] = 'The price is too high.'; }

    // File (required for new documents; optional replacement when editing)
    $fileInfo = null; $hasFile = !empty($files['doc_file']) && ($files['doc_file']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE;
    if ($hasFile) {
        $fileInfo = upload_check($files['doc_file'], allowed_exts(), max_upload_bytes('max_file_mb'));
        if (!$fileInfo['ok']) { $errors[] = $fileInfo['error']; }
    } elseif (!$id) { $errors[] = 'Please choose the document file to upload.'; }
    // Manual preview images
    $manual = [];
    if (!empty($files['preview_images']) && is_array($files['preview_images']['name'])) {
        foreach ($files['preview_images']['name'] as $i => $n) {
            if ($files['preview_images']['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
            $one = ['name' => $n, 'type' => $files['preview_images']['type'][$i], 'tmp_name' => $files['preview_images']['tmp_name'][$i], 'error' => $files['preview_images']['error'][$i], 'size' => $files['preview_images']['size'][$i]];
            $chk = upload_check($one, allowed_exts(), 8 * 1048576, true);
            if (!$chk['ok']) { $errors[] = 'Preview image ' . ($i + 1) . ': ' . $chk['error']; } else { $manual[] = [$one, $chk]; }
        }
        if (count($manual) > 20) { $errors[] = 'Please upload at most 20 preview images.'; }
    }
    $meta = meta_sanitize($cat ? (int)$cat['id'] : null, (array)($post['meta'] ?? []), $intent === 'publish');
    $errors = array_merge($errors, $meta['errors']);
    if ($errors) { return ['ok' => false, 'id' => $id, 'errors' => $errors, 'notes' => []]; }

    $slugIn = slugify(input_str($post, 'slug', 120) ?: $title);
    $slug = doc_unique_slug($slugIn, $cat ? (int)$cat['id'] : null, $id);
    $pages = input_int($post, 'pages', 0) ?: null;
    $fields = [
        'title' => $title, 'slug' => $slug, 'category_id' => $cat ? (int)$cat['id'] : null,
        'description' => input_str($post, 'description', 20000) ?: null, 'doc_type' => input_str($post, 'doc_type', 80) ?: null,
        'author' => input_str($post, 'author', 190) ?: null, 'is_free' => $isFree, 'price' => $price, 'currency' => setting('currency', 'KES'),
        'seo_title' => input_str($post, 'seo_title', 190) ?: null, 'meta_description' => input_str($post, 'meta_description', 320) ?: null,
        'seo_keywords' => input_str($post, 'seo_keywords', 400) ?: null,
        'download_limit' => ($post['download_limit'] ?? '') !== '' ? max(0, input_int($post, 'download_limit', 0)) : null,
        'preview_limit' => ($pl = input_int($post, 'preview_limit', 0)) > 0 ? min(20, $pl) : null,
        'featured' => !empty($post['featured']) ? 1 : 0, 'popular' => !empty($post['popular']) ? 1 : 0,
        'show_contributor' => !empty($post['show_contributor']) ? 1 : 0,
        'contributor_badge' => in_array(input_str($post, 'contributor_badge', 10), ['none', 'community', 'verified'], true) ? input_str($post, 'contributor_badge', 10) : 'none',
    ];
    if ($pages) { $fields['pages'] = $pages; }

    $stored = null;
    if ($fileInfo) {
        $stored = upload_save($files['doc_file']['tmp_name'], $fileInfo['ext'], PRIVATE_DIR);
        if (!$stored) { return ['ok' => false, 'id' => $id, 'errors' => ['The file could not be saved on the server. Check that the private documents folder is writable.'], 'notes' => []]; }
        $fields += ['file_name' => $stored, 'original_name' => $fileInfo['name'], 'file_ext' => $fileInfo['ext'], 'file_mime' => $fileInfo['mime'], 'file_size' => $fileInfo['size'], 'file_hash' => $fileInfo['hash']];
        if (!$pages) { $detected = doc_detect_pages(rtrim(PRIVATE_DIR, '/\\') . '/' . $stored, $fileInfo['ext']); $fields['pages'] = $detected; }
        $dup = (int)db_val('SELECT COUNT(*) FROM documents WHERE file_hash = ? AND id <> ?', [$fileInfo['hash'], $id]);
        if ($dup) { $notes[] = 'Note: an identical file already exists in another document (possible duplicate).'; }
    }

    if ($id) {
        $sets = []; $vals = [];
        foreach ($fields as $k => $v) { $sets[] = "`$k` = ?"; $vals[] = $v; }
        $vals[] = $id;
        db_exec('UPDATE documents SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
        if ($stored) {                                     // replaced file → remove old original, previews are now stale
            if (!empty($old['file_name'])) { doc_remove_files(array_merge($old, ['preview_dir' => null])); }
            preview_delete_dir($old['preview_dir']);
            db_exec("UPDATE documents SET preview_status = 'none', preview_dir = NULL, preview_pages = 0 WHERE id = ?", [$id]);
            $notes[] = 'File replaced — the preview was reset.';
        }
        log_admin('document_updated', 'document', $id, $title);
    } else {
        $fields['status'] = 'draft'; $fields['created_by'] = $_SESSION['admin']['id'] ?? null;
        $cols = array_keys($fields);
        $id = db_insert('INSERT INTO documents (`' . implode('`, `', $cols) . '`) VALUES (' . db_in($cols) . ')', array_values($fields));
        log_admin('document_created', 'document', $id, $title);
    }

    // Tags + metadata (+ search index)
    tags_save($id, input_str($post, 'tags', 600));
    db_exec('DELETE FROM document_meta WHERE document_id = ?', [$id]);
    foreach ($meta['rows'] as $r) { db_exec('INSERT INTO document_meta (document_id, field_id, meta_value) VALUES (?, ?, ?)', [$id, $r[0], $r[1]]); }
    doc_rebuild_search($id);

    // Preview: manual images win; otherwise generate when asked (or when there is none yet and previews are enabled)
    $cur = doc_get($id);
    $wantGen = !empty($post['gen_preview']) || ($cur['preview_status'] === 'none' && setting('preview_enabled', '1') === '1' && ($intent === 'publish' || $stored));
    if ($manual) {
        $tmpFiles = [];
        $tmpDir = rtrim(UPLOAD_DIR, '/\\') . '/temporary/m' . bin2hex(random_bytes(5));
        @mkdir($tmpDir, 0755, true);
        foreach ($manual as $i => $m) { $dest = $tmpDir . '/' . ($i + 1) . '.' . $m[1]['ext']; if (@move_uploaded_file($m[0]['tmp_name'], $dest)) { $tmpFiles[] = $dest; } }
        $r = preview_generate($id, $tmpFiles); preview_rrmdir($tmpDir);
        $notes[] = $r['message'];
    } elseif ($wantGen && setting('preview_enabled', '1') === '1') {
        $r = preview_generate($id);
        $notes[] = $r['message'];
    }

    // Status
    if ($intent === 'publish') {
        $r = doc_set_status($id, 'published');
        if (!$r['ok']) { $errors[] = $r['message']; } else { $notes[] = 'Published.'; }
    } elseif ($intent === 'draft') {
        db_exec("UPDATE documents SET status = 'draft' WHERE id = ?", [$id]);       // new draft, or "unpublish" of an existing document
    }
    $now = doc_get($id);
    if ($now && ($now['status'] === 'published' || ($old && $old['status'] === 'published'))) {
        indexnow_doc($now, $old && doc_url($old) !== doc_url($now) ? $old : null);   // changed, or its URL moved
    }
    return ['ok' => !$errors, 'id' => $id, 'errors' => $errors, 'notes' => $notes];
}
