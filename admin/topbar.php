<?php
/** Admin: the top bar above the main navigation — announcements, contacts, links, social icons, colours, pages, schedule. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/topbar.php';
$admin = require_admin('homepage.manage');

$platforms = topbar_platforms();
if (is_post()) {
    csrf_check();
    $txt = function ($k, $max) { return mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? ''))), 0, $max); };
    set_setting('topbar_on', !empty($_POST['topbar_on']) ? '1' : '0');
    set_setting('topbar_messages', $txt('topbar_messages', 3000));
    set_setting('topbar_rotate', (string)max(2, min(30, post_int('topbar_rotate', 6))));
    set_setting('topbar_phone', mb_substr(preg_replace('/[^\d+ ]/', '', $txt('topbar_phone', 30)), 0, 20));
    $em = $txt('topbar_email', 190);
    if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) { flash('error', 'The email address is not valid — it was not saved.'); } else { set_setting('topbar_email', $em); }
    set_setting('topbar_whatsapp', preg_replace('/[^\d+]/', '', $txt('topbar_whatsapp', 20)));
    set_setting('topbar_links', $txt('topbar_links', 1500));
    foreach (array_keys($platforms) as $k) {
        $u = $txt('topbar_social_' . $k, 300);
        if ($u !== '' && topbar_safe_url($u) === '') { flash('error', $platforms[$k][0] . ': enter a full link like https://… — it was not saved.'); continue; }
        set_setting('topbar_social_' . $k, $u);
    }
    foreach (['topbar_bg' => '#000040', 'topbar_fg' => '#ffffff', 'topbar_accent' => '#ffff66'] as $k => $d) { set_setting($k, topbar_color($txt($k, 7), $d)); }
    set_setting('topbar_dismiss', !empty($_POST['topbar_dismiss']) ? '1' : '0');
    set_setting('topbar_mobile_contacts', !empty($_POST['topbar_mobile_contacts']) ? '1' : '0');
    set_setting('topbar_pages', post_str('topbar_pages', 5) === 'home' ? 'home' : 'all');
    foreach (['topbar_start', 'topbar_end'] as $k) { $v = $txt($k, 20); set_setting($k, $v !== '' && strtotime($v) ? date('Y-m-d H:i', strtotime($v)) : ''); }
    log_admin('topbar_updated', 'settings');
    flash('success', 'Top bar saved.');
    redirect(url('admin/topbar.php'));
}

$cfg = topbar_config();
$v = function ($k, $d = '') { return e(setting($k, $d)); };
$dt = function ($k) { $s = (string)setting($k); return $s !== '' && strtotime($s) ? date('Y-m-d\TH:i', strtotime($s)) : ''; };
$state = !$cfg['on'] ? ['b-gray', 'Off'] : (topbar_visible($cfg, 'home') ? ['b-green', 'Live'] : ['b-orange', 'On, but hidden now (schedule or nothing to show)']);
$adm = ['title' => 'Top Bar', 'active' => 'topbar'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">TOP BAR <span class="badge <?= $state[0] ?>" style="margin-left:8px;"><?= e($state[1]) ?></span></div><div class="panel-body">
    <p class="help" style="margin-top:0;">The strip above the main menu on every public page: announcements that rotate, your contacts, a few links and your social pages. Visitors can close it (it comes back when you change the announcements).</p>

    <div class="section-title" style="margin-top:0;">LIVE PREVIEW</div>
    <div id="tbPreview" class="tb-preview-box"><?= topbar_html(array_merge($cfg, ['on' => true]), true) ?></div>
    <p id="tbContrast" class="help" aria-live="polite"></p>

    <form method="post" class="pd-form" id="tbForm"><?= csrf_field() ?>
        <div class="section-title">SHOW</div>
        <div class="form-grid">
            <div class="frow"><span class="flabel">Top bar</span><label class="chk"><input type="checkbox" name="topbar_on" value="1"<?= $cfg['on'] ? ' checked' : '' ?>><span>Show the top bar</span></label></div>
            <div class="frow"><label for="tbPages">Pages</label><select id="tbPages" name="topbar_pages"><option value="all"<?= $cfg['pages'] === 'all' ? ' selected' : '' ?>>Every public page</option><option value="home"<?= $cfg['pages'] === 'home' ? ' selected' : '' ?>>Home page only</option></select></div>
            <div class="frow"><label for="tbStart">Start showing (optional)</label><input type="datetime-local" id="tbStart" name="topbar_start" value="<?= e($dt('topbar_start')) ?>"></div>
            <div class="frow"><label for="tbEnd">Stop showing (optional)</label><input type="datetime-local" id="tbEnd" name="topbar_end" value="<?= e($dt('topbar_end')) ?>"><span class="help">Handy for a promotion that ends on a date.</span></div>
            <div class="frow"><span class="flabel">Close button</span><label class="chk"><input type="checkbox" name="topbar_dismiss" value="1"<?= $cfg['dismiss'] ? ' checked' : '' ?>><span>Visitors can close the bar</span></label></div>
            <div class="frow"><span class="flabel">Phones</span><label class="chk"><input type="checkbox" name="topbar_mobile_contacts" value="1"<?= $cfg['mobile_contacts'] ? ' checked' : '' ?>><span>Also show the phone number on phones</span></label><span class="help">Off = phones show only the announcement, so the bar stays one line.</span></div>
        </div>

        <div class="section-title">ANNOUNCEMENTS</div>
        <div class="form-grid">
            <div class="frow full"><label for="tbMsgs">One per line — add a link after a | sign</label>
                <textarea id="tbMsgs" name="topbar_messages" rows="5" placeholder="🎉 New: KCSE 2026 revision papers are here | /search?q=kcse&#10;📱 Pay with M-Pesa and download instantly — no account needed&#10;✍ Teachers: share your schemes of work | /contribute"><?= $v('topbar_messages') ?></textarea>
                <span class="help">Up to 10 lines. Several lines rotate automatically.</span></div>
            <div class="frow"><label for="tbRotate">Seconds per announcement</label><input type="number" id="tbRotate" name="topbar_rotate" min="2" max="30" value="<?= (int)$cfg['rotate'] ?>"></div>
        </div>

        <div class="section-title">CONTACTS (LEFT)</div>
        <div class="form-grid">
            <div class="frow"><label for="tbPhone">Phone</label><input type="tel" id="tbPhone" name="topbar_phone" value="<?= $v('topbar_phone') ?>" placeholder="0712 345 678"></div>
            <div class="frow"><label for="tbWa">WhatsApp number</label><input type="tel" id="tbWa" name="topbar_whatsapp" value="<?= $v('topbar_whatsapp') ?>" placeholder="0712 345 678"></div>
            <div class="frow"><label for="tbEmail">Email</label><input type="email" id="tbEmail" name="topbar_email" value="<?= $v('topbar_email') ?>" placeholder="info@knickpoint.co.ke"></div>
        </div>

        <div class="section-title">LINKS &amp; SOCIAL (RIGHT)</div>
        <div class="form-grid">
            <div class="frow full"><label for="tbLinks">Links — one per line: Label | link (up to 4)</label>
                <textarea id="tbLinks" name="topbar_links" rows="3" placeholder="Recover purchase | /recover&#10;Help | /contact"><?= $v('topbar_links') ?></textarea></div>
            <?php foreach ($platforms as $k => $p) { ?>
                <div class="frow"><label for="tbS<?= e($k) ?>"><?= topbar_icon($k, 14) ?> <?= e($p[0]) ?></label><input type="url" id="tbS<?= e($k) ?>" name="topbar_social_<?= e($k) ?>" value="<?= $v('topbar_social_' . $k) ?>" placeholder="https://…"></div>
            <?php } ?>
        </div>

        <div class="section-title">COLOURS</div>
        <div class="form-grid">
            <div class="frow"><label for="tbBg">Background</label><input type="color" id="tbBg" name="topbar_bg" value="<?= e($cfg['bg']) ?>"></div>
            <div class="frow"><label for="tbFg">Text</label><input type="color" id="tbFg" name="topbar_fg" value="<?= e($cfg['fg']) ?>"></div>
            <div class="frow"><label for="tbAc">Announcement links</label><input type="color" id="tbAc" name="topbar_accent" value="<?= e($cfg['accent']) ?>"></div>
            <div class="frow"><span class="flabel">Presets</span><div class="flex">
                <?php foreach (['Navy' => ['#000040', '#ffffff', '#ffff66'], 'Brand red' => ['#800000', '#ffffff', '#ffff00'], 'M-Pesa green' => ['#0b5e2b', '#ffffff', '#ffe98a'], 'Charcoal' => ['#222222', '#f0f0f0', '#9fcdff'], 'Light' => ['#fff8d6', '#1a1a1a', '#003399']] as $n => $c) {
                    echo '<button type="button" class="btn-classic btn-sm" data-preset="' . e(implode(',', $c)) . '" style="background:' . e($c[0]) . ';color:' . e($c[1]) . ';">' . e($n) . '</button>'; } ?>
            </div></div>
        </div>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE TOP BAR</button></div>
    </form>
</div></div>
<script>
(function () {
    var form = document.getElementById('tbForm'), box = document.getElementById('tbPreview'), note = document.getElementById('tbContrast'), t;
    var lum = function (h) { var c = [1, 3, 5].map(function (i) { var v = parseInt(h.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
    var ratio = function (a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    var check = function () {
        var bg = form.topbar_bg.value, r1 = ratio(bg, form.topbar_fg.value), r2 = ratio(bg, form.topbar_accent.value), bad = [];
        if (r1 < 4.5) { bad.push('text (' + r1.toFixed(1) + ':1)'); } if (r2 < 4.5) { bad.push('announcement links (' + r2.toFixed(1) + ':1)'); }
        note.innerHTML = bad.length ? '⚠ Hard to read: ' + bad.join(' and ') + ' — aim for at least 4.5:1 against the background.' : '✔ Colours are easy to read.';
    };
    var refresh = function () {
        check();
        var fd = new FormData(form); fd.append('action', 'topbar_preview');
        window.PDUI.api(PD.base + 'ajax/admin.php', { data: fd }).then(function (r) { if (r.ok && r.html) { box.innerHTML = r.html; } });
    };
    form.addEventListener('input', function () { clearTimeout(t); t = setTimeout(refresh, 350); });
    form.addEventListener('click', function (e) {
        var b = e.target.closest('[data-preset]'); if (!b) { return; }
        var c = b.getAttribute('data-preset').split(','); form.topbar_bg.value = c[0]; form.topbar_fg.value = c[1]; form.topbar_accent.value = c[2]; refresh();
    });
    check();
})();
</script>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
