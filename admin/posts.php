<?php
/** Admin: all articles — status tabs, search, category filter, bulk actions (publish / draft / trash / restore / delete forever). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/blog_admin.php';
$admin = require_admin('blog.write');
$canPublish = admin_can('blog.publish');
$back = url('admin/posts.php');

if (is_post()) {
    csrf_check();
    $act = post_str('bulk', 10);
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
    if (post_int('id')) { $ids = [post_int('id')]; $act = post_str('row', 10); }   // a row link (Trash / Restore / Delete)
    $n = 0;
    foreach ($ids as $i) { if (blog_post_set_status($i, $act)) { $n++; } }
    $labels = ['publish' => 'published', 'draft' => 'switched to draft', 'trash' => 'moved to the trash', 'restore' => 'restored', 'delete' => 'deleted permanently'];
    flash($n ? 'success' : 'error', $n ? $n . ' article(s) ' . ($labels[$act] ?? 'updated') . '.' : 'Nothing changed (check your permissions, or publish needs text).');
    redirect($back . '?' . http_build_query(array_filter(['status' => post_str('f_status', 12), 'q' => post_str('f_q', 80), 'cat' => post_int('f_cat') ?: null])));
}

$st = get_str('status', 12); $q = get_str('q', 80); $cat = get_int('cat');
$mine = !$canPublish;                                                   // writers only see their own articles
$base = $mine ? 'p.author_id = ' . (int)$admin['id'] : '1 = 1';
$counts = ['all' => 0, 'published' => 0, 'scheduled' => 0, 'draft' => 0, 'pending' => 0, 'private' => 0, 'trash' => 0];
foreach (db_all("SELECT CASE WHEN status = 'published' AND published_at > NOW() THEN 'scheduled' ELSE status END s, COUNT(*) n FROM blog_posts p WHERE $base GROUP BY s") as $r) { $counts[$r['s']] = (int)$r['n']; }
$counts['all'] = array_sum($counts) - $counts['trash'];
$where = [$base]; $params = [];
switch ($st) {
    case 'published': $where[] = "p.status = 'published' AND p.published_at <= NOW()"; break;
    case 'scheduled': $where[] = "p.status = 'published' AND p.published_at > NOW()"; break;
    case 'draft': case 'pending': case 'private': case 'trash': $where[] = 'p.status = ?'; $params[] = $st; break;
    default: $st = ''; $where[] = "p.status <> 'trash'";
}
if ($q !== '') { $where[] = '(p.title LIKE ? OR p.focus_keyword LIKE ? OR p.slug LIKE ?)'; $l = '%' . like_escape($q) . '%'; array_push($params, $l, $l, $l); }
if ($cat) { $where[] = 'p.category_id = ?'; $params[] = $cat; }
$w = implode(' AND ', $where);
$total = (int)db_val("SELECT COUNT(*) FROM blog_posts p WHERE $w", $params);
$pg = paginate($total, get_int('page', 1), 25);
$rows = db_all(str_replace('SELECT p.*', "SELECT p.*, (SELECT COUNT(*) FROM blog_comments bc WHERE bc.post_id = p.id AND bc.status = 'approved') AS ncom, (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM blog_post_tags pt JOIN blog_tags t ON t.id = pt.tag_id WHERE pt.post_id = p.id) AS tag_list", BLOG_SELECT)
    . " WHERE $w ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC LIMIT " . (int)$pg['offset'] . ', ' . (int)$pg['per'], $params);
$cats = db_all('SELECT id, name FROM blog_categories ORDER BY sort_order, name');
$adm = ['title' => 'Articles', 'active' => 'posts'];
include __DIR__ . '/../includes/admin_header.php';
$tab = function ($k, $label) use ($st, $counts, $q, $cat) {
    $n = $counts[$k === '' ? 'all' : $k] ?? 0;
    if ($k !== '' && !$n) { return ''; }
    return '<a class="btn-classic btn-sm' . ($st === $k ? ' primary' : '') . '" href="?' . e(http_build_query(array_filter(['status' => $k, 'q' => $q, 'cat' => $cat ?: null]))) . '">' . e($label) . ' (' . (int)$n . ')</a>';
};
?>
<div class="panel"><div class="panel-header">ARTICLES</div><div class="panel-body">
    <div class="flex" style="gap:6px; flex-wrap:wrap; margin-bottom:10px;">
        <a class="btn-classic success" href="post-edit.php">＋ ADD NEW ARTICLE</a>
        <?= $tab('', 'All') . $tab('published', 'Published') . $tab('scheduled', 'Scheduled') . $tab('draft', 'Drafts') . $tab('pending', 'Pending review') . $tab('private', 'Private') . $tab('trash', 'Trash') ?>
        <a class="btn-classic btn-sm" href="<?= e(blog_url()) ?>" target="_blank" rel="noopener">View blog ↗</a>
    </div>
    <form method="get" class="flex" style="gap:6px; flex-wrap:wrap; margin-bottom:10px;">
        <?php if ($st !== '') { echo '<input type="hidden" name="status" value="' . e($st) . '">'; } ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search title, keyphrase, slug…" aria-label="Search articles" style="max-width:280px;">
        <select name="cat" aria-label="Category"><option value="0">All categories</option><?php foreach ($cats as $c) { echo '<option value="' . (int)$c['id'] . '"' . ($cat === (int)$c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>'; } ?></select>
        <button class="btn-classic btn-sm primary" type="submit">FILTER</button>
    </form>
    <form method="post" id="postsBulk"><?= csrf_field() ?>
        <input type="hidden" name="f_status" value="<?= e($st) ?>"><input type="hidden" name="f_q" value="<?= e($q) ?>"><input type="hidden" name="f_cat" value="<?= (int)$cat ?>">
        <div class="flex" style="gap:6px; margin-bottom:8px;">
            <select name="bulk" aria-label="Bulk action"><option value="">Bulk actions</option>
                <?php if ($st === 'trash') { echo '<option value="restore">Restore</option>' . ($canPublish ? '<option value="delete">Delete permanently</option>' : ''); }
                else { echo ($canPublish ? '<option value="publish">Publish</option>' : '') . '<option value="draft">Switch to draft</option><option value="trash">Move to trash</option>'; } ?>
            </select>
            <button class="btn-classic btn-sm" type="submit" data-confirm="Apply this action to the selected articles?">APPLY</button>
        </div>
        <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th><input type="checkbox" aria-label="Select all" onclick="document.querySelectorAll('#postsBulk input[name=\'ids[]\']').forEach(function(c){c.checked=this.checked;},this)"></th><th>Title</th><th>Author</th><th>Category / tags</th><th title="SEO score">SEO</th><th>💬</th><th>Views</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($rows as $r) {
            $live = $r['status'] === 'published' && strtotime((string)$r['published_at']) <= time();
            $sched = $r['status'] === 'published' && !$live;
            $badge = $live ? '' : ' <span class="badge ' . ($sched ? 'b-blue">Scheduled' : ($r['status'] === 'pending' ? 'b-orange">Pending' : ($r['status'] === 'private' ? 'b-gray">Private' : ($r['status'] === 'trash' ? 'b-red">Trash' : 'b-gray">Draft')))) . '</span>';
            $sc = (int)$r['seo_score'];
            $can = blog_can_edit($r);
            echo '<tr><td><input type="checkbox" name="ids[]" value="' . (int)$r['id'] . '" aria-label="Select ' . e($r['title']) . '"' . ($can ? '' : ' disabled') . '></td>'
                . '<td class="doc-title">' . ($can ? '<a href="post-edit.php?id=' . (int)$r['id'] . '">' . e($r['title']) . '</a>' : e($r['title'])) . ($r['featured'] ? ' ⭐' : '') . $badge
                . '<span class="sub">/' . e($r['slug']) . ' · ' . num($r['word_count']) . ' words' . ($r['focus_keyword'] ? ' · 🔑 ' . e($r['focus_keyword']) : '') . '</span>'
                . '<span class="row-actions">' . ($can ? '<a href="post-edit.php?id=' . (int)$r['id'] . '">Edit</a> · ' : '')
                . ($r['status'] !== 'trash' ? '<a href="' . e(post_url($r)) . '" target="_blank" rel="noopener">' . ($live ? 'View' : 'Preview') . '</a>' : '')
                . ($can && $r['status'] !== 'trash' ? ' · <button class="linkish danger" name="row" value="trash" onclick="this.form.id.value=' . (int)$r['id'] . '" data-confirm="Move to trash?">Trash</button>' : '')
                . ($r['status'] === 'trash' && $can ? '<button class="linkish" name="row" value="restore" onclick="this.form.id.value=' . (int)$r['id'] . '">Restore</button>' . ($canPublish ? ' · <button class="linkish danger" name="row" value="delete" onclick="this.form.id.value=' . (int)$r['id'] . '" data-confirm="Delete this article forever?">Delete forever</button>' : '') : '')
                . '</span></td>'
                . '<td class="small">' . e($r['author_name'] ?: '—') . '</td>'
                . '<td class="small">' . e($r['category_name'] ?: '—') . ($r['tag_list'] ? '<span class="sub">' . e(excerpt((string)$r['tag_list'], 60)) . '</span>' : '') . '</td>'
                . '<td><span class="seo-score-pill ' . ($sc >= 75 ? 'good' : ($sc >= 50 ? 'ok' : 'bad')) . '">' . $sc . '</span></td>'
                . '<td class="num">' . (int)$r['ncom'] . '</td><td class="num">' . num($r['views']) . '</td>'
                . '<td class="nowrap small">' . ($r['published_at'] ? e(fmt_date($r['published_at'], true)) : '<span class="muted">Last edit ' . e(fmt_date($r['updated_at'])) . '</span>') . '</td></tr>';
        }
        if (!$rows) { echo '<tr><td colspan="8" class="empty-cell">No articles here yet. <a href="post-edit.php">Write the first one →</a></td></tr>'; } ?>
        </tbody></table></div>
        <input type="hidden" name="id" value="">
    </form>
    <?= pager_html($pg, $back, array_filter(['status' => $st, 'q' => $q, 'cat' => $cat ?: null])) ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
