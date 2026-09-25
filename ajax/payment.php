<?php
/** AJAX: Buy button — creates the order + its Hub invoice and returns the payment intent id for EditoriaPay.open({ token }). */
define('PD_AJAX', true);
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';

if (!is_post()) { json_out(['ok' => false, 'message' => 'Invalid request.'], 405); }
csrf_check(true);
$type = post_str('type', 12) === 'collection' ? 'collection' : 'doc';
$r = checkout_start($type, post_int('id'));
if ($r['ok']) {
    // token = payment intent id for EditoriaPay.open(); status_url = our page that follows the Hub status
    json_out($r + ['status_url' => url('payment.php?ref=' . rawurlencode($r['ref']) . '&k=' . rawurlencode($r['key']))]);
}
json_out(['ok' => false, 'message' => $r['message']], 400);
