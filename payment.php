<?php
/**
 * PATADOCS — payment page, following the Editoria Payment Hub's documented flow:
 *   payment.php?doc=ID | ?col=ID  → item + Pay button. With JavaScript the button opens the Hub's widget
 *                                  (EditoriaPay.open); without it, the order is created and the buyer goes to the
 *                                  Hub's hosted payment page.
 *   payment.php?o=ORDER&k=KEY     → the order's status as the Hub reports it (GET /payment-intents/{id}/status,
 *                                  checked server-side). Paid → the download page. Nothing else unlocks an order.
 */
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/payment_hub.php';

// ---- Order status (follows the Hub) --------------------------------------------------------
if (get_str('o', 20) !== '') {
    $order = order_by_code(strtoupper(get_str('o', 20)));
    if (!$order || !order_key_ok($order, get_str('k', 64))) { abort_page(404, 'Order not found', 'We could not find that order. If you already paid, use "Recover purchase".', [['🧾 RECOVER PURCHASE', page_url('recover')], ['🏠 HOME', url('')]]); }
    $order = order_refresh($order, true);                           // ask the Hub now
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
            </div>
            <div id="payState" style="margin-top:16px;">
            <?php if ($pending) { ?>
                <div id="payLive"><div class="alert alert-info"><span class="spinner"></span> <strong>Waiting for the Payment Hub to confirm your payment.</strong> This page updates by itself the moment it does.</div></div>
                <div class="form-actions">
                    <?php if ($order['hub_reference']) { ?><button type="button" class="btn-classic success hidden" id="hubPayBtn" style="font-size:1.1rem;">PAY <?= e(money($order['amount'])) ?></button><?php } ?>
                    <?php if ($hostedUrl) { ?><a class="btn-classic" id="hubPayLink" href="<?= e($hostedUrl) ?>" rel="noopener">Open the secure payment page</a><?php } ?>
                </div>
                <noscript><meta http-equiv="refresh" content="10"></noscript>
            <?php } else { ?>
                <div class="alert alert-error">❌ <?= $order['status'] === 'expired' ? 'This payment request expired.' : ($order['status'] === 'refunded' ? 'This order was refunded.' : 'The payment was not completed.') ?> You have not been charged unless M-Pesa confirmed a payment.</div>
                <a class="btn-classic primary" href="<?= e($docBack) ?>">↩ TRY AGAIN</a>
                <a class="btn-classic" href="<?= e(page_url('recover')) ?>">🧾 I ALREADY PAID</a>
            <?php } ?>
            </div>
        </div></div>
    </section>
    <?php
    include __DIR__ . '/includes/footer.php'; exit;
}

// ---- Item + Pay button ---------------------------------------------------------------------
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
if (is_post()) {                                                   // no JavaScript: go to the Hub's hosted payment page
    csrf_check();
    $r = checkout_start($type, $item['id']);
    if ($r['ok']) { redirect($r['pay_url'] !== '' ? $r['pay_url'] : url('payment.php?o=' . rawurlencode($r['order']) . '&k=' . rawurlencode($r['key']))); }
    $error = $r['message'];
}
$meta = ['title' => 'Pay for ' . $item['title'], 'robots' => 'noindex,nofollow', 'nav' => '', 'payment_widget' => true];
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
            <p class="help">No account needed. Pay with M-Pesa (STK prompt or PayBill) in the secure Editoria payment window. Your download unlocks as soon as the payment is confirmed.</p>
            <div class="form-actions"><button type="submit" class="btn-classic success" style="font-size:1.1rem;" data-pay-type="<?= e($type) ?>" data-pay-id="<?= (int)$item['id'] ?>">PAY <?= e(money($item['price'])) ?></button><a class="btn-classic" href="<?= e($item['back']) ?>">CANCEL</a></div>
            <div class="pay-msg help" role="status" aria-live="polite"></div>
        </form>
    </div></div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
