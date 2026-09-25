<?php
/** PATADOCS — dynamic sitemap.xml (published content only). /sitemap.xml is rewritten here by .htaccess. */
require __DIR__ . '/includes/init.php';
if (setting('sitemap_enabled', '1') !== '1') { http_response_code(404); exit; }
header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: public, max-age=3600');
$x = function ($u, $mod = null, $freq = null, $prio = null) {
    return '<url><loc>' . htmlspecialchars($u, ENT_XML1) . '</loc>' . ($mod ? '<lastmod>' . date('c', strtotime($mod)) . '</lastmod>' : '')
        . ($freq ? '<changefreq>' . $freq . '</changefreq>' : '') . ($prio ? '<priority>' . $prio . '</priority>' : '') . '</url>' . "\n";
};
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo $x(url(''), null, 'daily', '1.0');
foreach (['search', 'categories', 'popular', 'contribute', 'request-document', 'about', 'contact'] as $p) { echo $x(page_url($p), null, 'weekly', '0.5'); }
$counts = cat_counts();
foreach (cats_all() as $c) {
    if ($c['status'] === 'active' && (($counts[(int)$c['id']] ?? 0) > 0 || cat_children((int)$c['id'], true))) { echo $x(cat_url($c), $c['updated_at'], 'daily', '0.8'); }
}
foreach (db_all("SELECT id, slug, category_id, updated_at FROM documents WHERE status = 'published' ORDER BY id DESC LIMIT 45000") as $d) { echo $x(doc_url($d), $d['updated_at'], 'weekly', '0.7'); }
foreach (db_all("SELECT slug, updated_at FROM collections WHERE status = 'published'") as $c) { echo $x(collection_url($c), $c['updated_at'], 'weekly', '0.6'); }
echo '</urlset>';
