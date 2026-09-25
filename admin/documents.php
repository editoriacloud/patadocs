<?php
/** Admin: all documents — search, filter, sort, bulk actions, publish/unpublish/archive/delete/duplicate/feature. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('documents.view');

// ---- Bulk actions (plain form post) -------------------------------------------------------
if (is_post()) {
    csrf_check();
    if (!admin_can('documents.edit')) { abort_page(403, 'Access denied', 'You cannot change documents.'); }
    $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? []))); $bulk = post_str('bulk', 12); $n = 0;
    foreach ($ids as $i) {
        if ($bulk === 'delete') { if (admin_can('documents.delete') && doc_delete($i)['ok']) { $n++; } }
        elseif (in_array($bulk, ['published', 'draft', 'archived'], true)) { if (doc_set_status($i, $bulk)['ok']) { $n++; } }
        elseif ($bulk === 'feature' || $bulk === 'unfeature') { db_exec('UPDATE documents SET featured = ? WHERE id = ?', [$bulk === 'feature' ? 1 : 0, $i]); $n++; }
    }
    flash($n ? 'success' : 'warn', $n . ' of ' . count($ids) . ' document(s) updated.');
    redirect(url('admin/documents.php?' . http_build_query(array_filter(['q' => post_str('q', 100), 'status' => post_str('fstatus', 20), 'cat' => post_int('fcat')]))));
}

$q = get_str('q', 100); $status = get_str('status', 20); $cat = get_int('cat'); $price = get_str('price', 5); $src = get_str('source', 10);
$sort = get_str('sort', 12); $page = max(1, get_int('page', 1));
$where = ['1=1']; $p = [];
if ($q !== '') { $where[] = '(d.title LIKE ? OR d.slug LIKE ? OR d.search_text LIKE ?)'; $like = '%' . like_escape($q) . '%'; array_push($p, $like, $like, $like); }
if (in_array($status, ['draft', 'pending_review', 'published', 'archived', 'rejected'], true)) { $where[] = 'd.status = ?'; $p[] = $status; }
if ($cat) { $ids = cat_descendant_ids($cat); $where[] = $ids ? 'd.category_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '0=1'; }
if ($price === 'free') { $where[] = 'd.is_free = 1'; } elseif ($price === 'paid') { $where[] = 'd.is_free = 0'; }
if (in_array($src, ['admin', 'community'], true)) { $where[] = 'd.source = ?'; $p[] = $src; }
$orders = ['new' => 'd.id DESC', 'title' => 'd.title ASC', 'views' => 'd.view_count DESC', 'downloads' => '(d.free_downloads + d.paid_downloads) DESC', 'price' => 'd.price DESC', 'updated' => 'd.updated_at DESC'];
$orderSql = $orders[$sort] ?? $orders['new'];
$total = (int)db_val('SELECT COUNT(*) FROM documents d WHERE ' . implode(' AND ', $where), $p);
$pg = paginate($total, $page, 25);
$rows = db_all('SELECT d.*, c.name AS category_name FROM documents d LEFT JOIN categories c ON c.id = d.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderSql . ' LIMIT ' . (int)$pg['offset'] . ', 25', $p);
$counts = []; foreach (db_all('SELECT status, COUNT(*) n FROM documents GROUP BY status') as $r) { $counts[$r['status']] = (int)$r['n']; }

$adm = ['title' => 'Documents', 'active' => 'documents'];
include __DIR__ . '/../includes/admin_header.php';
$qs = array_filter(['q' => $q, 'status' => $status, 'cat' => $cat ?: '', 'price' => $price, 'source' => $src, 'sort' => $sort]);
?>
<div class="panel">
    <div class="panel-header">DOCUMENTS <span style="font-weight:normal; font-size:.9rem;">(<?= num($total) ?>)</span></div>
    <div class="panel-body">
        <div class="flex" style="margin-bottom:12px;">
            <?php foreach ([['', 'All'], ['published', 'Published'], ['draft', 'Draft'], ['pending_review', 'Pending'], ['archived', 'Archived'], ['rejected', 'Rejected']] as $s) {
                $n = $s[0] === '' ? array_sum($counts) : ($counts[$s[0]] ?? 0);
                echo '<a class="btn-classic btn-sm' . ($status === $s[0] ? ' active-toggle' : '') . '" href="?' . e(http_build_query(array_filter(['status' => $s[0], 'q' => $q]))) . '">' . e($s[1]) . ' (' . $n . ')</a>'; } ?>
            <?php if (admin_can('documents.edit')) { echo '<a class="btn-classic btn-sm success" style="margin-left:auto;" href="upload.php">＋ UPLOAD DOCUMENT</a>'; } ?>
        </div>
        <div class="admin-bar">
            <form method="get">
                <input type="text" name="q" placeholder="Search title, slug, tags..." value="<?= e($q) ?>">
                <select name="cat" data-autosubmit><?= cat_options_html($cat, 0, false, 'All categories') ?></select>
                <select name="price" data-autosubmit><option value="">Free &amp; paid</option><option value="free"<?= $price === 'free' ? ' selected' : '' ?>>Free</option><option value="paid"<?= $price === 'paid' ? ' selected' : '' ?>>Paid</option></select>
                <select name="source" data-autosubmit><option value="">All sources</option><option value="admin"<?= $src === 'admin' ? ' selected' : '' ?>>Admin</option><option value="community"<?= $src === 'community' ? ' selected' : '' ?>>Community</option></select>
                <select name="sort" data-autosubmit><?php foreach (['new' => 'Newest', 'updated' => 'Recently updated', 'title' => 'Title A–Z', 'views' => 'Most views', 'downloads' => 'Most downloads', 'price' => 'Highest price'] as $k => $lab) { echo '<option value="' . $k . '"' . ($sort === $k ? ' selected' : '') . '>' . e($lab) . '</option>'; } ?></select>
                <input type="hidden" name="status" value="<?= e($status) ?>">
                <button class="btn-classic primary btn-sm" type="submit">FILTER</button>
            </form>
        </div>
        <form method="post" id="bulkForm">
            <?= csrf_field() ?><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="fstatus" value="<?= e($status) ?>"><input type="hidden" name="fcat" value="<?= (int)$cat ?>">
            <div class="gv-wrap"><table class="gv-table compact"><thead><tr><th><input type="checkbox" id="selectAll" aria-label="Select all"></th><th>Document</th><th>Category</th><th>Format</th><th>Price</th><th>Status</th><th>Views</th><th>Downloads</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
            <?php foreach ($rows as $d) { $u = doc_url($d); ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= (int)$d['id'] ?>"></td>
                    <td class="doc-title"><a href="edit-document.php?id=<?= (int)$d['id'] ?>"><?= e($d['title']) ?></a><?= $d['featured'] ? ' ⭐' : '' ?><?= $d['source'] === 'community' ? ' 🤝' : '' ?><span class="sub">/<?= e($d['slug']) ?></span></td>
                    <td><?= e($d['category_name'] ?? '—') ?></td>
                    <td><span class="doc-format"><?= e(doc_ext_label((string)$d['file_ext'])) ?></span></td>
                    <td class="doc-price"><?= e(price_label($d)) ?></td>
                    <td><?= status_badge($d['status']) ?><span class="sub">preview: <?= e($d['preview_status']) ?></span></td>
                    <td class="num"><?= num($d['view_count']) ?></td>
                    <td class="num"><?= num($d['free_downloads'] + $d['paid_downloads']) ?></td>
                    <td class="nowrap"><?= e(fmt_date($d['updated_at'])) ?></td>
                    <td class="actions">
                        <a class="btn-classic btn-sm primary" href="edit-document.php?id=<?= (int)$d['id'] ?>">EDIT</a>
                        <a class="btn-classic btn-sm" href="<?= e($u) ?>" target="_blank" title="Open public page">👁</a>
                        <?php if (admin_can('documents.edit')) {
                            if ($d['status'] === 'published') { echo '<button type="button" class="btn-classic btn-sm" data-act="doc_status" data-id="' . $d['id'] . '" data-value="draft" title="Unpublish">⏸</button>'; }
                            else { echo '<button type="button" class="btn-classic btn-sm success" data-act="doc_status" data-id="' . $d['id'] . '" data-value="published" title="Publish">🚀</button>'; }
                            echo '<button type="button" class="btn-classic btn-sm" data-act="doc_feature" data-id="' . $d['id'] . '" title="Toggle featured">⭐</button>';
                            echo '<button type="button" class="btn-classic btn-sm" data-act="doc_duplicate" data-id="' . $d['id'] . '" title="Duplicate" data-confirm="Duplicate this document as a draft?">⧉</button>';
                        }
                        if (admin_can('documents.delete')) { echo '<button type="button" class="btn-classic btn-sm danger" data-act="doc_delete" data-id="' . $d['id'] . '" data-confirm="Delete this document permanently?" title="Delete">🗑</button>'; } ?>
                    </td>
                </tr>
            <?php } if (!$rows) { echo '<tr><td colspan="10" class="empty-cell">No documents match. <a href="upload.php">Upload one</a>.</td></tr>'; } ?>
            </tbody></table></div>
            <?php if ($rows && admin_can('documents.edit')) { ?>
            <div class="bulk-bar"><strong class="small">With selected:</strong>
                <select name="bulk"><option value="published">Publish</option><option value="draft">Unpublish (draft)</option><option value="archived">Archive</option><option value="feature">Mark featured</option><option value="unfeature">Remove featured</option><?php if (admin_can('documents.delete')) { echo '<option value="delete">Delete</option>'; } ?></select>
                <button type="submit" class="btn-classic btn-sm primary" data-confirm="Apply this action to all selected documents?">APPLY</button></div>
            <?php } ?>
        </form>
        <?= pager_html($pg, url('admin/documents.php'), $qs) ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
