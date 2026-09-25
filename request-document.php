<?php
/** PATADOCS — Request a document (form from the design; submitted with AJAX to ajax/public.php). */
require __DIR__ . '/includes/init.php';
$q = get_str('q', 200);
$meta = [
    'title' => 'Request a Document', 'nav' => 'request', 'canonical' => page_url('request-document'),
    'description' => "Can't find the document you need? Request it and our team will source it for you.",
    'schema' => [breadcrumb_schema([['Home', url('')], ['Request a document', null]])],
];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-request">
    <div class="panel">
        <div class="panel-header">REQUEST A DOCUMENT</div>
        <div class="panel-body">
            <?php if (setting('requests_enabled', '1') !== '1') { echo '<div class="alert alert-info">Document requests are temporarily closed. Please check back soon.</div>'; } else { ?>
            <form class="pd-form" method="post" action="<?= e(url('ajax/public.php')) ?>" data-ajax data-reset data-inline>
                <?= csrf_field() ?><input type="hidden" name="action" value="request"><input type="hidden" name="return" value="<?= e(page_url('request-document')) ?>">
                <div class="hp-field" aria-hidden="true"><label>Leave this empty <input type="text" name="hp" tabindex="-1" autocomplete="off"></label></div>
                <div class="result-area" style="max-width:680px;">
                    <div style="font-weight:bold; font-family:Tahoma, sans-serif; font-size:1rem; color:#000080; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:12px;">Can't find a document? Request it.</div>
                    <div class="result-row"><label for="reqTitle">Document:</label><input type="text" id="reqTitle" name="title" maxlength="200" required placeholder="e.g. Grade 7 German Notes" value="<?= e($q) ?>" style="width:100%;"></div>
                    <div class="result-row"><label for="reqCat">Category:</label><select id="reqCat" name="category"><?= cat_options_html(0, 0, true, 'Other / not sure') ?></select></div>
                    <div class="result-row"><label for="reqDesc">Additional:</label><textarea id="reqDesc" name="description" maxlength="1000" placeholder="Any details that help us find it (grade, term, year, curriculum...)" style="min-height:80px;"></textarea></div>
                    <div class="result-row"><label for="reqPhone">Phone:</label><input type="text" id="reqPhone" name="phone" maxlength="20" placeholder="07XXXXXXXX (optional)" style="width:100%;"></div>
                    <div class="result-row"><label for="reqEmail">Email:</label><input type="email" id="reqEmail" name="email" maxlength="190" placeholder="optional — so we can tell you when it is ready" style="width:100%;"></div>
                    <button type="submit" class="btn-classic primary mt-2">SUBMIT REQUEST</button>
                </div>
                <div id="formResult" style="max-width:680px; margin-top:14px;"></div>
            </form>
            <?php } ?>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
