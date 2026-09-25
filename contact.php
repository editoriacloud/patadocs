<?php
/** PATADOCS — Contact page. */
require __DIR__ . '/includes/init.php';
$meta = [ 'no_ads' => true,
    'title' => 'Contact Us', 'nav' => '', 'canonical' => page_url('contact'),
    'description' => 'Contact ' . setting('site_name') . ' for support, copyright concerns, partnerships or feedback.',
    'schema' => [['@context' => 'https://schema.org', '@type' => 'ContactPage', 'name' => 'Contact ' . setting('site_name'), 'url' => page_url('contact')], breadcrumb_schema([['Home', url('')], ['Contact', null]])],
];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-contact">
    <div class="panel">
        <div class="panel-header">CONTACT US</div>
        <div class="panel-body">
            <div class="grid-2">
                <div>
                    <p class="desc-text">Questions, feedback or a copyright concern? Send us a message and we will get back to you.</p>
                    <div class="result-area" style="margin-top:14px;">
                        <?php if (setting('site_email')) { echo '<div class="result-row"><strong>✉ Email:</strong> <a href="mailto:' . e(setting('site_email')) . '">' . e(setting('site_email')) . '</a></div>'; } ?>
                        <?php if (setting('site_phone')) { echo '<div class="result-row"><strong>📞 Phone:</strong> ' . e(setting('site_phone')) . '</div>'; } ?>
                        <div class="result-row"><strong>🧾 Paid but no download?</strong> <a href="<?= e(page_url('recover')) ?>">Recover your purchase</a></div>
                        <div class="result-row"><strong>🚩 Report a document?</strong> Use the <em>Report</em> button on the document page.</div>
                    </div>
                </div>
                <form class="pd-form" method="post" action="<?= e(url('ajax/public.php')) ?>" data-ajax data-reset data-inline>
                    <?= csrf_field() ?><input type="hidden" name="action" value="contact"><input type="hidden" name="return" value="<?= e(page_url('contact')) ?>">
                    <div class="hp-field" aria-hidden="true"><label>Leave this empty <input type="text" name="hp" tabindex="-1" autocomplete="off"></label></div>
                    <div class="form-grid">
                        <div class="frow full"><label for="mName">Your name</label><input type="text" id="mName" name="name" maxlength="120" required autocomplete="name"></div>
                        <div class="frow full"><label for="mEmail">Your email</label><input type="email" id="mEmail" name="email" maxlength="190" required autocomplete="email"></div>
                        <div class="frow full"><label for="mMsg">Message</label><textarea id="mMsg" name="message" maxlength="3000" required></textarea></div>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn-classic primary">SEND MESSAGE</button></div>
                    <div id="formResult" style="margin-top:14px;"></div>
                </form>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
