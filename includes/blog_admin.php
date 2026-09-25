<?php
/** PATADOCS — blog admin helpers (saving posts, status changes). Loaded by admin/post-edit.php and admin/posts.php. */

/** Datetime-local ("2026-09-25T14:30") or MySQL datetime → MySQL datetime, '' when invalid. */
function blog_parse_dt(string $v): string
{
    $v = trim(str_replace('T', ' ', $v));
    if ($v === '') { return ''; }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : '';
}

/**
 * Creates/updates a post from the editor form. $intent: draft | pending | publish.
 * Returns ['ok' => bool, 'id' => int, 'errors' => [], 'message' => string].
 */
function blog_post_save(array $in, int $id, string $intent): array
{
    $me = (int)($_SESSION['admin']['id'] ?? 0);
    $canPublish = admin_can('blog.publish');
    $old = $id ? db_row('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
    if ($id && !$old) { return ['ok' => false, 'id' => 0, 'errors' => ['That article no longer exists.']]; }
    if ($old && !blog_can_edit($old)) { return ['ok' => false, 'id' => $id, 'errors' => ['You cannot edit this article.']]; }
    $s = function (string $k, int $max) use ($in) { return mb_substr(trim((string)($in[$k] ?? '')), 0, $max); };

    $title = trim(preg_replace('/\s+/u', ' ', $s('title', 255)));
    $content = blog_sanitize_html((string)($in['content'] ?? ''));
    $text = blog_plain($content);
    $words = blog_word_count($text);
    $errors = [];
    if (mb_strlen($title) < 3) { $errors[] = 'Enter a title (at least 3 characters).'; }
    if ($intent !== 'draft' && $words < 1) { $errors[] = 'Write the article before publishing it.'; }
    $canon = $s('canonical_url', 500);
    if ($canon !== '' && !preg_match('#^https?://[^\s]+$#i', $canon)) { $errors[] = 'The canonical URL must start with http:// or https://'; }
    $catId = (int)($in['category_id'] ?? 0);
    if ($catId && !db_val('SELECT id FROM blog_categories WHERE id = ?', [$catId])) { $catId = 0; }

    // status + date
    $pubAt = blog_parse_dt($s('published_at', 25));
    $visibility = ($in['visibility'] ?? 'public') === 'private' ? 'private' : 'published';
    if ($intent === 'publish' && $canPublish) { $status = $visibility; $pubAt = $pubAt ?: ($old['published_at'] ?? '') ?: date('Y-m-d H:i:s'); }
    elseif ($intent === 'publish' || $intent === 'pending') { $status = 'pending'; }
    else { $status = 'draft'; }
    if (!$canPublish && $old && $old['status'] === 'published') { $status = 'published'; }   // (writers can't reach here — blog_can_edit)
    if ($pubAt === '' && $old) { $pubAt = (string)$old['published_at']; }

    // cover image: picked from the media library (path) or uploaded now
    $cover = $s('cover_image', 255);
    if ($cover !== '' && (strpos($cover, 'uploads/blog/') !== 0 || strpos($cover, '..') !== false || !is_file(ROOT_DIR . '/' . $cover))) { $cover = (string)($old['cover_image'] ?? ''); }
    if (!empty($_FILES['cover_file']) && ($_FILES['cover_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $m = blog_media_store($_FILES['cover_file'], $s('cover_alt', 255));
        if (isset($m['error'])) { $errors[] = 'Featured image: ' . $m['error']; } else { $cover = $m['file_path']; }
    }
    if ($errors) { return ['ok' => false, 'id' => $id, 'errors' => $errors]; }

    $slug = slugify($s('slug', 191) ?: $title, 120);
    if (in_array($slug, ['category', 'tag', 'author', 'feed', 'page'], true)) { $slug .= '-post'; }
    $slug = unique_slug('blog_posts', 'slug', $slug, $id);
    $docIds = implode(',', array_slice(array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', (string)($in['doc_ids'] ?? '')))))), 0, 10));
    $authorId = $me;
    if ($old) { $authorId = (int)$old['author_id'] ?: $me; }
    if ($canPublish && (int)($in['author_id'] ?? 0) > 0 && db_val("SELECT id FROM admin_users WHERE id = ? AND status = 'active'", [(int)$in['author_id']])) { $authorId = (int)$in['author_id']; }
    $schemaType = in_array($in['schema_type'] ?? '', ['BlogPosting', 'Article', 'NewsArticle'], true) ? $in['schema_type'] : 'BlogPosting';
    $flag = function (string $k) use ($in) { return !empty($in[$k]) ? 1 : 0; };

    $cols = [
        'title' => $title, 'slug' => $slug, 'excerpt' => $s('excerpt', 1000) ?: null, 'content' => $content, 'content_text' => $text,
        'cover_image' => $cover !== '' ? $cover : null, 'cover_alt' => $s('cover_alt', 255) ?: null, 'category_id' => $catId ?: null, 'author_id' => $authorId ?: null,
        'status' => $status, 'published_at' => $pubAt !== '' ? $pubAt : null, 'seo_title' => $s('seo_title', 190) ?: null, 'meta_description' => $s('meta_description', 320) ?: null,
        'focus_keyword' => $s('focus_keyword', 120) ?: null, 'canonical_url' => $canon ?: null, 'robots_noindex' => $flag('robots_noindex'), 'schema_type' => $schemaType,
        'featured' => $canPublish ? $flag('featured') : (int)($old['featured'] ?? 0), 'allow_comments' => $flag('allow_comments'), 'show_toc' => $flag('show_toc'),
        'seo_score' => max(0, min(100, (int)($in['seo_score'] ?? 0))), 'word_count' => $words, 'doc_ids' => $docIds !== '' ? $docIds : null,
    ];
    if ($old) {
        db_exec('UPDATE blog_posts SET ' . implode(', ', array_map(function ($k) { return "`$k` = ?"; }, array_keys($cols))) . ' WHERE id = ?', array_merge(array_values($cols), [$id]));
    } else {
        $id = db_insert('INSERT INTO blog_posts (`' . implode('`, `', array_keys($cols)) . '`) VALUES (' . db_in(array_values($cols)) . ')', array_values($cols));
    }
    blog_tags_save($id, (string)($in['tags'] ?? ''));
    blog_revision_add($id, ['title' => $title, 'excerpt' => $cols['excerpt'], 'content' => $content]);
    db_exec("DELETE FROM blog_revisions WHERE post_id = ? AND kind = 'autosave'", [$id]);

    // renamed after going live → keep the old address working (301) and tell search engines about both
    $wasLive = $old && $old['status'] === 'published' && strtotime((string)$old['published_at']) <= time();
    if ($wasLive && $old['slug'] !== $slug) {
        db_exec('REPLACE INTO blog_slug_history (old_slug, post_id) VALUES (?, ?)', [$old['slug'], $id]);
    }
    db_exec('DELETE FROM blog_slug_history WHERE old_slug = ?', [$slug]);
    $live = $status === 'published' && strtotime((string)$pubAt) <= time();
    if ($live && !$cols['robots_noindex']) {
        require_once __DIR__ . '/indexnow.php';
        $urls = [post_url(['slug' => $slug]), blog_url()];
        if ($wasLive && $old['slug'] !== $slug) { $urls[] = post_url($old); }
        indexnow_ping(array_values(array_unique($urls)));
    }
    log_admin($old ? 'post_updated' : 'post_created', 'post', $id, $title . ' [' . $status . ']');
    $msg = 'Article saved as a draft.';
    if ($status === 'pending') { $msg = 'Article submitted for review. An editor will publish it.'; }
    elseif ($status === 'private') { $msg = 'Article saved as private (only signed-in editors can read it).'; }
    elseif ($status === 'published') { $msg = strtotime((string)$pubAt) > time() ? 'Article scheduled for ' . fmt_date($pubAt, true) . '.' : ($old && $old['status'] === 'published' ? 'Article updated.' : 'Article published.'); }
    return ['ok' => true, 'id' => $id, 'errors' => [], 'message' => $msg];
}

/** Bulk / row actions from the posts list: publish, draft, trash, restore, delete. */
function blog_post_set_status(int $id, string $to): bool
{
    $p = db_row('SELECT * FROM blog_posts WHERE id = ?', [$id]);
    if (!$p || !blog_can_edit($p)) { return false; }
    $canPublish = admin_can('blog.publish');
    switch ($to) {
        case 'publish':
            if (!$canPublish || trim((string)$p['content_text']) === '') { return false; }
            db_exec("UPDATE blog_posts SET status = 'published', published_at = COALESCE(published_at, NOW()) WHERE id = ?", [$id]);
            if (!$p['robots_noindex']) { require_once __DIR__ . '/indexnow.php'; indexnow_ping([post_url($p), blog_url()]); }
            break;
        case 'draft': db_exec("UPDATE blog_posts SET status = 'draft' WHERE id = ?", [$id]); break;
        case 'trash': db_exec("UPDATE blog_posts SET status = 'trash' WHERE id = ?", [$id]); break;
        case 'restore': db_exec("UPDATE blog_posts SET status = 'draft' WHERE id = ? AND status = 'trash'", [$id]); break;
        case 'delete':
            if (!$canPublish || $p['status'] !== 'trash') { return false; }
            db_exec('DELETE FROM blog_posts WHERE id = ?', [$id]);
            db_exec('DELETE FROM blog_tags WHERE id NOT IN (SELECT tag_id FROM blog_post_tags)');
            break;
        default: return false;
    }
    log_admin('post_' . $to, 'post', $id, (string)$p['title']);
    return true;
}
