<?php
/**
 * AJAX search:
 *   ?mode=suggest&q=...            live suggestions (documents + categories)
 *   ?mode=results&q=&cat=&f[x]=... HTML for the browse page (filters + table + pager)
 */
define('PD_AJAX', true);
require __DIR__ . '/../includes/init.php';

if (get_str('mode', 10) === 'results') {
    $o = search_options_from($_GET);
    $res = search_documents($o);
    json_out(['ok' => true, 'html' => search_zone_html($o, $res), 'total' => $res['total']]);
}

$q = get_str('q', 100);
if (mb_strlen(search_norm($q)) < 2) { json_out(['ok' => true, 'docs' => [], 'cats' => [], 'total' => 0]); }
// Keystroke suggestions skip spelling correction (cheap); the final request (log=1) and the results page correct typos.
$res = search_documents(['q' => $q, 'per' => 8, 'page' => 1, '_nospell' => get_str('log', 1) !== '1']);
$docs = [];
foreach ($res['rows'] as $d) {
    $docs[] = ['title' => $d['title'], 'url' => doc_url($d), 'icon' => doc_icon((string)$d['file_ext']), 'category' => $d['category_name'] ?? '—',
        'format' => doc_ext_label((string)$d['file_ext']), 'pages' => $d['pages'] ? (int)$d['pages'] : 0, 'price' => price_label($d)];
}
$cats = []; $counts = cat_counts(); $needle = search_norm($q);
foreach (cats_all() as $c) {
    if ($c['status'] === 'active' && $needle !== '' && mb_stripos($c['name'], $needle) !== false) {
        $cats[] = ['name' => $c['name'], 'url' => cat_url($c), 'icon' => $c['icon'] ?: '📁', 'count' => (int)($counts[(int)$c['id']] ?? 0)];
        if (count($cats) >= 3) { break; }
    }
}
if (get_str('log', 1) === '1') { search_log($q, (int)$res['strict_total']); }
json_out(['ok' => true, 'docs' => $docs, 'cats' => $cats, 'total' => $res['total']]);
