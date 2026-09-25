<?php
/** Admin: settings — General · Documents · Preview · SEO · Payment Hub · Security (stored in the database). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('settings.manage');
$secure = admin_can('settings.secure');

// Field definitions: [key, label, type, help, extra]. Types: text, email, url, textarea, number(min,max), bool, select(options), secret, path
$tabs = [
    'general' => ['General', false, [
        ['site_name', 'Platform name', 'text'], ['tagline', 'Tagline', 'text'], ['site_email', 'Contact email', 'email', 'Shown on the contact page and used as the sender of emails.'],
        ['site_phone', 'Phone', 'text'], ['about_text', 'About page text', 'textarea'], ['footer_text', 'Footer bar text', 'text'],
        ['currency', 'Currency code', 'text', 'e.g. KES'], ['currency_symbol', 'Currency symbol', 'text', 'e.g. KSh'],
        ['show_admin_link', 'Show “Admin Panel” in the public menu', 'bool'], ['contributions_enabled', 'Accept community contributions', 'bool'], ['requests_enabled', 'Accept document requests', 'bool'],
    ]],
    'documents' => ['Documents', false, [
        ['allowed_types', 'Allowed file types (comma separated)', 'text', 'Only pdf, doc, docx, jpg, jpeg, png, webp can ever be enabled.'],
        ['max_file_mb', 'Maximum file size (MB) — admin uploads', 'number', 'Limited by PHP: upload_max_filesize / post_max_size.', [1, 512]],
        ['default_download_limit', 'Default download limit per purchase', 'number', '0 = unlimited until the link expires.', [0, 100]],
        ['default_preview_pages', 'Default preview pages', 'number', '', [1, 20]],
        ['reviews_auto_approve', 'Publish verified-buyer reviews without moderation', 'bool', 'Off = every review waits in Admin → Reviews.'],
    ]],
    'preview' => ['Preview', false, [
        ['preview_enabled', 'Generate protected previews automatically', 'bool'],
        ['preview_watermark', 'Watermark text (repeated across the page)', 'text'], ['preview_watermark2', 'Footer strip text', 'text'],
        ['preview_opacity', 'Watermark strength (opacity %)', 'number', '5 = faint, 90 = strong.', [5, 90]],
        ['preview_quality', 'Preview quality (JPEG %)', 'number', 'Lower = smaller and harder to reuse.', [30, 90]],
        ['preview_width', 'Preview width (pixels)', 'number', '', [400, 1600]],
    ]],
    'seo' => ['SEO', false, [
        ['seo_default_title', 'Default SEO title (homepage)', 'text'], ['seo_default_description', 'Default meta description', 'textarea'], ['seo_title_suffix', 'Title suffix added to every page', 'text', 'e.g. " | PATADOCS"'],
        ['canonical_urls', 'Output canonical URLs', 'bool'], ['force_canonical_host', 'Redirect to the canonical domain (301 http→https and www↔non-www, from BASE_URL in config.php)', 'bool'],
        ['indexnow_enabled', 'Notify Bing / Yandex instantly (IndexNow) when documents are published, changed or removed', 'bool'], ['sitemap_enabled', 'Enable sitemap.xml', 'bool'], ['clean_urls', 'Clean URLs (needs Apache mod_rewrite)', 'bool', 'Turn OFF if links show 404 after install.'],
    ]],
    'hub' => ['Payment Hub', true, [
        ['hub_url', 'Payment Hub URL', 'url', 'https://payments.editoriaweb.co.ke — the Hub address only, without /api/v1.'],
        ['hub_client_id', 'Client ID', 'text', 'From the Hub → Applications → PATADOCS.'], ['hub_client_secret', 'Client secret', 'secret', 'Stays on this server; it is never sent to browsers.'],
        ['hub_webhook_secret', 'Webhook secret', 'secret', 'From the Hub → Webhooks. Verifies the X-Editoria-Signature of each callback.'],
        ['hub_timeout', 'Request timeout (seconds)', 'number', '', [5, 60]],
    ]],
    'security' => ['Security & limits', true, [
        ['session_timeout', 'Admin session timeout (minutes)', 'number', '', [5, 1440]], ['max_login_attempts', 'Maximum failed logins before lockout', 'number', '', [3, 20]], ['lockout_minutes', 'Lockout duration (minutes)', 'number', '', [1, 1440]],
        ['download_token_hours', 'Paid download link lifetime (hours)', 'number', '', [1, 720]], ['free_token_minutes', 'Free download link lifetime (minutes)', 'number', '', [1, 1440]], ['free_token_max', 'Downloads per free link', 'number', '', [1, 20]],
        ['order_expiry_minutes', 'Unpaid order expiry (minutes)', 'number', '', [5, 120]],
        ['contrib_max_mb', 'Upload limit for community contributions (MB)', 'number', '', [1, 100]], ['contrib_per_hour', 'Contributions allowed per visitor per hour', 'number', '', [1, 50]],
    ]],
];
$current = isset($tabs[get_str('tab', 10)]) ? get_str('tab', 10) : 'general';

if (is_post()) {
    csrf_check();
    $tab = post_str('tab', 10);
    if (!isset($tabs[$tab])) { abort_page(400, 'Bad request', 'Unknown settings tab.'); }
    if ($tabs[$tab][1] && !$secure) { abort_page(403, 'Access denied', 'Only Super Admins can change these settings.'); }
    $errors = [];
    foreach ($tabs[$tab][2] as $f) {
        [$key, $label, $type] = $f; $extra = $f[4] ?? null; $raw = $_POST[$key] ?? '';
        switch ($type) {
            case 'bool': set_setting($key, !empty($_POST[$key]) ? '1' : '0'); break;
            case 'number': $n = (int)$raw; if (is_array($extra)) { $n = max($extra[0], min($extra[1], $n)); } set_setting($key, (string)$n); break;
            case 'secret': if (is_string($raw) && trim($raw) !== '') { set_setting($key, trim($raw)); } elseif (!empty($_POST[$key . '_clear'])) { set_setting($key, ''); } break;
            case 'select': if (is_array($extra) && isset($extra[$raw])) { set_setting($key, (string)$raw); } break;
            case 'url':
                $u = rtrim(trim((string)$raw), '/');
                if ($u !== '' && !preg_match('#^https?://[^\s]+$#i', $u)) { $errors[] = $label . ' must start with https://'; break; }
                if ($u !== '' && stripos($u, 'http://') === 0 && !preg_match('#^http://(localhost|127\.0\.0\.1)#i', $u)) { $errors[] = $label . ': use https:// (plain http is only allowed for localhost).'; break; }
                set_setting($key, $u); break;
            case 'path': $v = '/' . ltrim(trim((string)$raw), '/'); set_setting($key, mb_substr($v, 0, 190)); break;
            case 'email': $v = trim((string)$raw); if ($v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[] = 'The email address is invalid.'; break; } set_setting($key, $v); break;
            default:
                $v = mb_substr(trim((string)$raw), 0, $type === 'textarea' ? 5000 : 500);
                if ($key === 'allowed_types') { $v = implode(',', array_values(array_intersect(array_map('trim', explode(',', strtolower($v))), PD_ALL_EXTS))) ?: implode(',', PD_ALL_EXTS); }
                if ($key === 'currency') { $v = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $v), 0, 3)) ?: 'KES'; }
                set_setting($key, $v);
        }
    }
    if ($tab === 'general') {                                    // logo + favicon uploads (images only)
        foreach (['site_logo' => 'logo', 'site_favicon' => 'favicon'] as $key => $field) {
            if (!empty($_FILES[$field]) && ($_FILES[$field]['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                $chk = upload_check($_FILES[$field], allowed_exts(), 1024 * 1024, true);
                if (!$chk['ok']) { $errors[] = ucfirst($field) . ': ' . $chk['error']; continue; }
                $dir = rtrim(UPLOAD_DIR, '/\\') . '/branding'; $st = upload_save($_FILES[$field]['tmp_name'], $chk['ext'], $dir);
                if ($st) { @chmod($dir . '/' . $st, 0644); set_setting($key, 'uploads/branding/' . $st); }
            }
            if (!empty($_POST[$field . '_remove'])) { set_setting($key, ''); }
        }
    }
    if ($tab === 'hub') { require_once __DIR__ . '/../includes/payment_hub.php'; hub_token_forget(); }   // credentials may have changed
    log_admin('settings_saved', 'settings', $tab);
    foreach ($errors as $er) { flash('error', $er); }
    if (!$errors) { flash('success', 'Settings saved.'); }
    redirect(url('admin/settings.php?tab=' . $tab));
}
$adm = ['title' => 'Settings', 'active' => 'settings'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">SETTINGS</div><div class="panel-body">
    <div class="flex" style="margin-bottom:14px;">
        <?php foreach ($tabs as $k => $t) { if ($t[1] && !$secure) { continue; } echo '<a class="btn-classic btn-sm' . ($current === $k ? ' active-toggle' : '') . '" href="?tab=' . $k . '">' . e($t[0]) . '</a>'; } ?>
    </div>
    <?php $t = $tabs[$current]; if ($t[1] && !$secure) { echo '<div class="alert alert-error">Only Super Admins can change these settings.</div>'; } else { ?>
    <form method="post" class="pd-form" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="tab" value="<?= e($current) ?>">
        <div class="form-grid">
        <?php foreach ($t[2] as $f) {
            [$key, $label, $type] = $f; $help = $f[3] ?? ''; $extra = $f[4] ?? null; $val = setting($key);
            echo '<div class="frow' . ($type === 'textarea' ? ' full' : '') . '">';
            if ($type === 'bool') { echo '<span class="flabel">' . e($label) . '</span><label class="chk"><input type="checkbox" name="' . e($key) . '" value="1" ' . ($val === '1' ? 'checked' : '') . '><span>Enabled</span></label>'; }
            else {
                echo '<label for="s_' . e($key) . '">' . e($label) . '</label>';
                if ($type === 'textarea') { echo '<textarea id="s_' . e($key) . '" name="' . e($key) . '">' . e($val) . '</textarea>'; }
                elseif ($type === 'number') { echo '<input type="number" id="s_' . e($key) . '" name="' . e($key) . '" value="' . e($val) . '"' . (is_array($extra) ? ' min="' . $extra[0] . '" max="' . $extra[1] . '"' : '') . '>'; }
                elseif ($type === 'select') { echo '<select id="s_' . e($key) . '" name="' . e($key) . '">'; foreach ($extra as $k => $lab) { echo '<option value="' . e($k) . '"' . ($val === $k ? ' selected' : '') . '>' . e($lab) . '</option>'; } echo '</select>'; }
                elseif ($type === 'secret') { echo '<input type="password" id="s_' . e($key) . '" name="' . e($key) . '" autocomplete="new-password" placeholder="' . ($val !== '' ? '•••••••• saved — type to replace' : 'not set') . '">' . ($val !== '' ? '<label class="chk"><input type="checkbox" name="' . e($key) . '_clear" value="1"><span>Clear the saved value</span></label>' : ''); }
                else { echo '<input type="' . ($type === 'email' ? 'email' : 'text') . '" id="s_' . e($key) . '" name="' . e($key) . '" value="' . e($val) . '">'; }
            }
            if ($help) { echo '<span class="help">' . e($help) . '</span>'; }
            echo '</div>';
        }
        if ($current === 'general') {
            foreach (['logo' => 'site_logo', 'favicon' => 'site_favicon'] as $field => $key) {
                echo '<div class="frow"><label>' . ucfirst($field) . ' (JPG, PNG or WEBP, ≤ 1 MB)</label><input type="file" name="' . $field . '" accept=".jpg,.jpeg,.png,.webp">';
                if (setting($key)) { echo '<span class="help">Current: <a href="' . e(url(setting($key))) . '" target="_blank">' . e(setting($key)) . '</a></span><label class="chk"><input type="checkbox" name="' . $field . '_remove" value="1"><span>Remove</span></label>'; }
                echo '</div>';
            }
        } ?>
        </div>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE SETTINGS</button>
        <?php if ($current === 'hub') { echo '<button type="button" class="btn-classic warning" data-act="hub_test" data-reload="0">🔌 TEST CONNECTION (save first)</button>'; } ?>
        <?php if ($current === 'documents') { echo '<button type="button" class="btn-classic" data-act="rebuild_search" data-reload="0" data-confirm="Rebuild the search index for all documents?">♻ REBUILD SEARCH INDEX</button>'; } ?></div>
    </form>
    <?php if ($current === 'hub') { ?>
        <div class="section-title">HOW PATADOCS TALKS TO THE PAYMENT HUB</div>
        <div class="result-area">
            <div class="result-row"><strong>Webhook URL to register in the Hub:</strong> <code><?= e(url('ajax/webhook.php')) ?></code> <button type="button" class="btn-classic btn-sm" data-copy="<?= e(url('ajax/webhook.php')) ?>">Copy</button></div>
            <div class="result-row"><strong>Allowed to embed</strong> (Hub → Applications): <code><?= e(url('')) ?></code> — the payment window only opens on sites listed there.</div>
            <div class="result-row">1. <strong>Create:</strong> the server gets a bearer token (<code>POST /api/v1/auth/token</code>) and creates an invoice (<code>POST /api/v1/invoices</code>, <code>external_invoice_id</code> = Order ID, also used as the Idempotency-Key).</div>
            <div class="result-row">2. <strong>Pay:</strong> the browser opens the Hub's modal (<code>EditoriaPay.open</code>) with the invoice's payment intent id; the customer pays by STK or PayBill.</div>
            <div class="result-row">3. <strong>Confirm:</strong> the Hub calls the webhook above (<code>payment.confirmed</code>, signed with the webhook secret) and PATADOCS also asks <code>GET /api/v1/payment-intents/{id}/status</code>. A download unlocks only after that server-side confirmation — the amount, order and payment intent must match and an M-Pesa receipt can be used once.</div>
        </div>
    <?php } } ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
