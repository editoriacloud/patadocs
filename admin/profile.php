<?php
/** Admin: my account — change name, email, password; see my login activity. */
require __DIR__ . '/../includes/init.php';
$admin = require_admin();
$acct = db_row('SELECT * FROM admin_users WHERE id = ?', [$admin['id']]);
if (is_post()) {
    csrf_check();
    $errors = []; $name = post_str('full_name', 120); $email = post_str('email', 190); $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email.'; }
    if (!password_verify($cur, $acct['password_hash'])) { $errors[] = 'Your current password is incorrect.'; }
    if (db_val('SELECT id FROM admin_users WHERE email = ? AND id <> ?', [$email, $acct['id']])) { $errors[] = 'That email is already used.'; }
    if ($new !== '' && (strlen($new) < 10 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new))) { $errors[] = 'New password must be at least 10 characters with letters and numbers.'; }
    if ($new !== '' && $new !== (string)($_POST['password2'] ?? '')) { $errors[] = 'The new passwords do not match.'; }
    if ($errors) { foreach ($errors as $er) { flash('error', $er); } }
    else {
        db_exec('UPDATE admin_users SET full_name = ?, email = ? WHERE id = ?', [$name ?: $acct['username'], $email, $acct['id']]);
        if ($new !== '') { db_exec('UPDATE admin_users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $acct['id']]); session_regenerate_id(true); log_admin('password_changed', 'admin', $acct['id']); }
        $_SESSION['admin']['name'] = $name ?: $acct['username'];
        flash('success', 'Your account was updated.');
    }
    redirect(url('admin/profile.php'));
}
$logins = db_all('SELECT * FROM login_attempts WHERE identifier IN (?, ?) ORDER BY id DESC LIMIT 15', [mb_strtolower($acct['username']), mb_strtolower($acct['email'])]);
$adm = ['title' => 'My Account', 'active' => 'profile'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">MY ACCOUNT</div><div class="panel-body">
    <p class="help" style="margin-bottom:12px;">Signed in as <strong><?= e($acct['username']) ?></strong> · role <span class="badge b-blue"><?= e($acct['role']) ?></span> · last login <?= e($acct['last_login_at'] ? fmt_date($acct['last_login_at'], true) : '—') ?> from <?= e($acct['last_login_ip'] ?: '—') ?></p>
    <form method="post" class="pd-form" autocomplete="off"><?= csrf_field() ?>
        <div class="form-grid">
            <div class="frow"><label>Full name</label><input type="text" name="full_name" maxlength="120" value="<?= e($acct['full_name']) ?>"></div>
            <div class="frow"><label>Email</label><input type="email" name="email" maxlength="190" required value="<?= e($acct['email']) ?>"></div>
            <div class="frow"><label>New password (optional)</label><input type="password" name="password" autocomplete="new-password"></div>
            <div class="frow"><label>Repeat new password</label><input type="password" name="password2" autocomplete="new-password"></div>
            <div class="frow full"><label>Current password (required to save)</label><input type="password" name="current" required autocomplete="current-password" style="max-width:340px;"></div>
        </div>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE</button></div>
    </form>
    <div class="section-title">MY LOGIN ACTIVITY</div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>When</th><th>Result</th><th>IP</th><th>Browser</th></tr></thead><tbody>
    <?php foreach ($logins as $l) { echo '<tr><td class="nowrap">' . e(fmt_date($l['created_at'], true)) . '</td><td>' . ($l['success'] ? '<span class="badge b-green">success</span>' : '<span class="badge b-red">failed</span>') . '</td><td>' . e($l['ip']) . '</td><td class="small">' . e(excerpt((string)$l['user_agent'], 70)) . '</td></tr>'; } ?></tbody></table></div>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
