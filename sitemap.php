<?php
/**
 * PATADOCS — XML sitemaps (published, indexable content only).
 *   /sitemap.xml                 → sitemap index
 *   /sitemap-pages.xml           → home + static pages
 *   /sitemap-categories.xml      → categories that have documents
 *   /sitemap-collections.xml     → published bundles
 *   /sitemap-documents-N.xml     → documents, SITEMAP_CHUNK per file, with their preview images (Google Images)
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
if (!in_array($part, ['', 'pages', 'categories', 'collections', 'documents'], true)) { http_response_code(404); exit; }
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
