<?php
/**
 * PATADOCS — ads.txt (/ads.txt is rewritten here). Lists the publishers allowed to sell ads on this site
 * (Admin → Ads & Google). ads.txt must be at the ROOT of the domain: when PATADOCS lives in a sub-folder, copy this
 * output into the domain's own ads.txt (the Ads & Google page shows it ready to paste).
 */
define('PD_NO_SESSION', true);
require __DIR__ . '/includes/init.php';
$txt = ads_txt();
if ($txt === '') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "# No advertising publisher configured.\n"; exit; }
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo $txt;
