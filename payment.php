<?php
/**
 * PATADOCS — payment page (works with or without JavaScript).
 *   payment.php?doc=ID | ?col=ID   → phone form (creates the order + its Payment Hub invoice)
 *   payment.php?o=ORDER&k=KEY      → pay (EditoriaPay modal, or the Hub's hosted page without JS) and
 *                                    live status of the order (polls until the Hub confirms)
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/payment_hub.php';

// ---- Order status view ---------------------------------------------------------------
if (get_str('o', 20) !== '') {
    $order = order_by_code(strtoupper(get_str('o', 20)));
    if (!$order || !order_key_ok($order, get_str('k', 64))) { abort_page(404, 'Order not found', 'We could not find that order. If you already paid, use "Recover purchase".', [['🧾 RECOVER PURCHASE', page_url('recover')], ['🏠 HOME', url('')]]); }
    $order = order_refresh($order);
    if ($order['status'] === 'paid') { redirect(url('payment-success.php?o=' . rawurlencode($order['order_code']) . '&k=' . rawurlencode($order['access_key']))); }
    $docBack = $order['document_id'] ? (($d = doc_get((int)$order['document_id'])) ? doc_url($d) : url('')) : ($order['collection_id'] ? (($c = db_row('SELECT * FROM collections WHERE id = ?', [$order['collection_id']])) ? collection_url($c) : url('')) : url(''));
    $pending = $order['status'] === 'pending';
    $hostedUrl = $pending ? order_hosted_payment_url($order) : '';
    $meta = ['title' => 'Payment ' . $order['order_code'], 'robots' => 'noindex,nofollow', 'nav' => '', 'payment_widget' => $pending];
    include __DIR__ . '/includes/header.php';
    ?>
    <section class="page-section active" id="page-pay">
        <div class="panel"><div class="panel-header <?= $pending ? 'orange' : '' ?>">PAYMENT <?= $pending ? 'PENDING' : e(strtoupper($order['status'])) ?></div>
        <div class="panel-body" id="payPage" data-order="<?= e($order['order_code']) ?>" data-key="<?= e($order['access_key']) ?>" data-token="<?= e($pending ? (string)$order['hub_reference'] : '') ?>" style="max-width:720px;">
            <div class="result-area">
                <div class="result-row"><strong>Order ID:</strong> <span class="mono"><?= e($order['order_code']) ?></span></div>
                <div class="result-row"><strong>Item:</strong> <?= e($order['item_title']) ?></div>
                <div class="result-row"><strong>Amount:</strong> <?= e(money($order['amount'])) ?></div>
                <div class="result-row"><strong>Phone:</strong> <?= e('0' . substr($order['phone'], 3, 3) . ' *** ' . substr($order['phone'], -3)) ?></div>
            </div>
            <div id="payState" style="margin-top:16px;">
            <?php if ($pending) { ?>
                <div class="alert alert-info"><span class="spinner"></span> <strong>Waiting for payment.</strong> Pay in the secure M-Pesa window (STK prompt or PayBill). This page updates automatically once the payment is confirmed.</div>
                <div class="form-actions">
                    <?php if ($order['hub_reference']) { ?><button type="button" class="btn-classic success hidden" id="hubPayBtn" style="font-size:1.1rem;">PAY <?= e(money($order['amount'])) ?></button><?php } ?>
                    <?php if ($hostedUrl) { ?><a class="btn-classic<?= $order['hub_reference'] ? '' : ' success' ?>" id="hubPayLink" href="<?= e($hostedUrl) ?>" rel="noopener">Open the secure payment page</a><?php } ?>
                </div>
                <noscript><meta http-equiv="refresh" content="10"></noscript>
            <?php } else { ?>
                <div class="alert alert-error">❌ <?= $order['status'] === 'expired' ? 'This payment request expired.' : 'The payment was not completed.' ?> You have not been charged unless M-Pesa confirmed a payment.</div>
                <a class="btn-classic primary" href="<?= e($docBack) ?>">↩ TRY AGAIN</a>
                <a class="btn-classic" href="<?= e(page_url('recover')) ?>">🧾 I ALREADY PAID</a>
            <?php } ?>
            </div>
        </div></div>
    </section>
    <?php
    include __DIR__ . '/includes/footer.php'; exit;
}

// ---- Purchase form (start a payment) ----------------------------------------------------
$type = get_int('col') > 0 ? 'collection' : 'doc';
$item = null;
if ($type === 'collection') {
    $c = db_row("SELECT * FROM collections WHERE id = ? AND status = 'published'", [get_int('col')]);
    if ($c && !(int)$c['is_free'] && (float)$c['price'] > 0) { $item = ['id' => (int)$c['id'], 'title' => $c['title'], 'price' => (float)$c['price'], 'back' => collection_url($c), 'fmt' => 'Bundle']; }
} else {
    $d = doc_get(get_int('doc'));
    if ($d && $d['status'] === 'published' && !(int)$d['is_free'] && (float)$d['price'] > 0) { $item = ['id' => (int)$d['id'], 'title' => $d['title'], 'price' => (float)$d['price'], 'back' => doc_url($d), 'fmt' => doc_ext_label($d['file_ext'])]; }
}
if (!$item) { abort_page(404, 'Nothing to pay for', 'This item is free or no longer available.', [['🔍 SEARCH DOCUMENTS', page_url('search')], ['🏠 HOME', url('')]]); }

$error = '';
if (is_post()) {
    csrf_check();
    $r = checkout_start($type, $item['id'], post_str('phone', 20), post_str('email', 190), post_str('name', 120));
    if ($r['ok']) { redirect(url('payment.php?o=' . rawurlencode($r['order']) . '&k=' . rawurlencode($r['key']))); }
    $error = $r['message'];
}
$meta = ['title' => 'Pay for ' . $item['title'], 'robots' => 'noindex,nofollow', 'nav' => ''];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-pay">
    <div class="panel"><div class="panel-header orange">SECURE M-PESA PAYMENT</div>
    <div class="panel-body" style="max-width:720px;">
        <?php if ($error) { echo '<div class="alert alert-error">' . e($error) . '</div>'; } ?>
        <div class="doc-selected"><div class="lbl"><?= $type === 'collection' ? 'Bundle' : 'Document' ?></div><div class="name"><?= e($item['title']) ?></div>
            <div class="doc-facts"><span>📄 <?= e($item['fmt']) ?></span></div>
            <div class="price-row"><span class="k">TOTAL:</span><span class="v"><?= e(money($item['price'])) ?></span></div></div>
        <form class="pd-form" method="post">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="frow"><label for="pPhone">M-Pesa phone number <span class="req">*</span></label><input type="tel" id="pPhone" name="phone" required placeholder="07XX XXX XXX" value="<?= e(post_str('phone', 20)) ?>" autocomplete="tel"></div>
                <div class="frow"><label for="pEmail">Email (optional)</label><input type="email" id="pEmail" name="email" placeholder="for your receipt" value="<?= e(post_str('email', 190)) ?>"></div>
            </div>
            <p class="help" style="margin-top:10px;">No account needed. On the next step, pay in the secure M-Pesa window (STK prompt or PayBill). Your download unlocks automatically once the payment is confirmed.</p>
            <div class="form-actions"><button type="submit" class="btn-classic success" style="font-size:1.1rem;">PAY <?= e(money($item['price'])) ?></button><a class="btn-classic" href="<?= e($item['back']) ?>">CANCEL</a></div>
        </form>
    </div></div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
