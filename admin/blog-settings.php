<?php
/** Admin: blog settings — name & SEO of the blog home, lists, comments, tag indexing, in-article ad position, images. */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('settings.manage');
$flags = ['blog_enabled', 'blog_in_nav', 'blog_on_home', 'blog_comments', 'blog_comments_auto', 'blog_notify_comments', 'blog_index_tags', 'blog_auto_related_docs'];
$texts = ['blog_title' => 60, 'blog_seo_title' => 190, 'blog_description' => 500];
$nums = ['blog_per_page' => [3, 50], 'blog_home_count' => [1, 9], 'blog_ad_after_paragraph' => [2, 20], 'blog_image_mb' => [1, 20], 'blog_revisions_keep' => [5, 200]];
if (is_post()) {
    csrf_check();
    foreach ($flags as $k) { set_setting($k, !empty($_POST[$k]) ? '1' : '0'); }
    foreach ($texts as $k => $max) { set_setting($k, post_str($k, $max)); }
    foreach ($nums as $k => [$lo, $hi]) { set_setting($k, (string)max($lo, min($hi, post_int($k, $lo)))); }
    if (trim((string)setting('blog_title')) === '') { set_setting('blog_title', 'Blog'); }
    log_admin('blog_settings', 'settings'); flash('success', 'Blog settings saved.');
    redirect(url('admin/blog-settings.php'));
}
$chk = function ($k, $label, $help = '') { return '<label class="chk"><input type="checkbox" name="' . $k . '" value="1"' . (setting($k) === '1' ? ' checked' : '') . '><span>' . e($label) . ($help !== '' ? ' <span class="help">— ' . e($help) . '</span>' : '') . '</span></label>'; };
$adm = ['title' => 'Blog settings', 'active' => 'blog-settings'];
include __DIR__ . '/../includes/admin_header.php';
?>
<form method="post" class="pd-form"><?= csrf_field() ?>
<div class="panel"><div class="panel-header">BLOG</div><div class="panel-body">
    <?= $chk('blog_enabled', 'Blog is on', 'off hides every blog page (404) and removes it from the sitemap') ?>
    <div class="form-grid" style="margin-top:10px;">
        <div class="frow"><label>Blog name (menu + page heading)</label><input type="text" name="blog_title" maxlength="60" value="<?= e(setting('blog_title')) ?>"></div>
        <div class="frow"><label>Blog home SEO title</label><input type="text" name="blog_seo_title" maxlength="190" value="<?= e(setting('blog_seo_title')) ?>" placeholder="<?= e(setting('blog_title') . ' — guides, tips and news from ' . setting('site_name')) ?>"></div>
        <div class="frow full"><label>Blog description (meta description + intro on the blog home + RSS)</label><textarea name="blog_description" maxlength="500" style="min-height:60px;"><?= e(setting('blog_description')) ?></textarea></div>
        <div class="frow"><label>Articles per page</label><input type="number" name="blog_per_page" min="3" max="50" value="<?= (int)setting('blog_per_page') ?>"></div>
        <div class="frow"><label>Articles on the homepage</label><input type="number" name="blog_home_count" min="1" max="9" value="<?= (int)setting('blog_home_count') ?>"></div>
    </div>
    <?= $chk('blog_in_nav', 'Show the blog in the main menu') ?>
    <?= $chk('blog_on_home', 'Show the latest articles on the homepage', 'fresh content + internal links help the whole site rank') ?>
</div></div>
<div class="panel"><div class="panel-header">SEO</div><div class="panel-body">
    <?= $chk('blog_index_tags', 'Let Google index tag pages', 'only tags with 2+ articles; off is safer — tag pages are usually thin/duplicate') ?>
    <?= $chk('blog_auto_related_docs', 'Under each article, fill "Documents for this topic" automatically', 'matches the focus keyphrase / title against your documents') ?>
    <p class="help">Every article gets: SEO title & meta description, canonical URL, BlogPosting/Article schema with author and dates, breadcrumbs, Open Graph + Twitter cards (article:published_time, section, tags), a table of contents with jump links, lazy-loaded images, an RSS feed (<a href="<?= e(blog_url('feed')) ?>" target="_blank" rel="noopener">/blog/feed</a>), a sitemap entry with images, and an instant IndexNow ping (Bing, Yandex…) when published or updated. Renamed articles redirect (301) from their old address.</p>
</div></div>
<div class="panel"><div class="panel-header">COMMENTS</div><div class="panel-body">
    <?= $chk('blog_comments', 'Allow comments on articles', 'can also be switched off per article') ?>
    <?= $chk('blog_comments_auto', 'Auto-approve people who already have an approved comment (same email)') ?>
    <?= $chk('blog_notify_comments', 'Email the site address when a comment waits for moderation') ?>
</div></div>
<div class="panel"><div class="panel-header">ADS, IMAGES &amp; HISTORY</div><div class="panel-body"><div class="form-grid">
    <div class="frow"><label>In-article ad after paragraph</label><input type="number" name="blog_ad_after_paragraph" min="2" max="20" value="<?= (int)setting('blog_ad_after_paragraph') ?>"><span class="help">Only in articles with at least 3 more paragraphs after it. Switch the space on in <a href="ads.php">Ads &amp; Google</a> (“Article — inside the text”).</span></div>
    <div class="frow"><label>Maximum image upload (MB)</label><input type="number" name="blog_image_mb" min="1" max="20" value="<?= (int)setting('blog_image_mb') ?>"></div>
    <div class="frow"><label>Revisions kept per article</label><input type="number" name="blog_revisions_keep" min="5" max="200" value="<?= (int)setting('blog_revisions_keep') ?>"></div>
</div></div></div>
<div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE SETTINGS</button></div>
</form>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
