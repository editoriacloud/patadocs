<?php
/** Admin: search analytics, document analytics and SEO health. */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('analytics.view');
$tab = in_array(get_str('tab', 6), ['docs', 'seo'], true) ? get_str('tab', 6) : 'search';
$range = in_array(get_str('range', 3), ['7', '30', '90', 'all'], true) ? get_str('range', 3) : '30';
$since = $range === 'all' ? '2000-01-01' : date('Y-m-d', strtotime('-' . (int)$range . ' days'));

// ---- Search analytics ----------------------------------------------------------------------------
$sa = $pop = $zero = [];
if ($tab === 'search') {
    $sa = db_row('SELECT COUNT(*) total, COUNT(DISTINCT norm_query) uniq, SUM(results = 0) zero FROM search_logs WHERE created_at >= ?', [$since]);
    $sa['purchases'] = (int)db_val("SELECT COUNT(*) FROM orders o JOIN search_logs s ON s.id = o.search_log_id WHERE o.status = 'paid' AND s.created_at >= ?", [$since]);
    $sa['converted'] = (int)db_val("SELECT COUNT(DISTINCT o.search_log_id) FROM orders o JOIN search_logs s ON s.id = o.search_log_id WHERE o.status = 'paid' AND s.created_at >= ?", [$since]);
    $pop = db_all("SELECT MAX(s.query) q, s.norm_query, COUNT(*) n, SUBSTRING_INDEX(GROUP_CONCAT(s.results ORDER BY s.id DESC), ',', 1) res,
        (SELECT COUNT(*) FROM orders o WHERE o.status = 'paid' AND o.search_log_id IN (SELECT id FROM search_logs x WHERE x.norm_query = s.norm_query)) purchases
        FROM search_logs s WHERE s.created_at >= ? GROUP BY s.norm_query ORDER BY n DESC LIMIT 25", [$since]);
    $zero = db_all('SELECT MAX(query) q, COUNT(*) n, MAX(created_at) last FROM search_logs WHERE results = 0 AND created_at >= ? GROUP BY norm_query ORDER BY n DESC LIMIT 25', [$since]);
}
// ---- Document analytics ---------------------------------------------------------------------------
$docs = []; $pg = null; $sort = get_str('sort', 10);
if ($tab === 'docs') {
    $all = $range === 'all';
    $views = $all ? 'd.view_count' : '(SELECT COALESCE(SUM(s.views),0) FROM document_stats_daily s WHERE s.document_id = d.id AND s.stat_date >= ?)';
    $prev = $all ? 'd.preview_count' : '(SELECT COALESCE(SUM(s.preview_views),0) FROM document_stats_daily s WHERE s.document_id = d.id AND s.stat_date >= ?)';
    $pur = $all ? 'd.purchase_count' : "(SELECT COUNT(*) FROM orders o WHERE o.document_id = d.id AND o.status = 'paid' AND o.paid_at >= ?)";
    $free = $all ? 'd.free_downloads' : "(SELECT COUNT(*) FROM download_logs l WHERE l.document_id = d.id AND l.type = 'free' AND l.created_at >= ?)";
    $paidd = $all ? 'd.paid_downloads' : "(SELECT COUNT(*) FROM download_logs l WHERE l.document_id = d.id AND l.type = 'paid' AND l.created_at >= ?)";
    $rev = $all ? 'd.revenue' : "(SELECT COALESCE(SUM(o.amount),0) FROM orders o WHERE o.document_id = d.id AND o.status = 'paid' AND o.paid_at >= ?)";
    $p = $all ? [] : array_fill(0, 9, $since);          // 9 date placeholders: 6 columns + 3 inside the conversion formula
    $orders = ['views' => 'views DESC', 'previews' => 'previews DESC', 'purchases' => 'purchases DESC', 'free' => 'free_dl DESC', 'paid' => 'paid_dl DESC', 'revenue' => 'revenue DESC', 'conv' => 'conv DESC'];
    $total = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published'");
    $pg = paginate($total, max(1, get_int('page', 1)), 25);
    $docs = db_all("SELECT d.id, d.title, d.is_free, $views AS views, $prev AS previews, $pur AS purchases, $free AS free_dl, $paidd AS paid_dl, $rev AS revenue,
        IF($views > 0, $pur / $views * 100, 0) AS conv FROM documents d WHERE d.status = 'published' ORDER BY " . ($orders[$sort] ?? 'views DESC') . ', d.id DESC LIMIT ' . (int)$pg['offset'] . ', 25', $p);
}
// ---- SEO health --------------------------------------------------------------------------------------
$seo = [];
if ($tab === 'seo') {
    $seo['nometa'] = db_all("SELECT id, title FROM documents WHERE status = 'published' AND (meta_description IS NULL OR meta_description = '') ORDER BY id DESC LIMIT 30");
    $seo['notitle'] = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published' AND (seo_title IS NULL OR seo_title = '')");
    $seo['nodesc'] = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published' AND (description IS NULL OR description = '')");
    $seo['nopreview'] = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published' AND preview_status <> 'ready'");
    $seo['catnodesc'] = array_values(array_filter(cats_all(), function ($c) { return $c['status'] === 'active' && trim((string)$c['description']) === ''; }));
    $seo['published'] = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published'");
}
$adm = ['title' => 'Analytics & SEO', 'active' => 'analytics'];
include __DIR__ . '/../includes/admin_header.php';
$rlink = function ($r, $lab) use ($tab, $range) { return '<a class="btn-classic btn-sm' . ($range === $r ? ' active-toggle' : '') . '" href="?tab=' . $tab . '&range=' . $r . '">' . $lab . '</a>'; };
?>
<div class="panel"><div class="panel-header">ANALYTICS &amp; SEO</div><div class="panel-body">
    <div class="flex" style="margin-bottom:12px;">
        <a class="btn-classic btn-sm<?= $tab === 'search' ? ' active-toggle' : '' ?>" href="?tab=search&range=<?= e($range) ?>">🔍 Search analytics</a><a class="btn-classic btn-sm<?= $tab === 'docs' ? ' active-toggle' : '' ?>" href="?tab=docs&range=<?= e($range) ?>">📄 Document analytics</a><a class="btn-classic btn-sm<?= $tab === 'seo' ? ' active-toggle' : '' ?>" href="?tab=seo">🌐 SEO health</a>
        <?php if ($tab !== 'seo') { echo '<span style="margin-left:auto;" class="flex"><span class="small muted">Period:</span>' . $rlink('7', '7 days') . $rlink('30', '30 days') . $rlink('90', '90 days') . $rlink('all', 'All time') . '</span>'; } ?></div>

<?php if ($tab === 'search') { $conv = $sa['total'] > 0 ? round($sa['converted'] / $sa['total'] * 100, 1) : 0; ?>
    <div class="grid-4" style="margin-bottom:16px;">
        <div class="stat-box"><div class="stat-label">Total searches</div><div class="stat-value"><?= num($sa['total']) ?></div><div class="stat-sub"><?= num($sa['uniq']) ?> different queries</div></div>
        <div class="stat-box"><div class="stat-label">Zero-result searches</div><div class="stat-value"><?= num($sa['zero']) ?></div><div class="stat-sub"><?= $sa['total'] > 0 ? round($sa['zero'] / $sa['total'] * 100) : 0 ?>% of searches</div></div>
        <div class="stat-box"><div class="stat-label">Search conversion</div><div class="stat-value"><?= $conv ?>%</div><div class="stat-sub">searches followed by a purchase</div></div>
        <div class="stat-box"><div class="stat-label">Purchased after search</div><div class="stat-value"><?= num($sa['purchases']) ?></div></div>
    </div>
    <div class="section-title">POPULAR SEARCHES</div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Search term</th><th>Searches</th><th>Results</th><th>Purchases</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($pop as $r) { echo '<tr><td class="doc-title">' . e($r['q']) . '</td><td class="num">' . num($r['n']) . ' searches</td><td class="num">' . num($r['res']) . ' results</td><td class="num">' . num($r['purchases']) . ' purchases</td><td class="actions"><a class="btn-classic btn-sm" target="_blank" href="' . e(page_url('search', 'q=' . rawurlencode($r['q']))) . '">🔍 TRY</a>' . ((int)$r['res'] === 0 ? '<a class="btn-classic btn-sm success" href="upload.php?title=' . rawurlencode($r['q']) . '">CREATE</a>' : '') . '</td></tr>'; }
    if (!$pop) { echo '<tr><td colspan="5" class="empty-cell">No searches recorded yet in this period.</td></tr>'; } ?></tbody></table></div>
    <div class="section-title" id="zero">ZERO RESULT SEARCHES — WHAT PEOPLE NEED</div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Search term</th><th>Searches</th><th>Results</th><th>Last searched</th><th>Action</th></tr></thead><tbody>
    <?php foreach ($zero as $r) { echo '<tr><td class="doc-title">' . e($r['q']) . '</td><td class="num">' . num($r['n']) . ' searches</td><td class="num">0 results</td><td class="nowrap">' . e(fmt_date($r['last'], true)) . '</td><td class="actions"><a class="btn-classic btn-sm success" href="upload.php?title=' . rawurlencode($r['q']) . '">CREATE DOCUMENT</a><a class="btn-classic btn-sm" href="synonyms.php?test=' . rawurlencode($r['q']) . '" title="See how this search is understood">🔤</a></td></tr>'; }
    if (!$zero) { echo '<tr><td colspan="5" class="empty-cell">No zero-result searches in this period. 🎉</td></tr>'; } ?></tbody></table></div>

<?php } elseif ($tab === 'docs') { $sl = function ($k, $lab) use ($sort, $range) { return '<a href="?tab=docs&range=' . e($range) . '&sort=' . $k . '" style="color:inherit;">' . $lab . (($sort ?: 'views') === $k ? ' ▼' : '') . '</a>'; }; ?>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>Document</th><th><?= $sl('views', 'Views') ?></th><th><?= $sl('previews', 'Preview views') ?></th><th><?= $sl('purchases', 'Purchases') ?></th><th><?= $sl('free', 'Free downloads') ?></th><th><?= $sl('paid', 'Paid downloads') ?></th><th><?= $sl('revenue', 'Revenue') ?></th><th><?= $sl('conv', 'Conversion') ?></th></tr></thead><tbody>
    <?php foreach ($docs as $d) { echo '<tr><td class="doc-title"><a href="edit-document.php?id=' . (int)$d['id'] . '">' . e($d['title']) . '</a></td><td class="num">' . num($d['views']) . '</td><td class="num">' . num($d['previews']) . '</td><td class="num">' . num($d['purchases']) . '</td><td class="num">' . num($d['free_dl']) . '</td><td class="num">' . num($d['paid_dl']) . '</td><td class="num doc-price">' . e(money($d['revenue'])) . '</td><td class="num">' . ($d['is_free'] ? '—' : round((float)$d['conv'], 1) . '%') . '</td></tr>'; }
    if (!$docs) { echo '<tr><td colspan="8" class="empty-cell">No published documents yet.</td></tr>'; } ?></tbody></table></div>
    <?= $pg ? pager_html($pg, url('admin/analytics.php'), ['tab' => 'docs', 'range' => $range, 'sort' => $sort]) : '' ?>

<?php } else { ?>
    <div class="grid-4" style="margin-bottom:16px;">
        <div class="stat-box"><div class="stat-label">Published documents</div><div class="stat-value"><?= num($seo['published']) ?></div></div>
        <div class="stat-box"><div class="stat-label">Without SEO title</div><div class="stat-value"><?= num($seo['notitle']) ?></div><div class="stat-sub">title is used instead</div></div>
        <div class="stat-box"><div class="stat-label">Without description</div><div class="stat-value"><?= num($seo['nodesc']) ?></div><div class="stat-sub">thin pages rank badly</div></div>
        <div class="stat-box"><div class="stat-label">Without preview</div><div class="stat-value"><?= num($seo['nopreview']) ?></div></div>
    </div>
    <div class="result-area" style="margin-bottom:16px;"><strong>Technical checklist</strong>
        <div class="result-row">Sitemap: <a href="<?= e(url('sitemap.xml')) ?>" target="_blank"><?= e(url('sitemap.xml')) ?></a> <?= setting('sitemap_enabled', '1') === '1' ? '<span class="badge b-green">on</span>' : '<span class="badge b-red">off</span>' ?></div>
        <div class="result-row">robots.txt: <a href="<?= e(url('robots.txt')) ?>" target="_blank"><?= e(url('robots.txt')) ?></a> — submit the sitemap in Google Search Console</div>
        <div class="result-row">Clean URLs: <?= setting('clean_urls', '1') === '1' ? '<span class="badge b-green">on</span>' : '<span class="badge b-orange">off</span>' ?> · Canonical tags: <?= setting('canonical_urls', '1') === '1' ? '<span class="badge b-green">on</span>' : '<span class="badge b-red">off</span>' ?> · HTTPS: <?= is_https() ? '<span class="badge b-green">yes</span>' : '<span class="badge b-orange">no</span>' ?></div></div>
    <div class="section-title">DOCUMENTS WITHOUT A META DESCRIPTION</div>
    <div class="gv-wrap"><table class="gv-table compact"><tbody><?php foreach ($seo['nometa'] as $d) { echo '<tr><td class="doc-title">' . e($d['title']) . '</td><td class="actions"><a class="btn-classic btn-sm primary" href="edit-document.php?id=' . (int)$d['id'] . '&_step=7">FIX IN SEO STEP</a></td></tr>'; } if (!$seo['nometa']) { echo '<tr><td class="empty-cell">All published documents have a meta description. 🎉</td></tr>'; } ?></tbody></table></div>
    <div class="section-title">CATEGORIES WITHOUT A DESCRIPTION</div>
    <div class="gv-wrap"><table class="gv-table compact"><tbody><?php foreach (array_slice($seo['catnodesc'], 0, 30) as $c) { echo '<tr><td class="doc-title">' . e($c['name']) . '<span class="sub">/' . e($c['path']) . '/</span></td><td class="actions"><a class="btn-classic btn-sm primary" href="categories.php?edit=' . (int)$c['id'] . '">ADD DESCRIPTION</a></td></tr>'; } if (!$seo['catnodesc']) { echo '<tr><td class="empty-cell">All categories are described. 🎉</td></tr>'; } ?></tbody></table></div>
<?php } ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
