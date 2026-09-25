<?php
/** Admin: edit a document — same wizard as upload, plus analytics, file replacement and regenerate-preview. */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('documents.edit');

$id = get_int('id');
$doc = doc_get($id);
if (!$doc) { abort_page(404, 'Document not found', 'This document does not exist.', [['📄 ALL DOCUMENTS', url('admin/documents.php')]]); }

$formErrors = [];
if (is_post()) {
    csrf_check();
    $intent = post_str('intent', 10);
    $r = doc_save($_POST, $_FILES, $id, in_array($intent, ['draft', 'publish'], true) ? $intent : 'save');
    foreach ($r['notes'] as $n) { flash('info', $n); }
    if ($r['ok']) {
        flash('success', $intent === 'publish' ? 'Document published.' : ($intent === 'draft' ? 'Document moved to draft.' : 'Changes saved.'));
        if ($intent === 'preview') { redirect(doc_url(doc_get($id))); }
        redirect(url('admin/edit-document.php?id=' . $id));
    }
    $formErrors = $r['errors'];
    $doc = doc_get($id);
}
// Field values: posted (after an error) or from the database
if (is_post() && $formErrors) { $v = $_POST + ['is_free' => 1]; }
else {
    $v = ['title' => $doc['title'], 'slug' => $doc['slug'], 'description' => $doc['description'], 'doc_type' => $doc['doc_type'], 'author' => $doc['author'], 'pages' => $doc['pages'],
        'category' => $doc['category_id'], 'tags' => implode(', ', array_column(tags_get($id), 'name')), 'is_free' => $doc['is_free'], 'price' => $doc['price'],
        'download_limit' => $doc['download_limit'], 'preview_limit' => $doc['preview_limit'], 'featured' => $doc['featured'], 'popular' => $doc['popular'],
        'seo_title' => $doc['seo_title'], 'meta_description' => $doc['meta_description'], 'seo_keywords' => $doc['seo_keywords'],
        'show_contributor' => $doc['show_contributor'], 'contributor_badge' => $doc['contributor_badge'], 'meta' => meta_values($id)];
}
$views = (int)$doc['view_count']; $purchases = (int)$doc['purchase_count'];
$conv = $views > 0 ? round($purchases / $views * 100, 1) : 0;
$adm = ['title' => 'Edit: ' . $doc['title'], 'active' => 'documents'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel">
    <div class="panel-header orange">EDIT DOCUMENT #<?= (int)$id ?> <?= e('· ' . strtoupper(str_replace('_', ' ', $doc['status']))) ?></div>
    <div class="panel-body">
        <div class="flex" style="margin-bottom:14px;">
            <a class="btn-classic btn-sm" href="<?= e(doc_url($doc)) ?>" target="_blank">🔗 VIEW PAGE</a>
            <button type="button" class="btn-classic btn-sm warning" data-act="doc_regen_preview" data-id="<?= $id ?>" data-reload="0">♻ REGENERATE PREVIEW</button>
            <button type="button" class="btn-classic btn-sm" data-act="doc_duplicate" data-id="<?= $id ?>" data-confirm="Duplicate this document as a new draft?">⧉ DUPLICATE</button>
            <?php if ($doc['status'] !== 'archived') { echo '<button type="button" class="btn-classic btn-sm" data-act="doc_status" data-id="' . $id . '" data-value="archived" data-confirm="Archive this document? It disappears from the site but paying customers keep access.">🗄 ARCHIVE</button>'; } ?>
            <?php if (admin_can('documents.delete')) { echo '<button type="button" class="btn-classic btn-sm danger" data-act="doc_delete" data-id="' . $id . '" data-confirm="Delete this document permanently?">🗑 DELETE</button>'; } ?>
        </div>
        <div class="grid-4" style="margin-bottom:16px;">
            <div class="stat-box"><div class="stat-label">Views</div><div class="stat-value"><?= num($views) ?></div></div>
            <div class="stat-box"><div class="stat-label">Preview views</div><div class="stat-value"><?= num($doc['preview_count']) ?></div></div>
            <div class="stat-box"><div class="stat-label">Purchases</div><div class="stat-value"><?= num($purchases) ?></div><div class="stat-sub">conversion <?= $conv ?>%</div></div>
            <div class="stat-box"><div class="stat-label">Revenue</div><div class="stat-value green" style="font-size:1.5rem;"><?= e(money($doc['revenue'])) ?></div></div>
            <div class="stat-box"><div class="stat-label">Free downloads</div><div class="stat-value"><?= num($doc['free_downloads']) ?></div></div>
            <div class="stat-box"><div class="stat-label">Paid downloads</div><div class="stat-value"><?= num($doc['paid_downloads']) ?></div></div>
            <div class="stat-box"><div class="stat-label">Created</div><div class="stat-value" style="font-size:1.1rem;"><?= e(fmt_date($doc['created_at'])) ?></div></div>
            <div class="stat-box"><div class="stat-label">Source</div><div class="stat-value" style="font-size:1.1rem;"><?= e(ucfirst($doc['source'])) ?></div></div>
        </div>
        <?php $isNew = false; $formAction = url('admin/edit-document.php?id=' . $id); include __DIR__ . '/../includes/admin_docform.php'; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
