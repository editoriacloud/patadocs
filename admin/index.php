<?php
/** Admin dashboard — every number comes from the database (no mock data). */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('dashboard.view');

// Global admin search box (design's "Search documents, orders, categories...")
if (get_str('q', 100) !== '') {
    $q = get_str('q', 100);
    if (preg_match('/^DOC-/i', $q) && admin_can('orders.view')) { redirect(url('admin/orders.php?q=' . rawurlencode($q))); }
    redirect(url('admin/documents.php?q=' . rawurlencode($q)));
}

$days = in_array(get_int('days', 7), [7, 14, 30], true) ? get_int('days', 7) : 7;
$v = function ($sql, $p = []) { return (float)db_val($sql, $p); };

// ---- Cards ----------------------------------------------------------------------------------
$c = [
    'docs'      => (int)$v("SELECT COUNT(*) FROM documents WHERE status = 'published'"),
    'drafts'    => (int)$v("SELECT COUNT(*) FROM documents WHERE status <> 'published'"),
    'free'      => (int)$v("SELECT COUNT(*) FROM documents WHERE status = 'published' AND is_free = 1"),
    'paid'      => (int)$v("SELECT COUNT(*) FROM documents WHERE status = 'published' AND is_free = 0"),
    'downloads' => (int)$v('SELECT COUNT(*) FROM download_logs'),
    'orders'    => (int)$v('SELECT COUNT(*) FROM orders'),
    'paid_orders' => (int)$v("SELECT COUNT(*) FROM orders WHERE status = 'paid'"),
    'revenue'   => $v("SELECT COALESCE(SUM(amount), 0) FROM orders WHERE status = 'paid'"),
    'today'     => $v("SELECT COALESCE(SUM(amount), 0) FROM orders WHERE status = 'paid' AND DATE(paid_at) = CURDATE()"),
    'contrib'   => (int)$v("SELECT COUNT(*) FROM contributions WHERE status IN ('pending','under_review')"),
    'requests'  => (int)$v("SELECT COUNT(*) FROM document_requests WHERE status = 'new'"),
    'reports'   => (int)$v("SELECT COUNT(*) FROM document_reports WHERE status = 'new'"),
    'searches'  => (int)$v('SELECT COUNT(*) FROM search_logs WHERE created_at > (NOW() - INTERVAL 30 DAY)'),
    'zero'      => (int)$v('SELECT COUNT(*) FROM search_logs WHERE results = 0 AND created_at > (NOW() - INTERVAL 30 DAY)'),
];
// Month-over-month trends
$mom = function ($table, $col, $extra = '', $agg = 'COUNT(*)') use ($v) {
    $cur = $v("SELECT $agg FROM $table WHERE $col >= DATE_FORMAT(NOW(), '%Y-%m-01') $extra");
    $prev = $v("SELECT $agg FROM $table WHERE $col >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH, '%Y-%m-01') AND $col < DATE_FORMAT(NOW(), '%Y-%m-01') $extra");
    return [$cur, $prev];
};
$tDocs = $mom('documents', 'created_at'); $tDl = $mom('download_logs', 'created_at');
$tRev = $mom('orders', 'paid_at', "AND status = 'paid'", 'COALESCE(SUM(amount),0)'); $tOrd = $mom('orders', 'created_at');

// ---- Chart series -----------------------------------------------------------------------------
$series = function ($sql) use ($days) {
    $rows = []; foreach (db_all($sql, [$days - 1]) as $r) { $rows[$r['d']] = (float)$r['n']; }
    $labels = []; $vals = [];
    for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $labels[] = date($days > 7 ? 'j M' : 'D', strtotime($d)); $vals[] = $rows[$d] ?? 0; }
    return ['labels' => $labels, 'values' => $vals];
};
$charts = [
    'downloads' => $series('SELECT DATE(created_at) d, COUNT(*) n FROM download_logs WHERE created_at >= (CURDATE() - INTERVAL ? DAY) GROUP BY d'),
    'revenue'   => $series("SELECT DATE(paid_at) d, SUM(amount) n FROM orders WHERE status = 'paid' AND paid_at >= (CURDATE() - INTERVAL ? DAY) GROUP BY d"),
    'views'     => $series('SELECT stat_date d, SUM(views) n FROM document_stats_daily WHERE stat_date >= (CURDATE() - INTERVAL ? DAY) GROUP BY d'),
    'purchases' => $series("SELECT DATE(paid_at) d, COUNT(*) n FROM orders WHERE status = 'paid' AND paid_at >= (CURDATE() - INTERVAL ? DAY) GROUP BY d"),
    'contributions' => $series('SELECT DATE(created_at) d, COUNT(*) n FROM contributions WHERE created_at >= (CURDATE() - INTERVAL ? DAY) GROUP BY d'),
    'searches'  => $series('SELECT DATE(created_at) d, COUNT(*) n FROM search_logs WHERE created_at >= (CURDATE() - INTERVAL ? DAY) GROUP BY d'),
];

$top = db_all('SELECT id, title, view_count, preview_count, purchase_count, (free_downloads + paid_downloads) AS dl, revenue, is_free FROM documents WHERE status = \'published\' ORDER BY view_count DESC, id DESC LIMIT 8');
$zero = db_all("SELECT MAX(query) q, COUNT(*) n FROM search_logs WHERE results = 0 AND created_at > (NOW() - INTERVAL 30 DAY) GROUP BY norm_query ORDER BY n DESC LIMIT 8");

// ---- Recent activity blocks ---------------------------------------------------------------------
$rDocs = db_all('SELECT id, title, status, created_at FROM documents ORDER BY id DESC LIMIT 5');
$rPay = db_all("SELECT COALESCE(invoice_ref, order_code) AS order_code, item_title, amount, paid_at FROM orders WHERE status = 'paid' ORDER BY paid_at DESC LIMIT 5");
$rCon = db_all('SELECT id, title, status, created_at FROM contributions ORDER BY id DESC LIMIT 5');
$rRep = db_all('SELECT r.id, r.reason, r.created_at, d.title FROM document_reports r JOIN documents d ON d.id = r.document_id ORDER BY r.id DESC LIMIT 5');
$rReq = db_all('SELECT id, title, status, created_at FROM document_requests ORDER BY id DESC LIMIT 5');
$rAct = db_all('SELECT a.action, a.entity, a.details, a.created_at, u.username FROM admin_activity a LEFT JOIN admin_users u ON u.id = a.admin_id ORDER BY a.id DESC LIMIT 6');

$adm = ['title' => 'Dashboard', 'active' => 'index', 'charts' => true,
    'foot_extra' => '<script>window.PD_CHARTS = ' . json_encode($charts, JSON_HEX_TAG | JSON_HEX_AMP) . '; PDAdmin.charts();</script>'];
include __DIR__ . '/../includes/admin_header.php';
$box = function ($label, $value, $sub, $green = false) {
    return '<div class="stat-box"><div class="stat-label">' . e($label) . '</div><div class="stat-value' . ($green ? ' green' : '') . '"' . ($green ? ' style="font-size:1.5rem;"' : '') . '>' . $value . '</div>' . $sub . '</div>';
};
$compact = function ($n) { $n = (float)$n; return $n >= 1000000 ? round($n / 1000000, 2) . 'M' : ($n >= 10000 ? round($n / 1000, 1) . 'K' : number_format($n)); };
?>
<div class="panel">
    <div class="panel-header">ADMIN DASHBOARD</div>
    <div class="panel-body">
        <form method="get"><div class="search-hero" data-search-id="admin" data-nosuggest="1" style="margin-bottom:18px; max-width:100%;">
            <div class="search-wrap"><div class="search-icon-big">🔍</div>
                <input type="text" class="search-input" name="q" placeholder="Search documents, orders (DOC-…)..." autocomplete="off" spellcheck="false">
                <button type="submit" class="search-go-btn"><span class="go-text">SEARCH</span><span>🔍</span></button></div>
        </div></form>

        <div class="grid-4" style="margin-bottom:18px;">
            <?= $box('Total Documents', num($c['docs']), trend_html($tDocs[0], $tDocs[1], ' new vs last month')) ?>
            <?= $box('Free Documents', num($c['free']), '<div class="stat-sub">published</div>') ?>
            <?= $box('Paid Documents', num($c['paid']), '<div class="stat-sub">published</div>') ?>
            <?= $box('Total Downloads', num($c['downloads']), trend_html($tDl[0], $tDl[1])) ?>
            <?= $box('Total Orders', num($c['orders']), '<div class="stat-sub">' . num($c['paid_orders']) . ' paid · ' . trend_html($tOrd[0], $tOrd[1], '') . '</div>') ?>
            <?= $box('Total Revenue', e(money($c['revenue'])), trend_html($tRev[0], $tRev[1]), true) ?>
            <?= $box("Today's Revenue", e(money($c['today'])), '<div class="stat-sub">' . e(date('D j M')) . '</div>', true) ?>
            <?= $box('Pending Contributions', num($c['contrib']), '<a href="contributions.php">review →</a>') ?>
            <?= $box('Document Requests', num($c['requests']), '<div class="stat-trend">new requests</div>') ?>
            <?= $box('Open Reports', num($c['reports']), '<a href="reports.php">view →</a>') ?>
            <?= $box('Searches (30 days)', num($c['searches']), '<div class="stat-sub">anonymous</div>') ?>
            <?= $box('Zero-result Searches', num($c['zero']), '<a href="analytics.php#zero">see what people need →</a>') ?>
        </div>

        <div class="flex" style="justify-content:flex-end; margin-bottom:10px;"><span class="small muted">Charts:</span>
            <?php foreach ([7, 14, 30] as $d) { echo '<a class="btn-classic btn-sm' . ($d === $days ? ' active-toggle' : '') . '" href="?days=' . $d . '">' . $d . ' days</a>'; } ?></div>
        <div class="grid-2" style="margin-bottom:18px;">
            <div class="stat-box p-3 chart-box"><div class="section-title" style="margin:0 0 12px;">DOWNLOADS (Last <?= $days ?> days)</div><canvas id="chartDownloads" height="140"></canvas></div>
            <div class="stat-box p-3 chart-box"><div class="section-title" style="margin:0 0 12px;">REVENUE (<?= e(setting('currency_symbol')) ?>, Last <?= $days ?> days)</div><canvas id="chartRevenue" height="140"></canvas></div>
            <div class="stat-box p-3 chart-box"><div class="section-title" style="margin:0 0 12px;">VIEWS</div><canvas id="chartViews" height="140"></canvas></div>
            <div class="stat-box p-3 chart-box"><div class="section-title" style="margin:0 0 12px;">PURCHASES</div><canvas id="chartPurchases" height="140"></canvas></div>
            <div class="stat-box p-3 chart-box"><div class="section-title" style="margin:0 0 12px;">CONTRIBUTION ACTIVITY</div><canvas id="chartContrib" height="140"></canvas></div>
            <div class="stat-box p-3 chart-box"><div class="section-title" style="margin:0 0 12px;">SEARCH ACTIVITY</div><canvas id="chartSearches" height="140"></canvas></div>
        </div>

        <div class="section-title">TOP DOCUMENTS</div>
        <div style="overflow-x:auto;"><table class="gv-table"><thead><tr><th>Document</th><th>Views</th><th>Previews</th><th>Purchases</th><th>Downloads</th><th>Revenue</th></tr></thead><tbody>
            <?php foreach ($top as $t) { echo '<tr><td class="doc-title"><a href="edit-document.php?id=' . (int)$t['id'] . '">' . e($t['title']) . '</a></td><td>' . num($t['view_count']) . '</td><td>' . num($t['preview_count']) . '</td><td>' . num($t['purchase_count']) . '</td><td>' . num($t['dl']) . '</td>'
                . ($t['revenue'] > 0 ? '<td class="doc-price">' . e(money($t['revenue'])) . '</td>' : '<td style="color:#404040;">' . e(money(0)) . '</td>') . '</tr>'; }
            if (!$top) { echo '<tr><td colspan="6" class="empty-cell">No published documents yet.</td></tr>'; } ?>
        </tbody></table></div>

        <div class="section-title">ZERO RESULT SEARCHES <span class="muted small">(last 30 days)</span></div>
        <div style="overflow-x:auto;"><table class="gv-table"><thead><tr><th>Search Term</th><th>Searches</th><th>Action</th></tr></thead><tbody>
            <?php foreach ($zero as $z) { echo '<tr><td class="doc-title">' . e($z['q']) . '</td><td>' . num($z['n']) . '</td><td><a class="btn-classic success btn-sm" href="upload.php?title=' . rawurlencode($z['q']) . '">CREATE</a></td></tr>'; }
            if (!$zero) { echo '<tr><td colspan="3" class="empty-cell">No zero-result searches — great!</td></tr>'; } ?>
        </tbody></table></div>

        <div class="section-title">RECENT ACTIVITY</div>
        <div class="grid-3">
            <div class="result-area"><strong>📤 Recent documents</strong>
                <?php foreach ($rDocs as $r) { echo '<div class="result-row"><a href="edit-document.php?id=' . (int)$r['id'] . '">' . e(excerpt($r['title'], 40)) . '</a> ' . status_badge($r['status']) . '<span class="when">' . e(time_ago($r['created_at'])) . '</span></div>'; } if (!$rDocs) { echo '<div class="result-row muted">None yet</div>'; } ?></div>
            <div class="result-area"><strong>💳 Recent payments</strong>
                <?php foreach ($rPay as $r) { echo '<div class="result-row"><span style="color:#008000;font-weight:bold;">' . e(money($r['amount'])) . '</span> ' . e(excerpt($r['item_title'], 28)) . '<span class="when">' . e(time_ago($r['paid_at'])) . '</span></div>'; } if (!$rPay) { echo '<div class="result-row muted">No payments yet</div>'; } ?></div>
            <div class="result-area"><strong>🤝 Recent contributions</strong>
                <?php foreach ($rCon as $r) { echo '<div class="result-row"><a href="contributions.php?id=' . (int)$r['id'] . '">' . e(excerpt($r['title'], 34)) . '</a> ' . status_badge($r['status']) . '<span class="when">' . e(time_ago($r['created_at'])) . '</span></div>'; } if (!$rCon) { echo '<div class="result-row muted">None yet</div>'; } ?></div>
            <div class="result-area"><strong>🚩 Recent reports</strong>
                <?php foreach ($rRep as $r) { echo '<div class="result-row"><a href="reports.php">' . e(excerpt($r['title'], 30)) . '</a> <span class="badge b-orange">' . e($r['reason']) . '</span><span class="when">' . e(time_ago($r['created_at'])) . '</span></div>'; } if (!$rRep) { echo '<div class="result-row muted">None yet</div>'; } ?></div>
            <div class="result-area"><strong>💬 Recent requests</strong>
                <?php foreach ($rReq as $r) { echo '<div class="result-row"><a href="requests.php">' . e(excerpt($r['title'], 34)) . '</a> ' . status_badge($r['status']) . '<span class="when">' . e(time_ago($r['created_at'])) . '</span></div>'; } if (!$rReq) { echo '<div class="result-row muted">None yet</div>'; } ?></div>
            <div class="result-area"><strong>✏️ Recent admin actions</strong>
                <?php foreach ($rAct as $r) { echo '<div class="result-row"><span>' . e($r['username'] ?: 'system') . ': ' . e(excerpt($r['action'] . ($r['details'] ? ' — ' . $r['details'] : ''), 44)) . '</span><span class="when">' . e(time_ago($r['created_at'])) . '</span></div>'; } if (!$rAct) { echo '<div class="result-row muted">None yet</div>'; } ?></div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
