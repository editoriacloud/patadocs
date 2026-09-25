<?php
/** Admin: homepage content — hero text, popular searches, sections, testimonials, call-to-action; featured items overview. */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('homepage.manage');
$keys = ['home_banner_text' => 200, 'home_hero_title' => 190, 'home_hero_subtitle' => 400, 'home_popular_searches' => 1000, 'home_testimonials' => 4000, 'home_cta_title' => 190, 'home_cta_text' => 400];
$toggles = ['home_show_categories' => 'Featured categories', 'home_show_tabs' => 'Document tabs (featured / popular / latest / free / premium / recently updated)', 'home_show_collections' => 'Featured collections', 'home_show_features' => '“Why choose us” block', 'home_show_how' => '“How it works” block'];
if (is_post()) {
    csrf_check();
    foreach ($keys as $k => $max) { set_setting($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max)); }
    foreach ($toggles as $k => $_) { set_setting($k, !empty($_POST[$k]) ? '1' : '0'); }
    set_setting('home_banner_on', !empty($_POST['home_banner_on']) ? '1' : '0');
    $bl = trim((string)($_POST['home_banner_link'] ?? ''));
    set_setting('home_banner_link', preg_match('#^(https?://|/)#i', $bl) ? mb_substr($bl, 0, 300) : '');       // only http(s) or site-relative links
    set_setting('home_tab_size', (string)max(4, min(20, post_int('home_tab_size', 8))));
    log_admin('homepage_updated', 'settings'); flash('success', 'Homepage saved.'); redirect(url('admin/homepage.php'));
}
$feat = db_all("SELECT id, title FROM documents WHERE status = 'published' AND featured = 1 ORDER BY published_at DESC LIMIT 30");
$pop = db_all("SELECT id, title FROM documents WHERE status = 'published' AND popular = 1 ORDER BY published_at DESC LIMIT 30");
$fc = featured_categories(30); $fcol = db_all("SELECT id, title FROM collections WHERE status = 'published' AND featured = 1");
$adm = ['title' => 'Homepage', 'active' => 'homepage'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">HOMEPAGE CONTENT</div><div class="panel-body">
    <form method="post" class="pd-form"><?= csrf_field() ?>
        <div class="form-grid">
            <div class="frow full"><span class="flabel">Announcement banner (top of the homepage)</span>
                <label class="chk"><input type="checkbox" name="home_banner_on" value="1" <?= setting('home_banner_on', '0') === '1' ? 'checked' : '' ?>><span>Show the banner</span></label></div>
            <div class="frow"><label>Banner text</label><input type="text" name="home_banner_text" maxlength="200" value="<?= e(setting('home_banner_text')) ?>" placeholder="New: Term 3 2026 schemes of work are now available!"></div>
            <div class="frow"><label>Banner link (optional, https://… or /page)</label><input type="text" name="home_banner_link" maxlength="300" value="<?= e(setting('home_banner_link')) ?>"></div>
            <div class="frow full"><label>Hero title</label><input type="text" name="home_hero_title" maxlength="190" value="<?= e(setting('home_hero_title')) ?>"></div>
            <div class="frow full"><label>Hero subtitle</label><input type="text" name="home_hero_subtitle" maxlength="400" value="<?= e(setting('home_hero_subtitle')) ?>"></div>
            <div class="frow full"><label>Popular searches (one per line — shown as pills under the search box)</label><textarea name="home_popular_searches" style="min-height:110px;"><?= e(setting('home_popular_searches')) ?></textarea><span class="help">If fewer than six, the most frequent real searches of the last 30 days are added automatically.</span></div>
            <div class="frow"><label>Documents per tab</label><input type="number" name="home_tab_size" min="4" max="20" value="<?= (int)setting('home_tab_size', 8) ?>"></div>
            <div class="frow"><span class="flabel">Sections to show</span><?php foreach ($toggles as $k => $lab) { echo '<label class="chk"><input type="checkbox" name="' . $k . '" value="1" ' . (setting($k, '1') === '1' ? 'checked' : '') . '><span>' . e($lab) . '</span></label>'; } ?></div>
            <div class="frow full"><label>Testimonials — one per line: <code>quote | person | role, town</code></label><textarea name="home_testimonials" style="min-height:120px;" placeholder="I found the scheme in seconds! | Grace W. | Teacher, Nairobi"><?= e(setting('home_testimonials')) ?></textarea><span class="help">The “What our users say” block only appears when you add real testimonials here.</span></div>
            <div class="frow"><label>Call-to-action title</label><input type="text" name="home_cta_title" maxlength="190" value="<?= e(setting('home_cta_title')) ?>"></div>
            <div class="frow"><label>Call-to-action text</label><input type="text" name="home_cta_text" maxlength="400" value="<?= e(setting('home_cta_text')) ?>"></div>
        </div>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE HOMEPAGE</button><a class="btn-classic" target="_blank" href="<?= e(url('')) ?>">👁 VIEW HOMEPAGE</a></div>
    </form>
</div></div>
<div class="panel"><div class="panel-header orange">WHAT IS FEATURED RIGHT NOW</div><div class="panel-body"><div class="grid-2">
    <div class="result-area"><strong>⭐ Featured documents</strong><?php foreach ($feat as $d) { echo '<div class="result-row"><a href="edit-document.php?id=' . (int)$d['id'] . '">' . e(excerpt($d['title'], 46)) . '</a><button class="btn-classic btn-sm" style="margin-left:auto;" data-act="doc_feature" data-id="' . (int)$d['id'] . '">REMOVE</button></div>'; } if (!$feat) { echo '<div class="result-row muted">None. Tick “Featured” when editing a document (step 6).</div>'; } ?></div>
    <div class="result-area"><strong>🔥 Pinned as popular</strong><?php foreach ($pop as $d) { echo '<div class="result-row"><a href="edit-document.php?id=' . (int)$d['id'] . '">' . e(excerpt($d['title'], 46)) . '</a><button class="btn-classic btn-sm" style="margin-left:auto;" data-act="doc_popular" data-id="' . (int)$d['id'] . '">UNPIN</button></div>'; } if (!$pop) { echo '<div class="result-row muted">None pinned — popularity is calculated from views and downloads.</div>'; } ?></div>
    <div class="result-area"><strong>📂 Featured categories</strong><?php foreach ($fc as $c) { echo '<div class="result-row">' . e(($c['icon'] ?: '') . ' ' . $c['name']) . '</div>'; } ?><div class="result-row"><a href="categories.php">Manage in Categories →</a></div></div>
    <div class="result-area"><strong>📦 Featured collections</strong><?php foreach ($fcol as $c) { echo '<div class="result-row">' . e($c['title']) . '</div>'; } ?><div class="result-row"><a href="collections.php">Manage in Collections →</a></div></div>
</div></div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
