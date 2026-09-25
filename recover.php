<?php
/**
 * PATADOCS — Recover a purchase (no customer accounts).
 * Ownership is proven with Order ID + the phone number used to pay, OR the M-Pesa transaction code.
 * Guessing an order number alone reveals nothing; attempts are rate limited.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/payment_hub.php';

$result = null; $error = ''; $mode = 'order';
if (is_post()) {
    csrf_check();
    $mode = post_str('mode', 10) === 'receipt' ? 'receipt' : 'order';
    if (!rate_limit('recover:' . client_ip(), 10, 3600)) { $error = 'Too many attempts. Please try again in an hour, or contact us.'; }
    else {
        $order = null;
        if ($mode === 'receipt') {
            $rc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', post_str('receipt', 30)));
            if (strlen($rc) >= 8) { $order = db_row("SELECT * FROM orders WHERE mpesa_receipt = ? AND status = 'paid'", [$rc]); }
        } else {
            $o = order_by_code(strtoupper(trim(post_str('order', 20))));
            $phone = normalize_phone(post_str('phone', 20));
            if ($o && $phone !== '' && hash_equals((string)$o['phone'], $phone) && $o['status'] === 'paid') { $order = $o; }
        }
        if (!$order) { $error = 'We could not verify that purchase. Check your details and try again.'; }
        else {
            $links = order_download_links($order);
            if (!$links) {                                       // links expired / used up → issue fresh ones (bounded)
                $docs = order_documents($order);
                $issued = (int)db_val('SELECT COUNT(*) FROM download_tokens WHERE order_id = ?', [$order['id']]);
                if ($issued >= 6 * max(1, count($docs))) { $error = 'This order has reached its recovery limit. Please contact us for help.'; }
                else {
                    foreach ($docs as $d) {
                        $max = $d['download_limit'] !== null ? (int)$d['download_limit'] : (int)setting('default_download_limit', 3);
                        token_create((int)$d['id'], (int)$order['id'], 'paid', max(1, (int)setting('download_token_hours', 48)) * 60, $max);
                    }
                    $links = order_download_links($order);
                }
            }
            if ($links) { $result = ['order' => $order, 'links' => $links]; }
            elseif (!$error) { $error = 'The document for this order is no longer available. Please contact us.'; }
        }
    }
}
$meta = ['title' => 'Recover Purchase', 'nav' => '', 'robots' => 'noindex,nofollow', 'canonical' => page_url('recover'), 'description' => 'Recover your purchased document with your Order ID and phone number.'];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-recover">
    <div class="panel">
        <div class="panel-header orange">RECOVER PURCHASE</div>
        <div class="panel-body">
            <?php if ($result) { ?>
                <div class="alert alert-success">✅ Purchase verified — <strong><?= e($result['order']['item_title']) ?></strong> (Order <?= e($result['order']['order_code']) ?>)</div>
                <?php foreach ($result['links'] as $l) { ?>
                    <div class="result-area" style="margin-bottom:10px;"><div class="result-row"><strong><?= e($l['title']) ?></strong> <span class="muted"><?= e($l['format']) ?></span>
                        <a class="btn-classic success btn-sm" style="margin-left:auto;" href="<?= e($l['url']) ?>">⬇ DOWNLOAD NOW</a></div></div>
                <?php } ?>
                <p class="help">Links are time-limited for your security. You can come back here any time to get a fresh link.</p>
            <?php } else { ?>
                <?php if ($error) { echo '<div class="alert alert-error">' . e($error) . '</div>'; } ?>
                <p class="desc-text" style="margin-bottom:14px;">Paid for a document but lost your download link? No account is needed — verify your purchase below.</p>
                <form class="pd-form" method="post" style="max-width:680px;">
                    <?= csrf_field() ?>
                    <div class="tabs" data-scope="#recoverTabs" style="margin-bottom:0;">
                        <button type="button" class="tab<?= $mode === 'order' ? ' active' : '' ?>" data-tab="rec-order" onclick="document.getElementById('recMode').value='order'">Order ID + phone</button>
                        <button type="button" class="tab<?= $mode === 'receipt' ? ' active' : '' ?>" data-tab="rec-receipt" onclick="document.getElementById('recMode').value='receipt'">M-Pesa code</button>
                    </div>
                    <input type="hidden" name="mode" id="recMode" value="<?= e($mode) ?>">
                    <div id="recoverTabs">
                        <div class="tab-pane<?= $mode === 'order' ? ' active' : '' ?>" id="rec-order">
                            <div class="form-grid">
                                <div class="frow"><label for="rOrder">Order ID</label><input type="text" id="rOrder" name="order" maxlength="20" placeholder="DOC-8F92K4X7" style="text-transform:uppercase;"></div>
                                <div class="frow"><label for="rPhone">Phone used to pay</label><input type="text" id="rPhone" name="phone" maxlength="20" placeholder="07XX XXX XXX"></div>
                            </div>
                        </div>
                        <div class="tab-pane<?= $mode === 'receipt' ? ' active' : '' ?>" id="rec-receipt">
                            <div class="frow"><label for="rReceipt">M-Pesa transaction code</label><input type="text" id="rReceipt" name="receipt" maxlength="30" placeholder="e.g. SGH7X1YZ2A" style="text-transform:uppercase;"><span class="help">It is in the M-Pesa confirmation SMS.</span></div>
                        </div>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn-classic primary">🔎 FIND MY DOWNLOAD</button></div>
                </form>
            <?php } ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
