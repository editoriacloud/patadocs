<?php
/**
 * Editoria Payment Hub webhook — the source of truth for "paid".
 * Register it on the Hub's Webhooks page:  https://YOUR-DOMAIN/ajax/webhook.php
 *
 * Protections: POST only · HMAC-SHA256 signature over "{X-Editoria-Event-Id}.{X-Editoria-Timestamp}.{raw body}"
 * (Admin → Settings → Payment Hub → Webhook secret) · 5-minute timestamp window · one-time event id (replay
 * protection) · order/amount/currency/intent validation inside order_finalize() · idempotent (a duplicate call
 * can never create a second authorisation).
 */
define('PD_AJAX', true);
define('PD_NO_SESSION', true);
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['ok' => false, 'message' => 'POST only'], 405); }
$raw = (string)file_get_contents('php://input', false, null, 0, 65536);
if (!hub_verify_signature($raw)) {
    log_error('Webhook rejected: bad/missing signature from ' . client_ip());
    // Keep a trace (rate limited, truncated) so Admin → Payments shows "webhooks arrive but the secret does not match"
    if (rate_limit('wh-bad:' . client_ip(), 20, 600)) {
        $why = setting('hub_webhook_secret') === '' ? 'no_secret_set' : (empty($_SERVER['HTTP_X_EDITORIA_SIGNATURE']) ? 'unsigned' : (abs(time() - (int)($_SERVER['HTTP_X_EDITORIA_TIMESTAMP'] ?? 0)) > 300 ? 'stale_timestamp' : 'bad_signature'));
        try { db_insert('INSERT INTO webhook_events (event_id, order_code, signature_ok, payload, ip, result) VALUES (?, NULL, 0, ?, ?, ?)', ['rejected-' . bin2hex(random_bytes(8)), mb_substr($raw, 0, 4000), client_ip(), $why]); } catch (Throwable $e) { }
    }
    json_out(['ok' => false, 'message' => 'Invalid signature'], 401);
}
$j = json_decode($raw, true);
if (!is_array($j)) { json_out(['ok' => false, 'message' => 'Invalid JSON'], 400); }

$hub = hub_normalize($j);
$eventType = strtolower((string)($j['event_type'] ?? ''));
$eventId = mb_substr((string)$_SERVER['HTTP_X_EDITORIA_EVENT_ID'], 0, 120);
try {
    db_insert('INSERT INTO webhook_events (event_id, order_code, signature_ok, payload, ip, result) VALUES (?, ?, 1, ?, ?, ?)',
        [$eventId, ($hub['invoice_id'] ?: $hub['reference']) !== '' ? substr($hub['invoice_id'] ?: $hub['reference'], 0, 100) : null, $raw, client_ip(), 'received']);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') { json_out(['ok' => true, 'message' => 'Duplicate event ignored']); }   // replay / Hub retry
    throw $e;
}
$setResult = function ($r) use ($eventId) { db_exec('UPDATE webhook_events SET result = ? WHERE event_id = ?', [mb_substr($r, 0, 60), $eventId]); };

// Find the order by the Hub's invoice ($event['invoice_id'] — the reference the Hub created), then the payment intent,
// then the external_invoice_id we sent.
$order = null;
foreach (array_filter([$hub['invoice_id'], $hub['reference']]) as $ref) {
    $order = order_by_ref($ref) ?: db_row('SELECT o.* FROM orders o JOIN payments p ON p.order_id = o.id WHERE p.hub_reference = ? ORDER BY p.id DESC LIMIT 1', [$ref]);
    if ($order) { break; }
}
if (!$order && $hub['intent_id'] !== '') { $order = db_row('SELECT * FROM orders WHERE hub_reference = ? LIMIT 1', [$hub['intent_id']]); }
if (!$order && $hub['reference'] !== '') { $order = order_by_code(strtoupper($hub['reference'])); }
if (!$order) { $setResult('order_not_found'); json_out(['ok' => true, 'message' => 'Unknown order — ignored']); }

db_exec("UPDATE payments SET webhook_status = 'received' WHERE order_id = ? AND webhook_status = 'none' ORDER BY id DESC LIMIT 1", [$order['id']]);

if ($eventType === 'payment.confirmed' || $hub['state'] === 'success') {
    // The Hub documents its signed webhook as the source of truth for "paid". Its status endpoint is asked first
    // only for the normalised amount + receipt; if it is unreachable or still lags behind the event, the signed
    // event itself is used (order_finalize still checks order code, amount, currency, intent and receipt).
    $c = hub_check($order);
    $source = $c['ok'] && $c['hub']['state'] === 'success' ? $c['hub'] : array_merge($hub, ['state' => 'success']);
    if ($c['ok'] && $c['hub']['state'] !== 'success') { log_error('Webhook ' . $eventId . ' says paid but the Hub status says "' . $c['hub']['raw_status'] . '" for invoice ' . order_ref($order) . ' — trusting the signed webhook.'); }
    $r = order_finalize((int)$order['id'], $source, 'webhook');
    $setResult($r['result']);
    json_out(['ok' => true, 'result' => $r['result']]);
}
if ($hub['state'] === 'failed' && $order['status'] === 'pending') {
    db_exec("UPDATE orders SET status = 'failed' WHERE id = ? AND status = 'pending'", [$order['id']]);
    db_exec("UPDATE payments SET status = 'failed', webhook_status = 'verified', result_desc = ? WHERE order_id = ? ORDER BY id DESC LIMIT 1", [mb_substr($hub['message'] ?: 'Payment failed or cancelled', 0, 250), $order['id']]);
    $setResult('failed');
    json_out(['ok' => true, 'result' => 'failed']);
}
$setResult('ignored:' . ($eventType ?: 'unknown'));
json_out(['ok' => true, 'result' => 'no_change']);
