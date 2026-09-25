    </main>
</div><!-- /.admin-layout -->
</section>
</div><!-- /.aspx-content -->
<div class="footer-bar"><?= e(strtoupper(setting('site_name', 'PATADOCS'))) ?> ADMIN · SIGNED IN AS <?= e(strtoupper($_SESSION['admin']['username'] ?? '')) ?> (<?= e($_SESSION['admin']['role'] ?? '') ?>) · VERSION <?= e(PD_VERSION) ?></div>
</div><!-- /#form1 -->

<div class="modal-overlay" id="modalOverlay">
    <div class="modal-box" id="modalBox" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-header" id="modalTitle">NOTIFICATION</div>
        <div class="modal-body" id="modalBody">
            <div id="modalDynamicContent"></div>
            <div class="modal-section" id="modalTotalSection" style="display:none;"><div class="modal-row"><label>TOTAL:</label><span class="modal-total-display" id="modalTotalDisplay"></span></div></div>
        </div>
        <div class="modal-actions"><button type="button" class="modal-btn" id="modalCloseBtn">CLOSE</button><button type="button" class="modal-btn modal-btn-primary" id="modalActionBtn">OK</button></div>
    </div>
</div>
<script>window.PD = {base: <?= json_encode(url(''), JSON_UNESCAPED_SLASHES) ?>, csrf: <?= json_encode(csrf_token()) ?>, currency: <?= json_encode(setting('currency_symbol', 'KSh')) ?>, pages: {}};</script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if (!empty($adm['charts'])) { echo '<script src="' . e(asset('js/vendor/chart.umd.min.js')) . '"></script>' . "\n"; } ?>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
<?= $adm['foot_extra'] ?? '' ?>
</body>
</html>
