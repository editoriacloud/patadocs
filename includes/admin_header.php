<?php
/**
 * Admin layout header (same toolbar/panels as the public design + a left navigation panel).
 * Set $adm = ['title' => 'Documents', 'active' => 'documents', 'charts' => false] before including.
 */
$adm = (isset($adm) && is_array($adm)) ? $adm : [];
$hdrMe = $_SESSION['admin'] ?? ['name' => '', 'role' => ''];
$siteName = setting('site_name', 'PATADOCS');
$brand = preg_match('/^(.+?)(docs)$/i', $siteName, $bm) ? e($bm[1]) . '<span>' . e($bm[2]) . '</span>' : e($siteName);
static $badgeCounts = null;
if ($badgeCounts === null) {
    $badgeCounts = ['contrib' => 0, 'requests' => 0, 'reports' => 0];
    try {
        $badgeCounts['contrib'] = (int)db_val("SELECT COUNT(*) FROM contributions WHERE status IN ('pending','under_review')");
        $badgeCounts['requests'] = (int)db_val("SELECT COUNT(*) FROM document_requests WHERE status = 'new'");
        $badgeCounts['reports'] = (int)db_val("SELECT COUNT(*) FROM document_reports WHERE status = 'new'");
    } catch (Throwable $e) { }
}
$nav = [
    'OVERVIEW' => [['index', 'Dashboard', 'index.php', 'dashboard.view', 0]],
    'CONTENT' => [
        ['documents', 'Documents', 'documents.php', 'documents.view', 0], ['upload', 'Upload Document', 'upload.php', 'documents.edit', 0],
        ['categories', 'Categories', 'categories.php', 'categories.manage', 0], ['metadata', 'Metadata Fields', 'metadata.php', 'metadata.manage', 0],
        ['collections', 'Collections', 'collections.php', 'collections.manage', 0], ['homepage', 'Homepage', 'homepage.php', 'homepage.manage', 0],
    ],
    'COMMUNITY' => [
        ['contributions', 'Contributions', 'contributions.php', 'contributions.review', $badgeCounts['contrib']],
        ['requests', 'Requests & Messages', 'requests.php', 'requests.manage', $badgeCounts['requests']],
        ['reports', 'Document Reports', 'reports.php', 'reports.manage', $badgeCounts['reports']],
    ],
    'SALES' => [['orders', 'Orders', 'orders.php', 'orders.view', 0], ['payments', 'Payments', 'payments.php', 'payments.view', 0], ['downloads', 'Downloads', 'downloads.php', 'downloads.manage', 0]],
    'INSIGHTS' => [['analytics', 'Analytics & SEO', 'analytics.php', 'analytics.view', 0]],
    'SYSTEM' => [['synonyms', 'Search Synonyms', 'synonyms.php', 'synonyms.manage', 0], ['settings', 'Settings', 'settings.php', 'settings.manage', 0], ['users', 'Admin Users & Roles', 'users.php', 'users.manage', 0], ['profile', 'My Account', 'profile.php', '', 0]],
];
$active = $adm['active'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title><?= e(($adm['title'] ?? 'Admin') . ' — ' . $siteName . ' Admin') ?></title>
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/extra.css')) ?>">
</head>
<body class="light-theme">
<script>try{if(localStorage.getItem('patadocs-theme')==='dark'){document.body.className='dark-theme';}}catch(e){}</script>
<div class="aspx-form" id="form1">
<div class="aspx-toolbar">
    <a class="brand" href="<?= e(url('admin/')) ?>" style="text-decoration:none;"><?= $brand ?><small>Admin Panel</small></a>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">☰</button>
    <div class="aspx-menu" id="mobileMenu"><ul>
        <li><a class="nav-link<?= $active === 'index' ? ' active' : '' ?>" href="<?= e(url('admin/')) ?>">Dashboard</a></li>
        <?php if (admin_can('documents.view')) { echo '<li><a class="nav-link' . ($active === 'documents' ? ' active' : '') . '" href="' . e(url('admin/documents.php')) . '">Documents</a></li>'; } ?>
        <?php if (admin_can('contributions.review')) { echo '<li><a class="nav-link' . ($active === 'contributions' ? ' active' : '') . '" href="' . e(url('admin/contributions.php')) . '">Contributions</a></li>'; } ?>
        <?php if (admin_can('orders.view')) { echo '<li><a class="nav-link' . ($active === 'orders' ? ' active' : '') . '" href="' . e(url('admin/orders.php')) . '">Orders</a></li>'; } ?>
        <?php if (admin_can('analytics.view')) { echo '<li><a class="nav-link' . ($active === 'analytics' ? ' active' : '') . '" href="' . e(url('admin/analytics.php')) . '">Analytics</a></li>'; } ?>
        <li><a class="nav-link" href="<?= e(url('')) ?>" target="_blank" rel="noopener">View Site ↗</a></li>
        <li><a class="nav-link" href="<?= e(url('admin/logout.php')) ?>">Logout (<?= e($hdrMe['name']) ?>)</a></li>
    </ul></div>
    <div class="toolbar-right"><button type="button" class="theme-toggle" id="themeToggleBtn">🌙 DARK</button></div>
</div>
<div class="aspx-content">
<section class="page-section active">
<button type="button" class="btn-classic admin-menu-toggle" id="adminMenuToggle">☰ ADMIN MENU</button>
<div class="admin-layout">
    <aside class="panel admin-nav" id="adminNav">
        <div class="panel-header" style="font-size:1rem;">ADMIN MENU</div>
        <?php foreach ($nav as $group => $items) {
            $visible = array_filter($items, function ($i) { return $i[3] === '' || admin_can($i[3]); });
            if (!$visible) { continue; }
            echo '<div class="nav-group">' . e($group) . '</div>';
            foreach ($visible as $i) { echo '<a href="' . e(url('admin/' . $i[2])) . '" class="' . ($active === $i[0] ? 'active' : '') . '"><span>' . e($i[1]) . '</span>' . ($i[4] > 0 ? '<span class="count">' . (int)$i[4] . '</span>' : '') . '</a>'; }
        } ?>
        <a href="<?= e(url('')) ?>" target="_blank" rel="noopener"><span>🌐 View public site</span></a>
        <a href="<?= e(url('admin/logout.php')) ?>"><span>⏻ Logout</span></a>
    </aside>
    <main style="min-width:0;">
    <?= flash_html() ?>
