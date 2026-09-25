<?php
/**
 * Admin document form — the 9-step wizard used by upload.php (new) and edit-document.php (existing).
 * Expects: $v (field values), $doc (row or null), $formErrors (array), $formAction (url), $isNew (bool).
 * Steps: 1 Upload File · 2 Basic Info · 3 Category · 4 Metadata · 5 Tags · 6 Pricing · 7 Preview · 8 SEO · 9 Publish
 */
$catId = (int)($v['category'] ?? 0);
$metaVals = (array)($v['meta'] ?? []);
foreach ($metaVals as $k => $x) { if (is_array($x)) { $metaVals[$k] = '|' . implode('|', $x) . '|'; } }
$stepNames = ['Upload File', 'Basic Info', 'Category', 'Metadata', 'Tags', 'Pricing', 'Preview', 'SEO', 'Publish'];
$stepKeys = ['file', 'basic', 'category', 'metadata', 'tags', 'pricing', 'preview', 'seo', 'publish'];
$maxMb = (int)floor(max_upload_bytes('max_file_mb') / 1048576);
?>
<?php foreach ($formErrors as $er) { echo '<div class="alert alert-error">' . e($er) . '</div>'; } ?>
<form id="docForm" class="pd-form" method="post" action="<?= e($formAction) ?>" enctype="multipart/form-data"
      data-doc="<?= (int)($doc['id'] ?? 0) ?>" data-site="<?= e(rtrim(url(''), '/')) ?>" data-start="<?= (int)($_POST['_step'] ?? 0) ?>" <?= $catId ? 'data-meta-loaded="1"' : '' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="_step" id="stepField" value="0">
    <?php if (!empty($v['request_id'])) { echo '<input type="hidden" name="request_id" value="' . (int)$v['request_id'] . '">'; } ?>

    <div class="stepper" id="docStepper">
        <?php foreach ($stepNames as $i => $n) { echo '<button type="button" data-step="' . $i . '"><span class="n">' . ($i + 1) . '</span>' . e($n) . '</button>'; } ?>
    </div>

    <!-- 1 · UPLOAD FILE -->
    <div class="step-pane" data-step-name="file">
        <div class="step-title">1 · Upload the file</div>
        <p class="help" style="margin-bottom:12px;">The original is stored privately (never in a public folder) and is only delivered through secure, time-limited download links.</p>
        <div class="frow"><label for="docFile">Document file <?= $isNew ? '<span class="req">*</span>' : '(leave empty to keep the current file)' ?></label>
            <input type="file" id="docFile" name="doc_file" <?= $isNew ? 'required' : '' ?> accept=".<?= e(implode(',.', allowed_exts())) ?>">
            <span class="help">Allowed: <?= e(strtoupper(implode(', ', allowed_exts()))) ?> · Max <?= $maxMb ?> MB · <span id="docFileInfo"></span></span></div>
        <?php if ($doc && $doc['file_name']) { ?>
            <div class="result-area" style="margin-top:14px;">
                <div class="result-row"><strong>Current file:</strong> <?= e($doc['original_name'] ?: $doc['file_name']) ?> · <?= e(strtoupper($doc['file_ext'])) ?> · <?= e(fmt_size($doc['file_size'])) ?><?= $doc['pages'] ? ' · ' . (int)$doc['pages'] . ' pages' : '' ?>
                    <a class="btn-classic btn-sm" style="margin-left:auto;" href="<?= e(url('admin/file.php?type=doc&id=' . (int)$doc['id'])) ?>" target="_blank">⬇ OPEN ORIGINAL</a></div>
            </div>
        <?php } ?>
        <div class="step-nav"><span></span><button type="button" class="btn-classic primary" data-next>NEXT ▶</button></div>
    </div>

    <!-- 2 · BASIC INFORMATION -->
    <div class="step-pane" data-step-name="basic">
        <div class="step-title">2 · Basic information</div>
        <div class="form-grid">
            <div class="frow full"><label for="docTitle">Title <span class="req">*</span></label><input type="text" id="docTitle" name="title" maxlength="255" required value="<?= e($v['title'] ?? '') ?>" placeholder="e.g. Grade 7 Mathematics Scheme of Work Term 1 2026"></div>
            <div class="frow full"><label for="docSlug">URL slug</label><input type="text" id="docSlug" name="slug" maxlength="120" value="<?= e($v['slug'] ?? '') ?>" placeholder="auto-generated from the title"><span class="help">Part of the page address. Lowercase letters, numbers and dashes.</span></div>
            <div class="frow full"><label for="docDesc">Description</label><textarea id="docDesc" name="description" style="min-height:160px;" placeholder="Explain what is inside, who it is for, term/year/curriculum... (shown on the document page and used by Google)"><?= e($v['description'] ?? '') ?></textarea></div>
            <div class="frow"><label for="docType">Document type</label><input type="text" id="docType" name="doc_type" list="docTypes" maxlength="80" value="<?= e($v['doc_type'] ?? '') ?>" placeholder="Scheme of Work, Business Plan, CV Template...">
                <datalist id="docTypes"><?php foreach (db_all("SELECT DISTINCT doc_type FROM documents WHERE doc_type IS NOT NULL AND doc_type <> '' ORDER BY doc_type LIMIT 50") as $t) { echo '<option value="' . e($t['doc_type']) . '">'; } ?></datalist></div>
            <div class="frow"><label for="docAuthor">Author / source</label><input type="text" id="docAuthor" name="author" maxlength="190" value="<?= e($v['author'] ?? '') ?>"></div>
            <div class="frow"><label for="docPages">Pages</label><input type="number" id="docPages" name="pages" min="1" max="9999" value="<?= e($v['pages'] ?? '') ?>" placeholder="auto-detected"><span class="help">Detected automatically for PDF, DOCX and images.</span></div>
        </div>
        <div class="step-nav"><button type="button" class="btn-classic" data-prev>◀ BACK</button><button type="button" class="btn-classic primary" data-next>NEXT ▶</button></div>
    </div>

    <!-- 3 · CATEGORY -->
    <div class="step-pane" data-step-name="category">
        <div class="step-title">3 · Category</div>
        <div class="frow" style="max-width:640px;"><label for="docCat">Category / sub-category</label>
            <select id="docCat" name="category"><?= cat_options_html($catId, 0, false, '— Select a category —') ?></select>
            <span class="help">Pick the most specific category. Metadata fields (grade, subject, year...) depend on it. <a href="categories.php" target="_blank">Manage categories</a></span></div>
        <div class="step-nav"><button type="button" class="btn-classic" data-prev>◀ BACK</button><button type="button" class="btn-classic primary" data-next>NEXT ▶</button></div>
    </div>

    <!-- 4 · METADATA -->
    <div class="step-pane" data-step-name="metadata">
        <div class="step-title">4 · Metadata</div>
        <p class="help" style="margin-bottom:12px;">Fields come from the category you chose (Admin → Metadata Fields). Searchable and filterable fields power the search filters.</p>
        <div id="metaFields"><?= $catId ? meta_fields_html(meta_fields_for($catId), $metaVals) : '<div class="help">Choose a category first.</div>' ?></div>
        <div class="step-nav"><button type="button" class="btn-classic" data-prev>◀ BACK</button><button type="button" class="btn-classic primary" data-next>NEXT ▶</button></div>
    </div>

    <!-- 5 · TAGS -->
    <div class="step-pane" data-step-name="tags">
        <div class="step-title">5 · Tags</div>
        <div class="frow" style="max-width:720px;"><label for="docTags">Tags</label><input type="text" id="docTags" name="tags" maxlength="600" value="<?= e($v['tags'] ?? '') ?>" placeholder="maths, scheme of work, term 1, cbc">
            <span class="help">Separate with commas (max 15). Tags improve search and "related documents".</span></div>
        <div class="step-nav"><button type="button" class="btn-classic" data-prev>◀ BACK</button><button type="button" class="btn-classic primary" data-next>NEXT ▶</button></div>
    </div>

    <!-- 6 · PRICING -->
    <div class="step-pane" data-step-name="pricing">
        <div class="step-title">6 · Pricing &amp; access</div>
        <div class="form-grid">
            <div class="frow"><span class="flabel">Access</span>
                <div class="chk-group">
                    <label class="chk"><input type="radio" name="is_free" value="1" <?= ((int)($v['is_free'] ?? 1) === 1) ? 'checked' : '' ?>><span>FREE download</span></label>
                    <label class="chk"><input type="radio" name="is_free" value="0" <?= ((int)($v['is_free'] ?? 1) === 0) ? 'checked' : '' ?>><span>PAID (M-Pesa)</span></label>
                </div></div>
            <div class="frow" id="priceBox"><label for="docPrice">Price (<?= e(setting('currency', 'KES')) ?>)</label><input type="number" id="docPrice" name="price" min="0" step="1" value="<?= e(($v['price'] ?? '') !== '' && (float)($v['price'] ?? 0) > 0 ? (float)$v['price'] : '') ?>" placeholder="e.g. 50"></div>
            <div class="frow"><label for="docDl">Download limit per purchase (optional)</label><input type="number" id="docDl" name="download_limit" min="0" max="100" value="<?= e($v['download_limit'] ?? '') ?>" placeholder="default: <?= (int)setting('default_download_limit', 3) ?> (0 = unlimited)"></div>
            <div class="frow"><span class="flabel">Homepage</span>
                <label class="chk"><input type="checkbox" name="featured" value="1" <?= !empty($v['featured']) ? 'checked' : '' ?>><span>⭐ Featured document</span></label>
                <label class="chk"><input type="checkbox" name="popular" value="1" <?= !empty($v['popular']) ? 'checked' : '' ?>><span>🔥 Pin as popular</span></label></div>
        </div>
        <div class="step-nav"><button type="button" class="btn-classic" data-prev>◀ BACK</button><button type="button" class="btn-classic primary" data-next>NEXT ▶</button></div>
    </div>

    <!-- 7 · PREVIEW -->
    <div class="step-pane" data-step-name="preview">
        <div class="step-title">7 · Protected preview</div>
        <p class="help" style="margin-bottom:12px;">Visitors only ever see a separate, watermarked, low-quality preview of the first pages — never the original. Watermark text, opacity, quality and default pages are in <a href="settings.php#tab-preview" target="_blank">Settings → Preview</a>.</p>
        <?php if ($doc) { ?><div class="alert alert-info">Current preview: <?= status_badge($doc['preview_status']) ?> <?= (int)$doc['preview_pages'] ? '· ' . (int)$doc['preview_pages'] . ' page(s)' : '' ?>
            <?php if ($doc['preview_status'] === 'ready') { $pu = doc_preview_urls($doc); echo ' · <a href="' . e($pu[0]) . '" target="_blank">open page 1</a>'; } ?></div><?php } ?>
        <div class="form-grid">
            <div class="frow"><span class="flabel">Automatic preview</span>
                <label class="chk"><input type="checkbox" name="gen_preview" value="1" <?= ($isNew || ($doc && $doc['preview_status'] !== 'ready')) ? 'checked' : '' ?>><span>Generate the protected preview when I save<?= $doc ? ' (regenerates the existing one)' : '' ?></span></label></div>
            <div class="frow"><label for="docPl">Preview pages (this document)</label><input type="number" id="docPl" name="preview_limit" min="1" max="20" value="<?= e($v['preview_limit'] ?? '') ?>" placeholder="default: <?= (int)setting('default_preview_pages', 3) ?>"></div>
            <div class="frow full"><label for="docPrevImgs">…or upload preview image(s) manually</label><input type="file" id="docPrevImgs" name="preview_images[]" multiple accept=".jpg,.jpeg,.png,.webp"><span class="help">Use this when the server cannot render the file automatically. Images are resized and watermarked the same way.</span></div>
        </div>
        <div class="step-nav"><button type="button" class="btn-classic" data-prev>◀ BACK</button><button type="button" class="btn-classic primary" data-next>NEXT ▶</button></div>
    </div>

    <!-- 8 · SEO -->
    <div class="step-pane" data-step-name="seo">
        <div class="step-title">8 · SEO</div>
        <div class="flex" style="margin-bottom:12px;"><button type="button" class="btn-classic warning" id="seoSuggest">✨ SUGGEST SEO FROM MY DATA</button><span class="help">Fills the title, description and keywords — you can edit everything.</span></div>
        <div class="form-grid">
            <div class="frow full"><label for="seoTitle">SEO title (≈ 60 characters)</label><input type="text" id="seoTitle" name="seo_title" maxlength="190" value="<?= e($v['seo_title'] ?? '') ?>"></div>
            <div class="frow full"><label for="seoDesc">Meta description (≈ 155 characters)</label><textarea id="seoDesc" name="meta_description" maxlength="320" style="min-height:80px;"><?= e($v['meta_description'] ?? '') ?></textarea></div>
            <div class="frow full"><label for="seoKeywords">Keywords</label><input type="text" id="seoKeywords" name="seo_keywords" maxlength="400" value="<?= e($v['seo_keywords'] ?? '') ?>" placeholder="grade 7 mathematics, scheme of work, term 1, kenya"></div>
        </div>
        <div class="section-title">GOOGLE PREVIEW</div>
        <div class="seo-preview"><div class="t" id="serpTitle"></div><div class="u" id="serpUrl"></div><div class="d" id="serpDesc"></div></div>
        <div class="step-nav"><button type="button" class="btn-classic" data-prev>◀ BACK</button><button type="button" class="btn-classic primary" data-next>NEXT ▶</button></div>
    </div>

    <!-- 9 · PUBLISH -->
    <div class="step-pane" data-step-name="publish">
        <div class="step-title">9 · Publish</div>
        <?php if ($doc && $doc['source'] === 'community') { ?>
            <div class="result-area" style="margin-bottom:14px;"><strong>🤝 Community contribution — attribution</strong>
                <label class="chk" style="margin-top:8px;"><input type="checkbox" name="show_contributor" value="1" <?= !empty($v['show_contributor']) ? 'checked' : '' ?>><span>Show “Contributed by <?= e($doc['contributor_name']) ?>” on the document page</span></label>
                <div class="frow" style="max-width:320px; margin-top:8px;"><label for="docBadge">Badge</label><select id="docBadge" name="contributor_badge"><?php foreach (['none' => 'No badge', 'community' => 'Community Contributor', 'verified' => 'Verified Contributor'] as $k => $lab) { echo '<option value="' . $k . '"' . (($v['contributor_badge'] ?? 'none') === $k ? ' selected' : '') . '>' . e($lab) . '</option>'; } ?></select></div></div>
        <?php } ?>
        <p class="desc-text">Review your entries with the step buttons above, then choose what to do. Community and public visitors only see <strong>published</strong> documents. Drafts are visible to admins only.</p>
        <?php if ($doc) { echo '<p class="help">Current status: ' . status_badge($doc['status']) . ($doc['published_at'] ? ' · first published ' . e(fmt_date($doc['published_at'])) : '') . '</p>'; } ?>
        <div class="step-nav"><button type="button" class="btn-classic" data-prev>◀ BACK</button><span></span></div>
    </div>

    <!-- Action bar (visible on every step: Save Draft · Preview · Publish) -->
    <div class="form-actions" style="border-top:2px solid var(--border); padding-top:14px;">
        <?php if ($isNew) { ?>
            <button type="submit" name="intent" value="draft" class="btn-classic">💾 SAVE DRAFT</button>
            <button type="submit" name="intent" value="preview" class="btn-classic warning">👁 SAVE &amp; PREVIEW</button>
            <button type="submit" name="intent" value="publish" class="btn-classic success">🚀 PUBLISH</button>
        <?php } else { ?>
            <button type="submit" name="intent" value="save" class="btn-classic primary">💾 SAVE CHANGES</button>
            <button type="submit" name="intent" value="preview" class="btn-classic warning">👁 SAVE &amp; PREVIEW</button>
            <?php if ($doc['status'] !== 'published') { echo '<button type="submit" name="intent" value="publish" class="btn-classic success">🚀 PUBLISH</button>'; } else { echo '<button type="submit" name="intent" value="draft" class="btn-classic" data-confirm="Unpublish this document and move it back to draft?">⏸ UNPUBLISH</button>'; } ?>
        <?php } ?>
        <a class="btn-classic" href="documents.php">CANCEL</a>
    </div>
</form>
