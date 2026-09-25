<?php
/** Admin: Ads & Google — AdSense readiness, ad spaces, Google Analytics / Tag Manager, search-engine verification, ads.txt. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/legal.php';
$admin = require_admin('settings.manage');
$canChange = admin_can('settings.secure');                  // ad code is inserted into every page: Super Admins only
$slots = ad_slots();

if (is_post()) {
    csrf_check();
    if (!$canChange) { abort_page(403, 'Access denied', 'Only Super Admins can change advertising and Google settings.'); }
    $txt = function ($k, $max) { return mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST[$k] ?? ''))), 0, $max); };
    foreach (['ads_enabled', 'ads_test_mode', 'ads_preview', 'ads_hide_admins'] as $b) { set_setting($b, !empty($_POST[$b]) ? '1' : '0'); }
    $pub = strtolower(preg_replace('/\s+/', '', $txt('ads_publisher_id', 40)));
    $pub = preg_replace('/^ca-/', '', $pub);
    if ($pub !== '' && !preg_match('/^pub-\d{10,20}$/', $pub)) { flash('error', 'The AdSense publisher ID looks wrong — it is “pub-” followed by 16 digits (AdSense → Account → Account information).'); }
    else { set_setting('ads_publisher_id', $pub); }
    set_setting('ads_max_per_page', (string)max(1, min(10, post_int('ads_max_per_page', 3))));
    set_setting('ads_label', mb_substr(strip_tags($txt('ads_label', 40)), 0, 40));
    set_setting('ads_txt_extra', $txt('ads_txt_extra', 3000));
    foreach ($slots as $k => $sl) {
        set_setting('ad_' . $k . '_on', !empty($_POST['slot'][$k]['on']) ? '1' : '0');
        set_setting('ad_' . $k . '_slot', preg_replace('/\D/', '', (string)($_POST['slot'][$k]['slot'] ?? '')));
        $f = (string)($_POST['slot'][$k]['format'] ?? 'auto');
        set_setting('ad_' . $k . '_format', in_array($f, ['auto', 'horizontal', 'rectangle', 'vertical', 'fluid'], true) ? $f : 'auto');
        $d = (string)($_POST['slot'][$k]['devices'] ?? 'all');
        set_setting('ad_' . $k . '_devices', in_array($d, ['all', 'desktop', 'mobile'], true) ? $d : 'all');
        set_setting('ad_' . $k . '_height', (string)max(50, min(600, (int)($_POST['slot'][$k]['height'] ?? $sl[2]))));
        set_setting('ad_' . $k . '_code', mb_substr(trim((string)($_POST['slot'][$k]['code'] ?? '')), 0, 5000));
    }
    $ga = strtoupper($txt('ga4_id', 20)); $gtm = strtoupper($txt('gtm_id', 20));
    if ($ga !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $ga)) { flash('error', 'The Google Analytics ID should look like G-XXXXXXXXXX.'); } else { set_setting('ga4_id', $ga); }
    if ($gtm !== '' && !preg_match('/^GTM-[A-Z0-9]{4,12}$/', $gtm)) { flash('error', 'The Tag Manager ID should look like GTM-XXXXXXX.'); } else { set_setting('gtm_id', $gtm); }
    foreach (['google_site_verification', 'bing_site_verification', 'yandex_site_verification', 'pinterest_site_verification'] as $k) {
        $v = $txt($k, 300);
        if (preg_match('/content=["\']([^"\']+)["\']/', $v, $m)) { $v = $m[1]; }          // whole <meta> tag pasted → keep the code
        set_setting($k, preg_replace('/[^A-Za-z0-9_\-=., ]/', '', $v));
    }
    log_admin('ads_settings_saved', 'settings');
    flash('success', 'Ads & Google settings saved.');
    redirect(url('admin/ads.php'));
}

// ---- AdSense readiness: what Google's reviewers and crawler look for ----------------------------------
$root = preg_replace('#^(https?://[^/]+).*$#', '$1', url(''));
$sub = trim((string)parse_url(url(''), PHP_URL_PATH), '/') !== '';
$rootAds = '';
if (ads_publisher() !== '' && function_exists('curl_init')) {                     // is ads.txt live at the domain root?
    $ch = curl_init($root . '/ads.txt');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
    $body = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $rootAds = $code === 200 && stripos($body, ads_publisher()) !== false ? 'ok' : 'missing';
}
$goodDocs = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published' AND (CHAR_LENGTH(COALESCE(description, '')) >= 250 OR content_status = 'ok')");
$pubDocs = (int)db_val("SELECT COUNT(*) FROM documents WHERE status = 'published'");
$priv = legal_text('privacy-policy');
$checks = [
    ['AdSense publisher ID entered', ads_publisher() !== '', 'AdSense → Account → Account information. It also adds the verification tag Google looks for.'],
    ['Site served over HTTPS', stripos(url(''), 'https://') === 0, 'Set BASE_URL to https://… in includes/config.php once your SSL certificate works.'],
    ['Site at the root of the domain', !$sub, 'AdSense adds sites by domain (e.g. knickpoint.co.ke). A site in a sub-folder is reviewed as part of the whole domain — the root pages must also meet the policies.'],
    ['ads.txt live at ' . $root . '/ads.txt', $rootAds === 'ok', $rootAds === '' ? 'Enter the publisher ID first.' : 'Copy the ads.txt text below into a file called ads.txt at the root of the domain (public_html/ads.txt).'],
    ['Privacy Policy mentions Google advertising cookies and opt-out', stripos($priv, 'adssettings.google.com') !== false && stripos($priv, 'cookie') !== false, 'Required by the AdSense Program Policies. The default text already has it — check Admin → Legal pages if you edited it.'],
    ['Contact email set (shown on Contact & policies)', filter_var(setting('site_email'), FILTER_VALIDATE_EMAIL) !== false, 'Settings → General → Contact email.'],
    ['About page has real text (200+ characters)', mb_strlen(trim((string)setting('about_text'))) >= 200, 'Settings → General → About page text: who runs the site, for whom, and why.'],
    ['At least 30 published documents with real text', $goodDocs >= 30, $goodDocs . ' of ' . $pubDocs . ' published documents have a 250+ character description or readable text. Thin sites are the #1 rejection reason (“low value content”).'],
    ['Google Search Console verified', trim((string)setting('google_site_verification')) !== '', 'Add the HTML-tag code below, then submit ' . url('sitemap.xml') . ' in Search Console.'],
    ['XML sitemap on', setting('sitemap_enabled', '1') === '1', 'Settings → SEO.'],
    ['No ads on pages without content', true, 'Automatic: payment, download, recovery, error, admin, form and policy pages, empty search results and noindex pages never show ads.'],
];
$passed = count(array_filter($checks, function ($c) { return $c[1]; }));

$adm = ['title' => 'Ads & Google', 'active' => 'ads'];
include __DIR__ . '/../includes/admin_header.php';
$v = function ($k, $d = '') { return e(setting($k, $d)); };
?>
<div class="panel"><div class="panel-header">ADSENSE READINESS — <?= $passed ?>/<?= count($checks) ?></div><div class="panel-body">
    <div class="gv-wrap"><table class="gv-table compact"><tbody>
    <?php foreach ($checks as $c) { echo '<tr><td style="width:36px; text-align:center;">' . ($c[1] ? '<span class="badge b-green">✔</span>' : '<span class="badge b-orange">!</span>') . '</td><td class="doc-title">' . e($c[0]) . '<span class="sub">' . e($c[2]) . '</span></td></tr>'; } ?>
    </tbody></table></div>
    <p class="help">Also in AdSense: <strong>Privacy &amp; messaging → European regulations message</strong> — switch on Google's certified consent message (required for visitors from the EEA, UK and Switzerland; this site already starts those visitors with ads/analytics consent denied). After approval, choose ad spaces below or turn on <strong>Auto ads</strong> in AdSense — both work with the same code.</p>
</div></div>

<form method="post" class="pd-form"><?= csrf_field() ?>
<div class="panel"><div class="panel-header">GOOGLE ADSENSE</div><div class="panel-body">
    <div class="form-grid">
        <div class="frow"><label for="aPub">Publisher ID</label><input type="text" id="aPub" name="ads_publisher_id" value="<?= e(ads_publisher() !== '' ? 'ca-' . ads_publisher() : '') ?>" placeholder="ca-pub-1234567890123456"<?= $canChange ? '' : ' disabled' ?>><span class="help">Adds the site-verification tag and the AdSense code. Paste it before applying for review.</span></div>
        <div class="frow"><span class="flabel">Show ads</span><label class="chk"><input type="checkbox" name="ads_enabled" value="1"<?= setting('ads_enabled', '0') === '1' ? ' checked' : '' ?>><span>Ads switched on</span></label><span class="help">Leave on during review too — Google needs to see the code on content pages.</span></div>
        <div class="frow"><label for="aMax">Most ad units on one page</label><input type="number" id="aMax" name="ads_max_per_page" min="1" max="10" value="<?= (int)setting('ads_max_per_page', '3') ?>"><span class="help">3 keeps pages content-first.</span></div>
        <div class="frow"><label for="aLabel">Label above each ad</label><input type="text" id="aLabel" name="ads_label" value="<?= $v('ads_label', 'Advertisement') ?>"><span class="help">Google allows “Advertisement” or “Sponsored links”.</span></div>
        <div class="frow"><span class="flabel">Preview</span><label class="chk"><input type="checkbox" name="ads_preview" value="1"<?= setting('ads_preview', '0') === '1' ? ' checked' : '' ?>><span>Show ad-space outlines to signed-in admins</span></label></div>
        <div class="frow"><span class="flabel">Your own visits</span><label class="chk"><input type="checkbox" name="ads_hide_admins" value="1"<?= setting('ads_hide_admins', '1') === '1' ? ' checked' : '' ?>><span>No ads for signed-in admins (no self-impressions)</span></label></div>
        <div class="frow"><span class="flabel">Testing</span><label class="chk"><input type="checkbox" name="ads_test_mode" value="1"<?= setting('ads_test_mode', '0') === '1' ? ' checked' : '' ?>><span>Test mode (data-adtest — no revenue, no policy risk)</span></label></div>
    </div>
</div></div>

<div class="panel"><div class="panel-header">AD SPACES</div><div class="panel-body">
    <p class="help" style="margin-top:0;">Each space sits between blocks of content — never in the menu, in pop-ups or next to Buy / Download buttons. Create a <em>Display ad</em> unit in AdSense → Ads → By ad unit, then paste its <strong>data-ad-slot</strong> number here. Height is reserved before the ad loads so the page never jumps.</p>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>On</th><th>Space</th><th>Ad unit slot ID</th><th>Format</th><th>Min height</th><th>Devices</th></tr></thead><tbody>
    <?php foreach ($slots as $k => [$title, $where, $h]) { $n = 'slot[' . e($k) . ']'; ?>
        <tr><td><input type="checkbox" name="<?= $n ?>[on]" value="1" aria-label="Enable <?= e($title) ?>"<?= setting('ad_' . $k . '_on', '0') === '1' ? ' checked' : '' ?>></td>
            <td class="doc-title"><?= e($title) ?><span class="sub"><?= e($where) ?></span>
                <details><summary class="small">Paste full ad code instead (optional)</summary><textarea name="<?= $n ?>[code]" rows="3" class="mono small" style="width:100%;" placeholder="&lt;ins class=&quot;adsbygoogle&quot; …&gt;&lt;/ins&gt; (without the adsbygoogle.js line — it is already on the page)"><?= $v('ad_' . $k . '_code') ?></textarea></details></td>
            <td><input type="text" name="<?= $n ?>[slot]" inputmode="numeric" value="<?= $v('ad_' . $k . '_slot') ?>" placeholder="1234567890" style="width:130px;" aria-label="Slot ID for <?= e($title) ?>"></td>
            <td><select name="<?= $n ?>[format]" aria-label="Format for <?= e($title) ?>"><?php foreach (['auto' => 'Responsive', 'horizontal' => 'Horizontal', 'rectangle' => 'Rectangle', 'vertical' => 'Vertical', 'fluid' => 'In-article'] as $fk => $fl) { echo '<option value="' . $fk . '"' . (setting('ad_' . $k . '_format', 'auto') === $fk ? ' selected' : '') . '>' . $fl . '</option>'; } ?></select></td>
            <td><input type="number" name="<?= $n ?>[height]" min="50" max="600" value="<?= (int)setting('ad_' . $k . '_height', (string)$h) ?>" style="width:80px;" aria-label="Minimum height for <?= e($title) ?>"></td>
            <td><select name="<?= $n ?>[devices]" aria-label="Devices for <?= e($title) ?>"><?php foreach (['all' => 'All', 'desktop' => 'Desktop only', 'mobile' => 'Phones only'] as $dk => $dl) { echo '<option value="' . $dk . '"' . (setting('ad_' . $k . '_devices', 'all') === $dk ? ' selected' : '') . '>' . $dl . '</option>'; } ?></select></td></tr>
    <?php } ?>
    </tbody></table></div>
</div></div>

<div class="panel"><div class="panel-header">GOOGLE &amp; SEARCH ENGINES</div><div class="panel-body">
    <div class="form-grid">
        <div class="frow"><label for="gSc">Google Search Console — HTML tag code</label><input type="text" id="gSc" name="google_site_verification" value="<?= $v('google_site_verification') ?>" placeholder="paste the code or the whole &lt;meta&gt; tag"><span class="help">Search Console → Add property → HTML tag. Then submit <?= e(url('sitemap.xml')) ?>.</span></div>
        <div class="frow"><label for="gGa">Google Analytics 4 — Measurement ID</label><input type="text" id="gGa" name="ga4_id" value="<?= $v('ga4_id') ?>" placeholder="G-XXXXXXXXXX"><span class="help">Consent Mode v2 is built in (EEA/UK/CH start denied).</span></div>
        <div class="frow"><label for="gTm">Google Tag Manager — Container ID</label><input type="text" id="gTm" name="gtm_id" value="<?= $v('gtm_id') ?>" placeholder="GTM-XXXXXXX"><span class="help">Optional. Do not add GA4 both here and inside Tag Manager.</span></div>
        <div class="frow"><label for="gBing">Bing Webmaster Tools — msvalidate.01 code</label><input type="text" id="gBing" name="bing_site_verification" value="<?= $v('bing_site_verification') ?>"></div>
        <div class="frow"><label for="gYx">Yandex Webmaster — verification code</label><input type="text" id="gYx" name="yandex_site_verification" value="<?= $v('yandex_site_verification') ?>"></div>
        <div class="frow"><label for="gPin">Pinterest — domain verify code</label><input type="text" id="gPin" name="pinterest_site_verification" value="<?= $v('pinterest_site_verification') ?>"></div>
    </div>
</div></div>

<div class="panel"><div class="panel-header">ADS.TXT</div><div class="panel-body">
    <div class="frow full"><label for="aTxt">Other authorised sellers (one per line, optional)</label><textarea id="aTxt" name="ads_txt_extra" rows="3" class="mono" placeholder="example-exchange.com, 12345, RESELLER, abc123"><?= $v('ads_txt_extra') ?></textarea></div>
    <?php if (ads_txt() !== '') { ?>
        <p class="help">Served at <a href="<?= e(url('ads.txt')) ?>" target="_blank"><?= e(url('ads.txt')) ?></a>.<?= $sub ? ' Google only reads <strong>' . e($root) . '/ads.txt</strong> — copy these lines into that file:' : '' ?></p>
        <textarea readonly class="mono" rows="3" style="width:100%;"><?= e(ads_txt()) ?></textarea>
    <?php } ?>
    <div class="form-actions"><?php if ($canChange) { ?><button class="btn-classic success" type="submit">💾 SAVE ADS &amp; GOOGLE SETTINGS</button><?php } else { ?><span class="help">Only Super Admins can change these settings.</span><?php } ?></div>
</div></div>
</form>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
