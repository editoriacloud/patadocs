<?php
/** PATADOCS — "Payment successful": verified server-side; shows the download link(s) and starts the download. */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/payment_hub.php';
require_once __DIR__ . '/includes/reviews.php';

$order = order_by_code(strtoupper(get_str('o', 20)));
if (!$order || !order_key_ok($order, get_str('k', 64))) { abort_page(404, 'Order not found', 'We could not find that order. If you already paid, use "Recover purchase".', [['🧾 RECOVER PURCHASE', page_url('recover')], ['🏠 HOME', url('')]]); }
$order = order_refresh($order);                                   // never trust the browser: ask the Hub if still pending
if ($order['status'] !== 'paid') { redirect(url('payment.php?o=' . rawurlencode($order['order_code']) . '&k=' . rawurlencode($order['access_key']))); }
$links = order_download_links($order);
$toReview = review_pending_docs($order);
$meta = ['title' => 'Payment Successful', 'robots' => 'noindex,nofollow', 'nav' => ''];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-paid">
    <div class="panel"><div class="panel-header green">✅ PAYMENT SUCCESSFUL</div>
    <div class="panel-body" style="max-width:720px; text-align:center;">
        <div style="font-size:4rem; color:#008000; margin-bottom:12px;">✓</div>
        <div style="font-size:1.6rem; font-weight:bold; font-family:Tahoma, sans-serif; margin-bottom:8px;">Payment Successful!</div>
        <div style="font-size:1.1rem; color:var(--text-secondary); margin-bottom:18px;">Your document is ready.</div>
        <?php foreach ($links as $l) { ?>
            <div class="doc-selected" style="text-align:left;"><div class="name" style="color:var(--text-primary);"><?= e($l['title']) ?></div><div class="doc-facts"><span><?= e($l['format']) ?></span></div>
                <a class="btn-classic success block" href="<?= e($l['url']) ?>" <?= count($links) === 1 ? 'data-autodownload' : '' ?>><span style="font-size:1.1rem;">⬇ DOWNLOAD NOW</span></a></div>
        <?php } ?>
        <?php if (!$links) { echo '<div class="alert alert-warn">Your download links have expired or been used. <a href="' . e(page_url('recover')) . '"><strong>Recover your purchase</strong></a> to get a fresh link.</div>'; } else { echo '<div class="help">Your download will start automatically... Having trouble? Click <strong>Download Now</strong>.</div>'; } ?>
        <div class="result-area" style="margin-top:16px; text-align:left;">
            <div class="result-row"><strong>Order ID:</strong> <span class="mono"><?= e($order['order_code']) ?></span></div>
            <?php if ($order['mpesa_receipt']) { echo '<div class="result-row"><strong>M-Pesa receipt:</strong> <span class="mono">' . e($order['mpesa_receipt']) . '</span></div>'; } ?>
            <div class="result-row"><strong>Amount paid:</strong> <?= e(money($order['amount'])) ?></div>
        </div>
        <?php if ($toReview) { ?>
            <div class="reviews-box" id="rate" style="margin-top:18px; text-align:left;">
                <p class="help">⭐ Help other buyers: once you have opened your document, tell us what you think. Only verified buyers can review.</p>
                <?php foreach ($toReview as $d) { echo review_form_html($order, $d); } ?>
            </div>
        <?php } ?>
        <p class="help" style="margin-top:12px;">📌 Keep your Order ID — with the phone number you paid from, you can <a href="<?= e(page_url('recover')) ?>">recover this download</a> any time.</p>
        <a class="btn-classic" href="<?= e(url('')) ?>">🏠 BACK TO HOME</a>
    </div></div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
