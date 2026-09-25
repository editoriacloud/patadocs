<?php
/** Admin: admin users, roles and the role → permission matrix (SUPER_ADMIN, ADMIN, CONTENT_MANAGER, REVIEWER). */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('users.manage');
$isSuper = $admin['role'] === 'SUPER_ADMIN';
$roles = ['SUPER_ADMIN' => 'Super Admin — everything', 'ADMIN' => 'Admin — most management', 'CONTENT_MANAGER' => 'Content Manager — documents & categories', 'REVIEWER' => 'Reviewer — contributions & reports'];
$perms = ['dashboard.view' => 'View dashboard', 'documents.view' => 'View documents', 'documents.edit' => 'Create / edit / publish documents', 'documents.delete' => 'Delete documents',
    'categories.manage' => 'Manage categories', 'metadata.manage' => 'Manage metadata fields', 'collections.manage' => 'Manage collections', 'homepage.manage' => 'Manage homepage',
    'blog.write' => 'Write blog posts (own drafts)', 'blog.publish' => 'Publish & edit all blog posts', 'blog.comments' => 'Moderate blog comments',
    'synonyms.manage' => 'Manage search synonyms', 'contributions.review' => 'Review contributions', 'reports.manage' => 'Manage document reports', 'requests.manage' => 'Manage requests & messages',
    'orders.view' => 'View orders', 'orders.manage' => 'Manage orders (recheck / refund)', 'payments.view' => 'View payments', 'downloads.manage' => 'Manage download links',
    'analytics.view' => 'View analytics', 'settings.manage' => 'Change general settings', 'settings.secure' => 'Change Payment Hub & security settings', 'users.manage' => 'Manage admin users'];
$back = url('admin/users.php');
$pwOk = function ($p) { return strlen($p) >= 10 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p); };

if (is_post()) {
    csrf_check(); $act = post_str('do', 8); $id = post_int('id');
    if ($act === 'matrix') {
        if (!$isSuper) { abort_page(403, 'Access denied', 'Only a Super Admin can change role permissions.'); }
        foreach (['ADMIN', 'CONTENT_MANAGER', 'REVIEWER'] as $role) {
            db_exec('DELETE FROM role_permissions WHERE role = ?', [$role]);
            foreach ((array)($_POST['perm'][$role] ?? []) as $perm) { if (isset($perms[$perm])) { db_exec('INSERT IGNORE INTO role_permissions (role, permission) VALUES (?, ?)', [$role, $perm]); } }
        }
        log_admin('permissions_changed', 'roles'); flash('success', 'Role permissions saved.'); redirect($back . '#matrix');
    }
    $role = post_str('role', 20); $username = post_str('username', 60); $email = post_str('email', 190); $name = post_str('full_name', 120); $pw = (string)($_POST['password'] ?? '');
    $errors = [];
    if (!isset($roles[$role])) { $errors[] = 'Choose a role.'; }
    if ($role === 'SUPER_ADMIN' && !$isSuper) { $errors[] = 'Only a Super Admin can create or promote Super Admins.'; }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email.'; }
    if ($act === 'create') {
        if (!preg_match('/^[A-Za-z0-9._\-]{3,60}$/', $username)) { $errors[] = 'Username: 3-60 letters, numbers, dots, dashes or underscores.'; }
        if (!$pwOk($pw)) { $errors[] = 'Password must be at least 10 characters with letters and numbers.'; }
        if (db_val('SELECT id FROM admin_users WHERE username = ? OR email = ?', [$username, $email])) { $errors[] = 'That username or email is already used.'; }
        if (!$errors) { $nid = db_insert('INSERT INTO admin_users (username, email, full_name, password_hash, role) VALUES (?, ?, ?, ?, ?)', [$username, $email, $name ?: $username, password_hash($pw, PASSWORD_DEFAULT), $role]); log_admin('admin_created', 'admin', $nid, $username . ' (' . $role . ')'); flash('success', 'Admin user created.'); redirect($back); }
    } elseif ($act === 'update') {
        $u = db_row('SELECT * FROM admin_users WHERE id = ?', [$id]);
        if (!$u) { $errors[] = 'User not found.'; }
        else {
            if ($u['role'] === 'SUPER_ADMIN' && !$isSuper) { $errors[] = 'Only a Super Admin can change a Super Admin account.'; }
            $status = post_str('status', 8) === 'disabled' ? 'disabled' : 'active';
            if ($id === (int)$admin['id'] && $status === 'disabled') { $errors[] = 'You cannot disable your own account.'; }
            $supers = (int)db_val("SELECT COUNT(*) FROM admin_users WHERE role = 'SUPER_ADMIN' AND status = 'active' AND id <> ?", [$id]);
            if ($u['role'] === 'SUPER_ADMIN' && ($role !== 'SUPER_ADMIN' || $status === 'disabled') && $supers === 0) { $errors[] = 'There must always be at least one active Super Admin.'; }
            if ($pw !== '' && !$pwOk($pw)) { $errors[] = 'New password must be at least 10 characters with letters and numbers.'; }
            if (db_val('SELECT id FROM admin_users WHERE email = ? AND id <> ?', [$email, $id])) { $errors[] = 'That email is already used.'; }
            if (!$errors) {
                db_exec('UPDATE admin_users SET email = ?, full_name = ?, role = ?, status = ? WHERE id = ?', [$email, $name ?: $u['username'], $role, $status, $id]);
                if ($pw !== '') { db_exec('UPDATE admin_users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]); }
                log_admin('admin_updated', 'admin', $id, $u['username']); flash('success', 'User updated.'); redirect($back);
            }
        }
    }
    foreach ($errors as $er) { flash('error', $er); }
    redirect($back . ($act === 'update' ? '?edit=' . $id : '?new=1'));
}
$edit = get_int('edit') ? db_row('SELECT * FROM admin_users WHERE id = ?', [get_int('edit')]) : null;
$users = db_all('SELECT * FROM admin_users ORDER BY id');
$matrix = []; foreach (db_all('SELECT role, permission FROM role_permissions') as $r) { $matrix[$r['role']][$r['permission']] = true; }
$logins = db_all('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 25');
$adm = ['title' => 'Admin Users & Roles', 'active' => 'users'];
include __DIR__ . '/../includes/admin_header.php';
$showForm = $edit || get_str('new', 1) === '1';
$row = $edit ?: ['id' => 0, 'username' => '', 'email' => '', 'full_name' => '', 'role' => 'CONTENT_MANAGER', 'status' => 'active'];
?>
<?php if ($showForm) { ?>
<div class="panel"><div class="panel-header orange"><?= $edit ? 'EDIT ADMIN USER' : 'NEW ADMIN USER' ?></div><div class="panel-body">
    <form method="post" class="pd-form" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="do" value="<?= $edit ? 'update' : 'create' ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
        <div class="form-grid">
            <div class="frow"><label>Username</label><input type="text" name="username" maxlength="60" value="<?= e($row['username']) ?>" <?= $edit ? 'readonly' : 'required' ?>></div>
            <div class="frow"><label>Full name</label><input type="text" name="full_name" maxlength="120" value="<?= e($row['full_name']) ?>"></div>
            <div class="frow"><label>Email</label><input type="email" name="email" maxlength="190" required value="<?= e($row['email']) ?>"></div>
            <div class="frow"><label>Role</label><select name="role"><?php foreach ($roles as $k => $lab) { if ($k === 'SUPER_ADMIN' && !$isSuper) { continue; } echo '<option value="' . $k . '"' . ($row['role'] === $k ? ' selected' : '') . '>' . e($lab) . '</option>'; } ?></select></div>
            <?php if ($edit) { echo '<div class="frow"><label>Status</label><select name="status"><option value="active"' . ($row['status'] === 'active' ? ' selected' : '') . '>Active</option><option value="disabled"' . ($row['status'] === 'disabled' ? ' selected' : '') . '>Disabled</option></select></div>'; } ?>
            <div class="frow"><label><?= $edit ? 'New password (leave empty to keep)' : 'Password' ?></label><input type="password" name="password" autocomplete="new-password" <?= $edit ? '' : 'required' ?>><span class="help">At least 10 characters with letters and numbers.</span></div>
        </div>
        <div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE</button><a class="btn-classic" href="users.php">CANCEL</a></div>
    </form></div></div>
<?php } ?>
<div class="panel"><div class="panel-header">ADMIN USERS</div><div class="panel-body">
    <div class="flex" style="margin-bottom:12px;"><a class="btn-classic success" href="?new=1">＋ NEW ADMIN USER</a></div>
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Last login</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($users as $u) { echo '<tr><td class="doc-title">' . e($u['full_name'] ?: $u['username']) . '<span class="sub">' . e($u['username']) . ' · ' . e($u['email']) . '</span></td><td><span class="badge b-blue">' . e($u['role']) . '</span></td><td>' . status_badge($u['status']) . '</td><td class="nowrap">' . e($u['last_login_at'] ? fmt_date($u['last_login_at'], true) : 'never') . '<span class="sub">' . e($u['last_login_ip'] ?: '') . '</span></td><td>' . (($u['role'] !== 'SUPER_ADMIN' || $isSuper) ? '<a class="btn-classic btn-sm primary" href="?edit=' . (int)$u['id'] . '">EDIT</a>' : '') . '</td></tr>'; } ?></tbody></table></div>
</div></div>
<div class="panel" id="matrix"><div class="panel-header">ROLE PERMISSIONS</div><div class="panel-body">
    <p class="help" style="margin-bottom:10px;"><?= $isSuper ? 'Tick what each role may do. Super Admin always has every permission.' : 'Only a Super Admin can change this matrix.' ?></p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="matrix">
        <div class="gv-wrap"><table class="gv-table compact matrix"><thead><tr><th>Permission</th><th class="center">Super Admin</th><th class="center">Admin</th><th class="center">Content Manager</th><th class="center">Reviewer</th></tr></thead><tbody>
        <?php foreach ($perms as $k => $lab) { echo '<tr><td>' . e($lab) . '<span class="sub mono">' . e($k) . '</span></td><td class="center"><input type="checkbox" checked disabled></td>';
            foreach (['ADMIN', 'CONTENT_MANAGER', 'REVIEWER'] as $r) { echo '<td class="center"><input type="checkbox" name="perm[' . $r . '][]" value="' . e($k) . '" ' . (!empty($matrix[$r][$k]) ? 'checked' : '') . ($isSuper ? '' : ' disabled') . '></td>'; }
            echo '</tr>'; } ?></tbody></table></div>
        <?php if ($isSuper) { echo '<div class="form-actions"><button class="btn-classic success" type="submit">💾 SAVE PERMISSIONS</button></div>'; } ?>
    </form>
</div></div>
<div class="panel"><div class="panel-header">RECENT LOGIN ACTIVITY (INCLUDING FAILED ATTEMPTS)</div><div class="panel-body">
    <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th>When</th><th>Account</th><th>Result</th><th>IP</th><th>Browser</th></tr></thead><tbody>
    <?php foreach ($logins as $l) { echo '<tr><td class="nowrap">' . e(fmt_date($l['created_at'], true)) . '</td><td>' . e($l['identifier']) . '</td><td>' . ($l['success'] ? '<span class="badge b-green">success</span>' : '<span class="badge b-red">failed</span>') . '</td><td>' . e($l['ip']) . '</td><td class="small">' . e(excerpt((string)$l['user_agent'], 60)) . '</td></tr>'; } if (!$logins) { echo '<tr><td colspan="5" class="empty-cell">No activity.</td></tr>'; } ?></tbody></table></div>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
