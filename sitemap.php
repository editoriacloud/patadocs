<?php
/**
 * PATADOCS — XML sitemaps (published, indexable content only).
 *   /sitemap.xml                 → sitemap index
 *   /sitemap-pages.xml           → home + static pages
 *   /sitemap-categories.xml      → categories that have documents
 *   /sitemap-collections.xml     → published bundles
 *   /sitemap-documents-N.xml     → documents, SITEMAP_CHUNK per file, with their preview images (Google Images)
 *   /sitemap-posts.xml           → blog articles (with their images) + blog categories that have articles
 * Rewritten here by .htaccess; without rewriting use sitemap.php?part=documents&n=1.
 */
define('PD_NO_SESSION', true);
require __DIR__ . '/includes/init.php';
if (setting('sitemap_enabled', '1') !== '1') { http_response_code(404); exit; }

const SITEMAP_CHUNK = 5000;
$clean = setting('clean_urls', '1') === '1';
$partUrl = function (string $part, int $n = 0) use ($clean) {
    return $clean ? url('sitemap-' . $part . ($n ? '-' . $n : '') . '.xml') : url('sitemap.php?part=' . $part . ($n ? '&n=' . $n : ''));
};
$iso = function ($d) { return $d ? date('c', strtotime((string)$d)) : null; };
$x = function (string $u, $mod = null, array $images = []) use ($iso) {
    $h = '<url><loc>' . htmlspecialchars($u, ENT_XML1) . '</loc>' . ($mod ? '<lastmod>' . $iso($mod) . '</lastmod>' : '');
    foreach ($images as $img) {
        $h .= '<image:image><image:loc>' . htmlspecialchars($img, ENT_XML1) . '</image:loc></image:image>';
    }
    return $h . "</url>\n";
};

$part = get_str('part', 20);
if (!in_array($part, ['', 'pages', 'categories', 'collections', 'documents', 'posts'], true) || ($part === 'posts' && !blog_enabled())) { http_response_code(404); exit; }
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

$docTotal = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published'");

if ($part === '') {
    $last = function (string $sql) { return db_val($sql); };
    $docMod = $last("SELECT MAX(updated_at) FROM documents WHERE status = 'published'");
    $parts = [
        [$partUrl('pages'), $docMod],
        [$partUrl('categories'), $last('SELECT MAX(updated_at) FROM categories')],
        [$partUrl('collections'), $last("SELECT MAX(updated_at) FROM collections WHERE status = 'published'")],
    ];
    if (blog_enabled()) { $parts[] = [$partUrl('posts'), $last('SELECT MAX(GREATEST(updated_at, published_at)) FROM blog_posts p WHERE ' . blog_live_sql())]; }
    for ($n = 1; $n <= max(1, (int)ceil($docTotal / SITEMAP_CHUNK)); $n++) { $parts[] = [$partUrl('documents', $n), $docMod]; }
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    foreach ($parts as [$u, $mod]) { echo '<sitemap><loc>' . htmlspecialchars($u, ENT_XML1) . '</loc>' . ($mod ? '<lastmod>' . $iso($mod) . '</lastmod>' : '') . "</sitemap>\n"; }
    echo '</sitemapindex>';
    exit;
}

echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
switch ($part) {
    case 'pages':
        echo $x(url(''), db_val("SELECT MAX(updated_at) FROM documents WHERE status = 'published'"));
        // Only real, indexable pages (recover/saved/payment are noindex and stay out)
        foreach (['search', 'categories', 'popular', 'contribute', 'request-document', 'about', 'contact', 'privacy-policy', 'terms', 'cookie-policy', 'copyright', 'disclaimer'] as $p) { echo $x(page_url($p)); }
        break;
    case 'posts':
        $latest = db_val('SELECT MAX(GREATEST(updated_at, published_at)) FROM blog_posts p WHERE ' . blog_live_sql());
        echo $x(blog_url(), $latest);
        foreach (blog_categories() as $c) { if ((int)$c['n'] > 0) { echo $x(blog_cat_url($c), $latest); } }
        if (setting('blog_index_tags', '0') === '1') {
            foreach (db_all('SELECT t.slug FROM blog_tags t JOIN blog_post_tags pt ON pt.tag_id = t.id JOIN blog_posts p ON p.id = pt.post_id WHERE ' . blog_live_sql() . ' GROUP BY t.id HAVING COUNT(*) >= 2') as $t) { echo $x(blog_tag_url($t)); }
        }
        foreach (db_all('SELECT slug, cover_image, content, updated_at, published_at, canonical_url FROM blog_posts p WHERE ' . blog_live_sql() . ' AND p.robots_noindex = 0 ORDER BY p.published_at DESC LIMIT 20000') as $p) {
            if (trim((string)$p['canonical_url']) !== '' && $p['canonical_url'] !== post_url($p)) { continue; }   // canonical points elsewhere → not ours to list
            $imgs = $p['cover_image'] ? [url($p['cover_image'])] : [];
            if (preg_match_all('#<img[^>]+src="([^"]+)"#i', (string)$p['content'], $m)) { foreach (array_slice($m[1], 0, 5) as $i) { $imgs[] = blog_abs(html_entity_decode($i)); } }
            echo $x(post_url($p), max($p['updated_at'], $p['published_at']), array_values(array_unique($imgs)));
        }
        break;
    case 'categories':
        $counts = cat_counts();
        foreach (cats_all() as $c) {
            // same rule as category.php: empty categories without sub-categories are noindex
            if ($c['status'] === 'active' && (($counts[(int)$c['id']] ?? 0) > 0 || cat_children((int)$c['id'], true))) { echo $x(cat_url($c), $c['updated_at']); }
        }
        break;
    case 'collections':
        foreach (db_all("SELECT slug, updated_at, cover_image FROM collections WHERE status = 'published' ORDER BY id") as $c) {
            echo $x(collection_url($c), $c['updated_at'], $c['cover_image'] ? [url($c['cover_image'])] : []);
        }
        break;
    case 'documents':
        $n = max(1, get_int('n', 1));
        $rows = db_all("SELECT d.id, d.slug, d.category_id, d.updated_at, d.preview_status, d.preview_dir, d.preview_pages, c.path AS category_path
                        FROM documents d LEFT JOIN categories c ON c.id = d.category_id
                        WHERE d.status = 'published' ORDER BY d.id LIMIT " . SITEMAP_CHUNK . ' OFFSET ' . (($n - 1) * SITEMAP_CHUNK));
        foreach ($rows as $d) { echo $x(doc_url($d), $d['updated_at'], array_slice(doc_preview_urls($d), 0, 3)); }
        break;
}
echo '</urlset>';
