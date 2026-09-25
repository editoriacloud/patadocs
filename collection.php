<?php
/** PATADOCS — Collection (bundle) page. Free bundles download every file; paid bundles use one M-Pesa payment. */
require __DIR__ . '/includes/init.php';

$slug = $GLOBALS['ROUTE_COLLECTION'] ?? get_str('slug', 191);
$col = $slug !== '' ? db_row("SELECT * FROM collections WHERE slug = ? AND status = 'published'", [$slug]) : null;
if (!$col) { abort_page(404, 'Collection not found', 'This collection does not exist or is no longer available.', [['🔍 SEARCH', page_url('search')], ['🏠 HOME', url('')]]); }
$docs = db_all("SELECT d.*, c.name AS category_name, c.path AS category_path FROM collection_documents cd JOIN documents d ON d.id = cd.document_id
                LEFT JOIN categories c ON c.id = d.category_id WHERE cd.collection_id = ? AND d.status = 'published' ORDER BY cd.sort_order, d.title", [$col['id']]);
if (!isset($GLOBALS['ROUTE_COLLECTION']) && setting('clean_urls', '1') === '1') { redirect(collection_url($col), 301); }
$isFree = (int)$col['is_free'] === 1 || (float)$col['price'] <= 0;
$sumIndividual = 0.0; foreach ($docs as $d) { if (!$d['is_free']) { $sumIndividual += (float)$d['price']; } }
$canonical = collection_url($col);
$desc = trim((string)$col['meta_description']) !== '' ? $col['meta_description'] : excerpt((string)$col['description'] ?: $col['title'] . ' - a bundle of ' . count($docs) . ' documents.', 158);
$crumbs = [['Home', url('')], ['Collection', null], [$col['title'], null]];
$items = []; foreach ($docs as $i => $d) { $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => doc_url($d), 'name' => $d['title']]; }
$meta = [
    'title' => trim((string)$col['seo_title']) !== '' ? $col['seo_title'] : $col['title'], 'description' => $desc, 'canonical' => $canonical, 'nav' => 'browse',
    'og_image' => $col['cover_image'] ? url($col['cover_image']) : '', 'payment_widget' => !$isFree && $docs,
    'schema' => [['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $col['title'], 'description' => $desc, 'url' => $canonical, 'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items]],
        breadcrumb_schema([['Home', url('')], [$col['title'], null]])],
];
if ($docs) {
    $meta['schema'][] = product_schema(['name' => $col['title'], 'description' => (string)$col['description'] !== '' ? $col['description'] : $desc, 'url' => $canonical,
        'sku' => 'PD-C' . (int)$col['id'], 'images' => $col['cover_image'] ? [url($col['cover_image'])] : [], 'category' => 'Document bundle',
        'price' => (float)$col['price'], 'free' => $isFree, 'props' => ['Documents in bundle' => count($docs)]]);
}
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-collection">
    <div class="panel">
        <div class="panel-header orange">📦 DOCUMENT COLLECTION</div>
        <div class="panel-body">
            <div class="grid-2">
                <div>
                    <?php if ($col['cover_image']) { echo '<img src="' . e(url($col['cover_image'])) . '" alt="' . e($col['title']) . '" loading="lazy" style="max-width:100%; border:2px solid var(--border); border-radius:4px; margin-bottom:14px;">'; } ?>
                    <h1 class="section-title" style="margin-top:0;"><?= e($col['title']) ?></h1>
                    <?php if (trim((string)$col['description']) !== '') { echo '<div class="desc-text">' . nl2br(e($col['description'])) . '</div>'; } ?>
                </div>
                <div>
                    <div class="doc-selected">
                        <div class="lbl">Bundle</div>
                        <div class="name"><?= e($col['title']) ?></div>
                        <div class="doc-facts"><span>📄 <?= count($docs) ?> documents</span><?php if (!$isFree && $sumIndividual > (float)$col['price']) { echo '<span>💡 Save ' . e(money($sumIndividual - (float)$col['price'])) . ' vs buying separately</span>'; } ?></div>
                        <div class="price-row"><span class="k">PRICE:</span><span class="v"><?= $isFree ? 'FREE' : e(money($col['price'])) ?></span></div>
                    </div>
                    <?php if (!$docs) { echo '<div class="alert alert-warn">This collection has no published documents yet.</div>'; }
                    elseif ($isFree) { ?>
                        <form method="post" action="<?= e(url('download.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="free"><input type="hidden" name="col" value="<?= (int)$col['id'] ?>">
                            <button type="submit" class="btn-classic success block"><span style="font-size:1.1rem;">⬇ DOWNLOAD ALL — FREE</span></button></form>
                    <?php } else { ?>
                        <a class="btn-classic primary block" id="bundleBuyBtn" rel="nofollow" href="<?= e(url('payment.php?col=' . (int)$col['id'])) ?>" data-id="<?= (int)$col['id'] ?>" data-title="<?= e($col['title']) ?>" data-price="<?= e(money($col['price'])) ?>" data-docs="<?= count($docs) ?>"><span style="font-size:1.1rem;">💳 BUY BUNDLE &amp; DOWNLOAD</span></a>
                    <?php } ?>
                    <?= share_links_html($canonical, $col['title']) ?>
                </div>
            </div>
            <div class="section-title">DOCUMENTS IN THIS BUNDLE</div>
            <?= doc_table_html($docs, 'No documents in this collection yet.') ?>
        </div>
    </div>
    <?= $docs ? ad_slot('collection_list', $meta) : '' ?>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
