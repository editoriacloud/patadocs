<?php
/** AJAX: start a payment (creates the order and asks the Payment Hub to send the M-Pesa STK push). */
define('PD_AJAX', true);
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';

if (!is_post()) { json_out(['ok' => false, 'message' => 'Invalid request.'], 405); }
csrf_check(true);
$type = post_str('type', 12) === 'collection' ? 'collection' : 'doc';
$r = checkout_start($type, post_int('id'), post_str('phone', 20), post_str('email', 190), post_str('name', 120));
if ($r['ok']) { json_out(['ok' => true, 'order' => $r['order'], 'key' => $r['key'], 'message' => $r['message']]); }
json_out(['ok' => false, 'message' => $r['message'], 'recover' => !empty($r['recover'])], 400);
