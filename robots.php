<?php
/**
 * PATADOCS — dynamic robots.txt (/robots.txt is rewritten here by .htaccess).
 * Paths are generated from BASE_URL, so they are right whether the site lives at the domain root or in a folder.
 * Crawlers only read robots.txt at the ROOT of a domain: when PATADOCS runs in a sub-folder (e.g. /patadocs),
 * copy this output into the domain's own robots.txt (Admin → SEO audit shows it ready to paste).
 * Pages that must stay out of the index (payment, download, recover, search results...) are NOT blocked here on
 * purpose — they send noindex, which crawlers can only see if they are allowed to fetch the page.
 */
define('PD_NO_SESSION', true);
require __DIR__ . '/includes/init.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo robots_txt();
