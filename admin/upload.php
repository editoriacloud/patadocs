<?php
/** Admin: upload a new document (9-step wizard: file → basics → category → metadata → tags → pricing → preview → SEO → publish). */
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/admin_lib.php';
$admin = require_admin('documents.edit');

$formErrors = [];
$v = ['title' => get_str('title', 255), 'is_free' => 1, 'request_id' => get_int('request')];
if (is_post()) {
    csrf_check();
    $v = $_POST + $v;
    $intent = post_str('intent', 10);
    $r = doc_save($_POST, $_FILES, 0, $intent === 'publish' ? 'publish' : 'draft');
    if ($r['id']) {
        if (post_int('request_id') > 0) { db_exec("UPDATE document_requests SET status = 'created', document_id = ? WHERE id = ?", [$r['id'], post_int('request_id')]); }
        foreach ($r['notes'] as $n) { flash('info', $n); }
        if (!$r['ok']) { foreach ($r['errors'] as $er) { flash('error', $er); } }
        else { flash('success', $intent === 'publish' ? 'Document published.' : 'Draft saved.'); }
        if ($intent === 'preview' && $r['ok']) { $d = doc_get($r['id']); redirect(doc_url($d)); }
        redirect(url('admin/edit-document.php?id=' . $r['id']));
    }
    $formErrors = array_merge($r['errors'], ['If you had chosen a file, please select it again — browsers do not keep file selections after an error.']);
}
$adm = ['title' => 'Upload Document', 'active' => 'upload'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel">
    <div class="panel-header green">UPLOAD DOCUMENT</div>
    <div class="panel-body">
        <?php $doc = null; $isNew = true; $formAction = url('admin/upload.php'); include __DIR__ . '/../includes/admin_docform.php'; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
