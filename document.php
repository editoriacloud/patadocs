<?php
/**
 * PATADOCS — Document page: protected preview + purchase/download, details, metadata, tags,
 * related documents, collections, share and report. Reached via clean URLs (router.php) or
 * document.php?slug=... when URL rewriting is off.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/reviews.php';

$doc = $GLOBALS['ROUTE_DOC'] ?? null;
$viaRouter = $doc !== null;
if (!$doc) {
    if (get_str('slug', 191) !== '') { $doc = doc_get_by_slug(get_str('slug', 191)); }
    elseif (get_int('id')) { $doc = doc_get(get_int('id')); }
}
$adminView = admin_current() && admin_can('documents.view');
if ($doc && $doc['status'] === 'archived' && !$adminView) {      // gone for good: 410 makes search engines drop it quickly
    abort_page(410, 'Document removed', 'This document has been removed and is no longer available. Search for a similar document or request it.',
        [['🔍 SEARCH DOCUMENTS', page_url('search')], ['📝 REQUEST A DOCUMENT', page_url('request-document')], ['🏠 HOME', url('')]]);
}
if (!$doc || ($doc['status'] !== 'published' && !$adminView)) {
    abort_page(404, 'Document not found', 'This document does not exist or is no longer available. Try searching, or request it and we will source it for you.',
        [['🔍 SEARCH DOCUMENTS', page_url('search')], ['📝 REQUEST A DOCUMENT', page_url('request-document')], ['🏠 HOME', url('')]]);
}
$canonical = doc_url($doc);
if (!$viaRouter && setting('clean_urls', '1') === '1' && $doc['status'] === 'published') { redirect($canonical, 301); }

// Count the view once per visitor session (bots are ignored).
if ($doc['status'] === 'published' && !is_bot() && session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['viewed'][$doc['id']])) {
    $_SESSION['viewed'][$doc['id']] = time();
    db_exec('UPDATE documents SET view_count = view_count + 1, updated_at = updated_at WHERE id = ?', [$doc['id']]);
    stat_bump((int)$doc['id'], 'views');
}

$id = (int)$doc['id'];
$isFree = (int)$doc['is_free'] === 1 || (float)$doc['price'] <= 0;
$crumbs = [['Home', url('')]];
foreach (cat_breadcrumb($doc['category_id'] ? (int)$doc['category_id'] : null) as $c) { $crumbs[] = [$c['name'], cat_url($c)]; }
$crumbs[] = [$doc['title'], null];
$metaRows = meta_display($id);
$tags = tags_get($id);
$related = related_docs($doc, 6);
$collections = doc_collections($id);
$pv = doc_preview_urls($doc);
$rating = review_summary($id);
$reviews = $rating['count'] ? reviews_for($id, 10) : [];
$format = doc_ext_label((string)$doc['file_ext']);
$catRow = $doc['category_id'] ? cat_get((int)$doc['category_id']) : null;
$desc = trim((string)$doc['meta_description']) !== '' ? $doc['meta_description'] : ((string)$doc['description'] !== '' ? excerpt($doc['description'], 158) : $doc['title'] . ' - ' . $format . ' document. Preview and download on ' . setting('site_name') . '.');

$catPath = $catRow ? implode(' > ', array_map(function ($c) { return $c['name']; }, cat_breadcrumb((int)$catRow['id']))) : '';
$meta = [
    'title' => trim((string)$doc['seo_title']) !== '' ? $doc['seo_title'] : $doc['title'],
    'description' => $desc, 'keywords' => $doc['seo_keywords'], 'canonical' => $canonical, 'nav' => 'browse',
    'og_type' => 'article', 'og_image' => $pv[0] ?? '', 'og_image_alt' => 'Preview of ' . $doc['title'],
    'published' => $doc['published_at'], 'modified' => $doc['updated_at'],
    'robots' => $doc['status'] === 'published' ? 'index,follow' : 'noindex,nofollow',
    'payment_widget' => !$isFree,
    'schema' => [
        array_filter(['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => $doc['title'], 'description' => $desc, 'url' => $canonical,
            'inLanguage' => 'en-KE', 'datePublished' => $doc['published_at'] ? date('c', strtotime($doc['published_at'])) : null,
            'dateModified' => date('c', strtotime($doc['updated_at'])), 'primaryImageOfPage' => $pv[0] ?? null,
            'isPartOf' => ['@type' => 'WebSite', 'name' => setting('site_name'), 'url' => url('')]]),
        product_schema(['name' => $doc['title'], 'description' => (string)$doc['description'] !== '' ? $doc['description'] : $desc, 'url' => $canonical,
            'sku' => 'PD-' . $id, 'images' => array_slice($pv, 0, 3), 'category' => $catPath, 'price' => (float)$doc['price'], 'free' => $isFree,
            'props' => ['Format' => $format, 'Pages' => $doc['pages'] ?: '', 'Document type' => (string)$doc['doc_type']],
            'rating' => $rating, 'reviews' => $reviews]),
        breadcrumb_schema($crumbs),
    ],
    'doc_js' => ['id' => $id, 'title' => $doc['title'], 'url' => $canonical, 'price' => price_label($doc), 'free' => $isFree, 'format' => $format, 'pages' => (int)$doc['pages']],
];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-preview">
    <?= breadcrumb_html($crumbs) ?>
    <?php if ($doc['status'] !== 'published') { echo '<div class="alert alert-warn">👁 <strong>Admin preview:</strong> this document is <strong>' . e(str_replace('_', ' ', $doc['status'])) . '</strong> and is not visible to the public.</div>'; } ?>

    <div class="panel">
        <div class="panel-header orange">DOCUMENT PREVIEW &amp; PURCHASE</div>
        <div class="panel-body">
            <div class="grid-2">
                <!-- ===== PROTECTED PREVIEW ===== -->
                <div>
                    <?php if ($pv) { ?>
                        <div class="preview-container has-img" id="previewContainer">
                            <div class="preview-watermark" id="previewWatermark"><?= e(setting('preview_watermark', 'PATADOCS PREVIEW')) ?></div>
                            <div class="preview-page has-img" id="previewPage"><img id="pvImg" src="<?= e($pv[0]) ?>" style="width:100%;" fetchpriority="high" decoding="async" alt="Protected preview of <?= e($doc['title']) ?> — page 1" draggable="false"></div>
                        </div>
                        <div class="viewer-bar" id="previewViewer" data-pages="<?= e(json_encode($pv)) ?>">
                            <button type="button" class="btn-classic" id="pvPrev">◀ PREV</button>
                            <span class="count" id="pvCount">Page 1 / <?= count($pv) ?></span>
                            <button type="button" class="btn-classic" id="pvNext">NEXT ▶</button>
                            <button type="button" class="btn-classic" id="pvZoomOut" title="Zoom out">−</button>
                            <span class="zoomv" id="pvZoom">100%</span>
                            <button type="button" class="btn-classic" id="pvZoomIn" title="Zoom in">+</button>
                            <button type="button" class="btn-classic" id="pvFull" title="Fullscreen">⛶ FULLSCREEN</button>
                        </div>
                    <?php } else { ?>
                        <div class="preview-container" id="previewContainer">
                            <div class="preview-watermark" id="previewWatermark"><?= e(setting('preview_watermark', 'PATADOCS PREVIEW')) ?></div>
                            <div class="preview-page preview-text" id="previewPage">
                                <div class="row"><strong>📄 <?= e($doc['title']) ?></strong></div>
                                <?php if ($catRow) { echo '<div class="row">Category: ' . e($catRow['name']) . '</div>'; } ?>
                                <?php if ($doc['doc_type']) { echo '<div class="row">Type: ' . e($doc['doc_type']) . '</div>'; } ?>
                                <div class="row">Format: <?= e($format) ?><?= $doc['pages'] ? ' · ' . e(pages_label($doc['pages'])) : '' ?> · <?= e(fmt_size($doc['file_size'])) ?></div>
                                <?php if ($doc['description']) { echo '<div class="row" style="margin-top:14px;">' . e(excerpt($doc['description'], 260)) . '</div>'; } ?>
                            </div>
                        </div>
                    <?php } ?>
                    <div class="alert alert-info" style="margin-top:12px;">🔒 <strong>This is a protected preview.</strong> <?= $isFree ? 'Use the button to download the full original file for free.' : 'Purchase the document to access the full original file.' ?></div>
                </div>

                <!-- ===== SELECTED DOCUMENT + ACTIONS ===== -->
                <div>
                    <div class="doc-selected">
                        <div class="lbl">Selected Document</div>
                        <h1 class="name"><?= e($doc['title']) ?></h1>
                        <?php if ($rating['count']) { echo '<a class="rating-line" href="#reviews">' . stars_html($rating['avg']) . ' <strong>' . e(number_format($rating['avg'], 1)) . '</strong> (' . num($rating['count']) . ' review' . ($rating['count'] === 1 ? '' : 's') . ')</a>'; } ?>
                        <div class="doc-facts">
                            <?php if ($catRow) { echo '<span>📁 ' . e($catRow['name']) . '</span>'; } ?>
                            <span>📄 <?= e($format) ?></span>
                            <?php if ($doc['pages']) { echo '<span>📖 ' . e(pages_label($doc['pages'])) . '</span>'; } ?>
                            <span>💾 <?= e(fmt_size($doc['file_size'])) ?></span>
                            <span>🕒 Updated <?= e(fmt_date($doc['updated_at'])) ?></span>
                        </div>
                        <div class="price-row"><span class="k">PRICE:</span><span class="v" id="totalAmount"><?= e(price_label($doc)) ?></span></div>
                    </div>

                    <div class="action-label">Actions</div>
                    <div class="action-row">
                        <?php if ($isFree) { ?>
                            <form id="freeDownloadForm" method="post" action="<?= e(url('download.php')) ?>" style="flex:1; min-width:160px; margin:0;">
                                <?= csrf_field() ?><input type="hidden" name="action" value="free"><input type="hidden" name="doc" value="<?= $id ?>">
                                <button type="submit" class="btn-classic success block" id="freeDownloadBtn"><span style="font-size:1.1rem;">⬇ DOWNLOAD FREE</span></button>
                            </form>
                        <?php } else { ?>
                            <a class="btn-classic primary" id="checkoutBtn" rel="nofollow" href="<?= e(url('payment.php?doc=' . $id)) ?>"><span style="font-size:1.1rem;">💳 BUY &amp; DOWNLOAD</span></a>
                        <?php } ?>
                    </div>
                    <div class="action-row">
                        <button type="button" class="btn-classic" id="saveBtn">☆ SAVE</button>
                        <button type="button" class="btn-classic warning" id="requestBtn">📝 REQUEST A DOCUMENT</button>
                    </div>
                    <div class="action-row">
                        <button type="button" class="btn-classic danger" id="reportBtn">🚩 REPORT DOCUMENT</button>
                    </div>
                    <div class="keys-hint">F1: Search · F2: Preview · F3: Pay · F9: Download</div>
                    <?= share_links_html($canonical, $doc['title']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">ABOUT THIS DOCUMENT</div>
        <div class="panel-body">
            <?php if (trim((string)$doc['description']) !== '') { echo '<div class="desc-text">' . nl2br(e($doc['description'])) . '</div>'; } ?>
            <?php if (($inside = doc_content_excerpt($doc, (int)setting('content_excerpt_words', 80))) !== '') { ?>
                <div class="section-title">📖 FROM INSIDE THE DOCUMENT</div>
                <blockquote class="doc-excerpt"><?= e($inside) ?></blockquote>
            <?php } ?>
            <?php if ((int)$doc['show_contributor'] === 1 && $doc['contributor_name']) { ?>
                <div class="contrib-note">🤝 Contributed by: <strong><?= e($doc['contributor_name']) ?></strong>
                    <?php if ($doc['contributor_badge'] === 'verified') { echo ' <span class="badge attribution">✔ Verified Contributor</span>'; } elseif ($doc['contributor_badge'] === 'community') { echo ' <span class="badge attribution">Community Contributor</span>'; } ?>
                    <?php if ($doc['author']) { echo '<div class="help" style="margin-top:4px;">Source / author: ' . e($doc['author']) . '</div>'; } ?>
                </div>
            <?php } elseif ($doc['author']) { echo '<div class="contrib-note">Author / source: <strong>' . e($doc['author']) . '</strong></div>'; } ?>

            <div class="section-title">DOCUMENT DETAILS</div>
            <div class="gv-wrap"><table class="gv-table kv"><tbody>
                <?php if ($catRow) { echo '<tr><td>Category</td><td><a href="' . e(cat_url($catRow)) . '">' . e(implode(' › ', array_map(function ($c) { return $c['name']; }, cat_breadcrumb((int)$catRow['id'])))) . '</a></td></tr>'; } ?>
                <?php if ($doc['doc_type']) { echo '<tr><td>Document Type</td><td>' . e($doc['doc_type']) . '</td></tr>'; } ?>
                <tr><td>Format</td><td><?= e($format) ?> (.<?= e($doc['file_ext']) ?>)</td></tr>
                <?php if ($doc['pages']) { echo '<tr><td>Pages</td><td>' . (int)$doc['pages'] . '</td></tr>'; } ?>
                <tr><td>File Size</td><td><?= e(fmt_size($doc['file_size'])) ?></td></tr>
                <?php foreach ($metaRows as $m) { echo '<tr><td>' . e($m['label']) . '</td><td>' . e($m['value']) . '</td></tr>'; } ?>
                <tr><td>Access</td><td><?= $isFree ? '<span class="badge b-green">FREE</span>' : '<span class="badge b-blue">PAID</span> ' . e(money($doc['price'])) ?></td></tr>
                <tr><td>Last Updated</td><td><?= e(fmt_date($doc['updated_at'])) ?></td></tr>
            </tbody></table></div>

            <?php if ($tags) { echo '<div class="tag-row">'; foreach ($tags as $t) { echo '<a class="pill" href="' . e(page_url('search', 'tag=' . rawurlencode($t['slug']))) . '">#' . e($t['name']) . '</a>'; } echo '</div>'; } ?>
        </div>
    </div>

    <?php if ($reviews) { ?>
    <div class="panel" id="reviews">
        <div class="panel-header">REVIEWS FROM VERIFIED BUYERS</div>
        <div class="panel-body">
            <div class="rating-line big"><?= stars_html($rating['avg']) ?> <strong><?= e(number_format($rating['avg'], 1)) ?> out of 5</strong> · <?= num($rating['count']) ?> review<?= $rating['count'] === 1 ? '' : 's' ?></div>
            <?php foreach ($reviews as $r) { ?>
                <div class="review-item">
                    <div><?= stars_html((float)$r['rating']) ?> <strong><?= e($r['name']) ?></strong> <span class="badge b-green">✔ Verified buyer</span> <span class="muted small"><?= e(fmt_date($r['created_at'])) ?></span></div>
                    <?php if ((string)$r['comment'] !== '') { echo '<div class="desc-text">' . nl2br(e($r['comment'])) . '</div>'; } ?>
                </div>
            <?php } ?>
        </div>
    </div>
    <?php } ?>

    <?php if ($related) { ?>
    <div class="panel">
        <div class="panel-header">RELATED DOCUMENTS</div>
        <div class="panel-body"><?= doc_table_html($related) ?></div>
    </div>
    <?php } ?>

    <?php if ($collections) { ?>
    <div class="panel">
        <div class="panel-header orange">COLLECTIONS CONTAINING THIS DOCUMENT</div>
        <div class="panel-body"><div class="feature-grid">
            <?php foreach ($collections as $c) { ?>
                <a class="feature-card" href="<?= e(collection_url($c)) ?>"><span class="feature-icon">📦</span>
                    <div class="feature-title"><?= e($c['title']) ?></div>
                    <div class="feature-desc"><?= e(excerpt((string)$c['description'], 110)) ?></div>
                    <div class="coll-price"><?= $c['is_free'] ? 'FREE BUNDLE' : e(money($c['price'])) . ' bundle' ?></div></a>
            <?php } ?>
        </div></div>
    </div>
    <?php } ?>
</section>
<template id="catTemplate"><?= cat_options_html(0, 0, true, 'Other / not sure') ?></template>
<?php include __DIR__ . '/includes/footer.php'; ?>
