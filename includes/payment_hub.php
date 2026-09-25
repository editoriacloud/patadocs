<?php
/**
 * PATADOCS — Editoria Payment Hub integration, orders and secure download tokens.
 *
 * PATADOCS never talks to Safaricom. The Hub (https://payments.editoriaweb.co.ke) does it all:
 *   1. PATADOCS (server) creates an invoice:   POST /api/v1/invoices  → payment_intent.id
 *   2. The browser opens the Hub's modal:      EditoriaPay.open({ token: payment_intent.id, ... })
 *   3. The customer pays by STK or PayBill; the Hub reconciles it and calls our signed webhook
 *      (ajax/webhook.php, event "payment.confirmed").
 *   4. Only the webhook or GET /api/v1/payment-intents/{id}/status (server-to-server) can mark an
 *      order paid — the browser's onSuccess callback just tells us to go and check.
 *
 * Credentials (client id/secret, webhook secret) live in Admin → Settings → Payment Hub and never
 * reach the browser.
 */

// ============================================================================
// HUB CLIENT
// ============================================================================

const HUB_API_PREFIX = '/api/v1';

/** Hub origin, e.g. https://payments.editoriaweb.co.ke (no trailing slash, no /api/v1). */
function hub_base(): string
{
    $u = rtrim(trim((string)setting('hub_url')), '/');
    $u = preg_replace('#/api/v1$#i', '', $u);                 // tolerate an API-base URL pasted by mistake
    return preg_match('#^https?://[^\s/]+#i', $u) ? $u : '';
}

function hub_configured(): bool
{
    return hub_base() !== '' && setting('hub_client_id') !== '' && setting('hub_client_secret') !== '';
}

/** The Hub's payment modal script. It finds the Hub from its own src, so it must be loaded from the Hub. */
function hub_widget_url(): string { return hub_base() !== '' ? hub_base() . '/pay/widget.js' : ''; }

/**
 * One HTTP call. Returns ['status' => int (0 = network error), 'raw' => string, 'json' => ?array, 'error' => string, 'ms' => int].
 * The cURL handle is reused for the whole request, so the second and later calls to the Hub skip the TCP + TLS
 * handshake (invoice → STK prompt → status checks happen back to back).
 */
function hub_http(string $method, string $url, array $headers, ?string $body, ?int $timeout = null): array
{
    static $ch = null;
    $timeout = $timeout ?? max(5, min(60, (int)setting('hub_timeout', 20)));
    $headers = array_merge(['Accept: application/json', 'User-Agent: PATADOCS/1.2', 'Expect:'], $headers);
    if ($body !== null) { $headers[] = 'Content-Type: application/json'; }
    $t0 = microtime(true);
    if (function_exists('curl_init')) {
        if ($ch === null) { $ch = curl_init(); } else { curl_reset($ch); }
        curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_ENCODING => '']);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($raw === false) { $code = 0; $raw = ''; }
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true, 'follow_location' => 0]]);
        $raw = @file_get_contents($url, false, $ctx); $err = $raw === false ? 'stream request failed' : '';
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) { $code = (int)$m[1]; }
        if ($raw === false) { $raw = ''; }
    }
    $ms = (int)round((microtime(true) - $t0) * 1000);
    $json = json_decode((string)$raw, true);
    $error = $code === 0 ? 'Could not reach the Payment Hub' . ($err !== '' ? ' (' . $err . ')' : '') . '.' : (($code >= 200 && $code < 300) ? '' : 'Payment Hub returned HTTP ' . $code);
    if ($code === 0) { log_error('Hub ' . $method . ' ' . $url . ' failed: ' . $err); }
    return ['status' => $code, 'raw' => mb_substr((string)$raw, 0, 4000), 'json' => is_array($json) ? $json : null, 'error' => $error, 'ms' => $ms];
}

/** Records one Hub call for Admin → Payments → Hub API log (tokens and phone numbers masked). Never throws. */
function hub_log(string $method, string $path, array $r, string $orderCode = ''): void
{
    try {
        $resp = preg_replace('/("(?:access_token|token|client_secret|refresh_token)"\s*:\s*")[^"]+/i', '$1***', (string)$r['raw']);
        $resp = preg_replace('/\b(254|0)(7|1)(\d{5})(\d{3})\b/', '$1$2*****$4', $resp);
        db_exec('INSERT INTO hub_log (method, path, status, duration_ms, order_code, error, response) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$method, mb_substr($path, 0, 190), (int)$r['status'], (int)($r['ms'] ?? 0), $orderCode !== '' ? $orderCode : null,
             $r['error'] !== '' ? mb_substr($r['error'], 0, 255) : null, mb_substr($resp, 0, 2000)]);
    } catch (Throwable $e) { /* table not created yet: logging is best-effort */ }
}

/**
 * Bearer token from POST /auth/token (client_id + client_secret). Tokens last one hour, so they are
 * cached in the settings table and renewed a minute before they expire (or right away after a 401).
 */
function hub_token(bool $renew = false): string
{
    $cached = (string)setting('hub_token');
    if (!$renew && $cached !== '' && (int)setting('hub_token_expires', 0) > time() + 60) { return $cached; }

    $r = hub_http('POST', hub_base() . HUB_API_PREFIX . '/auth/token', [], json_encode([
        'client_id' => (string)setting('hub_client_id'), 'client_secret' => (string)setting('hub_client_secret'), 'grant_type' => 'client_credentials',
    ]), 15);
    hub_log('POST', '/auth/token', $r);
    $j = $r['json'] ?? [];
    $d = isset($j['data']) && is_array($j['data']) ? $j['data'] : $j;
    $token = (string)($d['access_token'] ?? ($d['token'] ?? ''));
    if ($r['status'] < 200 || $r['status'] >= 300 || $token === '') {
        log_error('Hub auth/token failed: HTTP ' . $r['status'] . ' ' . $r['raw']);
        return '';
    }
    $ttl = (int)($d['expires_in'] ?? 3600);
    if (!empty($d['expires_at']) && ($ts = strtotime((string)$d['expires_at'])) !== false) { $ttl = $ts - time(); }
    set_setting('hub_token', $token);
    set_setting('hub_token_expires', (string)(time() + max(120, $ttl)));
    return $token;
}

/** Forgets the cached bearer token (after the credentials change). */
function hub_token_forget(): void { set_setting('hub_token', ''); set_setting('hub_token_expires', '0'); }

/**
 * Authenticated JSON request to /api/v1{$path}. Returns ['ok','status','json','error','raw','ms'].
 * $idempotencyKey makes a retried POST return the original result instead of creating a duplicate.
 * $orderCode only labels the call in the Hub API log.
 */
function hub_request(string $method, string $path, ?array $payload = null, string $idempotencyKey = '', string $orderCode = '', ?int $timeout = null): array
{
    if (!hub_configured()) { return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'The Payment Hub is not configured.', 'raw' => '', 'ms' => 0]; }
    $url = hub_base() . HUB_API_PREFIX . '/' . ltrim($path, '/');
    $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = hub_token($attempt > 0);
        if ($token === '') { return ['ok' => false, 'status' => 401, 'json' => null, 'error' => 'The Payment Hub rejected the client ID / secret.', 'raw' => '', 'ms' => 0]; }
        $headers = ['Authorization: Bearer ' . $token];
        if ($idempotencyKey !== '') { $headers[] = 'Idempotency-Key: ' . $idempotencyKey; }
        $r = hub_http($method, $url, $headers, $body, $timeout);
        hub_log($method, '/' . ltrim($path, '/'), $r, $orderCode);
        if ($r['status'] !== 401) { break; }                  // 401 → token expired early or was revoked: renew once
    }
    return ['ok' => $r['status'] >= 200 && $r['status'] < 300] + $r;
}

/** Status words the Hub (or M-Pesa) may use. Anything else counts as "still pending". */
function hub_status_word(string $w): string
{
    $w = strtolower(trim($w));
    $ok = ['success', 'succeeded', 'successful', 'paid', 'completed', 'complete', 'confirmed', 'reconciled', 'settled', 'received', 'matched', 'fulfilled', 'captured', 'overpaid'];
    $bad = ['failed', 'failure', 'declined', 'cancelled', 'canceled', 'rejected', 'error', 'timeout', 'timed_out', 'expired', 'reversed', 'void', 'voided'];
    return in_array($w, $ok, true) ? 'success' : (in_array($w, $bad, true) ? 'failed' : 'pending');
}

/**
 * Maps a Hub JSON response / webhook body / transaction to one shape:
 * state (success|failed|pending), reference (our order code = external_invoice_id), intent_id, invoice_id, receipt,
 * amount, currency, phone, ids (every identifier found — for matching a transaction to an order), message.
 * Payment objects are searched at any depth (data / payment_intent / invoice / payment / transaction). A status on the
 * outer API wrapper only counts when there is no inner payment object, so {"status":"success","data":{...}} is never
 * mistaken for "paid". Any inner object that says paid wins (an invoice can be paid while an old STK attempt failed).
 */
function hub_normalize(array $j): array
{
    $objs = [];                                              // [depth, array]
    $walk = function (array $a, int $d) use (&$walk, &$objs) {
        foreach (['data', 'payment_intent', 'intent', 'invoice', 'payment', 'transaction'] as $k) {
            if (isset($a[$k]) && is_array($a[$k]) && !isset($a[$k][0]) && $d < 4) { $objs[] = [$d + 1, $a[$k]]; $walk($a[$k], $d + 1); }
        }
    };
    $walk($j, 0);
    usort($objs, function ($x, $y) { return $y[0] <=> $x[0]; });   // innermost first
    $scopes = array_column($objs, 1);
    $inner = $scopes;                                        // scopes that may carry a payment status
    $scopes[] = $j;
    if (!$inner) { $inner = [$j]; }
    $pick = function (array $keys, ?array $in = null) use ($scopes) {
        foreach ($in ?? $scopes as $s) {
            foreach ($keys as $k) { if (isset($s[$k]) && !is_array($s[$k]) && !is_bool($s[$k]) && (string)$s[$k] !== '') { return (string)$s[$k]; } }
        }
        return '';
    };
    $states = []; $raw = '';
    foreach ($inner as $s) {
        foreach (['payment_status', 'transaction_status', 'status', 'state', 'result'] as $k) {
            if (isset($s[$k]) && is_string($s[$k]) && $s[$k] !== '') { $states[] = hub_status_word($s[$k]); $raw = $raw ?: strtolower($s[$k]); break; }
        }
        foreach (['paid', 'is_paid'] as $k) { if (isset($s[$k]) && $s[$k] === true) { $states[] = 'success'; $raw = $raw ?: $k; } }
    }
    if (!$states) {                                         // webhook that only carries an event name, e.g. "payment.confirmed"
        $event = strtolower($pick(['event_type', 'event', 'type']));
        if (preg_match('/(confirm|succe|paid|complet|reconcil)/', $event)) { $states[] = 'success'; }
        elseif (preg_match('/(fail|cancel|declin|expire|timeout)/', $event)) { $states[] = 'failed'; }
        $raw = $event;
    }
    $state = in_array('success', $states, true) ? 'success' : (in_array('failed', $states, true) ? 'failed' : 'pending');
    $amount = str_replace([',', ' '], '', $pick(['amount_paid', 'paid_amount', 'TransAmount', 'trans_amount', 'amount']));
    $ids = [];
    foreach ($scopes as $s) {
        foreach (['external_invoice_id', 'invoice_id', 'payment_intent_id', 'intent_id', 'account_reference', 'bill_ref_number', 'BillRefNumber', 'reference'] as $k) {
            if (isset($s[$k]) && is_scalar($s[$k]) && (string)$s[$k] !== '') { $ids[] = strtoupper(trim((string)$s[$k])); }
        }
    }
    if (isset($j['payment_intent']['id']) && is_scalar($j['payment_intent']['id'])) { $ids[] = strtoupper((string)$j['payment_intent']['id']); }
    if (isset($j['invoice']['id']) && is_scalar($j['invoice']['id'])) { $ids[] = strtoupper((string)$j['invoice']['id']); }
    return [
        'state' => $state, 'raw_status' => $raw,
        'reference' => $pick(['external_invoice_id']),
        'intent_id' => $pick(['payment_intent_id']) ?: (isset($j['payment_intent']['id']) && is_scalar($j['payment_intent']['id']) ? (string)$j['payment_intent']['id'] : ''),
        'invoice_id' => $pick(['invoice_id']) ?: (isset($j['invoice']['id']) && is_scalar($j['invoice']['id']) ? (string)$j['invoice']['id'] : ''),
        'receipt' => strtoupper($pick(['mpesa_receipt', 'mpesa_receipt_number', 'MpesaReceiptNumber', 'receipt', 'receipt_number', 'mpesa_code', 'trans_id', 'TransID', 'transaction_code'])),
        'amount' => is_numeric($amount) ? (float)$amount : null,
        'currency' => strtoupper($pick(['currency'])),
        'phone' => preg_replace('/\D+/', '', $pick(['msisdn', 'MSISDN', 'phone', 'phone_number', 'customer_phone', 'sender_phone'])),
        'time' => $pick(['paid_at', 'trans_time', 'TransTime', 'transaction_date', 'created_at']),
        'ids' => array_values(array_unique($ids)),
        'message' => $pick(['message', 'result_desc', 'description', 'error', 'detail']),
    ];
}

/**
 * Creates (or, for a retry, fetches) the Hub invoice for an order. The order code is both the
 * external_invoice_id and the Idempotency-Key, so a double click can never bill twice.
 * Returns ['ok', 'message', 'intent_id', 'invoice_id', 'payment_url', 'raw'].
 */
function hub_create_invoice(array $order): array
{
    $payload = [
        'external_invoice_id' => $order['order_code'],
        'amount' => (float)$order['amount'],
        'customer_phone' => '0' . substr((string)$order['phone'], 3),            // 2547XXXXXXXX → 07XXXXXXXX
        'description' => mb_substr(setting('site_name', 'PATADOCS') . ' ' . $order['order_code'] . ': ' . $order['item_title'], 0, 120),
    ];
    if (!empty($order['customer_name'])) { $payload['customer_name'] = $order['customer_name']; }
    $r = hub_request('POST', '/invoices', $payload, $order['order_code'], $order['order_code']);
    $j = $r['json'] ?? [];
    $inv = isset($j['data']) && is_array($j['data']) ? $j['data'] : $j;
    $intent = isset($inv['payment_intent']) && is_array($inv['payment_intent']) ? $inv['payment_intent'] : [];
    $intentId = (string)($intent['id'] ?? '');
    if (!$r['ok'] || $intentId === '') {
        log_error('Hub invoice failed for ' . $order['order_code'] . ': HTTP ' . $r['status'] . ' ' . $r['raw']);
        $msg = is_string($j['message'] ?? null) && $r['status'] >= 400 && $r['status'] < 500 ? $j['message'] : 'The payment could not be started. Please try again.';
        return ['ok' => false, 'message' => $msg, 'intent_id' => '', 'invoice_id' => '', 'payment_url' => '', 'raw' => $r['raw']];
    }
    return ['ok' => true, 'message' => '', 'intent_id' => $intentId, 'invoice_id' => (string)($inv['id'] ?? ($inv['invoice']['id'] ?? '')),
        'payment_url' => (string)($intent['payment_url'] ?? ''), 'raw' => $r['raw']];
}

/**
 * Asks the Hub for the authoritative status of an order's payment intent (server-to-server):
 * GET /payment-intents/{id}/status, falling back to GET /payment-intents/{id} if the status route is unavailable.
 */
function hub_check(array $order): array
{
    $intentId = (string)($order['hub_reference'] ?? '');
    if ($intentId === '') { return ['ok' => false, 'hub' => null, 'error' => 'No payment intent for this order']; }
    $r = hub_request('GET', '/payment-intents/' . rawurlencode($intentId) . '/status', null, '', (string)$order['order_code'], 10);
    if (in_array($r['status'], [404, 405], true)) { $r = hub_request('GET', '/payment-intents/' . rawurlencode($intentId), null, '', (string)$order['order_code'], 10); }
    if (!$r['ok'] || !$r['json']) { return ['ok' => false, 'hub' => null, 'error' => $r['error'] ?: 'Invalid Hub response']; }
    $hub = hub_normalize($r['json']);
    if ($hub['intent_id'] === '') { $hub['intent_id'] = $intentId; }
    return ['ok' => true, 'hub' => $hub, 'raw' => $r['raw']];
}

/** M-Pesa number in 2547XXXXXXXX form. */
function hub_msisdn(string $phone): string { $p = preg_replace('/\D+/', '', $phone); return strlen($p) === 10 && $p[0] === '0' ? '254' . substr($p, 1) : $p; }

/**
 * Sends the M-Pesa STK prompt for an order straight from the server (POST /payment-intents/{id}/stk), so the
 * customer's phone rings the moment they press Pay — no second form in the payment window.
 * Returns ['ok', 'message'].
 */
function hub_stk(array $order): array
{
    $intentId = (string)($order['hub_reference'] ?? '');
    if ($intentId === '') { return ['ok' => false, 'message' => 'No payment request to send.']; }
    $msisdn = hub_msisdn((string)$order['phone']);
    $n = (int)$order['stk_count'] + 1;
    $r = hub_request('POST', '/payment-intents/' . rawurlencode($intentId) . '/stk', ['phone' => $msisdn, 'phone_number' => $msisdn],
        $order['order_code'] . '-stk-' . $n, (string)$order['order_code'], 25);
    db_exec('UPDATE orders SET stk_count = LEAST(255, stk_count + 1), stk_sent_at = NOW() WHERE id = ?', [$order['id']]);
    $j = $r['json'] ?? [];
    $hubMsg = '';
    foreach ([$j, $j['data'] ?? []] as $x) { if (is_array($x)) { foreach (['customer_message', 'CustomerMessage', 'message'] as $k) { if (!empty($x[$k]) && is_string($x[$k])) { $hubMsg = $x[$k]; break 2; } } } }
    if (!$r['ok']) {
        log_error('STK push failed for ' . $order['order_code'] . ': HTTP ' . $r['status'] . ' ' . $r['raw']);
        return ['ok' => false, 'message' => $r['status'] >= 400 && $r['status'] < 500 && $hubMsg !== '' ? $hubMsg : 'We could not send the M-Pesa prompt. You can pay by PayBill instead, or try again.'];
    }
    return ['ok' => true, 'message' => 'Check your phone and enter your M-Pesa PIN.'];
}

/**
 * Looks up an M-Pesa receipt at the Hub. POST /payments/verify first asks the Hub to re-check M-Pesa / FlexPay for
 * that receipt (safe to repeat), then GET /transactions/{receipt} returns it if it belongs to this application.
 * Returns ['found' => bool, 'tx' => hub_normalize() + 'raw' JSON, 'error' => string].
 */
function hub_find_receipt(string $receipt, string $orderCode = ''): array
{
    hub_request('POST', '/payments/verify', ['receipt' => $receipt, 'mpesa_receipt' => $receipt], 'verify-' . $receipt . '-' . date('YmdHi'), $orderCode, 15);
    $r = hub_request('GET', '/transactions/' . rawurlencode($receipt), null, '', $orderCode, 10);
    if ($r['status'] === 404) { return ['found' => false, 'tx' => null, 'error' => '']; }
    if (!$r['ok'] || !$r['json']) { return ['found' => false, 'tx' => null, 'error' => $r['error'] ?: 'Invalid Hub response']; }
    $tx = hub_normalize($r['json']);
    if ($tx['receipt'] === '') { $tx['receipt'] = $receipt; }
    return ['found' => strcasecmp($tx['receipt'], $receipt) === 0, 'tx' => $tx, 'error' => ''];
}

/**
 * Verifies a webhook: signature = HMAC-SHA256("{event id}.{timestamp}.{raw body}", webhook secret),
 * sent as "X-Editoria-Signature: sha256=<hex>", and the timestamp must be within 5 minutes.
 */
function hub_verify_signature(string $raw): bool
{
    $secret = (string)setting('hub_webhook_secret');
    if ($secret === '') { return false; }                                    // never accept unsigned webhooks
    $eventId = (string)($_SERVER['HTTP_X_EDITORIA_EVENT_ID'] ?? '');
    $ts = (string)($_SERVER['HTTP_X_EDITORIA_TIMESTAMP'] ?? '');
    $sig = trim((string)($_SERVER['HTTP_X_EDITORIA_SIGNATURE'] ?? ''));
    if ($eventId === '' || !ctype_digit($ts) || strncmp($sig, 'sha256=', 7) !== 0) { return false; }
    if (abs(time() - (int)$ts) > 300) { return false; }                     // replay window
    return hash_equals(hash_hmac('sha256', $eventId . '.' . $ts . '.' . $raw, $secret), strtolower(substr($sig, 7)));
}

// ============================================================================
// ORDERS
// ============================================================================

function order_get(int $id): ?array { return db_row('SELECT * FROM orders WHERE id = ?', [$id]); }
function order_by_code(string $code): ?array
{
    return preg_match('/^DOC-[A-Z0-9]{8}$/', $code) ? db_row('SELECT * FROM orders WHERE order_code = ?', [$code]) : null;
}
/** Proof-of-possession check: the access key is only known to the browser that created the order. */
function order_key_ok(array $order, string $key): bool { return $key !== '' && hash_equals((string)$order['access_key'], $key); }

function order_create(array $item, string $phone, string $email, string $name, ?int $searchLogId): array
{
    $minutes = max(5, (int)setting('order_expiry_minutes', 15));
    for ($i = 0; $i < 6; $i++) {
        $code = order_code_gen(); $key = secure_token(16);
        try {
            $id = db_insert('INSERT INTO orders (order_code, access_key, document_id, collection_id, item_title, amount, currency, phone, email, customer_name, status, search_log_id, ip, expires_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, (NOW() + INTERVAL ' . (int)$minutes . ' MINUTE))',
                [$code, $key, $item['type'] === 'doc' ? $item['id'] : null, $item['type'] === 'collection' ? $item['id'] : null,
                 mb_substr($item['title'], 0, 255), $item['amount'], $item['currency'], $phone, $email ?: null, $name ?: null, $searchLogId, client_ip()]);
            return order_get($id);
        } catch (PDOException $e) { if ($e->getCode() !== '23000') { throw $e; } }   // duplicate order code → try again
    }
    throw new RuntimeException('Could not allocate an order code');
}

/**
 * Creates the Hub invoice for an order and records the payment row.
 * orders.hub_reference = the payment intent id (what the browser modal and the status check use);
 * payments.hub_reference = the Hub invoice id (what webhooks may refer to).
 */
function order_start_payment(array $order): array
{
    $pid = db_insert("INSERT INTO payments (order_id, phone, amount, currency, method, status) VALUES (?, ?, ?, ?, 'editoria_pay', 'initiated')", [$order['id'], $order['phone'], $order['amount'], $order['currency']]);
    $r = hub_create_invoice($order);
    if ($r['ok']) {
        db_exec("UPDATE payments SET status = 'pending', hub_reference = ?, response_json = ? WHERE id = ?", [$r['invoice_id'] ?: null, $r['raw'], $pid]);
        db_exec('UPDATE orders SET hub_reference = ? WHERE id = ?', [$r['intent_id'], $order['id']]);
    } else {
        db_exec("UPDATE payments SET status = 'failed', result_desc = ?, response_json = ? WHERE id = ?", [mb_substr($r['message'], 0, 250), $r['raw'], $pid]);
    }
    return $r;
}

/**
 * Validates the request, creates the order and its Hub invoice.
 * $type: 'doc' | 'collection'. Returns ['ok', 'message', 'order', 'key', 'token', 'pay_url', ...] where
 * token is the payment intent id the browser passes to EditoriaPay.open().
 */
function checkout_start(string $type, int $id, string $phoneRaw, string $email = '', string $name = ''): array
{
    $fail = function ($m, $extra = []) { return array_merge(['ok' => false, 'message' => $m], $extra); };
    if (!rate_limit('pay:ip:' . client_ip(), 8, 600)) { return $fail('Too many payment attempts. Please wait a few minutes and try again.'); }
    $phone = normalize_phone($phoneRaw);
    if ($phone === '') { return $fail('Enter a valid Safaricom M-Pesa number, e.g. 0712 345 678.'); }
    if (!rate_limit('pay:phone:' . $phone, 4, 600)) { return $fail('Too many attempts for this number. Please wait a few minutes.'); }
    $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? mb_substr(trim($email), 0, 190) : '';
    $name = mb_substr(trim(strip_tags($name)), 0, 120);

    if ($type === 'collection') {
        $c = db_row("SELECT * FROM collections WHERE id = ? AND status = 'published'", [$id]);
        if (!$c) { return $fail('This bundle is not available.'); }
        if ((int)$c['is_free'] === 1 || (float)$c['price'] <= 0) { return $fail('This bundle is free — no payment is needed.'); }
        $item = ['type' => 'collection', 'id' => (int)$c['id'], 'title' => $c['title'], 'amount' => (float)$c['price'], 'currency' => setting('currency', 'KES')];
    } else {
        $d = doc_get($id);
        if (!$d || $d['status'] !== 'published' || empty($d['file_name'])) { return $fail('This document is not available.'); }
        if ((int)$d['is_free'] === 1 || (float)$d['price'] <= 0) { return $fail('This document is free — no payment is needed.'); }
        $item = ['type' => 'doc', 'id' => (int)$d['id'], 'title' => $d['title'], 'amount' => (float)$d['price'], 'currency' => $d['currency'] ?: setting('currency', 'KES')];
    }
    if (!hub_configured()) { log_error('Checkout attempted but the Payment Hub is not configured'); return $fail('Online payments are not available right now. Please try again later.'); }

    $docId = $item['type'] === 'doc' ? $item['id'] : null; $colId = $item['type'] === 'collection' ? $item['id'] : null;
    // Duplicate-payment protection
    if (db_val("SELECT id FROM orders WHERE phone = ? AND status = 'paid' AND document_id <=> ? AND collection_id <=> ? LIMIT 1", [$phone, $docId, $colId])) {
        return $fail('You have already purchased this on this number. Use "Recover purchase" with your Order ID to download it again.', ['code' => 'already_paid', 'recover' => true]);
    }
    // Same customer, same item, still unpaid → reuse that order and its invoice instead of billing twice
    $pend = db_row("SELECT * FROM orders WHERE phone = ? AND status = 'pending' AND document_id <=> ? AND collection_id <=> ? AND hub_reference IS NOT NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1", [$phone, $docId, $colId]);
    if ($pend) {                                             // pressed Pay again: ring the phone again (unless a prompt just went out)
        $stk = null;
        if (setting('hub_stk_direct', '1') === '1') {
            $stk = $pend['stk_sent_at'] && time() - strtotime($pend['stk_sent_at']) < 20 ? ['ok' => true, 'message' => 'A prompt was just sent to your phone — enter your M-Pesa PIN.'] : order_resend_stk($pend);
        }
        return checkout_result(order_get((int)$pend['id']), true, $stk);
    }

    $last = $_SESSION['last_search'] ?? null;
    $searchId = ($last && time() - (int)$last['t'] < 7200) ? (int)$last['id'] : null;
    $order = order_create($item, $phone, $email, $name, $searchId);
    $r = order_start_payment($order);
    if (!$r['ok']) {
        db_exec("UPDATE orders SET status = 'failed' WHERE id = ?", [$order['id']]);
        return $fail($r['message'] ?: 'The payment could not be started. Please try again.');
    }
    $order = order_get((int)$order['id']);
    $stk = setting('hub_stk_direct', '1') === '1' ? hub_stk($order) : null;   // ring the phone right away
    return checkout_result(order_get((int)$order['id']), false, $stk);
}

/**
 * What the browser needs for a pending order. stk: true = the prompt was sent to the phone, false = sending failed
 * (the browser opens the Hub's payment window instead), null = direct STK is off.
 */
function checkout_result(array $order, bool $reused, ?array $stk = null): array
{
    return ['ok' => true, 'order' => $order['order_code'], 'key' => $order['access_key'], 'token' => (string)$order['hub_reference'],
        'pay_url' => order_hosted_payment_url($order), 'reused' => $reused, 'amount' => money($order['amount']),
        'phone' => '0' . substr((string)$order['phone'], 3, 3) . ' *** ' . substr((string)$order['phone'], -3),
        'stk' => $stk === null ? null : $stk['ok'], 'message' => $stk ? $stk['message'] : 'Complete the payment in the secure M-Pesa window.'];
}

/** Sends the STK prompt again (max 4 per order, 20 s apart). Returns ['ok', 'message']. */
function order_resend_stk(array $order): array
{
    if ($order['status'] === 'paid') { return ['ok' => true, 'message' => 'This order is already paid.']; }
    if (!in_array($order['status'], ['pending', 'failed', 'expired'], true) || empty($order['hub_reference'])) { return ['ok' => false, 'message' => 'This order can no longer be paid — please start again.']; }
    if ((int)$order['stk_count'] >= 4) { return ['ok' => false, 'message' => 'Too many prompts for this order. Pay by PayBill, or start a new order.']; }
    if ($order['stk_sent_at'] && time() - strtotime($order['stk_sent_at']) < 20) { return ['ok' => false, 'message' => 'A prompt was just sent — please wait a few seconds for it to arrive.']; }
    if (!rate_limit('stk:phone:' . $order['phone'], 6, 600)) { return ['ok' => false, 'message' => 'Too many prompts to this number. Please wait a few minutes.']; }
    if ($order['status'] !== 'pending') {                    // a cancelled prompt marked it failed: reopen it for 15 minutes
        db_exec("UPDATE orders SET status = 'pending', expires_at = GREATEST(COALESCE(expires_at, NOW()), NOW() + INTERVAL 15 MINUTE) WHERE id = ? AND status IN ('failed','expired')", [$order['id']]);
        $order = order_get((int)$order['id']);
    }
    return hub_stk($order);
}

/**
 * "I already paid — here is my M-Pesa code": the server asks the Hub about that receipt and unlocks the order only if
 * the money really paid for THIS order. Anti-theft rules:
 *  - only the browser that created the order can call this (order code + secret access key, checked by the caller);
 *  - a receipt can unlock one order, ever (unique column + check inside order_finalize);
 *  - the transaction must reference this order (order code / payment intent / Hub invoice), OR — for a PayBill
 *    payment typed with a wrong account number — come from the same phone number as the order, not be linked to
 *    any other invoice, and be made after the order was created;
 *  - amount paid ≥ price; attempts are rate limited per order and per IP.
 * Returns ['ok', 'message'].
 */
function order_claim_receipt(array $order, string $receipt): array
{
    $receipt = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $receipt));
    if ($order['status'] === 'paid') { return ['ok' => true, 'message' => 'This order is already paid.']; }
    if ($order['status'] === 'refunded') { return ['ok' => false, 'message' => 'This order was refunded.']; }
    if (!preg_match('/^[A-Z0-9]{8,12}$/', $receipt)) { return ['ok' => false, 'message' => 'Enter the 10-character M-Pesa code from your confirmation SMS, e.g. TXA1B2C3D4.']; }
    if (!rate_limit('claim:order:' . $order['id'], 6, 900) || !rate_limit('claim:ip:' . client_ip(), 15, 900)) { return ['ok' => false, 'message' => 'Too many attempts. Please wait a few minutes and try again.']; }
    if (db_val('SELECT id FROM orders WHERE mpesa_receipt = ? AND id <> ?', [$receipt, $order['id']])) { return ['ok' => false, 'message' => 'That M-Pesa code has already been used for another order.']; }
    $f = hub_find_receipt($receipt, (string)$order['order_code']);
    if ($f['error'] !== '') { return ['ok' => false, 'message' => 'We could not reach the payment system just now. Please try again in a moment.']; }
    if (!$f['found']) { return ['ok' => false, 'message' => 'We could not find that M-Pesa code yet. If you just paid, wait a minute and try again.']; }
    $tx = $f['tx'];
    if ($tx['state'] === 'failed') { return ['ok' => false, 'message' => 'That M-Pesa payment did not go through.']; }
    $mine = array_map('strtoupper', array_filter([(string)$order['order_code'], (string)$order['hub_reference'],
        (string)db_val("SELECT hub_reference FROM payments WHERE order_id = ? AND hub_reference IS NOT NULL ORDER BY id DESC LIMIT 1", [$order['id']])]));
    $linked = (bool)array_intersect($tx['ids'], $mine);
    if (!$linked) {
        $samePhone = $tx['phone'] !== '' && substr($tx['phone'], -9) === substr(hub_msisdn((string)$order['phone']), -9);
        $otherInvoice = false;
        foreach ($tx['ids'] as $id) { if (preg_match('/^DOC-[A-Z0-9]{8}$/', $id) || db_val("SELECT id FROM orders WHERE hub_reference = ? AND id <> ?", [$id, $order['id']])) { $otherInvoice = true; } }
        $after = $tx['time'] === '' || !strtotime($tx['time']) || strtotime($tx['time']) >= strtotime($order['created_at']) - 300;
        if (!$samePhone || $otherInvoice || !$after) {
            log_error('Receipt claim refused for ' . $order['order_code'] . ': ' . $receipt . ' is not linked to this order (phone match: ' . ($samePhone ? 'yes' : 'no') . ', other invoice: ' . ($otherInvoice ? 'yes' : 'no') . ')');
            return ['ok' => false, 'message' => 'That M-Pesa payment is not for this order. Use the code from the payment you made for this document, from the number ' . checkout_result($order, false)['phone'] . '.'];
        }
    }
    if ($tx['amount'] === null || $tx['amount'] + 0.009 < (float)$order['amount']) { return ['ok' => false, 'message' => 'That payment (' . ($tx['amount'] === null ? 'unknown amount' : money($tx['amount'])) . ') is less than the price of ' . money($order['amount']) . '.']; }
    $r = order_finalize((int)$order['id'], ['state' => 'success', 'reference' => '', 'intent_id' => '', 'invoice_id' => $tx['invoice_id'], 'receipt' => $receipt,
        'amount' => (float)$order['amount'], 'currency' => $tx['currency'], 'message' => 'Confirmed from M-Pesa code ' . $receipt], 'receipt');
    return $r['ok'] ? ['ok' => true, 'message' => 'Payment confirmed.'] : ['ok' => false, 'message' => $r['result'] === 'duplicate_receipt' ? 'That M-Pesa code has already been used for another order.' : 'We could not confirm that payment. Please contact us with your Order ID.'];
}

/** The Hub's hosted payment page for an order (no-JavaScript fallback), taken from the invoice response. */
function order_hosted_payment_url(array $order): string
{
    $raw = db_val("SELECT response_json FROM payments WHERE order_id = ? AND method = 'editoria_pay' AND response_json IS NOT NULL ORDER BY id DESC LIMIT 1", [$order['id']]);
    $j = $raw ? json_decode((string)$raw, true) : null;
    if (!is_array($j)) { return ''; }
    $inv = isset($j['data']) && is_array($j['data']) ? $j['data'] : $j;
    $u = (string)($inv['payment_intent']['payment_url'] ?? '');
    return preg_match('#^https://#i', $u) ? $u : '';
}

/**
 * Marks an order PAID after the Hub confirmed it (webhook or status check). Idempotent and safe to call
 * from several processes at once: the order row is locked, amount/currency/reference/payment intent are validated,
 * a receipt can only ever belong to one order, and download tokens are issued exactly once.
 * $hub is a hub_normalize() array. Returns ['ok', 'result', 'order'].
 */
function order_finalize(int $orderId, array $hub, string $source): array
{
    $pdo = db(); $pdo->beginTransaction();
    try {
        $o = db_row('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$o) { $pdo->rollBack(); return ['ok' => false, 'result' => 'not_found']; }
        if ($o['status'] === 'paid') { $pdo->commit(); return ['ok' => true, 'result' => 'already_paid', 'order' => $o]; }
        if ($o['status'] === 'refunded') { $pdo->rollBack(); return ['ok' => false, 'result' => 'refunded']; }
        $reject = function ($why) use ($pdo, $o, $source) {
            $pdo->rollBack();
            log_error('Payment rejected (' . $why . ') order=' . $o['order_code'] . ' via ' . $source);
            db_exec("UPDATE payments SET result_desc = ?, webhook_status = IF(? = 'webhook', 'rejected', webhook_status) WHERE order_id = ? ORDER BY id DESC LIMIT 1", [mb_substr('Rejected: ' . $why, 0, 250), $source, $o['id']]);
            return ['ok' => false, 'result' => $why];
        };
        if ($hub['state'] !== 'success') { $pdo->rollBack(); return ['ok' => false, 'result' => 'not_success']; }
        if ($hub['reference'] !== '' && strcasecmp($hub['reference'], $o['order_code']) !== 0) { return $reject('reference_mismatch'); }
        if ($hub['amount'] !== null && abs($hub['amount'] - (float)$o['amount']) > 0.009) { return $reject('amount_mismatch'); }
        if ($hub['currency'] !== '' && strcasecmp($hub['currency'], $o['currency']) !== 0) { return $reject('currency_mismatch'); }
        if ($hub['intent_id'] !== '' && (string)$o['hub_reference'] !== '' && $hub['intent_id'] !== (string)$o['hub_reference']) { return $reject('intent_mismatch'); }
        $receipt = $hub['receipt'] !== '' ? $hub['receipt'] : null;
        if ($receipt && db_val('SELECT id FROM orders WHERE mpesa_receipt = ? AND id <> ?', [$receipt, $orderId])) { return $reject('duplicate_receipt'); }

        db_exec("UPDATE orders SET status = 'paid', paid_at = NOW(), mpesa_receipt = ? WHERE id = ?", [$receipt, $orderId]);
        db_exec("UPDATE payments SET status = 'success', mpesa_receipt = COALESCE(?, mpesa_receipt), hub_reference = COALESCE(hub_reference, NULLIF(?, '')), confirmed_at = NOW(),
                 result_desc = ?, webhook_status = IF(? = 'webhook', 'verified', webhook_status) WHERE order_id = ? ORDER BY id DESC LIMIT 1",
            [$receipt, $hub['invoice_id'], mb_substr($hub['message'] ?: 'Confirmed via ' . $source, 0, 250), $source, $orderId]);
        if ($o['document_id']) { db_exec('UPDATE documents SET purchase_count = purchase_count + 1, revenue = revenue + ?, updated_at = updated_at WHERE id = ?', [$o['amount'], $o['document_id']]); }
        elseif ($o['collection_id']) { db_exec('UPDATE documents SET purchase_count = purchase_count + 1, updated_at = updated_at WHERE id IN (SELECT document_id FROM collection_documents WHERE collection_id = ?)', [$o['collection_id']]); }
        $o = order_get($orderId);
        tokens_issue($o);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        log_error('order_finalize failed: ' . $e->getMessage());
        return ['ok' => false, 'result' => 'error'];
    }
    order_notify($o);
    return ['ok' => true, 'result' => 'paid', 'order' => $o];
}

/**
 * Server-side status refresh for a pending order: asks the Hub (rate limited), finalises, fails or expires it.
 * Safe to call on every poll. Returns the fresh order row.
 */
function order_refresh(array $order, bool $force = false): array
{
    // 'failed' is re-checked too: a cancelled STK prompt can still be followed by a PayBill payment on the same invoice
    if (!in_array($order['status'], ['pending', 'expired', 'failed'], true)) { return $order; }
    $pay = db_row('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$order['id']]);
    if ($pay && !$force && $pay['last_checked_at'] && time() - strtotime($pay['last_checked_at']) < 3) { return $order; }
    if ($pay) { db_exec('UPDATE payments SET last_checked_at = NOW() WHERE id = ?', [$pay['id']]); }
    if (hub_configured()) {
        $c = hub_check($order);
        if ($c['ok']) {
            if ($c['hub']['state'] === 'success') { $r = order_finalize((int)$order['id'], $c['hub'], 'status_check'); return $r['order'] ?? order_get((int)$order['id']); }
            if ($c['hub']['state'] === 'failed' && $order['status'] === 'pending') {
                db_exec("UPDATE orders SET status = 'failed' WHERE id = ? AND status = 'pending'", [$order['id']]);
                if ($pay) { db_exec("UPDATE payments SET status = 'failed', result_desc = ? WHERE id = ?", [mb_substr($c['hub']['message'] ?: 'Payment failed or was cancelled', 0, 250), $pay['id']]); }
                return order_get((int)$order['id']);
            }
        }
    }
    if ($order['status'] === 'pending' && $order['expires_at'] && strtotime($order['expires_at']) < time()) {
        db_exec("UPDATE orders SET status = 'expired' WHERE id = ? AND status = 'pending'", [$order['id']]);
        if ($pay) { db_exec("UPDATE payments SET status = 'timeout' WHERE id = ? AND status IN ('initiated','pending')", [$pay['id']]); }
        return order_get((int)$order['id']);
    }
    return $order;
}

/** Documents unlocked by an order (one for a document order, several for a bundle). */
function order_documents(array $order): array
{
    if ($order['document_id']) { $d = doc_get((int)$order['document_id']); return $d ? [$d] : []; }
    if ($order['collection_id']) {
        return db_all('SELECT d.*, c.path AS category_path, c.name AS category_name FROM collection_documents cd JOIN documents d ON d.id = cd.document_id
                       LEFT JOIN categories c ON c.id = d.category_id WHERE cd.collection_id = ? ORDER BY cd.sort_order, d.title', [$order['collection_id']]);
    }
    return [];
}

function order_notify(array $o): void
{
    if (empty($o['email'])) { return; }
    $lines = ["Hello" . ($o['customer_name'] ? ' ' . $o['customer_name'] : '') . ",", '', 'Thank you! Your payment was received.', '',
        'Order ID: ' . $o['order_code'], 'Item: ' . $o['item_title'], 'Amount: ' . money($o['amount']), ''];
    $links = order_download_links($o);
    if ($links) { $lines[] = 'Download link' . (count($links) > 1 ? 's' : '') . ':'; foreach ($links as $l) { $lines[] = '- ' . $l['title'] . ': ' . $l['url']; } $lines[] = ''; }
    $lines[] = 'Lost your link? Use "Recover purchase": ' . page_url('recover') . ' with your Order ID and phone number.';
    $lines[] = ''; $lines[] = setting('site_name', 'PATADOCS');
    send_mail($o['email'], 'Your ' . setting('site_name', 'PATADOCS') . ' order ' . $o['order_code'], implode("\n", $lines));
}

// ============================================================================
// SECURE DOWNLOAD TOKENS
// ============================================================================

function download_url(string $token): string { return url('download.php?token=' . $token); }

/** Creates a token (64 hex chars from random_bytes). $max = 0 means unlimited downloads until expiry. */
function token_create(int $docId, ?int $orderId, string $type, int $minutes, int $max): array
{
    $token = secure_token(32);
    $id = db_insert('INSERT INTO download_tokens (token, document_id, order_id, type, status, max_downloads, expires_at, created_ip)
                     VALUES (?, ?, ?, ?, \'active\', ?, (NOW() + INTERVAL ' . max(1, (int)$minutes) . ' MINUTE), ?)', [$token, $docId, $orderId, $type, max(0, $max), client_ip()]);
    return db_row('SELECT * FROM download_tokens WHERE id = ?', [$id]);
}

/** Issues paid tokens for every document in a PAID order — exactly once (idempotent). Call inside a transaction. */
function tokens_issue(array $order): void
{
    if ($order['status'] !== 'paid') { return; }
    foreach (order_documents($order) as $d) {
        if (db_val("SELECT id FROM download_tokens WHERE order_id = ? AND document_id = ? AND type = 'paid' LIMIT 1", [$order['id'], $d['id']])) { continue; }
        $max = $d['download_limit'] !== null ? (int)$d['download_limit'] : (int)setting('default_download_limit', 3);
        token_create((int)$d['id'], (int)$order['id'], 'paid', max(1, (int)setting('download_token_hours', 48)) * 60, $max);
    }
}

/** Makes sure tokens exist (locking the order row) and returns usable download links for a paid order. */
function order_download_links(array $order): array
{
    if ($order['status'] !== 'paid') { return []; }
    $pdo = db(); $pdo->beginTransaction();
    try { db_row('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$order['id']]); tokens_issue($order); $pdo->commit(); }
    catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } log_error('tokens: ' . $e->getMessage()); }
    $rows = db_all("SELECT t.token, t.download_count, d.title, d.file_ext, d.pages FROM download_tokens t JOIN documents d ON d.id = t.document_id
                    WHERE t.order_id = ? AND t.type = 'paid' AND t.status = 'active' AND t.expires_at > NOW() AND (t.max_downloads = 0 OR t.download_count < t.max_downloads) ORDER BY d.title", [$order['id']]);
    $out = [];
    foreach ($rows as $r) { $out[] = ['title' => $r['title'], 'format' => doc_ext_label($r['file_ext']) . ($r['pages'] ? ' · ' . pages_label($r['pages']) : ''), 'url' => download_url($r['token']), 'fresh' => (int)$r['download_count'] === 0]; }
    return $out;
}

/**
 * Validates a token for delivery. Returns ['ok' => true, 'token' => row, 'doc' => row]
 * or ['ok' => false, 'reason' => invalid|expired|revoked|exhausted|refunded|unavailable].
 */
function token_check(string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return ['ok' => false, 'reason' => 'invalid']; }
    $t = db_row('SELECT * FROM download_tokens WHERE token = ?', [$token]);
    if (!$t) { return ['ok' => false, 'reason' => 'invalid']; }
    if ($t['status'] === 'revoked') { return ['ok' => false, 'reason' => 'revoked']; }
    if ($t['status'] === 'expired' || strtotime($t['expires_at']) < time()) {
        if ($t['status'] === 'active') { db_exec("UPDATE download_tokens SET status = 'expired' WHERE id = ?", [$t['id']]); }
        return ['ok' => false, 'reason' => 'expired'];
    }
    if ((int)$t['max_downloads'] > 0 && (int)$t['download_count'] >= (int)$t['max_downloads']) { return ['ok' => false, 'reason' => 'exhausted'];  }
    $doc = doc_get((int)$t['document_id']);
    if (!$doc || empty($doc['file_name']) || !is_file(doc_private_path($doc))) { return ['ok' => false, 'reason' => 'unavailable']; }
    if ($t['type'] === 'paid') {
        $o = $t['order_id'] ? order_get((int)$t['order_id']) : null;
        if (!$o || $o['status'] !== 'paid') { return ['ok' => false, 'reason' => $o && $o['status'] === 'refunded' ? 'refunded' : 'invalid']; }
    } elseif ($doc['status'] !== 'published') { return ['ok' => false, 'reason' => 'unavailable']; }
    return ['ok' => true, 'token' => $t, 'doc' => $doc];
}

/** Atomically consumes one download. Returns false if a parallel request used the last one. */
function token_consume(int $tokenId): bool
{
    return db_exec("UPDATE download_tokens SET download_count = download_count + 1, first_download_at = COALESCE(first_download_at, NOW()), last_download_at = NOW()
                    WHERE id = ? AND status = 'active' AND expires_at > NOW() AND (max_downloads = 0 OR download_count < max_downloads)", [$tokenId]) === 1;
}

function download_fail_message(string $reason): array
{
    switch ($reason) {
        case 'expired':   return ['Download link expired', 'This download link has expired. If you bought this document, use "Recover purchase" to get a new link.'];
        case 'exhausted': return ['Download limit reached', 'This link has reached its download limit. If you bought this document, use "Recover purchase" to get a fresh link.'];
        case 'revoked':   return ['Link no longer valid', 'This download link was cancelled. Please contact us if you think this is a mistake.'];
        case 'refunded':  return ['Order refunded', 'This order was refunded, so the download is no longer available.'];
        case 'unavailable': return ['File unavailable', 'This document is temporarily unavailable. Please try again later or contact us.'];
        default:          return ['Invalid download link', 'This download link is not valid. Please check the link or use "Recover purchase".'];
    }
}
