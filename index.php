<?php
/**
 * PATADOCS — Homepage. Every list comes from MySQL; the admin controls featured documents,
 * featured categories, popular documents/searches and the hero/CTA text (Admin → Homepage).
 */
require __DIR__ . '/includes/init.php';

$st = site_stats();
$tabSize = max(4, min(20, (int)setting('home_tab_size', 8)));
$pills = popular_searches(6);
$featCats = setting('home_show_categories', '1') === '1' ? featured_categories(10) : [];
$featCols = setting('home_show_collections', '1') === '1' ? featured_collections(3) : [];

$tabs = [];
if (setting('home_show_tabs', '1') === '1') {
    foreach ([['featured', '⭐ Featured'], ['popular', 'Popular'], ['latest', 'Latest'], ['free', 'Free'], ['premium', 'Premium'], ['recent', 'Recently Updated']] as $t) {
        $rows = home_docs($t[0], $tabSize);
        if ($rows) { $tabs[$t[0]] = [$t[1], $rows]; }
    }
}

$testimonials = [];
foreach (preg_split('/\R/u', (string)setting('home_testimonials')) as $line) {
    $p = array_map('trim', explode('|', $line));
    if (count($p) >= 2 && $p[0] !== '') { $testimonials[] = ['quote' => $p[0], 'author' => $p[1], 'role' => $p[2] ?? '']; }
}

$placeholder = $st['docs'] >= 100 ? 'Search ' . number_format($st['docs']) . '+ documents...' : 'Search documents...';
$meta = [
    'title' => '', 'description' => setting('seo_default_description'), 'canonical' => url(''), 'nav' => 'home',
    'schema' => [
        ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => setting('site_name'), 'url' => url(''), 'inLanguage' => 'en-KE',
         'description' => setting('seo_default_description'), 'publisher' => org_schema(),
         'potentialAction' => ['@type' => 'SearchAction', 'target' => page_url('search', 'q={search_term_string}'), 'query-input' => 'required name=search_term_string']],
        ['@context' => 'https://schema.org'] + org_schema() + array_filter([
            'email' => setting('site_email') ?: null, 'telephone' => setting('site_phone') ?: null,
            'areaServed' => ['@type' => 'Country', 'name' => 'Kenya'],
            'contactPoint' => (setting('site_email') || setting('site_phone')) ? array_filter(['@type' => 'ContactPoint', 'contactType' => 'customer support',
                'email' => setting('site_email') ?: null, 'telephone' => setting('site_phone') ?: null, 'areaServed' => 'KE', 'availableLanguage' => ['English', 'Swahili']]) : null,
        ]),
    ],
];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-home">
    <?php if (setting('home_banner_on', '0') === '1' && trim(setting('home_banner_text')) !== '') {
        $bl = trim(setting('home_banner_link'));
        echo '<div class="alert alert-info" style="text-align:center; font-weight:bold; font-size:1.05rem;">📣 ' . (preg_match('#^(https?://|/)#i', $bl) ? '<a href="' . e($bl) . '">' . e(setting('home_banner_text')) . '</a>' : e(setting('home_banner_text'))) . '</div>';
    } ?>
    <div class="panel">
        <div class="panel-header green">WELCOME TO <?= e(strtoupper(setting('site_name', 'PATADOCS'))) ?></div>
        <div class="panel-body">
            <div style="text-align:center; padding: 20px 8px 12px 8px;">
                <h1 class="hero-title"><?= e(setting('home_hero_title')) ?></h1>
                <div class="hero-subtitle"><?= e(setting('home_hero_subtitle')) ?></div>
            </div>
            <?= search_hero_html('home', $placeholder) ?>
            <?php if ($pills) { ?>
            <div class="popular-row">
                <span class="label">Popular:</span>
                <?php foreach ($pills as $p) { echo '<a class="pill" href="' . e(page_url('search', 'q=' . rawurlencode($p))) . '">' . e($p) . '</a>'; } ?>
            </div>
            <?php } ?>
        </div>
    </div>

    <div class="stats-strip">
        <div class="strip-item"><div class="strip-value"><?= num($st['docs']) ?></div><div class="strip-label">Documents</div></div>
        <div class="strip-item"><div class="strip-value"><?= num($st['downloads']) ?></div><div class="strip-label">Downloads</div></div>
        <div class="strip-item"><div class="strip-value"><?= num($st['categories']) ?></div><div class="strip-label">Categories</div></div>
        <div class="strip-item"><div class="strip-value">100%</div><div class="strip-label">Secure M-Pesa</div></div>
    </div>

    <?php if ($featCats) { ?>
    <div class="panel">
        <div class="panel-header">FEATURED CATEGORIES</div>
        <div class="panel-body">
            <?= cat_tiles_html($featCats) ?>
            <div class="text-center mt-4"><a class="btn-classic" href="<?= e(page_url('categories')) ?>">VIEW ALL CATEGORIES</a></div>
        </div>
    </div>
    <?php } ?>

    <?php if ($tabs) { $first = array_key_first($tabs); ?>
    <div class="panel">
        <div class="panel-header">DOCUMENTS</div>
        <div class="panel-body" id="homeTabs">
            <div class="tabs" data-scope="#homeTabs">
                <?php foreach ($tabs as $k => $t) { echo '<button type="button" class="tab' . ($k === $first ? ' active' : '') . '" data-tab="tab-' . e($k) . '">' . e($t[0]) . '</button>'; } ?>
            </div>
            <?php foreach ($tabs as $k => $t) { ?>
                <div class="tab-pane<?= $k === $first ? ' active' : '' ?>" id="tab-<?= e($k) ?>"><?= doc_table_html($t[1]) ?></div>
            <?php } ?>
            <div class="text-center mt-4"><a class="btn-classic primary" href="<?= e(page_url('search')) ?>">BROWSE ALL DOCUMENTS</a></div>
        </div>
    </div>
    <?php } ?>

    <?php if ($featCols) { ?>
    <div class="panel">
        <div class="panel-header orange">FEATURED COLLECTIONS</div>
        <div class="panel-body">
            <div class="feature-grid">
                <?php foreach ($featCols as $c) { ?>
                <a class="feature-card" href="<?= e(collection_url($c)) ?>">
                    <span class="feature-icon">📦</span>
                    <div class="feature-title"><?= e($c['title']) ?></div>
                    <div class="feature-desc"><?= e(excerpt((string)$c['description'], 110)) ?></div>
                    <div class="coll-price"><?= (int)$c['doc_count'] ?> documents · <?= $c['is_free'] ? 'FREE' : e(money($c['price'])) ?></div>
                </a>
                <?php } ?>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (setting('home_show_features', '1') === '1') { ?>
    <div class="panel">
        <div class="panel-header">WHY CHOOSE <?= e(strtoupper(setting('site_name', 'PATADOCS'))) ?></div>
        <div class="panel-body">
            <div class="feature-grid">
                <div class="feature-card"><span class="feature-icon">⚡</span><div class="feature-title">Instant Access</div><div class="feature-desc">Download your document immediately after payment. No waiting, no delays.</div></div>
                <div class="feature-card"><span class="feature-icon">🔒</span><div class="feature-title">Protected Previews</div><div class="feature-desc">See exactly what you're buying with our watermarked, secure preview system.</div></div>
                <div class="feature-card"><span class="feature-icon">📱</span><div class="feature-title">M-Pesa Ready</div><div class="feature-desc">Pay securely with M-Pesa STK push. No account required, no complicated checkout.</div></div>
                <div class="feature-card"><span class="feature-icon">🇰🇪</span><div class="feature-title">Kenyan Content</div><div class="feature-desc">Documents tailored for the Kenyan curriculum, business environment and legal system.</div></div>
                <div class="feature-card"><span class="feature-icon">📚</span><div class="feature-title">Huge Library</div><div class="feature-desc"><?= $st['docs'] >= 100 ? 'Over ' . e(number_format((int)(floor($st['docs'] / 100) * 100))) . ' documents' : 'A growing library of documents' ?> across education, business, government and personal categories.</div></div>
                <div class="feature-card"><span class="feature-icon">💬</span><div class="feature-title">Request Anything</div><div class="feature-desc">Can't find it? Request a document and we'll source it for you.</div></div>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if (setting('home_show_how', '1') === '1') { ?>
    <div class="panel">
        <div class="panel-header">HOW IT WORKS</div>
        <div class="panel-body">
            <div class="grid-4 text-center">
                <?php foreach ([['1', 'SEARCH', 'Find the document you need.'], ['2', 'PREVIEW', 'See a protected preview.'], ['3', 'PAY', 'Pay securely via M-Pesa.'], ['4', 'DOWNLOAD', 'Get your document instantly.']] as $s) { ?>
                <div><div class="how-num"><?= $s[0] ?></div><div class="how-title"><?= $s[1] ?></div><div class="how-desc"><?= $s[2] ?></div></div>
                <?php } ?>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php if ($testimonials) { ?>
    <div class="panel">
        <div class="panel-header">WHAT OUR USERS SAY</div>
        <div class="panel-body">
            <div class="testimonial-grid">
                <?php foreach ($testimonials as $t) { ?>
                <div class="testimonial"><div class="quote">"<?= e($t['quote']) ?>"</div><div class="author"><?= e($t['author']) ?></div><div class="role"><?= e($t['role']) ?></div></div>
                <?php } ?>
            </div>
        </div>
    </div>
    <?php } ?>

    <div class="cta-banner">
        <h3><?= e(setting('home_cta_title')) ?></h3>
        <p><?= e(setting('home_cta_text')) ?></p>
        <a class="btn-classic" href="<?= e(page_url('request-document')) ?>">📝 REQUEST A DOCUMENT</a>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
