<?php
/**
 * One blog post (used by blog.php and the admin's live preview). Needs $post (BLOG_SELECT row, or the unsaved
 * editor values for a preview), $preview (bool: not public → noindex + banner) and $blogName.
 */
$blogName = $blogName ?? setting('blog_title', 'Blog');
$pid = (int)($post['id'] ?? 0);
$author = !empty($post['author_id']) ? db_row('SELECT id, username, full_name, bio, author_title, author_links FROM admin_users WHERE id = ?', [$post['author_id']]) : null;
$tags = $pid ? blog_post_tags($pid) : ($post['_tags'] ?? []);
$url = post_url($post);
$metaForAds = ['robots' => ($preview || $post['robots_noindex']) ? 'noindex' : 'index'];
$render = blog_render_content((string)$post['content'], $preview ? null : function () use ($metaForAds) { return ad_slot('blog_in_article', $metaForAds); }, !empty($post['cover_image']));
$words = (int)$post['word_count'] ?: blog_word_count(blog_plain((string)$post['content']));
$comments = $pid ? blog_comments_tree($pid) : ['list' => [], 'count' => 0];
$commentsOpen = setting('blog_comments', '1') === '1' && (int)$post['allow_comments'] === 1 && !$preview;
$canonical = trim((string)$post['canonical_url']) !== '' ? (string)$post['canonical_url'] : $url;
$crumbs = [['Home', url('')], [$blogName, blog_url()]];
if (!empty($post['category_name'])) { $crumbs[] = [$post['category_name'], blog_cat_url(['slug' => $post['category_slug']])]; }
$crumbs[] = [$post['title'], null];
$pubTs = strtotime((string)($post['published_at'] ?: 'now'));
$modTs = strtotime((string)($post['updated_at'] ?? 'now'));
$cover = null;
if (!empty($post['cover_image'])) { $cover = db_row('SELECT width, height, alt FROM blog_media WHERE file_path = ? LIMIT 1', [$post['cover_image']]) ?: ['width' => 0, 'height' => 0, 'alt' => '']; }
$coverAlt = trim((string)($post['cover_alt'] ?? '')) ?: (string)($cover['alt'] ?? '') ?: (string)$post['title'];
$schema = [blog_post_schema(array_merge($post, ['word_count' => $words, 'published_at' => date('Y-m-d H:i:s', $pubTs), 'updated_at' => date('Y-m-d H:i:s', $modTs)]), $author, $tags, $render, $comments['count']), breadcrumb_schema($crumbs)];
$meta = [
    'title' => trim((string)$post['seo_title']) !== '' ? $post['seo_title'] : $post['title'],
    'description' => trim((string)$post['meta_description']) !== '' ? $post['meta_description'] : blog_excerpt($post, 158),
    'canonical' => $canonical, 'robots' => ($preview || $post['robots_noindex']) ? 'noindex,nofollow' : 'index,follow', 'nav' => 'blog',
    'og_type' => 'article', 'og_image' => !empty($post['cover_image']) ? url($post['cover_image']) : ($render['images'][0] ?? ''), 'og_image_alt' => $coverAlt,
    'published' => date('Y-m-d H:i:s', $pubTs), 'modified' => date('Y-m-d H:i:s', $modTs),
    'article_section' => $post['category_name'] ?? '', 'article_tags' => array_column($tags, 'name'), 'author_name' => $author ? ($author['full_name'] ?: $author['username']) : '',
    'keywords' => implode(', ', array_filter(array_merge([(string)$post['focus_keyword']], array_column($tags, 'name')))),
    'schema' => $schema,
];
if ($preview) { $meta['no_ads'] = true; }
$relDocs = ($pid || trim((string)$post['doc_ids']) !== '') ? blog_related_docs($post, 4) : [];
$relPosts = $pid && !$preview ? blog_related_posts($post, 3) : [];
$prevPost = $nextPost = null;
if ($pid && !$preview) {
    $prevPost = db_row('SELECT title, slug FROM blog_posts p WHERE ' . blog_live_sql() . ' AND (p.published_at < ? OR (p.published_at = ? AND p.id < ?)) ORDER BY p.published_at DESC, p.id DESC LIMIT 1', [$post['published_at'], $post['published_at'], $pid]);
    $nextPost = db_row('SELECT title, slug FROM blog_posts p WHERE ' . blog_live_sql() . ' AND (p.published_at > ? OR (p.published_at = ? AND p.id > ?)) ORDER BY p.published_at, p.id LIMIT 1', [$post['published_at'], $post['published_at'], $pid]);
}
include ROOT_DIR . '/includes/header.php';
$status = (string)($post['status'] ?? 'draft');
?>
<section class="page-section active" id="page-post">
    <?php if ($preview) { echo '<div class="alert alert-warn">👁 <strong>Preview</strong> — ' . (!$pid ? 'unsaved changes from the editor' : ($status === 'published' ? 'scheduled for ' . e(fmt_date($post['published_at'], true)) : 'this article is ' . e($status === 'pending' ? 'waiting for review' : $status))) . '. Only signed-in editors can see it; search engines are told not to index it.</div>'; } ?>
    <?= breadcrumb_html($crumbs) ?>
    <div class="blog-layout">
        <div class="blog-main">
            <article class="panel blog-article"><div class="panel-body">
                <header class="blog-head">
                    <?php if (!empty($post['category_name'])) { echo '<a class="blog-cat-chip" href="' . e(blog_cat_url(['slug' => $post['category_slug']])) . '">' . e($post['category_name']) . '</a>'; } ?>
                    <h1 class="blog-title"><?= e($post['title']) ?></h1>
                    <div class="blog-meta">
                        <?php if ($author) { echo 'By <a href="' . e(blog_author_url($author)) . '" rel="author">' . e($author['full_name'] ?: $author['username']) . '</a> · '; } ?>
                        <time datetime="<?= e(date('c', $pubTs)) ?>"><?= e(date('j M Y', $pubTs)) ?></time>
                        <?php if ($modTs - $pubTs > 86400) { echo ' · Updated <time datetime="' . e(date('c', $modTs)) . '">' . e(date('j M Y', $modTs)) . '</time>'; } ?>
                        · <?= blog_reading_minutes($words) ?> min read
                        <?php if ($comments['count']) { echo ' · <a href="#comments">' . (int)$comments['count'] . ' comment' . ($comments['count'] === 1 ? '' : 's') . '</a>'; } ?>
                    </div>
                </header>
                <?php if (!empty($post['cover_image'])) { ?>
                <figure class="blog-cover"><img src="<?= e(url($post['cover_image'])) ?>" alt="<?= e($coverAlt) ?>"<?= !empty($cover['width']) ? ' width="' . (int)$cover['width'] . '" height="' . (int)$cover['height'] . '"' : '' ?> fetchpriority="high" decoding="async"></figure>
                <?php } ?>
                <?= (int)($post['show_toc'] ?? 1) === 1 ? blog_toc_html($render['toc']) : '' ?>
                <div class="blog-content"><?= $render['html'] ?></div>
                <?php if ($tags) { echo '<div class="blog-tags" style="margin-top:18px;">'; foreach ($tags as $t) { echo '<a class="blog-tag" href="' . e(blog_tag_url($t)) . '" rel="tag">#' . e($t['name']) . '</a>'; } echo '</div>'; } ?>
                <?= share_links_html($url, (string)$post['title']) ?>
                <?php if ($author && trim((string)$author['bio']) !== '') { $an = $author['full_name'] ?: $author['username']; ?>
                <div class="blog-author-box"><div class="blog-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($an, 0, 1))) ?></div><div>
                    <div class="blog-author-name">About <a href="<?= e(blog_author_url($author)) ?>"><?= e($an) ?></a><?= $author['author_title'] ? ' <span class="muted">· ' . e($author['author_title']) . '</span>' : '' ?></div>
                    <p><?= nl2br(e($author['bio'])) ?></p>
                </div></div>
                <?php } ?>
            </div></article>

            <?= $preview ? '' : ad_slot('blog_after', $meta) ?>

            <?php if ($relDocs) { ?>
            <div class="panel"><div class="panel-header orange">DOCUMENTS FOR THIS TOPIC</div><div class="panel-body">
                <?php foreach ($relDocs as $d) { echo blog_doc_card($d); } ?>
            </div></div>
            <?php } ?>

            <?php if ($relPosts) { ?>
            <div class="panel"><div class="panel-header">KEEP READING</div><div class="panel-body"><div class="blog-grid blog-grid-3">
                <?php foreach ($relPosts as $p) { echo blog_card_html($p); } ?>
            </div></div></div>
            <?php } ?>

            <?php if ($prevPost || $nextPost) { ?>
            <nav class="blog-prevnext" aria-label="More articles">
                <?= $prevPost ? '<a class="prev" href="' . e(post_url($prevPost)) . '" rel="prev"><span>← Older</span>' . e($prevPost['title']) . '</a>' : '<span></span>' ?>
                <?= $nextPost ? '<a class="next" href="' . e(post_url($nextPost)) . '" rel="next"><span>Newer →</span>' . e($nextPost['title']) . '</a>' : '<span></span>' ?>
            </nav>
            <?php } ?>

            <?php if ($commentsOpen || $comments['count']) { ?>
            <div class="panel" id="comments"><div class="panel-header">COMMENTS (<?= (int)$comments['count'] ?>)</div><div class="panel-body">
                <?php
                $cHtml = function (array $c) {
                    return '<div class="blog-comment' . ($c['is_staff'] ? ' is-staff' : '') . '" id="comment-' . (int)$c['id'] . '"><div class="blog-comment-head"><strong>' . e($c['name']) . '</strong>'
                        . ($c['is_staff'] ? ' <span class="badge b-blue">' . e(setting('site_name')) . '</span>' : '') . ' <time class="muted small" datetime="' . e(date('c', strtotime((string)$c['created_at']))) . '">' . e(time_ago($c['created_at'])) . '</time></div>'
                        . '<div class="blog-comment-body">' . nl2br(e($c['body'])) . '</div>';
                };
                if (!$comments['list']) { echo '<p class="muted">No comments yet. Ask a question or share your experience.</p>'; }
                foreach ($comments['list'] as $c) {
                    echo $cHtml($c);
                    if ($commentsOpen) { echo '<button type="button" class="btn-classic btn-sm blog-reply-btn" data-reply="' . (int)$c['id'] . '" data-name="' . e($c['name']) . '">↩ Reply</button>'; }
                    foreach ($c['replies'] as $r) { echo '<div class="blog-replies">' . $cHtml($r) . '</div></div>'; }
                    echo '</div>';
                }
                ?>
                <?php if ($commentsOpen) { ?>
                <form class="pd-form blog-comment-form" id="blogCommentForm" method="post" action="<?= e(url('ajax/blog-comment.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="post_id" value="<?= $pid ?>"><input type="hidden" name="parent_id" value="0" id="commentParent">
                    <input type="hidden" name="ts" value="<?= e(blog_form_ts()) ?>">
                    <div class="hp-field" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <div class="section-title" style="margin-top:14px;" id="commentFormTitle">LEAVE A COMMENT</div>
                    <p class="help">Comments are checked before they appear. Your email is never shown.</p>
                    <div class="form-grid">
                        <div class="frow"><label for="cName">Name <span class="req">*</span></label><input type="text" id="cName" name="name" maxlength="80" required autocomplete="name"></div>
                        <div class="frow"><label for="cEmail">Email (optional, not published)</label><input type="email" id="cEmail" name="email" maxlength="190" autocomplete="email"></div>
                        <div class="frow full"><label for="cBody">Comment <span class="req">*</span></label><textarea id="cBody" name="body" maxlength="3000" required style="min-height:110px;"></textarea></div>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn-classic success">POST COMMENT</button><button type="button" class="btn-classic hidden" id="commentCancelReply">Cancel reply</button></div>
                    <div class="pay-msg help" role="status" aria-live="polite"></div>
                </form>
                <?php } ?>
            </div></div>
            <?php } ?>
        </div>
        <?php include ROOT_DIR . '/includes/blog_sidebar.php'; ?>
    </div>
</section>
<?php include ROOT_DIR . '/includes/footer.php'; ?>
