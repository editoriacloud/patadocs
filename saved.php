<?php
/** PATADOCS — Saved documents. Stored in the visitor's browser (localStorage) — no account needed. */
require __DIR__ . '/includes/init.php';
$meta = ['title' => 'Saved Documents', 'nav' => '', 'robots' => 'noindex,follow', 'canonical' => page_url('saved'), 'description' => 'Your saved documents.'];
include __DIR__ . '/includes/header.php';
?>
<section class="page-section active" id="page-saved">
    <div class="panel">
        <div class="panel-header">★ SAVED DOCUMENTS</div>
        <div class="panel-body">
            <p class="help" style="margin-bottom:14px;">Saved documents are kept in <strong>this browser only</strong> — no account needed. Clearing your browser data removes them.</p>
            <div id="savedList"><noscript><div class="alert alert-warn">Please enable JavaScript to see your saved documents.</div></noscript></div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
