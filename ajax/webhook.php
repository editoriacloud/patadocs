<?php
/**
 * Payment Hub webhook. Protections: POST only · HMAC signature (Admin → Settings → Payment Hub → Webhook Secret)
 * · timestamp replay window · one-time event id (replay protection) · order/amount/currency/platform validation
 * inside order_finalize() · idempotent (a duplicate call can never create a second authorisation).
 * Point the Hub's callback to:  https://YOUR-DOMAIN/ajax/webhook.php
 */
define('PD_AJAX', true);
define('PD_NO_SESSION', true);
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['ok' => false, 'message' => 'POST only'], 405); }
$raw = (string)file_get_contents('php://input', false, null, 0, 65536);
if (!hub_verify_signature($raw)) {
    log_error('Webhook rejected: bad/missing signature from ' . client_ip());
    json_out(['ok' => false, 'message' => 'Invalid signature'], 401);
}
$j = json_decode($raw, true);
if (!is_array($j)) { json_out(['ok' => false, 'message' => 'Invalid JSON'], 400); }

$hub = hub_normalize($j);
$eventId = (string)($j['event_id'] ?? ($j['data']['event_id'] ?? ($j['id'] ?? '')));
if ($eventId === '' || strlen($eventId) > 120) { $eventId = hash('sha256', $raw); }
try {
    db_insert('INSERT INTO webhook_events (event_id, order_code, signature_ok, payload, ip, result) VALUES (?, ?, 1, ?, ?, ?)',
        [$eventId, $hub['reference'] !== '' ? substr($hub['reference'], 0, 20) : null, $raw, client_ip(), 'received']);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { json_out(['ok' => true, 'message' => 'Duplicate event ignored']); }   // replay
    throw $e;
}
$setResult = function ($r) use ($eventId) { db_exec('UPDATE webhook_events SET result = ? WHERE event_id = ?', [mb_substr($r, 0, 60), $eventId]); };

$order = $hub['reference'] !== '' ? order_by_code(strtoupper($hub['reference'])) : null;
if (!$order && $hub['hub_reference'] !== '') { $order = db_row('SELECT * FROM orders WHERE hub_reference = ? LIMIT 1', [$hub['hub_reference']]); }
if (!$order) { $setResult('order_not_found'); json_out(['ok' => true, 'message' => 'Unknown order — ignored']); }

db_exec("UPDATE payments SET webhook_status = 'received' WHERE order_id = ? AND webhook_status = 'none' ORDER BY id DESC LIMIT 1", [$order['id']]);
if ($hub['state'] === 'success') {
    $r = order_finalize((int)$order['id'], $hub, 'webhook');
    $setResult($r['result']);
    json_out(['ok' => true, 'result' => $r['result']]);
}
if ($hub['state'] === 'failed' && $order['status'] === 'pending') {
    db_exec("UPDATE orders SET status = 'failed' WHERE id = ? AND status = 'pending'", [$order['id']]);
    db_exec("UPDATE payments SET status = 'failed', webhook_status = 'verified', result_desc = ? WHERE order_id = ? ORDER BY id DESC LIMIT 1", [mb_substr($hub['message'] ?: 'Payment failed or cancelled', 0, 250), $order['id']]);
    $setResult('failed');
    json_out(['ok' => true, 'result' => 'failed']);
}
$setResult('pending_ignored');
json_out(['ok' => true, 'result' => 'no_change']);
