<?php
/** Blog sidebar: search, categories, most-read articles, tags, store call-to-action. Needs $blogName. */
$sbCats = array_filter(blog_categories(), function ($c) { return (int)$c['n'] > 0; });
$sbPopular = cache_remember('blog_popular', 600, function () { return blog_posts(blog_live_sql() . ' AND p.robots_noindex = 0', [], 5, 0, 'p.views DESC, p.published_at DESC'); });
$sbTags = cache_remember('blog_tags_top', 600, function () {
    return db_all('SELECT t.name, t.slug, COUNT(*) n FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id = t.id JOIN blog_posts p ON p.id = pt.post_id
                   WHERE ' . blog_live_sql() . ' GROUP BY t.id ORDER BY n DESC, t.name LIMIT 20');
});
?>
<aside class="blog-sidebar" aria-label="Blog sidebar">
    <div class="panel"><div class="panel-header">SEARCH ARTICLES</div><div class="panel-body">
        <form method="get" action="<?= e(blog_url()) ?>" role="search" class="blog-search">
            <?php if (setting('clean_urls', '1') !== '1') { echo '<input type="hidden" name="route" value="">'; } ?>
            <label class="sr-only" for="blogQ">Search articles</label>
            <input type="search" id="blogQ" name="q" maxlength="100" value="<?= e(get_str('q', 100)) ?>" placeholder="Search articles…">
            <button type="submit" class="btn-classic primary btn-sm">GO</button>
        </form>
    </div></div>
    <?php if ($sbCats) { ?>
    <div class="panel"><div class="panel-header">CATEGORIES</div><div class="panel-body"><ul class="blog-side-list">
        <?php foreach ($sbCats as $c) { echo '<li><a href="' . e(blog_cat_url($c)) . '">' . e($c['name']) . '</a> <span class="muted">(' . (int)$c['n'] . ')</span></li>'; } ?>
    </ul></div></div>
    <?php } ?>
    <?php if ($sbPopular) { ?>
    <div class="panel"><div class="panel-header">MOST READ</div><div class="panel-body"><ol class="blog-side-list blog-popular">
        <?php foreach ($sbPopular as $p) { echo '<li><a href="' . e(post_url($p)) . '">' . e($p['title']) . '</a></li>'; } ?>
    </ol></div></div>
    <?php } ?>
    <?php if ($sbTags) { ?>
    <div class="panel"><div class="panel-header">TOPICS</div><div class="panel-body"><div class="blog-tags">
        <?php foreach ($sbTags as $t) { echo '<a class="blog-tag" href="' . e(blog_tag_url($t)) . '">#' . e($t['name']) . '</a>'; } ?>
    </div></div></div>
    <?php } ?>
    <div class="panel"><div class="panel-header orange">DOCUMENTS</div><div class="panel-body">
        <p style="margin-top:0;">Schemes of work, exams, templates, forms and business plans — ready to download.</p>
        <a class="btn-classic primary block" href="<?= e(page_url('search')) ?>">🔍 BROWSE DOCUMENTS</a>
        <p class="help" style="margin-bottom:0;"><a href="<?= e(blog_url('feed')) ?>">RSS feed</a></p>
    </div></div>
</aside>
