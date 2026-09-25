<?php
/** Admin: blog comment moderation — approve, spam, trash, delete, reply as the site. */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('blog.comments');
$back = url('admin/blog-comments.php');

if (is_post()) {
    csrf_check();
    $do = post_str('do', 12); $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? []))); if (post_int('id')) { $ids = [post_int('id')]; }
    if ($do === 'reply') {
        $c = db_row('SELECT * FROM blog_comments WHERE id = ?', [post_int('id')]); $body = trim((string)($_POST['body'] ?? ''));
        if ($c && mb_strlen($body) >= 2) {
            if ($c['status'] !== 'approved') { db_exec("UPDATE blog_comments SET status = 'approved' WHERE id = ?", [$c['id']]); }
            db_insert("INSERT INTO blog_comments (post_id, parent_id, name, body, status, is_staff) VALUES (?, ?, ?, ?, 'approved', 1)", [$c['post_id'], $c['parent_id'] ?: $c['id'], $admin['name'], mb_substr($body, 0, 3000)]);
            log_admin('blog_comment_reply', 'blog_comment', $c['id']); flash('success', 'Reply published (the comment was approved too).');
        }
        redirect($back . '?status=' . rawurlencode(post_str('f', 10)));
    }
    $map = ['approve' => 'approved', 'pending' => 'pending', 'spam' => 'spam', 'trash' => 'trash'];
    if ($ids && isset($map[$do])) { db_exec('UPDATE blog_comments SET status = ? WHERE id IN (' . implode(',', $ids) . ')', [$map[$do]]); flash('success', count($ids) . ' comment(s) updated.'); }
    if ($ids && $do === 'delete') { db_exec("DELETE FROM blog_comments WHERE id IN (" . implode(',', $ids) . ") AND status IN ('spam','trash')", []); flash('success', 'Deleted.'); }
    if ($do === 'empty_spam') { db_exec("DELETE FROM blog_comments WHERE status = 'spam'"); flash('success', 'Spam emptied.'); }
    log_admin('blog_comments_' . $do, 'blog_comment', implode(',', array_slice($ids, 0, 20)));
    redirect($back . '?status=' . rawurlencode(post_str('f', 10)));
}
$st = in_array(get_str('status', 10), ['approved', 'spam', 'trash', 'all'], true) ? get_str('status', 10) : 'pending';
$counts = []; foreach (db_all('SELECT status, COUNT(*) n FROM blog_comments GROUP BY status') as $r) { $counts[$r['status']] = (int)$r['n']; }
$where = $st === 'all' ? '1 = 1' : 'c.status = ?'; $params = $st === 'all' ? [] : [$st];
$total = (int)db_val("SELECT COUNT(*) FROM blog_comments c WHERE $where", $params);
$pg = paginate($total, get_int('page', 1), 30);
$rows = db_all("SELECT c.*, p.title AS post_title, p.slug AS post_slug FROM blog_comments c JOIN blog_posts p ON p.id = c.post_id WHERE $where ORDER BY c.id DESC LIMIT " . (int)$pg['offset'] . ', 30', $params);
$adm = ['title' => 'Blog comments', 'active' => 'blog-comments'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header">COMMENTS</div><div class="panel-body">
    <div class="flex" style="gap:6px; flex-wrap:wrap; margin-bottom:10px;">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'spam' => 'Spam', 'trash' => 'Trash', 'all' => 'All'] as $k => $l) { echo '<a class="btn-classic btn-sm' . ($st === $k ? ' primary' : '') . '" href="?status=' . $k . '">' . $l . ' (' . (int)($k === 'all' ? array_sum($counts) : ($counts[$k] ?? 0)) . ')</a>'; } ?>
        <?php if ($st === 'spam' && !empty($counts['spam'])) { echo '<form method="post" style="display:inline;">' . csrf_field() . '<input type="hidden" name="f" value="spam"><button class="btn-classic btn-sm danger" name="do" value="empty_spam" data-confirm="Delete all spam permanently?">Empty spam</button></form>'; } ?>
    </div>
    <form method="post" id="comBulk"><?= csrf_field() ?><input type="hidden" name="f" value="<?= e($st) ?>"><input type="hidden" name="id" value="">
        <div class="flex" style="gap:6px; margin-bottom:8px;">
            <select name="do" aria-label="Bulk action"><option value="">Bulk actions</option><option value="approve">Approve</option><option value="pending">Unapprove</option><option value="spam">Mark as spam</option><option value="trash">Move to trash</option><option value="delete">Delete permanently (spam/trash only)</option></select>
            <button class="btn-classic btn-sm" type="submit">APPLY</button>
        </div>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th></th><th>Author</th><th>Comment</th><th>On article</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($rows as $c) {
            $one = function ($do, $label, $cls = '') use ($c) { return '<button class="linkish' . $cls . '" name="do" value="' . $do . '" onclick="this.form.id.value=' . (int)$c['id'] . '">' . $label . '</button>'; };
            echo '<tr><td><input type="checkbox" name="ids[]" value="' . (int)$c['id'] . '" aria-label="Select comment"></td>'
                . '<td class="small"><strong>' . e($c['name']) . '</strong>' . ($c['is_staff'] ? ' <span class="badge b-blue">staff</span>' : '') . ($c['email'] ? '<span class="sub">' . e($c['email']) . '</span>' : '') . '</td>'
                . '<td>' . ($c['parent_id'] ? '<span class="muted small">↳ reply · </span>' : '') . status_badge($c['status']) . '<div style="margin:4px 0; white-space:pre-wrap;">' . e($c['body']) . '</div>'
                . '<span class="row-actions">' . ($c['status'] !== 'approved' ? $one('approve', 'Approve') . ' · ' : $one('pending', 'Unapprove') . ' · ') . $one('spam', 'Spam', ' danger') . ' · ' . $one('trash', 'Trash', ' danger')
                . ' · <a href="' . e(post_url(['slug' => $c['post_slug']])) . '#comment-' . (int)$c['id'] . '" target="_blank" rel="noopener">View</a></span>'
                . (!$c['is_staff'] ? '<details class="small" style="margin-top:4px;"><summary>Reply</summary><div class="flex" style="gap:6px; margin-top:4px;"><textarea form="reply' . (int)$c['id'] . '" name="body" maxlength="3000" style="min-height:60px; flex:1;" aria-label="Reply text"></textarea><button class="btn-classic btn-sm success" form="reply' . (int)$c['id'] . '">REPLY</button></div></details>' : '')
                . '</td><td class="small"><a href="' . e(post_url(['slug' => $c['post_slug']])) . '" target="_blank" rel="noopener">' . e(excerpt((string)$c['post_title'], 50)) . '</a></td>'
                . '<td class="nowrap small">' . e(fmt_date($c['created_at'], true)) . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="5" class="empty-cell">No comments here.</td></tr>'; } ?>
        </tbody></table></div>
    </form>
    <?php foreach ($rows as $c) { if (!$c['is_staff']) { echo '<form method="post" id="reply' . (int)$c['id'] . '">' . csrf_field() . '<input type="hidden" name="do" value="reply"><input type="hidden" name="id" value="' . (int)$c['id'] . '"><input type="hidden" name="f" value="' . e($st) . '"></form>'; } } ?>
    <?= pager_html($pg, $back, ['status' => $st]) ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
