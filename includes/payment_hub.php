<?php
/**
 * PATADOCS — Payment Hub integration, orders and secure download tokens.
 *
 * PATADOCS never talks to Safaricom. Your Payment Hub does STK push, M-Pesa processing,
 * confirmation and reconciliation. PATADOCS only handles: orders, payment requests,
 * payment status, unlocking documents and download authorisation.
 *
 * The Hub contract (paths, auth header, signature header) is configurable in
 * Admin → Settings → Payment Hub. hub_normalize() is the ONE place that maps the Hub's
 * JSON field names to what PATADOCS needs — adapt it if your Hub uses other names.
 */

// ============================================================================
// HUB CLIENT
// ============================================================================

function hub_configured(): bool
{
    return setting('hub_url') !== '' && setting('hub_platform_id') !== '' && setting('hub_api_key') !== '';
}

/** Replaces {order_code} / {access_key} placeholders in the configured success/failure URLs. */
function hub_return_url(string $setting, array $order): string
{
    $tpl = trim((string)setting($setting));
    if ($tpl === '') { return url('payment-success.php?o=' . rawurlencode($order['order_code']) . '&k=' . rawurlencode($order['access_key'])); }
    return str_replace(['{order_code}', '{access_key}'], [rawurlencode($order['order_code']), rawurlencode($order['access_key'])], $tpl);
}

/** Low-level JSON request to the Hub. Returns ['ok','status','json','error','raw']. */
function hub_request(string $method, string $path, ?array $payload = null): array
{
    $base = rtrim((string)setting('hub_url'), '/');
    if ($base === '' || !preg_match('#^https?://#i', $base)) { return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'Payment Hub URL is not configured.', 'raw' => '']; }
    $url = $base . '/' . ltrim($path, '/');
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'X-Platform-Id: ' . setting('hub_platform_id'), 'User-Agent: PATADOCS/1.0'];
    $mode = setting('hub_auth_mode', 'bearer'); $key = setting('hub_api_key');
    if ($mode === 'bearer' || $mode === 'both') { $headers[] = 'Authorization: Bearer ' . $key; }
    if ($mode === 'x-api-key' || $mode === 'both') { $headers[] = 'X-API-Key: ' . $key; }
    $timeout = max(5, min(60, (int)setting('hub_timeout', 20)));
    $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) { log_error('Hub request failed: ' . $err); return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'Could not reach the Payment Hub.', 'raw' => '']; }
    } else {
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) { $code = (int)$m[1]; }
        if ($raw === false) { log_error('Hub request failed (stream)'); return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'Could not reach the Payment Hub.', 'raw' => '']; }
    }
    $json = json_decode((string)$raw, true);
    return ['ok' => $code >= 200 && $code < 300, 'status' => $code, 'json' => is_array($json) ? $json : null,
        'error' => ($code >= 200 && $code < 300) ? '' : 'Payment Hub returned HTTP ' . $code, 'raw' => mb_substr((string)$raw, 0, 2000)];
}

/**
 * Maps a Hub JSON response / webhook body to a standard shape:
 * state (success|failed|pending), reference (our order code), hub_reference, receipt, amount, currency, platform_id, message.
 * The "status" word is read from the innermost object so an API-level {"status":"success"} wrapper is never
 * mistaken for "payment success".
 */
function hub_normalize(array $j): array
{
    $scopes = [];
    $inner = $j;
    foreach (['data', 'payment', 'transaction', 'intent'] as $k) {
        if (isset($inner[$k]) && is_array($inner[$k]) && !isset($inner[$k][0])) { $scopes[] = $inner[$k]; $inner = $inner[$k]; }
    }
    $scopes = array_reverse($scopes);   // innermost first
    $scopes[] = $j;
    $hasWrapper = count($scopes) > 1;
    $pick = function (array $keys, bool $onlyInner = false) use ($scopes, $hasWrapper) {
        foreach ($scopes as $i => $s) {
            if ($onlyInner && $hasWrapper && $i === count($scopes) - 1) { continue; }
            foreach ($keys as $k) { if (isset($s[$k]) && !is_array($s[$k]) && !is_bool($s[$k]) && (string)$s[$k] !== '') { return (string)$s[$k]; } }
        }
        return '';
    };
    $status = strtolower($pick(['payment_status', 'transaction_status', 'state']) ?: $pick(['status', 'result'], true));
    if ($status === '') {                                   // webhook that only carries an event name, e.g. "payment.succeeded"
        $event = strtolower($pick(['event', 'event_type', 'type']));
        if (preg_match('/(succe|paid|complet|confirm)/', $event)) { $status = 'success'; }
        elseif (preg_match('/(fail|cancel|declin|expire|timeout)/', $event)) { $status = 'failed'; }
    }
    $ok = ['success', 'succeeded', 'paid', 'completed', 'complete', 'confirmed', 'settled', 'successful'];
    $bad = ['failed', 'failure', 'declined', 'cancelled', 'canceled', 'rejected', 'error', 'timeout', 'timed_out', 'expired', 'reversed'];
    $state = in_array($status, $ok, true) ? 'success' : (in_array($status, $bad, true) ? 'failed' : 'pending');
    $amount = $pick(['amount', 'paid_amount', 'amount_paid', 'total']);
    return [
        'state' => $state, 'raw_status' => $status,
        'reference' => $pick(['reference', 'external_reference', 'order_reference', 'merchant_reference', 'order_code', 'account_reference']),
        'hub_reference' => $pick(['hub_reference', 'payment_id', 'transaction_id', 'intent_id', 'checkout_request_id', 'uuid', 'id']),
        'receipt' => strtoupper($pick(['mpesa_receipt', 'mpesa_receipt_number', 'MpesaReceiptNumber', 'receipt', 'receipt_number', 'mpesa_code', 'transaction_code'])),
        'amount' => is_numeric($amount) ? (float)$amount : null,
        'currency' => strtoupper($pick(['currency'])),
        'platform_id' => $pick(['platform_id', 'app_id', 'application_id']),
        'message' => $pick(['message', 'result_desc', 'result_description', 'description', 'error', 'detail']),
    ];
}

/** Sends the STK-push request to the Hub. Never returns "success" here — only the status check / webhook can. */
function hub_initiate(array $order): array
{
    $payload = [
        'platform_id' => setting('hub_platform_id'), 'reference' => $order['order_code'], 'amount' => (float)$order['amount'],
        'currency' => $order['currency'], 'phone' => $order['phone'],
        'description' => 'PATADOCS: ' . mb_substr($order['item_title'], 0, 60),
        'customer_name' => $order['customer_name'] ?: null, 'customer_email' => $order['email'] ?: null,
        'callback_url' => url('ajax/webhook.php'),
        'success_url' => hub_return_url('hub_success_url', $order), 'failure_url' => hub_return_url('hub_failure_url', $order),
        'metadata' => ['order_code' => $order['order_code'], 'document_id' => $order['document_id'], 'collection_id' => $order['collection_id'], 'source' => 'patadocs'],
    ];
    $r = hub_request('POST', setting('hub_create_path', '/api/v1/payments'), $payload);
    $n = $r['json'] ? hub_normalize($r['json']) : ['state' => 'pending', 'hub_reference' => '', 'message' => ''];
    if (!$r['ok'] || $n['state'] === 'failed') {
        $msg = $n['message'] ?: 'The M-Pesa request could not be sent. Please try again.';
        log_error('Hub initiate failed for ' . $order['order_code'] . ': HTTP ' . $r['status'] . ' ' . $r['raw']);
        return ['ok' => false, 'message' => $msg, 'hub_reference' => '', 'raw' => $r['raw']];
    }
    return ['ok' => true, 'message' => $n['message'], 'hub_reference' => $n['hub_reference'], 'raw' => $r['raw']];
}

/** Asks the Hub for the real status of a payment (server-to-server verification). */
function hub_check(array $order, string $hubRef = ''): array
{
    $path = str_replace(['{reference}', '{order_code}'], [rawurlencode($hubRef !== '' ? $hubRef : $order['order_code']), rawurlencode($order['order_code'])], setting('hub_status_path', '/api/v1/payments/{reference}'));
    $r = hub_request('GET', $path);
    if (!$r['ok'] || !$r['json']) { return ['ok' => false, 'hub' => null, 'error' => $r['error'] ?: 'Invalid Hub response']; }
    return ['ok' => true, 'hub' => hub_normalize($r['json']), 'raw' => $r['raw']];
}

/** Verifies the HMAC signature (+ optional timestamp) of a webhook call. */
function hub_verify_signature(string $raw): bool
{
    $secret = (string)setting('hub_webhook_secret');
    if ($secret === '') { return false; }                                    // never accept unsigned webhooks
    $hdr = 'HTTP_' . strtoupper(str_replace('-', '_', setting('hub_signature_header', 'X-Hub-Signature')));
    $sig = strtolower(trim((string)($_SERVER[$hdr] ?? '')));
    if ($sig === '') { return false; }
    $sig = preg_replace('/^sha256=/', '', $sig);
    $ts = (string)($_SERVER['HTTP_X_HUB_TIMESTAMP'] ?? ($_SERVER['HTTP_X_TIMESTAMP'] ?? ''));
    if ($ts !== '' && ctype_digit($ts) && abs(time() - (int)$ts) > 300) { return false; }   // replay window
    if (hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) { return true; }
    return $ts !== '' && hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), $sig);
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

/** Sends the STK request and records the payment row. */
function order_start_payment(array $order): array
{
    $pid = db_insert("INSERT INTO payments (order_id, phone, amount, currency, method, status) VALUES (?, ?, ?, ?, 'mpesa_stk', 'initiated')", [$order['id'], $order['phone'], $order['amount'], $order['currency']]);
    $r = hub_initiate($order);
    if ($r['ok']) {
        db_exec("UPDATE payments SET status = 'pending', hub_reference = ?, response_json = ? WHERE id = ?", [$r['hub_reference'] ?: null, $r['raw'], $pid]);
        if ($r['hub_reference'] !== '') { db_exec('UPDATE orders SET hub_reference = ? WHERE id = ?', [$r['hub_reference'], $order['id']]); }
    } else {
        db_exec("UPDATE payments SET status = 'failed', result_desc = ?, response_json = ? WHERE id = ?", [mb_substr($r['message'], 0, 250), $r['raw'], $pid]);
    }
    return $r;
}

/**
 * Validates request + creates the order + asks the Hub for the STK push.
 * $type: 'doc' | 'collection'. Returns ['ok', 'message', 'order', 'key', ...].
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
    $pend = db_row("SELECT * FROM orders WHERE phone = ? AND status = 'pending' AND document_id <=> ? AND collection_id <=> ? AND created_at > (NOW() - INTERVAL 3 MINUTE) AND expires_at > NOW() ORDER BY id DESC LIMIT 1", [$phone, $docId, $colId]);
    if ($pend) { return ['ok' => true, 'order' => $pend['order_code'], 'key' => $pend['access_key'], 'message' => 'A payment request was already sent to your phone.', 'reused' => true]; }

    $last = $_SESSION['last_search'] ?? null;
    $searchId = ($last && time() - (int)$last['t'] < 7200) ? (int)$last['id'] : null;
    $order = order_create($item, $phone, $email, $name, $searchId);
    $r = order_start_payment($order);
    if (!$r['ok']) {
        db_exec("UPDATE orders SET status = 'failed' WHERE id = ?", [$order['id']]);
        return $fail($r['message'] ?: 'The M-Pesa request could not be sent. Please try again.');
    }
    return ['ok' => true, 'order' => $order['order_code'], 'key' => $order['access_key'], 'message' => 'Check your phone and enter your M-Pesa PIN.'];
}

/**
 * Marks an order PAID after the Hub confirmed it (webhook or status check). Idempotent and safe to call
 * from several processes at once: the order row is locked, amount/currency/reference/platform are validated,
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
        $plat = (string)setting('hub_platform_id');
        if ($hub['platform_id'] !== '' && $plat !== '' && strcasecmp($hub['platform_id'], $plat) !== 0) { return $reject('platform_mismatch'); }
        $receipt = $hub['receipt'] !== '' ? $hub['receipt'] : null;
        if ($receipt && db_val('SELECT id FROM orders WHERE mpesa_receipt = ? AND id <> ?', [$receipt, $orderId])) { return $reject('duplicate_receipt'); }

        db_exec("UPDATE orders SET status = 'paid', paid_at = NOW(), mpesa_receipt = ?, hub_reference = COALESCE(NULLIF(?, ''), hub_reference) WHERE id = ?", [$receipt, $hub['hub_reference'], $orderId]);
        db_exec("UPDATE payments SET status = 'success', mpesa_receipt = COALESCE(?, mpesa_receipt), hub_reference = COALESCE(NULLIF(?, ''), hub_reference), confirmed_at = NOW(),
                 result_desc = ?, webhook_status = IF(? = 'webhook', 'verified', webhook_status) WHERE order_id = ? ORDER BY id DESC LIMIT 1",
            [$receipt, $hub['hub_reference'], mb_substr($hub['message'] ?: 'Confirmed via ' . $source, 0, 250), $source, $orderId]);
        if ($o['document_id']) { db_exec('UPDATE documents SET purchase_count = purchase_count + 1, revenue = revenue + ? WHERE id = ?', [$o['amount'], $o['document_id']]); }
        elseif ($o['collection_id']) { db_exec('UPDATE documents SET purchase_count = purchase_count + 1 WHERE id IN (SELECT document_id FROM collection_documents WHERE collection_id = ?)', [$o['collection_id']]); }
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
    if (!in_array($order['status'], ['pending', 'expired'], true)) { return $order; }
    $pay = db_row('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$order['id']]);
    if ($pay && !$force && $pay['last_checked_at'] && time() - strtotime($pay['last_checked_at']) < 3) { return $order; }
    if ($pay) { db_exec('UPDATE payments SET last_checked_at = NOW() WHERE id = ?', [$pay['id']]); }
    if (hub_configured()) {
        $c = hub_check($order, (string)($pay['hub_reference'] ?? ''));
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
    $rows = db_all("SELECT t.token, d.title, d.file_ext, d.pages FROM download_tokens t JOIN documents d ON d.id = t.document_id
                    WHERE t.order_id = ? AND t.type = 'paid' AND t.status = 'active' AND t.expires_at > NOW() AND (t.max_downloads = 0 OR t.download_count < t.max_downloads) ORDER BY d.title", [$order['id']]);
    $out = [];
    foreach ($rows as $r) { $out[] = ['title' => $r['title'], 'format' => doc_ext_label($r['file_ext']) . ($r['pages'] ? ' · ' . pages_label($r['pages']) : ''), 'url' => download_url($r['token'])]; }
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
