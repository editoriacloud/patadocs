<?php
/**
 * AJAX: order status. The browser is never trusted — for a pending order this asks the Payment Hub
 * (server-to-server, rate limited) and only reports "paid" after order_finalize() validated everything.
 * Needs the order code AND the order's access key (known only to the browser that created it).
 */
define('PD_AJAX', true);
define('PD_NO_SESSION', true);
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';

$order = order_from_request($_GET);
if (!$order) { json_out(['ok' => false, 'status' => 'unknown', 'message' => 'Invoice not found.'], 404); }
$order = order_refresh($order);
switch ($order['status']) {
    case 'paid':
        json_out(['ok' => true, 'status' => 'paid', 'ref' => order_ref($order), 'receipt' => $order['mpesa_receipt'], 'downloads' => order_download_links($order)]);
    case 'failed':   json_out(['ok' => true, 'status' => 'failed', 'message' => 'The payment was cancelled or failed.']);
    case 'expired':  json_out(['ok' => true, 'status' => 'expired', 'message' => 'The payment request expired.']);
    case 'refunded': json_out(['ok' => true, 'status' => 'refunded', 'message' => 'This order was refunded.']);
    default:         json_out(['ok' => true, 'status' => 'pending', 'hub_status' => (string)$order['hub_status'], 'note' => (string)$order['hub_note']]);
}
