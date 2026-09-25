<?php
/** Public page footer: footer bar (design), shared modal, JS config. */
$footerLinks = [
    ['Contribute a resource', page_url('contribute')], ['Recover purchase', page_url('recover')],
    ['Saved documents', page_url('saved')], ['Request a document', page_url('request-document')],
    ['Contact', page_url('contact')], ['About', page_url('about')],
    ['Privacy Policy', page_url('privacy-policy')], ['Terms', page_url('terms')], ['Cookies', page_url('cookie-policy')],
    ['Copyright', page_url('copyright')], ['Disclaimer', page_url('disclaimer')],
];
if (blog_enabled()) { array_splice($footerLinks, 5, 0, [[setting('blog_title', 'Blog'), blog_url()]]); }
?>
<?= ad_slot('footer', $meta) ?>
</div><!-- /.aspx-content -->

<!-- ===== FOOTER ===== -->
<div class="footer-links">
    <?php foreach ($footerLinks as $i => $l) { echo ($i ? '<span class="sep">|</span>' : '') . '<a href="' . e($l[1]) . '">' . e($l[0]) . '</a>'; } ?>
</div>
<div class="footer-bar"><?= e(setting('footer_text')) ?></div>
</div><!-- /#form1 -->

<!-- ===== MODAL (same markup as the design) ===== -->
<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box" id="modalBox" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-header" id="modalTitle">DOCUMENT</div>
        <div class="modal-body" id="modalBody">
            <div id="modalDynamicContent"></div>
            <div class="modal-section" id="modalTotalSection" style="border-bottom:none; margin-top:10px; padding-top:10px; border-top:2px solid var(--border); display:none;">
                <div class="modal-row"><label>TOTAL:</label><span class="modal-total-display" id="modalTotalDisplay">KSh 0</span></div>
            </div>
        </div>
        <div class="modal-actions" id="modalActions">
            <button type="button" class="modal-btn" id="modalCloseBtn">CLOSE</button>
            <button type="button" class="modal-btn modal-btn-primary" id="modalActionBtn">OK</button>
        </div>
    </div>
</div>

<script>
window.PD = {
    base: <?= json_encode(url(''), JSON_UNESCAPED_SLASHES) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    clean: <?= json_encode(setting('clean_urls', '1') === '1') ?>,
    currency: <?= json_encode(setting('currency_symbol', 'KSh')) ?>,
    pages: {search: <?= json_encode(page_url('search')) ?>, request: <?= json_encode(page_url('request-document')) ?>, recover: <?= json_encode(page_url('recover')) ?>, saved: <?= json_encode(page_url('saved')) ?>}
};
</script>
<?php if (!empty($meta['doc_js'])) { echo '<script>window.PD_DOC = ' . json_encode($meta['doc_js'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';</script>' . "\n"; } ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= $meta['foot_extra'] ?? '' ?>
</body>
</html>
