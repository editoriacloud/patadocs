<?php
/** AJAX: start a payment — creates the order + its Hub invoice and returns the payment intent id for EditoriaPay.open(). */
define('PD_AJAX', true);
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';

if (!is_post()) { json_out(['ok' => false, 'message' => 'Invalid request.'], 405); }
csrf_check(true);
$type = post_str('type', 12) === 'collection' ? 'collection' : 'doc';
$r = checkout_start($type, post_int('id'), post_str('phone', 20), post_str('email', 190), post_str('name', 120));
if ($r['ok']) {
    json_out(['ok' => true, 'order' => $r['order'], 'key' => $r['key'], 'token' => $r['token'], 'pay_url' => $r['pay_url'], 'message' => $r['message'],
        'status_url' => url('payment.php?o=' . rawurlencode($r['order']) . '&k=' . rawurlencode($r['key']))]);
}
json_out(['ok' => false, 'message' => $r['message'], 'recover' => !empty($r['recover'])], 400);
