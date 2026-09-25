<?php
/** Admin: blog media library — upload, describe (alt text), copy URL, delete unused images. */
require __DIR__ . '/../includes/init.php';
$admin = require_admin('blog.write');
$back = url('admin/media.php');

if (is_post()) {
    csrf_check();
    if (post_str('do', 10) === 'upload') {
        $files = $_FILES['files'] ?? null; $ok = 0; $errs = [];
        if ($files && is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) { continue; }
                $m = blog_media_store(['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]], post_str('alt', 255));
                if (isset($m['error'])) { $errs[] = $files['name'][$i] . ': ' . $m['error']; } else { $ok++; }
            }
        }
        if ($ok) { log_admin('media_uploaded', 'media', null, $ok . ' image(s)'); flash('success', $ok . ' image(s) uploaded.'); }
        foreach ($errs as $er) { flash('error', $er); }
    }
    redirect($back);
}
$q = get_str('q', 80);
$where = '1 = 1'; $params = [];
if ($q !== '') { $where = '(m.file_name LIKE ? OR m.alt LIKE ?)'; $params = ['%' . like_escape($q) . '%', '%' . like_escape($q) . '%']; }
$total = (int)db_val("SELECT COUNT(*) FROM blog_media m WHERE $where", $params);
$pg = paginate($total, get_int('page', 1), 48);
$rows = db_all("SELECT m.* FROM blog_media m WHERE $where ORDER BY m.id DESC LIMIT " . (int)$pg['offset'] . ', 48', $params);
$diskUse = (int)db_val('SELECT COALESCE(SUM(size), 0) FROM blog_media');
$adm = ['title' => 'Media library', 'active' => 'media', 'foot_extra' => '<script>window.PD_BLOG_AJAX = ' . json_encode(url('ajax/blog-admin.php'), JSON_UNESCAPED_SLASHES) . ';</script><script src="' . e(asset('js/blog-media.js')) . '"></script>'];
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="panel"><div class="panel-header orange">UPLOAD IMAGES</div><div class="panel-body">
    <form method="post" enctype="multipart/form-data" class="pd-form flex" style="gap:8px; flex-wrap:wrap; align-items:flex-end;"><?= csrf_field() ?><input type="hidden" name="do" value="upload">
        <div class="frow" style="margin:0;"><label for="mFiles">Images (JPG, PNG, WebP ≤ <?= (int)setting('blog_image_mb', '5') ?> MB each)</label><input type="file" id="mFiles" name="files[]" accept=".jpg,.jpeg,.png,.webp" multiple required></div>
        <div class="frow" style="margin:0; flex:1; min-width:200px;"><label for="mAlt">Alt text (optional, applied to all)</label><input type="text" id="mAlt" name="alt" maxlength="255"></div>
        <button class="btn-classic success" type="submit">⬆ UPLOAD</button>
    </form>
    <p class="help">Images are re-saved on upload (removes hidden data and camera location), scaled to at most 1600 px wide, and get a 640 px copy for fast lists.</p>
</div></div>
<div class="panel"><div class="panel-header">MEDIA LIBRARY — <?= num($total) ?> IMAGES · <?= e(fmt_size($diskUse)) ?></div><div class="panel-body">
    <form method="get" class="flex" style="gap:6px; margin-bottom:10px;"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search file name or alt text…" aria-label="Search images" style="max-width:300px;"><button class="btn-classic btn-sm primary">SEARCH</button></form>
    <div class="media-grid media-grid-admin">
    <?php foreach ($rows as $m) { $u = url($m['file_path']); ?>
        <div class="media-item" data-id="<?= (int)$m['id'] ?>">
            <a href="<?= e($u) ?>" target="_blank" rel="noopener"><img src="<?= e(url($m['thumb_path'] ?: $m['file_path'])) ?>" alt="<?= e((string)$m['alt']) ?>" loading="lazy"></a>
            <span class="small"><?= e($m['file_name']) ?> · <?= (int)$m['width'] ?>×<?= (int)$m['height'] ?> · <?= e(fmt_size((int)$m['size'])) ?></span>
            <input type="text" class="media-alt" value="<?= e((string)$m['alt']) ?>" maxlength="255" placeholder="Alt text (describe the image)" aria-label="Alt text for <?= e($m['file_name']) ?>">
            <div class="flex" style="gap:4px;"><button type="button" class="btn-classic btn-sm" data-copy="<?= e($u) ?>">Copy URL</button><?php if (admin_can('blog.publish')) { ?><button type="button" class="btn-classic btn-sm danger" data-media-del>🗑</button><?php } ?></div>
        </div>
    <?php } if (!$rows) { echo '<p class="muted">No images yet.</p>'; } ?>
    </div>
    <?= pager_html($pg, $back, array_filter(['q' => $q])) ?>
</div></div>
<?php include __DIR__ . '/../includes/admin_footer.php'; ?>
