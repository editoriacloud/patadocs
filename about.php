<?php
/** PATADOCS — About page (text editable in Admin → Settings → General). */
require __DIR__ . '/includes/init.php';
$meta = [ 'no_ads' => true,
    'title' => 'About ' . setting('site_name'), 'nav' => 'about', 'canonical' => page_url('about'),
    'description' => excerpt(setting('about_text'), 200),
    'schema' => [['@context' => 'https://schema.org', '@type' => 'AboutPage', 'name' => 'About ' . setting('site_name'), 'url' => page_url('about')], breadcrumb_schema([['Home', url('')], ['About', null]])],
];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-about">
    <div class="panel">
        <div class="panel-header">ABOUT <?= e(strtoupper(setting('site_name'))) ?></div>
        <div class="panel-body">
            <p style="font-size:1.1rem; line-height:1.8; color:var(--text-secondary); max-width:860px; font-family:Tahoma, sans-serif;"><?= nl2br(e(setting('about_text'))) ?></p>
            <p style="margin-top:16px; font-weight:bold; color:#000080; font-family:Tahoma, sans-serif; font-size:1.05rem;">Powered by Editoria Cloud Systems</p>
            <div class="flex mt-4">
                <a class="btn-classic primary" href="<?= e(page_url('contribute')) ?>">🤝 CONTRIBUTE A RESOURCE</a>
                <a class="btn-classic" href="<?= e(page_url('contact')) ?>">✉ CONTACT US</a>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
