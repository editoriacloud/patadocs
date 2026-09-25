<?php
/**
 * AJAX (or plain form) actions on a pending order, for the browser that created it (order code + secret key):
 *   action=resend   → send the M-Pesa STK prompt again (rate limited)
 *   action=claim    → "I already paid": verify an M-Pesa code with the Hub and unlock the order (see order_claim_receipt)
 */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';

if (!is_post()) { respond(false, 'Invalid request.', [], 405); }
csrf_check();
$order = order_by_code(strtoupper(post_str('o', 20)));
if (!$order || !order_key_ok($order, post_str('k', 64))) { respond(false, 'Order not found.', [], 404); }
$back = url('payment.php?o=' . rawurlencode($order['order_code']) . '&k=' . rawurlencode($order['access_key']));
switch (post_str('action', 10)) {
    case 'resend':
        $r = order_resend_stk($order);
        respond($r['ok'], $r['message'], ['redirect' => $back]);
    case 'claim':
        $r = order_claim_receipt($order, post_str('receipt', 20));
        $paid = $r['ok'] ? url('payment-success.php?o=' . rawurlencode($order['order_code']) . '&k=' . rawurlencode($order['access_key'])) : $back;
        respond($r['ok'], $r['message'], ['redirect' => $paid, 'paid' => $r['ok']]);
}
respond(false, 'Unknown action.');
