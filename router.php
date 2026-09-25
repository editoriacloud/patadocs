<?php
/**
 * PATADOCS — clean-URL router. .htaccess sends every non-file URL here as ?path=...
 *   /collection/<slug>                    → collection.php
 *   /education/grade-7/                    → category.php  (category path)
 *   /education/grade-7/<document-slug>     → document.php  (category path + slug)
 * Wrong category prefixes are redirected (301) to the canonical URL.
 */
require __DIR__ . '/includes/init.php';

$path = trim(rawurldecode((string)($_GET['path'] ?? '')), '/');
$path = preg_replace('#/+#', '/', $path);
if ($path === '' || strlen($path) > 400 || !preg_match('#^[A-Za-z0-9._\-/]+$#', $path) || strpos($path, '..') !== false) { abort_page(404, 'Page not found', 'The page you are looking for does not exist.'); }
$segs = explode('/', $path);

// Collections
if ($segs[0] === 'collection' && count($segs) === 2) { $GLOBALS['ROUTE_COLLECTION'] = $segs[1]; require __DIR__ . '/collection.php'; exit; }

// Category by full path (canonical form has a trailing slash)
if (($cat = cat_by_path($path)) !== null) {
    if ($cat['status'] !== 'active') { abort_page(404, 'Category not found', 'This category is not available.'); }
    $uriPath = (string)strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?');
    if (substr($uriPath, -1) !== '/') {
        $qs = ltrim(preg_replace('/(^|&)path=[^&]*/', '', (string)($_SERVER['QUERY_STRING'] ?? '')), '&');
        redirect(cat_url($cat) . ($qs !== '' ? (strpos(cat_url($cat), '?') === false ? '?' : '&') . $qs : ''), 301);
    }
    $GLOBALS['ROUTE_CAT'] = $cat; require __DIR__ . '/category.php'; exit;
}

// Document = <category path>/<slug>
$slug = array_pop($segs); $catPath = implode('/', $segs);
$doc = doc_get_by_slug($slug);
if ($doc) {
    $actual = (string)($doc['category_path'] ?? '');
    if ($actual !== $catPath) { redirect(doc_url($doc), 301); }          // wrong/old category path → canonical URL
    $GLOBALS['ROUTE_DOC'] = $doc; require __DIR__ . '/document.php'; exit;
}
abort_page(404, 'Page not found', 'We could not find that page. Try searching for the document you need.', [['🔍 SEARCH DOCUMENTS', page_url('search')], ['🏠 HOME', url('')]]);
