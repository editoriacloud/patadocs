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
$menu = [
    ['home', 'Home', url('')],
    ['browse', 'Browse Documents', page_url('search')],
    ['categories', 'Categories', page_url('categories')],
    ['popular', 'Popular', page_url('popular')],
    ['contribute', 'Contribute', page_url('contribute')],
    ['request', 'Request Document', page_url('request-document')],
    ['about', 'About', page_url('about')],
];
if (setting('show_admin_link', '1') === '1') { $menu[] = ['admin', 'Admin Panel', url('admin/')]; }
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
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="<?= e($favicon) ?>">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/extra.css')) ?>">
<?php if (!empty($meta['payment_widget'])) { require_once __DIR__ . '/payment_hub.php'; if (hub_widget_url() !== '') { echo '<script src="' . e(hub_widget_url()) . '" defer></script>' . "\n"; } } ?>
<?= $meta['head_extra'] ?? '' ?>
</head>
<body class="light-theme">
<script>try{if(localStorage.getItem('patadocs-theme')==='dark'){document.body.className='dark-theme';}}catch(e){}</script>
<div class="aspx-form" id="form1">

<!-- ===== TOP BAR ===== -->
<div class="aspx-toolbar">
    <a class="brand" href="<?= e(url('')) ?>" style="text-decoration:none;">
        <?php if (setting('site_logo')) { echo '<img class="brand-logo" src="' . e(url(setting('site_logo'))) . '" alt="">'; } ?>
        <?= $brand ?>
    </a>
    <button type="button" class="hamburger" id="hamburgerBtn" aria-label="Toggle menu">☰</button>
    <div class="aspx-menu" id="mobileMenu">
        <ul>
            <?php foreach ($menu as $m) { echo '<li><a href="' . e($m[2]) . '" class="nav-link' . ($navKey === $m[0] ? ' active' : '') . '">' . e($m[1]) . '</a></li>'; } ?>
        </ul>
    </div>
    <div class="toolbar-right">
        <a class="theme-toggle" href="<?= e(page_url('saved')) ?>" id="savedLink" title="Saved documents">★ <span id="savedCount">0</span></a>
        <button type="button" class="theme-toggle" id="themeToggleBtn">🌙 DARK</button>
    </div>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="aspx-content">
<?= flash_html() ?>
