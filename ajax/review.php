<?php
/** Verified-buyer review: needs the paid order's code + secret access key (only the buyer has them). Works with or without JS. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/payment_hub.php';
require_once __DIR__ . '/../includes/reviews.php';

if (!is_post()) { respond(false, 'Invalid request.', [], 405); }
csrf_check();
$order = order_from_request($_POST);
if (!$order) { respond(false, 'We could not find that order.', [], 404); }
$r = review_submit($order, post_int('doc'), post_int('rating'), post_str('name', 120), post_str('comment', 2000));
respond($r['ok'], $r['message']);
