<?php
/**
 * PATADOCS — public blog. Routes (see includes/blog.php): '', <slug>, category/<slug>, tag/<slug>, author/<user>, feed.
 * .htaccess sends /blog/... here as ?route=...; without clean URLs links are blog.php?route=... directly.
 */
require __DIR__ . '/includes/init.php';
if (!blog_enabled()) { abort_page(404, 'Page not found', 'The page you are looking for does not exist.'); }

$raw = (string)($_GET['route'] ?? '');
$route = trim(preg_replace('#/+#', '/', rawurldecode($raw)), '/');
if (strlen($route) > 300 || !preg_match('#^[A-Za-z0-9._\-/]*$#', $route) || strpos($route, '..') !== false) { abort_page(404, 'Page not found', 'The page you are looking for does not exist.'); }
$segs = $route === '' ? [] : explode('/', $route);

// One URL per page: blog.php?route=x → /blog/x with clean URLs, /blog → /blog/, /blog/post/ → /blog/post
$uriPath = (string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
if (setting('clean_urls', '1') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $want = (string)parse_url(blog_url($route), PHP_URL_PATH);
    if ($uriPath !== '' && rawurldecode($uriPath) !== rawurldecode($want)) {
        $qs = ltrim(preg_replace('/(^|&)route=[^&]*/', '', (string)($_SERVER['QUERY_STRING'] ?? '')), '&');
        redirect(blog_url($route) . ($qs !== '' ? '?' . $qs : ''), 301);
    }
}

$blogName = setting('blog_title', 'Blog');
$per = max(3, min(50, (int)setting('blog_per_page', '10')));
$isStaff = admin_current() && admin_can('blog.write');

// ---- RSS feed -----------------------------------------------------------------------------
if ($segs === ['feed']) {
    $posts = blog_posts(blog_live_sql() . ' AND p.robots_noindex = 0', [], 20);
    header('Content-Type: application/rss+xml; charset=utf-8');
    header('Cache-Control: public, max-age=900');
    $x = function ($s) { return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
    $abs = function (string $html) {                                     // feed readers need absolute links
        return preg_replace_callback('#\s(src|href)="(/[^"/][^"]*)"#', function ($m) { return ' ' . $m[1] . '="' . blog_abs(html_entity_decode($m[2])) . '"'; }, $html);
    };
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>' . "\n";
    echo '<title>' . $x($blogName . ' — ' . setting('site_name')) . '</title><link>' . $x(blog_url()) . '</link>'
        . '<atom:link href="' . $x(blog_url('feed')) . '" rel="self" type="application/rss+xml"/>'
        . '<description>' . $x(setting('blog_description') ?: setting('seo_default_description')) . '</description><language>en-KE</language>'
        . '<lastBuildDate>' . date(DATE_RSS, $posts ? strtotime((string)$posts[0]['updated_at']) : time()) . '</lastBuildDate>' . "\n";
    foreach ($posts as $p) {
        $body = $abs((string)$p['content']);
        if ($p['cover_image']) { $body = '<p><img src="' . e(url($p['cover_image'])) . '" alt="' . e($p['cover_alt'] ?: $p['title']) . '"></p>' . $body; }
        echo '<item><title>' . $x($p['title']) . '</title><link>' . $x(post_url($p)) . '</link><guid isPermaLink="true">' . $x(post_url($p)) . '</guid>'
            . '<pubDate>' . date(DATE_RSS, strtotime((string)$p['published_at'])) . '</pubDate><dc:creator>' . $x($p['author_name'] ?: setting('site_name')) . '</dc:creator>'
            . ($p['category_name'] ? '<category>' . $x($p['category_name']) . '</category>' : '')
            . '<description>' . $x(blog_excerpt($p, 300)) . '</description><content:encoded><![CDATA[' . str_replace(']]>', ']]&gt;', $body) . ']]></content:encoded></item>' . "\n";
    }
    echo '</channel></rss>';
    exit;
}

// ---- Archives: index, category, tag, author, search -----------------------------------------
$archive = null;
if (!$segs) {
    $q = get_str('q', 100);
    $archive = ['kind' => $q !== '' ? 'search' : 'index', 'h1' => $q !== '' ? 'Articles about “' . $q . '”' : $blogName, 'base' => blog_url(),
        'intro' => $q !== '' ? '' : (string)setting('blog_description'), 'where' => blog_live_sql(), 'params' => [], 'q' => $q,
        'title' => $q !== '' ? 'Search: ' . $q . ' — ' . $blogName : (setting('blog_seo_title') ?: $blogName . ' — guides, tips and news from ' . setting('site_name')),
        'desc' => setting('blog_description') ?: 'Guides, tips and news from ' . setting('site_name') . '.'];
    if ($q !== '') {
        $like = '%' . like_escape($q) . '%';
        $archive['where'] .= ' AND (p.title LIKE ? OR p.excerpt LIKE ? OR p.content_text LIKE ? OR p.focus_keyword LIKE ?)';
        $archive['params'] = [$like, $like, $like, $like];
    }
} elseif (count($segs) === 2 && $segs[0] === 'category') {
    $c = db_row('SELECT * FROM blog_categories WHERE slug = ?', [$segs[1]]);
    if (!$c) { abort_page(404, 'Category not found', 'This blog category does not exist.', [['📰 ' . strtoupper($blogName), blog_url()], ['🏠 HOME', url('')]]); }
    $archive = ['kind' => 'category', 'h1' => $c['name'], 'base' => blog_cat_url($c), 'intro' => (string)$c['description'], 'where' => blog_live_sql() . ' AND p.category_id = ?', 'params' => [$c['id']],
        'title' => $c['seo_title'] ?: $c['name'] . ' — ' . $blogName, 'desc' => $c['meta_description'] ?: ($c['description'] ?: $c['name'] . ' articles on ' . setting('site_name') . '.'), 'crumb' => $c['name']];
} elseif (count($segs) === 2 && $segs[0] === 'tag') {
    $t = db_row('SELECT * FROM blog_tags WHERE slug = ?', [$segs[1]]);
    if (!$t) { abort_page(404, 'Tag not found', 'There are no articles with this tag.', [['📰 ' . strtoupper($blogName), blog_url()], ['🏠 HOME', url('')]]); }
    $archive = ['kind' => 'tag', 'h1' => '#' . $t['name'], 'base' => blog_tag_url($t), 'intro' => '', 'where' => blog_live_sql() . ' AND p.id IN (SELECT post_id FROM blog_post_tags WHERE tag_id = ?)', 'params' => [$t['id']],
        'title' => $t['name'] . ' — ' . $blogName, 'desc' => 'Articles tagged “' . $t['name'] . '” on ' . setting('site_name') . '.', 'crumb' => '#' . $t['name']];
} elseif (count($segs) === 2 && $segs[0] === 'author') {
    $a = db_row("SELECT * FROM admin_users WHERE LOWER(username) = ? AND status = 'active'", [strtolower($segs[1])]);
    if (!$a) { abort_page(404, 'Author not found', 'This author does not exist.', [['📰 ' . strtoupper($blogName), blog_url()], ['🏠 HOME', url('')]]); }
    $an = $a['full_name'] ?: $a['username'];
    $archive = ['kind' => 'author', 'h1' => $an, 'base' => blog_author_url($a), 'intro' => (string)$a['bio'], 'where' => blog_live_sql() . ' AND p.author_id = ?', 'params' => [$a['id']],
        'title' => $an . ($a['author_title'] ? ', ' . $a['author_title'] : '') . ' — ' . $blogName, 'desc' => $a['bio'] ? excerpt((string)$a['bio'], 158) : 'Articles by ' . $an . ' on ' . setting('site_name') . '.', 'crumb' => $an, 'author' => $a];
}

if ($archive) {
    $total = (int)db_val('SELECT COUNT(*) FROM blog_posts p WHERE ' . $archive['where'], $archive['params']);
    $pg = paginate($total, get_int('page', 1), $per);
    if (get_int('page', 1) > $pg['pages'] && $total > 0) { redirect($archive['base'], 301); }
    $order = $archive['kind'] === 'index' ? 'p.featured DESC, p.published_at DESC, p.id DESC' : 'p.published_at DESC, p.id DESC';
    if ($archive['kind'] === 'index' && $pg['page'] > 1) { $order = 'p.published_at DESC, p.id DESC'; }
    $posts = $total ? blog_posts($archive['where'], $archive['params'], $per, $pg['offset'], $order) : [];
    $params = $archive['kind'] === 'search' ? ['q' => $archive['q']] : [];
    $canon = $archive['base'] . ($pg['page'] > 1 ? (strpos($archive['base'], '?') === false ? '?' : '&') . 'page=' . $pg['page'] : '');
    $robots = 'index,follow';
    if ($archive['kind'] === 'search' || !$posts) { $robots = 'noindex,follow'; }
    if ($archive['kind'] === 'tag' && (setting('blog_index_tags', '0') !== '1' || $total < 2)) { $robots = 'noindex,follow'; }   // thin archive pages stay out of the index
    $crumbs = [['Home', url('')], [$blogName, $archive['kind'] === 'index' ? null : blog_url()]];
    if (!empty($archive['crumb'])) { $crumbs[] = [$archive['crumb'], null]; }
    if ($archive['kind'] === 'search') { $crumbs[] = ['Search', null]; }
    $items = [];
    foreach ($posts as $i => $p) { $items[] = ['@type' => 'ListItem', 'position' => $pg['offset'] + $i + 1, 'url' => post_url($p), 'name' => $p['title']]; }
    $schema = [breadcrumb_schema($crumbs)];
    if ($archive['kind'] === 'index') {
        $schema[] = ['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => $blogName . ' — ' . setting('site_name'), 'url' => blog_url(), 'description' => $archive['desc'], 'publisher' => org_schema(), 'inLanguage' => 'en-KE',
            'blogPost' => array_map(function ($p) { return ['@type' => 'BlogPosting', 'headline' => mb_substr($p['title'], 0, 110), 'url' => post_url($p), 'datePublished' => date('c', strtotime((string)$p['published_at']))]; }, $posts)];
    } elseif ($archive['kind'] === 'author') {
        $schema[] = ['@context' => 'https://schema.org', '@type' => 'ProfilePage', 'url' => $archive['base'], 'mainEntity' => blog_author_schema($archive['author'])];
    } else {
        $schema[] = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $archive['h1'], 'url' => $canon, 'description' => $archive['desc'], 'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items]];
    }
    $meta = ['title' => $archive['title'] . ($pg['page'] > 1 ? ' — Page ' . $pg['page'] : ''), 'description' => $archive['desc'] . ($pg['page'] > 1 ? ' Page ' . $pg['page'] . '.' : ''),
        'canonical' => $canon, 'robots' => $robots, 'nav' => 'blog', 'schema' => $schema] + seo_pagination($pg, $archive['base'], $params);
    $featured = $posts[0]['cover_image'] ?? '';
    if ($featured) { $meta['og_image'] = url($featured); }
    include __DIR__ . '/includes/header.php';
    ?>
    <section class="page-section active" id="page-blog">
        <?= breadcrumb_html($crumbs) ?>
        <div class="blog-layout">
            <div class="blog-main">
                <div class="panel"><div class="panel-header"><?= e(strtoupper($archive['kind'] === 'index' ? $blogName : ($archive['kind'] === 'author' ? 'AUTHOR' : ($archive['kind'] === 'search' ? 'SEARCH' : ($archive['kind'] === 'tag' ? 'TAG' : 'CATEGORY'))))) ?></div><div class="panel-body">
                    <?php if ($archive['kind'] === 'author') { $a = $archive['author']; ?>
                        <div class="blog-author-box" style="margin-top:0;"><div class="blog-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($archive['h1'], 0, 1))) ?></div><div>
                            <h1 class="section-title" style="margin:0;"><?= e($archive['h1']) ?></h1>
                            <?php if ($a['author_title']) { echo '<div class="blog-meta">' . e($a['author_title']) . '</div>'; } ?>
                            <?php if ($a['bio']) { echo '<p>' . nl2br(e($a['bio'])) . '</p>'; } ?>
                        </div></div>
                    <?php } else { ?>
                        <h1 class="section-title" style="margin-top:0;"><?= e($archive['h1']) ?><?= $pg['page'] > 1 ? ' <small class="muted">— page ' . (int)$pg['page'] . '</small>' : '' ?></h1>
                        <?php if ($archive['intro'] !== '' && $pg['page'] === 1) { echo '<p class="blog-intro">' . nl2br(e($archive['intro'])) . '</p>'; } ?>
                    <?php } ?>
                    <?php if (!$posts) { echo '<div class="alert alert-info">' . ($archive['kind'] === 'search' ? 'No articles match your search. Try other words.' : 'No articles here yet — check back soon.') . '</div>'; } ?>
                    <div class="blog-grid">
                        <?php foreach ($posts as $i => $p) { echo blog_card_html($p, $i === 0 && $pg['page'] === 1 && $archive['kind'] === 'index'); } ?>
                    </div>
                    <?= pager_html($pg, $archive['base'], $params) ?>
                </div></div>
                <?= $posts ? ad_slot('blog_list', $meta) : '' ?>
            </div>
            <?php include __DIR__ . '/includes/blog_sidebar.php'; ?>
        </div>
    </section>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- A post ---------------------------------------------------------------------------------
if (count($segs) !== 1) { abort_page(404, 'Page not found', 'We could not find that article.', [['📰 ' . strtoupper($blogName), blog_url()], ['🏠 HOME', url('')]]); }
$post = db_row(BLOG_SELECT . ' WHERE p.slug = ?', [$segs[0]]);
if (!$post && ($moved = blog_slug_redirect($segs[0])) && $moved['status'] === 'published') { redirect(post_url($moved), 301); }
$live = $post && $post['status'] === 'published' && strtotime((string)$post['published_at']) <= time();
if (!$post || $post['status'] === 'trash' || (!$live && !$isStaff)) {
    abort_page(404, 'Article not found', 'This article does not exist or is no longer available.', [['📰 ' . strtoupper($blogName), blog_url()], ['🔍 SEARCH DOCUMENTS', page_url('search')]]);
}
$preview = !$live;
if ($live && !$isStaff && !is_bot() && empty($_SESSION['blog_seen'][$post['id']])) {
    db_exec('UPDATE blog_posts SET views = views + 1 WHERE id = ?', [$post['id']]);
    $_SESSION['blog_seen'][$post['id']] = 1;
}
include __DIR__ . '/includes/blog_post_view.php';
