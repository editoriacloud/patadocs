<?php
/**
 * Public page header (design: top toolbar + navigation).
 * Set $meta before including: title, description, canonical, robots, keywords, og_type, og_image, schema[], nav,
 * payment_widget (true on pages with a Buy button: loads the Payment Hub's EditoriaPay modal script).
 */
$meta = (isset($meta) && is_array($meta)) ? $meta : [];
$navKey = $meta['nav'] ?? '';
$siteName = setting('site_name', 'PATADOCS');
$brand = preg_match('/^(.+?)(docs)$/i', $siteName, $bm) ? e($bm[1]) . '<span>' . e($bm[2]) . '</span>' : e($siteName);
// [key, label, url, priority]: when the bar is too narrow, the highest priority numbers move into "More" first
$menu = [
    ['home', 'Home', url(''), 1],
    ['browse', 'Browse Documents', page_url('search'), 1],
    ['categories', 'Categories', page_url('categories'), 2],
    ['popular', 'Popular', page_url('popular'), 3],
    ['contribute', 'Contribute', page_url('contribute'), 5],
    ['request', 'Request Document', page_url('request-document'), 4],
    ['about', 'About', page_url('about'), 6],
];
if (setting('show_admin_link', '1') === '1') { $menu[] = ['admin', 'Admin Panel', url('admin/'), 9]; }
require_once __DIR__ . '/topbar.php';
$topbar = topbar_config();
$favicon = setting('site_favicon') ? url(setting('site_favicon')) : asset('images/favicon.svg');
// Same directive as the <meta name="robots"> tag, as an HTTP header (the only one crawlers honour for non-HTML fetches and redirects).
if (!headers_sent() && strpos(seo_robots($meta), 'noindex') !== false) { header('X-Robots-Tag: ' . seo_robots($meta)); }
// Automation "web cron": at most one cheap check per minute; due jobs run after this page has been sent.
if (setting('jobs_webcron', '1') === '1' && time() - (int)setting('jobs_last_tick', 0) >= 60) { require_once __DIR__ . '/jobs.php'; jobs_web_tick(); }
?>
<!DOCTYPE html>
<html lang="en-KE">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#000080">
<?= seo_head($meta) ?>
<?= google_head_html($meta) ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="<?= e($favicon) ?>">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/extra.css')) ?>">
<?php if (!empty($meta['payment_widget'])) { require_once __DIR__ . '/payment_hub.php'; if (hub_widget_url() !== '') { echo '<script src="' . e(hub_widget_url()) . '" defer></script>' . "\n"; } } ?>
<?= $meta['head_extra'] ?? '' ?>
</head>
<body class="light-theme">
<?= google_body_html() ?>
<script>document.documentElement.className+=' js';try{if(localStorage.getItem('patadocs-theme')==='dark'){document.body.className='dark-theme';}}catch(e){}</script>
<div class="aspx-form" id="form1">
<?= topbar_visible($topbar, $navKey) ? topbar_html($topbar) : '' ?>

<!-- ===== TOP BAR ===== -->
<div class="aspx-toolbar">
    <a class="brand" href="<?= e(url('')) ?>" style="text-decoration:none;">
        <?php if (setting('site_logo')) { echo '<img class="brand-logo" src="' . e(url(setting('site_logo'))) . '" alt="">'; } ?>
        <?= $brand ?>
    </a>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">☰</button>
    <nav class="aspx-menu" id="mobileMenu" aria-label="Main">
        <ul id="mainNav">
            <?php foreach ($menu as $m) { echo '<li data-prio="' . (int)$m[3] . '"><a href="' . e($m[2]) . '" class="nav-link' . ($navKey === $m[0] ? ' active" aria-current="page' : '') . '">' . e($m[1]) . '</a></li>'; } ?>
            <li class="nav-more" hidden><button type="button" class="nav-more-btn" aria-expanded="false" aria-controls="navMoreList">More <span aria-hidden="true">▾</span></button><ul class="nav-more-list" id="navMoreList"></ul></li>
        </ul>
    </nav>
    <div class="toolbar-right">
        <a class="theme-toggle" href="<?= e(page_url('saved')) ?>" id="savedLink" title="Saved documents">★ <span id="savedCount">0</span></a>
        <button type="button" class="theme-toggle" id="themeToggleBtn">🌙 DARK</button>
    </div>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="aspx-content">
<?= flash_html() ?>
