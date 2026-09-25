<?php
/** PATADOCS — Contribute a resource (free community resources; no account; every submission is reviewed). */
require __DIR__ . '/includes/init.php';
$maxMb = max(1, (int)floor(max_upload_bytes('contrib_max_mb') / 1048576));
$exts = strtoupper(implode(', ', allowed_exts()));
$meta = [
    'title' => 'Contribute a Resource', 'nav' => 'contribute', 'canonical' => page_url('contribute'),
    'description' => 'Share useful resources with the community. Upload a document for free and help more people find valuable documents.',
    'schema' => [breadcrumb_schema([['Home', url('')], ['Contribute a resource', null]])],
];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-contribute">
    <div class="cta-banner" style="margin-bottom:18px;">
        <h3>🤝 Contribute a Resource</h3>
        <p>Share useful resources with the community. Help more people find valuable documents. Upload a resource for free — no account needed.</p>
    </div>
    <div class="panel">
        <div class="panel-header green">CONTRIBUTE A RESOURCE</div>
        <div class="panel-body">
            <?php if (setting('contributions_enabled', '1') !== '1') { echo '<div class="alert alert-info">Contributions are temporarily closed. Thank you for your interest!</div>'; } else { ?>
            <div class="alert alert-info">📌 Community contributions are shared as <strong>FREE</strong> resources. Every submission is reviewed by our team before it is published, and you may be credited as the contributor if you wish.</div>
            <form class="pd-form" method="post" action="<?= e(url('ajax/contribute.php')) ?>" enctype="multipart/form-data" data-ajax data-reset data-inline>
                <?= csrf_field() ?><input type="hidden" name="ts" value="<?= time() ?>"><input type="hidden" name="return" value="<?= e(page_url('contribute')) ?>">
                <div class="hp-field" aria-hidden="true"><label>Leave this empty <input type="text" name="hp" tabindex="-1" autocomplete="off"></label></div>
                <div class="form-grid">
                    <div class="frow full"><label for="cTitle">Document title <span class="req">*</span></label><input type="text" id="cTitle" name="title" maxlength="200" required placeholder="e.g. Grade 7 Mathematics Scheme of Work Term 1 2026"></div>
                    <div class="frow full"><label for="cDesc">Description</label><textarea id="cDesc" name="description" maxlength="3000" placeholder="What is inside? Who is it for? Term, year, curriculum..."></textarea></div>
                    <div class="frow"><label for="contribCat">Category / sub-category <span class="req">*</span></label><select id="contribCat" name="category" required><?= cat_options_html(0, 0, true, '— Select a category —') ?></select></div>
                    <div class="frow"><label for="cTags">Tags</label><input type="text" id="cTags" name="tags" maxlength="300" placeholder="maths, scheme of work, term 1"><span class="help">Separate tags with commas.</span></div>
                    <div class="full" id="metaFields"></div>
                    <div class="frow"><label for="cFile">Document file <span class="req">*</span></label><input type="file" id="cFile" name="file" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"><span class="help">Allowed: <?= e($exts) ?> · Max <?= (int)$maxMb ?> MB</span></div>
                    <div class="frow"><label for="cPrev">Preview image (optional)</label><input type="file" id="cPrev" name="preview" accept=".jpg,.jpeg,.png,.webp"><span class="help">A cover or first-page image (JPG, PNG or WEBP).</span></div>
                    <div class="frow"><label for="cName">Your name <span class="req">*</span></label><input type="text" id="cName" name="name" maxlength="120" required autocomplete="name"></div>
                    <div class="frow"><label for="cEmail">Your email <span class="req">*</span></label><input type="email" id="cEmail" name="email" maxlength="190" required autocomplete="email"></div>
                    <div class="frow"><label for="cPhone">Phone (optional)</label><input type="text" id="cPhone" name="phone" maxlength="20" autocomplete="tel" placeholder="07XXXXXXXX"></div>
                    <div class="frow"><label for="cSource">Source / author information</label><input type="text" id="cSource" name="source" maxlength="250" placeholder="e.g. Written by me · Based on KICD design"></div>
                    <div class="full">
                        <label class="chk"><input type="checkbox" name="own" value="1" required><span>I confirm that I own this content OR have permission to share it.</span></label>
                        <label class="chk" style="margin-top:10px;"><input type="checkbox" name="understand" value="1" required><span>I understand that copyrighted or unauthorized content may be removed.</span></label>
                        <label class="chk" style="margin-top:10px;"><input type="checkbox" name="credit" value="1" checked><span>Credit me as the contributor on the document page (optional).</span></label>
                    </div>
                </div>
                <div class="form-actions"><button type="submit" class="btn-classic success" style="font-size:1.1rem;">⬆ SUBMIT FOR REVIEW</button></div>
                <div id="formResult" style="margin-top:14px;"></div>
            </form>
            <?php } ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
