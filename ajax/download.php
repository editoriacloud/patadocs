<?php
/** AJAX: prepare a FREE download → returns a short-lived secure token URL (same rules as download.php). */
define('PD_AJAX', true);
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';

if (!is_post()) { json_out(['ok' => false, 'message' => 'Invalid request.'], 405); }
csrf_check(true);
if (!rate_limit('free:' . client_ip(), 40, 3600)) { json_out(['ok' => false, 'message' => 'Too many downloads from your connection. Please try again later.'], 429); }
$doc = doc_get(post_int('doc'));
if (!$doc || $doc['status'] !== 'published' || empty($doc['file_name'])) { json_out(['ok' => false, 'message' => 'This document is not available.'], 404); }
if (!((int)$doc['is_free'] === 1 || (float)$doc['price'] <= 0)) { json_out(['ok' => false, 'message' => 'This document is not free. Please use Buy & Download.'], 403); }
$t = token_create((int)$doc['id'], null, 'free', max(1, (int)setting('free_token_minutes', 30)), max(1, (int)setting('free_token_max', 3)));
json_out(['ok' => true, 'url' => download_url($t['token'])]);
