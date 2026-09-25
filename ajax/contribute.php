<?php
/**
 * Community contribution (free resources only, no account). Every submission goes to "pending"
 * and is reviewed by an admin before anything is published.
 * Validates extension + MIME + real content + size, renames files, stores them outside the web root
 * (PRIVATE_DIR) and records the copyright declaration with a timestamp.
 */
require __DIR__ . '/../includes/init.php';

if (!is_post()) { respond(false, 'Invalid request.', [], 405); }
csrf_check();
if (setting('contributions_enabled', '1') !== '1') { respond(false, 'Contributions are temporarily closed.'); }
if (post_str('hp', 50) !== '') { respond(true, 'Thank you! Your resource was submitted for review.'); }        // honeypot: pretend success
if (time() - post_int('ts') < 4) { respond(false, 'That was very quick — please take a moment to check the form and submit again.'); }
if (!rate_limit('contrib:' . client_ip(), max(1, (int)setting('contrib_per_hour', 5)), 3600)) { respond(false, 'You have submitted several resources recently. Please try again later.', [], 429); }

$errors = [];
$title = post_str('title', 200); $desc = post_str('description', 3000); $name = post_str('name', 120);
$email = post_str('email', 190); $phone = post_str('phone', 20); $source = post_str('source', 250);
$catId = post_int('category'); $cat = $catId ? cat_get($catId) : null;
if (mb_strlen($title) < 3) { $errors[] = 'Please enter the document title.'; }
if (!$cat || $cat['status'] !== 'active') { $errors[] = 'Please choose a category.'; }
if ($name === '') { $errors[] = 'Please enter your name.'; }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Please enter a valid email address.'; }
if ($phone !== '' && normalize_phone($phone) === '') { $errors[] = 'The phone number looks invalid (use 07XX XXX XXX or leave it empty).'; }
if (post_str('own', 2) !== '1' || post_str('understand', 2) !== '1') { $errors[] = 'You must accept the copyright declaration.'; }
$file = upload_check($_FILES['file'] ?? [], allowed_exts(), max_upload_bytes('contrib_max_mb'));
if (!$file['ok']) { $errors[] = $file['error']; }
$prev = null;
if (!empty($_FILES['preview']) && ($_FILES['preview']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
    $prev = upload_check($_FILES['preview'], allowed_exts(), min(max_upload_bytes('contrib_max_mb'), 3 * 1048576), true);
    if (!$prev['ok']) { $errors[] = 'Preview image: ' . $prev['error']; }
}
$meta = meta_sanitize($cat ? (int)$cat['id'] : null, (array)($_POST['meta'] ?? []), true);
$errors = array_merge($errors, $meta['errors']);
if ($errors) { respond(false, implode(' ', $errors), ['errors' => $errors]); }

$stored = upload_save($_FILES['file']['tmp_name'], $file['ext'], PRIVATE_DIR);
if (!$stored) { log_error('Contribution: could not store the uploaded file'); respond(false, 'The file could not be saved. Please try again later.', [], 500); }
$prevStored = $prev ? upload_save($_FILES['preview']['tmp_name'], $prev['ext'], PRIVATE_DIR) : null;
$metaJson = [];
foreach ($meta['rows'] as $r) { $metaJson[(string)$r[0]] = $r[1]; }

$id = db_insert('INSERT INTO contributions (title, description, category_id, tags, metadata_json, file_name, original_name, file_ext, file_mime, file_size, preview_image,
        contributor_name, contributor_email, contributor_phone, source_info, copyright_confirmed, copyright_confirmed_at, ip, status, show_attribution, badge)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, \'pending\', ?, \'community\')',
    [$title, $desc !== '' ? $desc : null, (int)$cat['id'], mb_substr(implode(', ', tags_parse(post_str('tags', 300))), 0, 400), $metaJson ? json_encode($metaJson, JSON_UNESCAPED_UNICODE) : null,
     $stored, $file['name'], $file['ext'], $file['mime'], $file['size'], $prevStored, $name, $email, $phone !== '' ? $phone : null, $source !== '' ? $source : null,
     client_ip(), post_str('credit', 2) === '1' ? 1 : 0]);

$ref = 'CON-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
if (filter_var(setting('site_email'), FILTER_VALIDATE_EMAIL)) {
    send_mail(setting('site_email'), 'New contribution ' . $ref . ': ' . $title, "A new resource was submitted for review.\n\nTitle: $title\nBy: $name <$email>\nReview: " . url('admin/contributions.php?id=' . $id));
}
send_mail($email, 'We received your contribution ' . $ref, "Hello $name,\n\nThank you for contributing \"$title\" to " . setting('site_name') . ". Our team will review it before it is published.\nReference: $ref\n\n" . setting('site_name'));
respond(true, 'Thank you! Your resource was submitted for review. Reference: ' . $ref . '. We will email you once it has been reviewed.');
