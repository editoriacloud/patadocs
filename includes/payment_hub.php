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
    $w = strtolower(trim(str_replace([' ', '-'], '_', $w)));
    $ok = ['success', 'succeeded', 'successful', 'paid', 'completed', 'complete', 'confirmed', 'reconciled', 'settled', 'received', 'matched', 'fulfilled', 'captured', 'overpaid'];
    $bad = ['failed', 'failure', 'declined', 'cancelled', 'canceled', 'rejected', 'error', 'timeout', 'timed_out', 'expired', 'reversed', 'void', 'voided'];
    if (in_array($w, $ok, true)) { return 'success'; }
    if (in_array($w, $bad, true)) { return 'failed'; }
    // Variants such as "paid_in_full", "payment_confirmed", "completed_successfully" — but never "unpaid", "not_paid", "partially_paid"
    if (!preg_match('/(^|_)(un|not|partial|partially|pending|awaiting|requires)/', $w) && preg_match('/(paid|succe|complet|confirm|reconcil|settle)/', $w)) { return 'success'; }
    if (preg_match('/(fail|cancel|declin|expire|revers|reject)/', $w)) { return 'failed'; }
    return 'pending';
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
    // The top level counts too — unless it is only an API envelope ({"status":"success","data":{…}}), whose status is
    // about the HTTP call, not the payment. (An invoice's own "status":"paid" sits at the top next to its payment_intent.)
    if (!isset($j['data']) || !is_array($j['data'])) { $inner[] = $j; }
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
        'description' => mb_substr(setting('site_name', 'PATADOCS') . ' ' . $order['order_code'] . ': ' . $order['item_title'], 0, 120),
    ];
    if (!empty($order['phone'])) { $payload['customer_phone'] = '0' . substr((string)$order['phone'], 3); }   // 2547XXXXXXXX → 07XXXXXXXX
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
    $invoiceId = (string)($inv['id'] ?? ($inv['invoice']['id'] ?? ''));
    // The invoice reference the Hub shows (and puts on receipts); falls back to its public id
    $ref = '';
    foreach (['reference', 'invoice_reference', 'invoice_number', 'number', 'ref'] as $k) { if (!empty($inv[$k]) && is_scalar($inv[$k])) { $ref = (string)$inv[$k]; break; } }
    return ['ok' => true, 'message' => '', 'intent_id' => $intentId, 'invoice_id' => $invoiceId, 'invoice_ref' => mb_substr($ref !== '' ? $ref : $invoiceId, 0, 100),
        'payment_url' => (string)($intent['payment_url'] ?? ''), 'raw' => $r['raw']];
}

/**
 * Asks the Hub, server-to-server, whether this order's invoice is paid:
 *   GET /invoices/{invoice id}                  — the invoice created for this order
 *   GET /payment-intents/{id}/status            — "server-authoritative payment status" (→ GET /payment-intents/{id} if unavailable)
 * Paid if either answer says paid (and belongs to this invoice / intent); failed only if nothing says paid.
 * Returns ['ok', 'hub' => hub_normalize() + raw_status "invoice: x · intent: y", 'error'].
 */
function hub_check(array $order): array
{
    $intentId = (string)($order['hub_reference'] ?? '');
    $invoiceId = (string)($order['hub_invoice_id'] ?? '');
    if ($intentId === '' && $invoiceId === '') { return ['ok' => false, 'hub' => null, 'error' => 'No Hub invoice for this order']; }
    $label = (string)($order['invoice_ref'] ?: $order['order_code']);
    $answers = []; $errors = [];
    if ($invoiceId !== '') {
        $r = hub_request('GET', '/invoices/' . rawurlencode($invoiceId), null, '', $label, 10);
        if ($r['ok'] && $r['json']) { $answers['invoice'] = hub_normalize($r['json']); } else { $errors[] = 'invoice: ' . ($r['error'] ?: 'no answer'); }
    }
    // The payment intent is asked too when the invoice is not paid yet — or is paid but its answer lacks the M-Pesa receipt
    // (kept on the order for "Recover purchase" and to stop a receipt being used twice).
    if ($intentId !== '' && (!isset($answers['invoice']) || $answers['invoice']['state'] !== 'success' || $answers['invoice']['receipt'] === '')) {
        $r = hub_request('GET', '/payment-intents/' . rawurlencode($intentId) . '/status', null, '', $label, 10);
        if (in_array($r['status'], [404, 405], true)) { $r = hub_request('GET', '/payment-intents/' . rawurlencode($intentId), null, '', $label, 10); }
        if ($r['ok'] && $r['json']) { $answers['intent'] = hub_normalize($r['json']); } else { $errors[] = 'payment intent: ' . ($r['error'] ?: 'no answer'); }
    }
    if (!$answers) { return ['ok' => false, 'hub' => null, 'error' => implode('; ', $errors)]; }
    $pick = null;
    foreach (['invoice', 'intent'] as $k) { if (isset($answers[$k]) && $answers[$k]['state'] === 'success') { $pick = $answers[$k]; break; } }
    if (!$pick) { foreach (['intent', 'invoice'] as $k) { if (isset($answers[$k])) { $pick = $answers[$k]; break; } } }
    if ($pick['receipt'] === '') { foreach ($answers as $a) { if ($a['receipt'] !== '') { $pick['receipt'] = $a['receipt']; break; } } }
    if ($pick['phone'] === '') { foreach ($answers as $a) { if ($a['phone'] !== '') { $pick['phone'] = $a['phone']; break; } } }
    $raw = []; foreach ($answers as $k => $a) { $raw[] = $k . ': ' . ($a['raw_status'] !== '' ? $a['raw_status'] : 'unknown'); }
    $pick['raw_status'] = implode(' · ', $raw);
    if ($pick['intent_id'] === '' && isset($answers['intent'])) { $pick['intent_id'] = $intentId; }
    if ($pick['invoice_id'] === '' && isset($answers['invoice'])) { $pick['invoice_id'] = $invoiceId; }
    // an invoice answer never carries a different invoice's id; an intent answer is about this intent
    if (isset($answers['invoice']) && $pick === $answers['invoice'] && $pick['invoice_id'] === '') { $pick['invoice_id'] = $invoiceId; }
    return ['ok' => true, 'hub' => $pick, 'error' => ''];
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
/** Order by the Hub's invoice reference (what the buyer sees) — or its invoice id. */
function order_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '' || strlen($ref) > 100 || !preg_match('/^[A-Za-z0-9._\-\/]+$/', $ref)) { return null; }
    return db_row('SELECT * FROM orders WHERE invoice_ref = ? OR hub_invoice_id = ? ORDER BY id DESC LIMIT 1', [$ref, $ref]);
}
/** The order a page / AJAX call is about: ?ref=INVOICE-REF (older links: ?o=DOC-…) — plus its secret key ?k=. */
function order_from_request(array $src): ?array
{
    $ref = trim((string)($src['ref'] ?? ''));
    $o = $ref !== '' ? order_by_ref($ref) : order_by_code(strtoupper(trim((string)($src['o'] ?? ''))));
    return $o && order_key_ok($o, (string)($src['k'] ?? '')) ? $o : null;
}
/** What identifies an order to people: the Hub invoice reference. */
function order_ref(array $o): string { return (string)($o['invoice_ref'] ?: ($o['hub_invoice_id'] ?: $o['order_code'])); }
/** Query string for the buyer's own order pages (invoice reference + secret key). */
function order_qs(array $o): string { return 'ref=' . rawurlencode(order_ref($o)) . '&k=' . rawurlencode((string)$o['access_key']); }
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
        db_exec('UPDATE orders SET hub_reference = ?, hub_invoice_id = NULLIF(?, \'\'), invoice_ref = NULLIF(?, \'\') WHERE id = ?', [$r['intent_id'], $r['invoice_id'], $r['invoice_ref'], $order['id']]);
    } else {
        db_exec("UPDATE payments SET status = 'failed', result_desc = ?, response_json = ? WHERE id = ?", [mb_substr($r['message'], 0, 250), $r['raw'], $pid]);
    }
    return $r;
}

/**
 * Buy button → order + Hub invoice (POST /invoices). The browser then opens the Hub's widget with the returned
 * payment intent id; the widget collects the phone number and runs STK / PayBill. Nothing is unlocked here — only the
 * Hub's signed webhook or GET /payment-intents/{id}/status can mark the order paid.
 * $type: 'doc' | 'collection'. Returns ['ok', 'message', 'order', 'key', 'token', 'pay_url', ...].
 */
function checkout_start(string $type, int $id): array
{
    $fail = function ($m) { return ['ok' => false, 'message' => $m]; };
    if (!rate_limit('pay:ip:' . client_ip(), 10, 600)) { return $fail('Too many payment attempts. Please wait a few minutes and try again.'); }
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

    // Same browser, same item, still unpaid → reopen that invoice instead of creating another one
    $slot = $item['type'] . ':' . $item['id'];
    if (!empty($_SESSION['pending_orders'][$slot]) && ($pend = order_by_code((string)$_SESSION['pending_orders'][$slot]))
        && $pend['status'] === 'pending' && $pend['hub_reference'] && strtotime((string)$pend['expires_at']) > time() && (float)$pend['amount'] === (float)$item['amount']) {
        return checkout_result($pend);
    }
    $last = $_SESSION['last_search'] ?? null;
    $searchId = ($last && time() - (int)$last['t'] < 7200) ? (int)$last['id'] : null;
    $order = order_create($item, '', '', '', $searchId);
    $r = order_start_payment($order);
    if (!$r['ok']) {
        db_exec("UPDATE orders SET status = 'failed' WHERE id = ?", [$order['id']]);
        return $fail($r['message'] ?: 'The payment could not be started. Please try again.');
    }
    if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION['pending_orders'][$slot] = $order['order_code']; }
    return checkout_result(order_get((int)$order['id']));
}

/** What the browser needs to open the Hub's widget for a pending order. */
function checkout_result(array $order): array
{
    return ['ok' => true, 'ref' => order_ref($order), 'key' => $order['access_key'], 'token' => (string)$order['hub_reference'],
        'pay_url' => order_hosted_payment_url($order), 'message' => ''];
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
            log_error('Payment rejected (' . $why . ') invoice=' . order_ref($o) . ' via ' . $source);
            $notes = ['invoice_mismatch' => 'The Hub confirmed a payment for a different invoice.', 'intent_mismatch' => 'The Hub confirmed a payment for a different payment request.',
                'amount_short' => 'The Hub reports less money paid than the price.', 'currency_mismatch' => 'The payment was in a different currency.', 'duplicate_receipt' => 'That M-Pesa receipt was already used for another purchase.'];
            db_exec('UPDATE orders SET hub_note = ? WHERE id = ?', [mb_substr('Payment received by the Hub but not accepted: ' . ($notes[$why] ?? $why) . ' Please contact us with invoice ' . order_ref($o) . '.', 0, 255), $o['id']]);
            db_exec("UPDATE payments SET result_desc = ?, webhook_status = IF(? = 'webhook', 'rejected', webhook_status) WHERE order_id = ? ORDER BY id DESC LIMIT 1", [mb_substr('Rejected: ' . $why, 0, 250), $source, $o['id']]);
            return ['ok' => false, 'result' => $why];
        };
        if ($hub['state'] !== 'success') { $pdo->rollBack(); return ['ok' => false, 'result' => 'not_success']; }
        // Identity: the answer must be about THIS order's Hub invoice / payment intent (our internal code is not required —
        // the invoice reference the Hub created is what identifies the purchase).
        $mine = array_map('strtoupper', array_filter([(string)$o['hub_invoice_id'], (string)$o['invoice_ref'], (string)$o['hub_reference'], (string)$o['order_code']]));
        if ($hub['invoice_id'] !== '' && !in_array(strtoupper($hub['invoice_id']), $mine, true)) { return $reject('invoice_mismatch'); }
        if ($hub['intent_id'] !== '' && (string)$o['hub_reference'] !== '' && $hub['intent_id'] !== (string)$o['hub_reference']) { return $reject('intent_mismatch'); }
        if ($hub['reference'] !== '' && !in_array(strtoupper($hub['reference']), $mine, true) && $hub['invoice_id'] === '' && $hub['intent_id'] === '') { return $reject('invoice_mismatch'); }
        // Money: at least the price (the Hub may report the invoice total rather than the amount paid — both are fine)
        if ($hub['amount'] !== null && $hub['amount'] > 0 && $hub['amount'] + 0.009 < (float)$o['amount']) { return $reject('amount_short'); }
        if ($hub['currency'] !== '' && strcasecmp($hub['currency'], $o['currency']) !== 0) { return $reject('currency_mismatch'); }
        $receipt = $hub['receipt'] !== '' ? $hub['receipt'] : null;
        if ($receipt && db_val('SELECT id FROM orders WHERE mpesa_receipt = ? AND id <> ?', [$receipt, $orderId])) { return $reject('duplicate_receipt'); }

        $payer = normalize_phone((string)($hub['phone'] ?? ''));        // the widget collected it; keep it for "Recover purchase"
        db_exec("UPDATE orders SET status = 'paid', paid_at = NOW(), mpesa_receipt = ?, phone = IF(phone = '' AND ? <> '', ?, phone), hub_note = NULL, hub_status = LEFT(COALESCE(NULLIF(?, ''), 'paid'), 60) WHERE id = ?", [$receipt, $payer, $payer, (string)($hub['raw_status'] ?? ''), $orderId]);
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
        db_exec('UPDATE orders SET hub_status = ? WHERE id = ?', [mb_substr($c['ok'] ? ($c['hub']['raw_status'] ?: 'pending') : 'unreachable', 0, 60), $order['id']]);
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
        'Invoice: ' . order_ref($o), 'Item: ' . $o['item_title'], 'Amount: ' . money($o['amount']), ''];
    $links = order_download_links($o);
    if ($links) { $lines[] = 'Download link' . (count($links) > 1 ? 's' : '') . ':'; foreach ($links as $l) { $lines[] = '- ' . $l['title'] . ': ' . $l['url']; } $lines[] = ''; }
    $lines[] = 'Lost your link? Use "Recover purchase": ' . page_url('recover') . ' with your invoice reference or M-Pesa code.';
    $lines[] = ''; $lines[] = setting('site_name', 'PATADOCS');
    send_mail($o['email'], 'Your ' . setting('site_name', 'PATADOCS') . ' purchase — invoice ' . order_ref($o), implode("\n", $lines));
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
