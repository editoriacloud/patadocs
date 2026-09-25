<?php
/** PATADOCS — IndexNow key file (/{key}.txt is rewritten here). Answers only for the site's own key. */
define('PD_NO_SESSION', true);
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/indexnow.php';
if (setting('indexnow_enabled', '1') !== '1' || !hash_equals(indexnow_key(), (string)get_str('key', 64))) { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8');
echo indexnow_key();
