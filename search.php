<?php
/** PATADOCS — Browse / search results with dynamic filters (AJAX-enhanced, works without JS). */
require __DIR__ . '/includes/init.php';

$o = search_options_from($_GET);
$res = search_documents($o);
if ($o['q'] !== '' && $o['page'] === 1) {
    $f = array_filter(['cat' => $o['cat'], 'format' => $o['format'], 'price' => $o['price'], 'f' => $o['filters']]);
    search_log($o['q'], (int)$res['strict_total'], $f);
}
$plain = $o['q'] === '' && !$o['cat'] && !$o['format'] && !$o['price'] && !$o['filters'] && !$o['tag'] && $o['page'] === 1 && $o['sort'] === '' && $o['min'] === '' && $o['max'] === '';
$title = $o['q'] !== '' ? 'Search results for "' . $o['q'] . '"' : 'Browse Documents';
$meta = [
    'title' => $title, 'nav' => 'browse', 'canonical' => page_url('search'),
    'description' => 'Search and browse documents: schemes of work, exams, CV templates, business plans, forms and more. Preview, pay via M-Pesa and download instantly.',
    'robots' => $plain ? 'index,follow' : 'noindex,follow',
];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-browse">
    <div class="panel">
        <div class="panel-header">DOCUMENT LIST</div>
        <div class="panel-body">
            <?= search_hero_html('browse', 'Search documents in the library...', $o['q'], 'margin-bottom:16px; max-width:100%;') ?>
            <div id="searchZone"><?= search_zone_html($o, $res) ?></div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
