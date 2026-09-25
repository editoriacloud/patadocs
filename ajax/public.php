<?php
/**
 * Small public AJAX/form actions:
 *   request       – document request (POST)        report – report a document (POST)
 *   contact       – contact message (POST)         preview_view – count a preview view (POST)
 *   meta_fields   – metadata inputs for a category (GET, used by the contribute form)
 */
require __DIR__ . '/../includes/init.php';

$action = post_str('action', 20) ?: get_str('action', 20);

if ($action === 'meta_fields') {
    $cid = get_int('cat'); $cat = $cid ? cat_get($cid) : null;
    json_out(['ok' => true, 'html' => $cat ? meta_fields_html(meta_fields_for($cid)) : '']);
}
if (!is_post()) { json_out(['ok' => false, 'message' => 'Invalid request.'], 405); }
csrf_check();

switch ($action) {
    case 'preview_view':
        $id = post_int('doc');
        if ($id && !is_bot() && empty($_SESSION['pviewed'][$id]) && db_val("SELECT id FROM documents WHERE id = ? AND status = 'published'", [$id])) {
            $_SESSION['pviewed'][$id] = 1;
            db_exec('UPDATE documents SET preview_count = preview_count + 1, updated_at = updated_at WHERE id = ?', [$id]);
            stat_bump($id, 'preview_views');
        }
        json_out(['ok' => true]);

    case 'request':
        if (setting('requests_enabled', '1') !== '1') { respond(false, 'Requests are temporarily closed.'); }
        if (post_str('hp', 50) !== '') { respond(true, 'Request submitted! We will let you know when it is available.'); }
        if (!rate_limit('req:' . client_ip(), 6, 3600)) { respond(false, 'You have sent several requests recently. Please try again later.', [], 429); }
        $title = post_str('title', 200);
        if (mb_strlen($title) < 3) { respond(false, 'Please tell us which document you are looking for.'); }
        $phone = post_str('phone', 20); $email = post_str('email', 190);
        if ($phone !== '' && normalize_phone($phone) === '') { respond(false, 'The phone number looks invalid (use 07XX XXX XXX or leave it empty).'); }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { respond(false, 'The email address looks invalid.'); }
        $cid = post_int('category'); $catId = ($cid && cat_get($cid)) ? $cid : null;
        db_insert('INSERT INTO document_requests (title, norm_title, category_id, description, phone, email, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$title, mb_substr(search_norm($title), 0, 190), $catId, post_str('description', 1000) ?: null, $phone ?: null, $email ?: null, client_ip()]);
        respond(true, ($phone !== '' || $email !== '') ? 'Request submitted! We will contact you when it is available.' : 'Request submitted! We will add it to our list of documents to find.');

    case 'report':
        $doc = doc_get(post_int('doc'));
        if (!$doc || $doc['status'] !== 'published') { respond(false, 'This document was not found.', [], 404); }
        if (!rate_limit('rep:' . client_ip(), 8, 3600)) { respond(false, 'You have sent several reports recently. Please try again later.', [], 429); }
        $reason = post_str('reason', 20);
        if (!in_array($reason, ['copyright', 'incorrect', 'broken', 'duplicate', 'inappropriate', 'other'], true)) { $reason = 'other'; }
        $email = post_str('email', 190);
        db_insert('INSERT INTO document_reports (document_id, reason, message, email, ip) VALUES (?, ?, ?, ?, ?)',
            [$doc['id'], $reason, post_str('message', 1000) ?: null, filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null, client_ip()]);
        respond(true, 'Thank you. Your report was sent to our team and will be reviewed.');

    case 'contact':
        if (post_str('hp', 50) !== '') { respond(true, 'Thank you! Your message was sent.'); }
        if (!rate_limit('contact:' . client_ip(), 5, 3600)) { respond(false, 'You have sent several messages recently. Please try again later.', [], 429); }
        $name = post_str('name', 120); $email = post_str('email', 190); $msg = post_str('message', 3000);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($msg) < 5) { respond(false, 'Please enter your name, a valid email and your message.'); }
        db_insert('INSERT INTO contact_messages (name, email, message, ip) VALUES (?, ?, ?, ?)', [$name, $email, $msg, client_ip()]);
        if (filter_var(setting('site_email'), FILTER_VALIDATE_EMAIL)) { send_mail(setting('site_email'), 'Contact form: ' . $name, "From: $name <$email>\n\n$msg", $email); }
        respond(true, 'Thank you! Your message was sent. We will reply by email.');
}
json_out(['ok' => false, 'message' => 'Unknown action.'], 400);
