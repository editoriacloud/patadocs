<?php
/**
 * Blog comment submission (with or without JavaScript). Every comment waits for moderation unless Blog settings
 * auto-approve people who already have an approved comment. Anti-spam: CSRF token, honeypot field, signed
 * page-load time (too fast / too old = bot), per-IP rate limit, link limit.
 */
require __DIR__ . '/../includes/init.php';

if (!is_post()) { respond(false, 'Invalid request.', [], 405); }
csrf_check();
$post = db_row('SELECT id, slug, title, allow_comments, status, published_at FROM blog_posts WHERE id = ?', [post_int('post_id')]);
if (!$post || $post['status'] !== 'published' || strtotime((string)$post['published_at']) > time()) { respond(false, 'This article is not available.', [], 404); }
$back = post_url($post) . '#comments';
if (setting('blog_comments', '1') !== '1' || !(int)$post['allow_comments']) { respond(false, 'Comments are closed for this article.', ['redirect' => $back], 403); }

$name = trim(preg_replace('/\s+/u', ' ', post_str('name', 80)));
$email = post_str('email', 190);
$body = trim(str_replace("\r", '', (string)($_POST['body'] ?? '')));
$parent = post_int('parent_id');
$age = blog_form_ts_age(post_str('ts', 40));

// Bots: silently "accept" so they learn nothing, but store nothing.
if (post_str('website', 200) !== '' || $age < 3) { respond(true, 'Thank you! Your comment will appear once it has been checked.', ['redirect' => $back]); }
if ($age < 0 || $age > 86400) { respond(false, 'This page was open too long. Please reload it and post again.', ['redirect' => $back], 400); }
if (mb_strlen($name) < 2) { respond(false, 'Please enter your name.', ['redirect' => $back], 422); }
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { respond(false, 'Please enter a valid email address, or leave it empty.', ['redirect' => $back], 422); }
if (mb_strlen($body) < 3 || mb_strlen($body) > 3000) { respond(false, 'Your comment must be between 3 and 3,000 characters.', ['redirect' => $back], 422); }
if (preg_match_all('#(https?://|www\.)#i', $body) > 2) { respond(false, 'Please include at most two links.', ['redirect' => $back], 422); }
if (!rate_limit('blogc:' . ip_hash(client_ip()), 4, 600)) { respond(false, 'You are commenting too fast. Please wait a few minutes.', ['redirect' => $back], 429); }
if ($parent && !db_val("SELECT id FROM blog_comments WHERE id = ? AND post_id = ? AND parent_id IS NULL AND status = 'approved'", [$parent, $post['id']])) { $parent = 0; }
if (db_val("SELECT id FROM blog_comments WHERE post_id = ? AND body = ? AND created_at > (NOW() - INTERVAL 1 DAY)", [$post['id'], $body])) {
    respond(true, 'Thank you! Your comment will appear once it has been checked.', ['redirect' => $back]);
}

$status = 'pending';
if (setting('blog_comments_auto', '0') === '1' && $email !== '' && db_val("SELECT id FROM blog_comments WHERE email = ? AND status = 'approved' LIMIT 1", [mb_strtolower($email)])) { $status = 'approved'; }
$id = db_insert('INSERT INTO blog_comments (post_id, parent_id, name, email, body, status, ip) VALUES (?, ?, ?, ?, ?, ?, ?)',
    [$post['id'], $parent ?: null, $name, $email !== '' ? mb_strtolower($email) : null, $body, $status, ip_hash(client_ip())]);
if ($status === 'pending' && setting('blog_notify_comments', '1') === '1' && setting('site_email')) {
    send_mail((string)setting('site_email'), 'New comment waiting: ' . $post['title'],
        $name . " wrote on \"" . $post['title'] . "\":\n\n" . excerpt($body, 600) . "\n\nModerate: " . url('admin/blog-comments.php'));
}
cache_flush();
respond(true, $status === 'approved' ? 'Thank you! Your comment is published.' : 'Thank you! Your comment will appear once it has been checked.', ['redirect' => $back, 'id' => $id]);
