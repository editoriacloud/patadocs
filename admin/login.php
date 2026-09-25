<?php
/** Admin login: username/email + password, lockout after repeated failures, session ID regenerated on success. */
require __DIR__ . '/../includes/init.php';

if (admin_current()) { redirect(url('admin/')); }
$error = ''; $next = safe_next(get_str('next', 300));
if (is_post()) {
    csrf_check();
    $next = safe_next(post_str('next', 300));
    $r = admin_login(post_str('identifier', 190), (string)($_POST['password'] ?? ''));
    if ($r['ok']) { redirect($next !== '' ? $next : url('admin/')); }
    $error = $r['error'];
}
$siteName = setting('site_name', 'PATADOCS');
$brand = preg_match('/^(.+?)(docs)$/i', $siteName, $bm) ? e($bm[1]) . '<span>' . e($bm[2]) . '</span>' : e($siteName);
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow"><title>Admin Login — <?= e($siteName) ?></title>
<link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>"><link rel="stylesheet" href="<?= e(asset('css/extra.css')) ?>">
</head><body class="light-theme">
<script>try{if(localStorage.getItem('patadocs-theme')==='dark'){document.body.className='dark-theme';}}catch(e){}</script>
<div class="aspx-form">
<div class="aspx-toolbar"><a class="brand" href="<?= e(url('')) ?>" style="text-decoration:none;"><?= $brand ?><small>Admin Panel</small></a></div>
<div class="aspx-content"><section class="page-section active">
<div class="panel login-box">
    <div class="panel-header">ADMIN LOGIN</div>
    <div class="panel-body">
        <?php if ($error) { echo '<div class="alert alert-error">' . e($error) . '</div>'; } ?>
        <?php if (get_str('timeout', 1) === '1') { echo '<div class="alert alert-info">You were signed out because of inactivity.</div>'; } ?>
        <form method="post" class="pd-form" autocomplete="on">
            <?= csrf_field() ?><input type="hidden" name="next" value="<?= e($next) ?>">
            <div class="frow" style="margin-bottom:14px;"><label for="lid">Username or email</label><input type="text" id="lid" name="identifier" required autofocus autocomplete="username" maxlength="190" value="<?= e(post_str('identifier', 190)) ?>"></div>
            <div class="frow" style="margin-bottom:14px;"><label for="lpw">Password</label><input type="password" id="lpw" name="password" required autocomplete="current-password"></div>
            <button type="submit" class="btn-classic primary block" style="font-size:1.1rem;">SIGN IN</button>
        </form>
        <p class="help" style="margin-top:14px; text-align:center;"><a href="<?= e(url('')) ?>">← Back to the website</a></p>
    </div>
</div></section></div>
<div class="footer-bar"><?= e(strtoupper($siteName)) ?> · ADMIN</div></div></body></html>
