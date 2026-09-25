<?php
/**
 * PATADOCS — Category page: heading, description, sub-categories, documents (paginated),
 * related categories and internal links. Clean URL: /education/grade-7/  (router.php)
 * or category.php?path=education/grade-7 when rewriting is off.
 */
require __DIR__ . '/includes/init.php';

$cat = $GLOBALS['ROUTE_CAT'] ?? cat_by_path(trim(get_str('path', 191), '/'));
if (!$cat || $cat['status'] !== 'active') {
    abort_page(404, 'Category not found', 'This category does not exist. Browse all categories or search for what you need.', [['📂 ALL CATEGORIES', page_url('categories')], ['🔍 SEARCH', page_url('search')]]);
}
if (!isset($GLOBALS['ROUTE_CAT']) && setting('clean_urls', '1') === '1') { redirect(cat_url($cat), 301); }

$cid = (int)$cat['id'];
$counts = cat_counts();
$sort = in_array(get_str('sort', 12), ['new', 'popular', 'price_asc', 'price_desc', 'title'], true) ? get_str('sort', 12) : '';
$page = max(1, get_int('page', 1));
$res = search_documents(['cat' => $cid, 'sort' => $sort ?: 'new', 'page' => $page, 'per' => 20]);
$children = cat_children($cid, true);
$crumbs = [['Home', url('')]];
foreach (cat_breadcrumb($cid) as $c) { $crumbs[] = [$c['name'], (int)$c['id'] === $cid ? null : cat_url($c)]; }
// Related categories = siblings (or top-level categories for a root category)
$siblings = array_values(array_filter(cat_children($cat['parent_id'] !== null ? (int)$cat['parent_id'] : null, true), function ($c) use ($cid) { return (int)$c['id'] !== $cid; }));
$siblings = array_slice($siblings, 0, 10);

$total = (int)($counts[$cid] ?? 0);
$desc = trim((string)$cat['meta_description']) !== '' ? $cat['meta_description']
    : (trim((string)$cat['description']) !== '' ? excerpt($cat['description'], 158) : 'Browse ' . $cat['name'] . ' documents on ' . setting('site_name') . ': preview, pay via M-Pesa and download instantly.');
$items = [];
foreach (array_slice($res['rows'], 0, 10) as $i => $d) { $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => doc_url($d), 'name' => $d['title']]; }
$meta = [
    'title' => (trim((string)$cat['seo_title']) !== '' ? $cat['seo_title'] : $cat['name'] . ' Documents') . ($res['pg']['page'] > 1 ? ' – Page ' . $res['pg']['page'] : ''),
    'description' => ($res['pg']['page'] > 1 ? 'Page ' . $res['pg']['page'] . ' of ' . $res['pg']['pages'] . ': ' : '') . $desc, 'nav' => 'categories',
    'canonical' => cat_url($cat) . ($res['pg']['page'] > 1 ? (setting('clean_urls', '1') === '1' ? '?' : '&') . 'page=' . $res['pg']['page'] : ''),
    'robots' => ($total === 0 && !$children) ? 'noindex,follow' : 'index,follow',
] + seo_pagination($res['pg'], cat_url($cat)) + [
    'schema' => [
        ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $cat['name'], 'description' => $desc, 'url' => cat_url($cat),
         'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items]],
        breadcrumb_schema($crumbs),
    ],
];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-category">
    <?= breadcrumb_html($crumbs) ?>
    <div class="panel">
        <div class="panel-header"><?= e($cat['icon'] ? $cat['icon'] . ' ' : '') ?><?= e(strtoupper($cat['name'])) ?></div>
        <div class="panel-body">
            <h1 class="section-title" style="margin-top:0;"><?= e($cat['name']) ?> documents<?= $total ? ' <span class="muted small">(' . num($total) . ')</span>' : '' ?></h1>
            <?php if (trim((string)$cat['description']) !== '') { echo '<div class="desc-text" style="margin-bottom:16px;">' . nl2br(e($cat['description'])) . '</div>'; } ?>
            <?= search_hero_html('category', 'Search in ' . $cat['name'] . '...', '', 'margin-bottom:16px; max-width:100%;') ?>
            <?php if ($children) { ?>
                <div class="section-title">SUB-CATEGORIES</div>
                <?= cat_tiles_html($children) ?>
            <?php } ?>
            <div class="panel-tools" style="margin-top:20px;">
                <div class="section-title" style="margin:0;">DOCUMENTS IN <?= e(strtoupper($cat['name'])) ?></div>
                <form method="get" class="flex" style="margin:0;">
                    <label class="small muted" for="catSort">Sort:</label>
                    <select id="catSort" name="sort" onchange="this.form.submit()" style="padding:6px 10px; background:var(--bg-input); color:var(--text-primary); border:none; box-shadow:inset 2px 2px 0 var(--shadow); border-radius:4px; font-family:Tahoma,sans-serif;">
                        <?php foreach (['' => 'Newest', 'popular' => 'Most popular', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'title' => 'Title A–Z'] as $k => $v) { echo '<option value="' . e($k) . '"' . ($sort === $k ? ' selected' : '') . '>' . e($v) . '</option>'; } ?>
                    </select>
                    <a class="btn-classic btn-sm" href="<?= e(page_url('search', 'cat=' . $cid)) ?>">ADVANCED FILTERS</a>
                </form>
            </div>
            <?= doc_table_html($res['rows'], 'No documents in this category yet. Check back soon or request one.') ?>
            <?= pager_html($res['pg'], cat_url($cat), $sort ? ['sort' => $sort] : []) ?>
            <?php if (!$res['rows']) { echo '<div class="alert alert-info" style="margin-top:14px;">Looking for something specific? <a href="' . e(page_url('request-document')) . '"><strong>Request a document</strong></a> or <a href="' . e(page_url('contribute')) . '"><strong>contribute one</strong></a>.</div>'; } ?>
        </div>
    </div>
    <?= $res['rows'] ? ad_slot('category_list', $meta) : '' ?>
    <?php if ($siblings) { ?>
    <div class="panel">
        <div class="panel-header">RELATED CATEGORIES</div>
        <div class="panel-body"><div class="tag-row" style="margin-top:0;">
            <?php foreach ($siblings as $s) { echo '<a class="pill" href="' . e(cat_url($s)) . '">' . e(($s['icon'] ? $s['icon'] . ' ' : '') . $s['name']) . ' (' . num($counts[(int)$s['id']] ?? 0) . ')</a>'; } ?>
        </div></div>
    </div>
    <?php } ?>
</section>
<script>document.addEventListener('DOMContentLoaded',function(){var f=document.querySelector('[data-search-id="category"]');if(f){var form=f.closest('form');var h=document.createElement('input');h.type='hidden';h.name='cat';h.value='<?= $cid ?>';form.appendChild(h);}});</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
