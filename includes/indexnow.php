<?php
/**
 * PATADOCS — IndexNow (https://www.indexnow.org): tells Bing, Yandex, Seznam, Naver... the moment a document is
 * published, changed, unpublished or deleted, instead of waiting for the next crawl. Google does not use IndexNow;
 * it relies on the sitemap's <lastmod>, which PATADOCS keeps accurate.
 * The key is generated once and served at /{key}.txt (indexnow.php) so the engines can verify the site owns it.
 */

function indexnow_key(): string
{
    $k = (string)setting('indexnow_key');
    if (!preg_match('/^[a-f0-9]{32}$/', $k)) { $k = bin2hex(random_bytes(16)); set_setting('indexnow_key', $k); }
    return $k;
}

function indexnow_key_url(): string
{
    return setting('clean_urls', '1') === '1' ? url(indexnow_key() . '.txt') : url('indexnow.php?key=' . indexnow_key());
}

/** Submits changed URLs (once per URL per request). Never throws; the result is kept for Admin → SEO audit. */
function indexnow_ping(array $urls): void
{
    static $sent = [];
    if (setting('indexnow_enabled', '1') !== '1') { return; }
    $urls = array_values(array_diff(array_unique(array_filter($urls)), $sent));
    $host = (string)parse_url(url(''), PHP_URL_HOST);
    if (!$urls || $host === '' || preg_match('/^(localhost|127\.|10\.|192\.168\.)|\.(test|local)$/i', $host)) { return; }   // nothing to do / not a public site
    $sent = array_merge($sent, $urls);
    $body = json_encode(['host' => $host, 'key' => indexnow_key(), 'keyLocation' => indexnow_key_url(), 'urlList' => array_slice($urls, 0, 10000)], JSON_UNESCAPED_SLASHES);
    $code = 0; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8']]);
        curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json; charset=utf-8', 'content' => $body, 'timeout' => 4, 'ignore_errors' => true]]);
        @file_get_contents('https://api.indexnow.org/indexnow', false, $ctx);
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) { $code = (int)$m[1]; }
    }
    // 200 = accepted, 202 = accepted (key check pending). Anything else is logged.
    set_setting('indexnow_last', date('Y-m-d H:i') . ' · HTTP ' . $code . ' · ' . count($urls) . ' URL(s)' . ($err !== '' ? ' · ' . $err : ''));
    if ($code !== 200 && $code !== 202) { log_error('IndexNow submit failed: HTTP ' . $code . ' ' . $err); }
}

/** Current public URL(s) of a document row + its previous URL when the slug/category changed. */
function indexnow_doc(array $now, ?array $before = null): void
{
    $urls = [doc_url($now)];
    if ($before && !empty($before['slug'])) { $urls[] = doc_url($before); }
    indexnow_ping($urls);
}
