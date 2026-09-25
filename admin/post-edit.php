<?php
/**
 * Admin: write / edit an article — WordPress-style: rich editor (TinyMCE, bundled locally), permalink, excerpt,
 * SEO box (focus keyword, snippet preview, live analysis), and a sidebar with Publish, Category, Tags,
 * Featured image, Documents to promote and Revisions.
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/blog_admin.php';
$admin = require_admin('blog.write');
$id = get_int('id');
$post = $id ? db_row('SELECT * FROM blog_posts WHERE id = ?', [$id]) : null;
if ($id && !$post) { abort_page(404, 'Not found', 'That article does not exist.'); }
if ($post && !blog_can_edit($post)) { abort_page(403, 'Access denied', 'You can only edit your own articles until they are published.'); }
$canPublish = admin_can('blog.publish');

if (is_post()) {
    csrf_check();
    $do = post_str('do', 20);
    if ($do === 'restore_rev' && $post) {
        $rev = db_row('SELECT * FROM blog_revisions WHERE id = ? AND post_id = ?', [post_int('rev'), $post['id']]);
        if ($rev) {
            blog_revision_add((int)$post['id'], ['title' => $post['title'], 'excerpt' => $post['excerpt'], 'content' => $post['content']]);   // current version stays restorable
            $content = blog_sanitize_html((string)$rev['content']); $text = blog_plain($content);
            db_exec('UPDATE blog_posts SET title = ?, excerpt = ?, content = ?, content_text = ?, word_count = ? WHERE id = ?', [$rev['title'], $rev['excerpt'], $content, $text, blog_word_count($text), $post['id']]);
            log_admin('post_revision_restored', 'post', $post['id'], 'revision #' . $rev['id']);
            flash('success', 'Revision from ' . fmt_date($rev['created_at'], true) . ' restored.');
        }
        redirect(url('admin/post-edit.php?id=' . (int)$post['id']));
    }
    if ($do === 'trash' && $post) { blog_post_set_status((int)$post['id'], 'trash'); flash('success', 'Article moved to the trash.'); redirect(url('admin/posts.php')); }
    $intent = in_array($do, ['draft', 'pending', 'publish'], true) ? $do : 'draft';
    $r = blog_post_save($_POST, $id, $intent);
    if ($r['ok']) { flash('success', $r['message']); redirect(url('admin/post-edit.php?id=' . $r['id'])); }
    foreach ($r['errors'] as $er) { flash('error', $er); }
    $post = array_merge($post ?: [], array_intersect_key($_POST, array_flip(['title', 'slug', 'excerpt', 'content', 'seo_title', 'meta_description', 'focus_keyword', 'canonical_url', 'cover_alt', 'doc_ids'])));
    $post['content'] = blog_sanitize_html((string)($_POST['content'] ?? ''));
    $post['_tags'] = post_str('tags', 1000);
}

$p = array_merge(['id' => 0, 'title' => '', 'slug' => '', 'excerpt' => '', 'content' => '', 'cover_image' => '', 'cover_alt' => '', 'category_id' => 0, 'author_id' => $admin['id'],
    'status' => 'draft', 'published_at' => null, 'seo_title' => '', 'meta_description' => '', 'focus_keyword' => '', 'canonical_url' => '', 'robots_noindex' => 0,
    'schema_type' => 'BlogPosting', 'featured' => 0, 'allow_comments' => 1, 'show_toc' => 1, 'seo_score' => 0, 'word_count' => 0, 'views' => 0, 'doc_ids' => '', 'updated_at' => null], $post ?: []);
$tagsCsv = $p['_tags'] ?? ($p['id'] ? implode(', ', array_column(blog_post_tags((int)$p['id']), 'name')) : '');
$cats = db_all('SELECT id, name FROM blog_categories ORDER BY sort_order, name');
$authors = $canPublish ? db_all("SELECT id, username, full_name FROM admin_users WHERE status = 'active' ORDER BY full_name, username") : [];
$revs = $p['id'] ? db_all('SELECT r.id, r.kind, r.title, r.created_at, COALESCE(NULLIF(a.full_name, \'\'), a.username) AS who FROM blog_revisions r LEFT JOIN admin_users a ON a.id = r.author_id WHERE r.post_id = ? ORDER BY r.id DESC LIMIT 30', [$p['id']]) : [];
$autosave = $p['id'] ? db_row("SELECT * FROM blog_revisions WHERE post_id = ? AND kind = 'autosave' ORDER BY id DESC LIMIT 1", [$p['id']]) : null;
if ($autosave && $p['updated_at'] && strtotime((string)$autosave['created_at']) <= strtotime((string)$p['updated_at'])) { $autosave = null; }
$docPicks = [];
if (trim((string)$p['doc_ids']) !== '') {
    $ids = array_filter(array_map('intval', explode(',', (string)$p['doc_ids'])));
    if ($ids) { foreach (db_all('SELECT id, title FROM documents WHERE id IN (' . implode(',', $ids) . ')') as $d) { $docPicks[] = ['id' => (int)$d['id'], 'title' => $d['title']]; } }
}
$live = $p['status'] === 'published' && $p['published_at'] && strtotime((string)$p['published_at']) <= time();
$scheduled = $p['status'] === 'published' && $p['published_at'] && strtotime((string)$p['published_at']) > time();
$statusLabel = $live ? 'Published' : ($scheduled ? 'Scheduled' : ['draft' => 'Draft', 'pending' => 'Pending review', 'private' => 'Private', 'trash' => 'In trash', 'published' => 'Published'][$p['status']] ?? $p['status']);
$permBase = setting('clean_urls', '1') === '1' ? rtrim(blog_url(), '/') . '/' : url('blog.php?route=');
$cfg = [
    'id' => (int)$p['id'], 'csrf' => csrf_token(), 'ajax' => url('ajax/blog-admin.php'), 'tinymce' => url('assets/js/vendor/tinymce/tinymce.min.js'),
    'contentCss' => asset('css/blog-content.css'), 'base' => url(''), 'host' => (string)parse_url(url(''), PHP_URL_HOST), 'permBase' => $permBase,
    'titleSuffix' => (string)setting('seo_title_suffix', ' | ' . setting('site_name')), 'siteName' => (string)setting('site_name'), 'blogName' => (string)setting('blog_title', 'Blog'),
    'docs' => $docPicks, 'maxMb' => max(1, (int)setting('blog_image_mb', '5')),
];
$adm = ['title' => $p['id'] ? 'Edit article' : 'New article', 'active' => $p['id'] ? 'posts' : 'post-new',
    'foot_extra' => '<script>window.PD_BLOG = ' . json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script><script src="' . e(asset('js/blog-editor.js')) . '"></script>'];
include __DIR__ . '/../includes/admin_header.php';
?>
<form method="post" enctype="multipart/form-data" class="pd-form post-edit" id="postForm" autocomplete="off">
<?= csrf_field() ?>
<input type="hidden" name="seo_score" id="seoScoreField" value="<?= (int)$p['seo_score'] ?>">
<input type="hidden" name="doc_ids" id="docIdsField" value="<?= e((string)$p['doc_ids']) ?>">
<?php if ($autosave) { ?><div class="alert alert-warn">💾 There is an autosave of this article from <?= e(fmt_date($autosave['created_at'], true)) ?> that is newer than the saved version.
    <button type="submit" class="btn-classic btn-sm" name="do" value="restore_rev" formnovalidate onclick="this.form.rev.value='<?= (int)$autosave['id'] ?>'">Restore the autosave</button></div><?php } ?>
<input type="hidden" name="rev" value="">
<div class="post-edit-grid">
    <div class="post-edit-main">
        <div class="panel"><div class="panel-header"><?= $p['id'] ? 'EDIT ARTICLE' : 'ADD NEW ARTICLE' ?> <span class="badge b-blue" style="margin-left:8px;"><?= e($statusLabel) ?></span></div><div class="panel-body">
            <label class="sr-only" for="postTitle">Title</label>
            <input type="text" name="title" id="postTitle" class="post-title-input" maxlength="255" required placeholder="Add title" value="<?= e($p['title']) ?>">
            <div class="post-permalink"><strong>Permalink:</strong> <span class="mono"><?= e($permBase) ?></span><input type="text" name="slug" id="postSlug" maxlength="191" value="<?= e($p['slug']) ?>" placeholder="auto from title" aria-label="URL slug" class="mono">
                <?php if ($p['id'] && $p['status'] !== 'trash') { echo '<a class="btn-classic btn-sm" target="_blank" rel="noopener" href="' . e(post_url($p)) . '">' . ($live ? 'View ↗' : 'Preview saved ↗') . '</a>'; } ?></div>
            <textarea name="content" id="postContent" class="post-content-area" rows="24"><?= e($p['content']) ?></textarea>
            <div class="post-editor-status help" id="editorStatus" aria-live="polite"></div>
        </div></div>

        <div class="panel"><div class="panel-header">EXCERPT</div><div class="panel-body">
            <label for="postExcerpt" class="help" style="display:block;">A 1–2 sentence summary shown on blog lists and used when the meta description is empty.</label>
            <textarea name="excerpt" id="postExcerpt" maxlength="1000" style="min-height:70px;"><?= e((string)$p['excerpt']) ?></textarea>
        </div></div>

        <div class="panel" id="seoBox"><div class="panel-header green">SEO <span class="seo-score-pill" id="seoScorePill">–</span></div><div class="panel-body">
            <div class="form-grid">
                <div class="frow"><label for="focusKw">Focus keyphrase</label><input type="text" name="focus_keyword" id="focusKw" maxlength="120" value="<?= e((string)$p['focus_keyword']) ?>" placeholder="e.g. grade 7 schemes of work">
                    <span class="help" id="kwUsed"></span></div>
                <div class="frow"><label for="schemaType">Article type (schema.org)</label><select name="schema_type" id="schemaType">
                    <?php foreach (['BlogPosting' => 'Blog post', 'Article' => 'Article / guide', 'NewsArticle' => 'News article'] as $k => $v) { echo '<option value="' . $k . '"' . ($p['schema_type'] === $k ? ' selected' : '') . '>' . $v . '</option>'; } ?></select></div>
                <div class="frow full"><label for="seoTitle">SEO title <span class="help" id="seoTitleCount"></span></label><input type="text" name="seo_title" id="seoTitle" maxlength="190" value="<?= e((string)$p['seo_title']) ?>" placeholder="Leave empty to use the article title"></div>
                <div class="frow full"><label for="metaDesc">Meta description <span class="help" id="metaDescCount"></span></label><textarea name="meta_description" id="metaDesc" maxlength="320" style="min-height:64px;" placeholder="Leave empty to use the excerpt"><?= e((string)$p['meta_description']) ?></textarea></div>
            </div>
            <div class="section-title" style="margin-top:6px;">GOOGLE PREVIEW</div>
            <div class="serp" id="serpPreview" aria-label="Search result preview">
                <div class="serp-site"><span class="serp-favicon" aria-hidden="true"></span><span><span class="serp-name"><?= e(setting('site_name')) ?></span><span class="serp-url" id="serpUrl"></span></span></div>
                <div class="serp-title" id="serpTitle"></div>
                <div class="serp-desc"><span class="serp-date" id="serpDate"></span><span id="serpDesc"></span></div>
            </div>
            <div class="section-title">ANALYSIS</div>
            <ul class="seo-checks" id="seoChecks"><li class="muted">Start writing to see the analysis.</li></ul>
            <details class="seo-advanced"><summary>Advanced</summary>
                <div class="form-grid" style="margin-top:8px;">
                    <div class="frow full"><label for="canonUrl">Canonical URL</label><input type="url" name="canonical_url" id="canonUrl" maxlength="500" value="<?= e((string)$p['canonical_url']) ?>" placeholder="Only if this article was first published on another site"></div>
                    <div class="frow"><label class="chk"><input type="checkbox" name="robots_noindex" value="1" <?= $p['robots_noindex'] ? 'checked' : '' ?>><span>Hide from search engines (noindex)</span></label></div>
                    <div class="frow"><label class="chk"><input type="checkbox" name="show_toc" value="1" <?= $p['show_toc'] ? 'checked' : '' ?>><span>Show a table of contents (3+ headings)</span></label></div>
                </div>
            </details>
        </div></div>
    </div>

    <aside class="post-edit-side">
        <div class="panel"><div class="panel-header orange">PUBLISH</div><div class="panel-body">
            <p class="post-side-row">Status: <strong><?= e($statusLabel) ?></strong></p>
            <?php if ($p['id']) { echo '<p class="post-side-row">Words: <strong id="wordCountSide">' . (int)$p['word_count'] . '</strong> · Views: <strong>' . num($p['views']) . '</strong></p>'; } ?>
            <div class="frow"><label for="visibility">Visibility</label><select name="visibility" id="visibility"><option value="public">Public</option><option value="private"<?= $p['status'] === 'private' ? ' selected' : '' ?>>Private (editors only)</option></select></div>
            <div class="frow"><label for="pubAt"><?= $canPublish ? 'Publish date (future = schedule)' : 'Preferred publish date' ?></label><input type="datetime-local" name="published_at" id="pubAt" value="<?= $p['published_at'] ? e(date('Y-m-d\TH:i', strtotime((string)$p['published_at']))) : '' ?>"></div>
            <?php if ($authors) { ?><div class="frow"><label for="authorSel">Author</label><select name="author_id" id="authorSel"><?php foreach ($authors as $a) { echo '<option value="' . (int)$a['id'] . '"' . ((int)$p['author_id'] === (int)$a['id'] ? ' selected' : '') . '>' . e($a['full_name'] ?: $a['username']) . '</option>'; } ?></select></div><?php } ?>
            <div class="post-publish-actions">
                <?php if (!$live && !$scheduled) { ?><button type="submit" name="do" value="draft" class="btn-classic" formnovalidate>💾 Save draft</button><?php } ?>
                <button type="submit" formaction="<?= e(url('admin/post-preview.php')) ?>" formtarget="pdPreview" name="do" value="preview" class="btn-classic" id="previewBtn">👁 Preview</button>
                <?php if ($canPublish) { ?>
                    <button type="submit" name="do" value="publish" class="btn-classic success" id="publishBtn"><?= $live ? '✔ Update' : ($scheduled ? '✔ Update schedule' : '🚀 Publish') ?></button>
                    <?php if ($live || $scheduled) { ?><button type="submit" name="do" value="draft" class="btn-classic" formnovalidate data-confirm="Unpublish this article and turn it back into a draft?">↩ Switch to draft</button><?php } ?>
                <?php } else { ?>
                    <button type="submit" name="do" value="pending" class="btn-classic success">📨 Submit for review</button>
                <?php } ?>
            </div>
            <?php if ($p['id']) { ?><button type="submit" name="do" value="trash" class="btn-classic btn-sm danger" formnovalidate data-confirm="Move this article to the trash?" style="margin-top:10px;">🗑 Move to trash</button><?php } ?>
        </div></div>

        <div class="panel"><div class="panel-header">CATEGORY</div><div class="panel-body">
            <select name="category_id" id="catSel" aria-label="Category"><option value="0">— Uncategorised —</option><?php foreach ($cats as $c) { echo '<option value="' . (int)$c['id'] . '"' . ((int)$p['category_id'] === (int)$c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>'; } ?></select>
            <?php if ($canPublish) { ?><div class="post-inline-add"><input type="text" id="newCatName" maxlength="120" placeholder="New category" aria-label="New category name"><button type="button" class="btn-classic btn-sm" id="addCatBtn">＋ Add</button></div><?php } ?>
        </div></div>

        <div class="panel"><div class="panel-header">TAGS</div><div class="panel-body">
            <label for="postTags" class="help" style="display:block;">Separate with commas. 3–8 specific topics work best.</label>
            <input type="text" name="tags" id="postTags" maxlength="1000" value="<?= e($tagsCsv) ?>" placeholder="CBC, Grade 7, schemes of work">
        </div></div>

        <div class="panel"><div class="panel-header">FEATURED IMAGE</div><div class="panel-body">
            <input type="hidden" name="cover_image" id="coverPath" value="<?= e((string)$p['cover_image']) ?>">
            <div id="coverPreview" class="post-cover-preview"><?= $p['cover_image'] ? '<img src="' . e(url($p['cover_image'])) . '" alt="">' : '<span class="muted">No image. 1200×675 px or larger works best for Google Discover and social sharing.</span>' ?></div>
            <div class="flex" style="gap:6px; margin:8px 0;"><button type="button" class="btn-classic btn-sm" id="coverPick">🖼 Media library</button><button type="button" class="btn-classic btn-sm<?= $p['cover_image'] ? '' : ' hidden' ?>" id="coverRemove">✖ Remove</button></div>
            <label class="help" for="coverFile">…or upload (JPG, PNG, WebP ≤ <?= (int)$cfg['maxMb'] ?> MB)</label><input type="file" name="cover_file" id="coverFile" accept=".jpg,.jpeg,.png,.webp">
            <div class="frow" style="margin-top:8px;"><label for="coverAlt">Alt text (describe the image)</label><input type="text" name="cover_alt" id="coverAlt" maxlength="255" value="<?= e((string)$p['cover_alt']) ?>"></div>
        </div></div>

        <div class="panel"><div class="panel-header">DOCUMENTS TO PROMOTE</div><div class="panel-body">
            <p class="help" style="margin-top:0;">Shown under the article as “Documents for this topic”. Leave empty to pick matches automatically. In the text, the 📄 button inserts a document card.</p>
            <input type="text" id="docPickSearch" placeholder="Search documents…" aria-label="Search documents to promote">
            <div id="docPickResults" class="tree" style="margin:6px 0;"></div>
            <div id="docPickList" class="tree"></div>
        </div></div>

        <div class="panel"><div class="panel-header">OPTIONS</div><div class="panel-body">
            <?php if ($canPublish) { ?><label class="chk"><input type="checkbox" name="featured" value="1" <?= $p['featured'] ? 'checked' : '' ?>><span>⭐ Feature at the top of the blog</span></label><?php } ?>
            <label class="chk"><input type="checkbox" name="allow_comments" value="1" <?= $p['allow_comments'] ? 'checked' : '' ?>><span>Allow comments</span></label>
        </div></div>

        <?php if ($revs) { ?>
        <div class="panel"><div class="panel-header">REVISIONS (<?= count($revs) ?>)</div><div class="panel-body">
            <ul class="post-revs">
            <?php foreach ($revs as $r) { echo '<li><span>' . e(fmt_date($r['created_at'], true)) . ($r['kind'] === 'autosave' ? ' <span class="badge b-orange">autosave</span>' : '') . '<br><span class="muted small">' . e($r['who'] ?: '—') . '</span></span><button type="submit" class="btn-classic btn-sm" name="do" value="restore_rev" formnovalidate onclick="this.form.rev.value=\'' . (int)$r['id'] . '\'" data-confirm="Replace the current text with this revision? (The current text is kept as a revision.)">Restore</button></li>'; } ?>
            </ul>
        </div></div>
        <?php } ?>
    </aside>
</div>
</form>

<div class="modal-overlay" id="mediaModal" aria-hidden="true">
    <div class="modal-box media-modal" role="dialog" aria-modal="true" aria-labelledby="mediaModalTitle">
        <div class="modal-header" id="mediaModalTitle">MEDIA LIBRARY</div>
        <div class="modal-body">
            <div class="flex" style="gap:8px; margin-bottom:10px; flex-wrap:wrap;">
                <input type="search" id="mediaSearch" placeholder="Search images…" aria-label="Search images" style="flex:1; min-width:160px;">
                <label class="btn-classic success btn-sm" style="cursor:pointer;">⬆ Upload<input type="file" id="mediaUpload" accept=".jpg,.jpeg,.png,.webp" multiple class="sr-only"></label>
            </div>
            <div class="media-grid" id="mediaGrid"></div>
            <button type="button" class="btn-classic btn-sm hidden" id="mediaMore" style="margin-top:8px;">Load more</button>
        </div>
        <div class="modal-actions"><button type="button" class="modal-btn" id="mediaClose">CLOSE</button></div>
    </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
