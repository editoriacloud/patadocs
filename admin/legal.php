<?php
/** Admin: legal & trust pages (Privacy Policy, Terms, Cookies, Copyright, Disclaimer) — needed for AdSense and user trust. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/legal.php';
$admin = require_admin('settings.manage');
$pages = legal_pages();
$cur = isset($pages[get_str('p', 30)]) ? get_str('p', 30) : 'privacy-policy';

if (is_post()) {
    csrf_check();
    $slug = post_str('slug', 30);
    if (!isset($pages[$slug])) { abort_page(400, 'Bad request', 'Unknown page.'); }
    $text = trim(str_replace("\r\n", "\n", (string)($_POST['text'] ?? '')));
    // Saving the default unchanged (or ticking "restore") keeps the page on the maintained default text
    set_setting('legal_' . $slug, !empty($_POST['restore']) || $text === trim(legal_default($slug)) ? '' : mb_substr($text, 0, 30000));
    set_setting('legal_updated', (string)time());
    log_admin('legal_page_saved', 'settings', $slug);
    flash('success', $pages[$slug][0] . ' saved.');
    redirect(url('admin/legal.php?p=' . $slug));
}
$adm = ['title' => 'Legal pages', 'active' => 'legal'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">LEGAL &amp; TRUST PAGES</div><div class="panel-body">
    <p class="help" style="margin-top:0;">Linked in the footer of every page and listed in the sitemap. AdSense reviewers look for a Privacy Policy that explains advertising cookies (the default text does), and every site should say how to contact it and how copyright is handled.
        Placeholders: <code>{site}</code> <code>{url}</code> <code>{email}</code> <code>{phone}</code> <code>{date}</code>. Markup: <code>## Heading</code>, <code>- list item</code>, blank line = new paragraph, full URLs become links.</p>
    <div class="flex" style="margin-bottom:12px;"><?php foreach ($pages as $s => $p) { echo '<a class="btn-classic btn-sm' . ($s === $cur ? ' active-toggle' : '') . '" href="?p=' . e($s) . '">' . e($p[0]) . (trim((string)setting('legal_' . $s)) !== '' ? ' ✎' : '') . '</a>'; } ?></div>
    <form method="post" class="pd-form"><?= csrf_field() ?><input type="hidden" name="slug" value="<?= e($cur) ?>">
        <div class="frow full"><label for="lText"><?= e($pages[$cur][0]) ?> — <a href="<?= e(page_url($cur)) ?>" target="_blank">view page</a></label>
            <textarea id="lText" name="text" rows="24" class="mono small"><?= e(legal_text($cur)) ?></textarea></div>
        <label class="chk"><input type="checkbox" name="restore" value="1"><span>Restore the default text</span></label>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE</button></div>
    </form>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
