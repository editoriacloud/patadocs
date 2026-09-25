/* ============================================================
   PATADOCS — admin JavaScript (vanilla). Needs app.js (PDUI) first.
   Sidebar · confirmations · AJAX row actions · bulk select · upload stepper ·
   dynamic metadata fields · SEO suggestions · charts
   ============================================================ */
(function () {
    'use strict';
    var PD = window.PD || {}, UI = window.PDUI || {};
    function $(s, c) { return (c || document).querySelector(s); }
    function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
    function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

    /* Sidebar toggle (mobile) */
    var mt = $('#adminMenuToggle'), nav = $('#adminNav');
    if (mt && nav) { mt.addEventListener('click', function () { nav.classList.toggle('open'); }); }

    /* Confirmation dialogs: any element / form with data-confirm */
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]:not([data-act])');
        if (el && el.tagName !== 'FORM' && !window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopPropagation(); }
    }, true);
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (f.matches && f.matches('form[data-confirm]') && !window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
    /* Auto-submit selects */
    document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('select[data-autosubmit]')) { e.target.form.submit(); } });

    /* AJAX row actions: <button data-act="doc_status" data-id="12" data-value="published"> */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]');
        if (!b) { return; }
        e.preventDefault();
        var msg = b.getAttribute('data-confirm');
        if (msg && !window.confirm(msg)) { return; }
        var data = { action: b.getAttribute('data-act') };
        ['id', 'value', 'extra'].forEach(function (k) { var v = b.getAttribute('data-' + k); if (v !== null) { data[k] = v; } });
        b.disabled = true;
        UI.api(PD.base + 'ajax/admin.php', { data: data }).then(function (r) {
            b.disabled = false;
            if (r.ok) {
                if (b.getAttribute('data-reload') === '0') { UI.showMessage(r.message || 'Done.', 'DONE'); if (r.html && b.getAttribute('data-target')) { $(b.getAttribute('data-target')).innerHTML = r.html; } }
                else { window.location.reload(); }
            } else { UI.showMessage(r.message || 'The action failed.', 'ERROR'); }
        });
    });

    /* Select-all for bulk actions */
    var all = $('#selectAll');
    if (all) { all.addEventListener('change', function () { $$('input[name="ids[]"]').forEach(function (c) { c.checked = all.checked; }); }); }

    /* ---------------- Upload / edit document form ---------------- */
    var form = $('#docForm');
    if (form) {
        var steps = $$('.step-pane', form), btns = $$('#docStepper button[data-step]'), cur = 0;
        var go = function (n) {
            cur = Math.max(0, Math.min(steps.length - 1, n));
            steps.forEach(function (s, i) { s.classList.toggle('active', i === cur); });
            btns.forEach(function (b, i) { b.classList.toggle('active', i === cur); });
            var sf = $('#stepField'); if (sf) { sf.value = cur; }
            var top = $('#docStepper'); if (top) { top.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
            if (steps[cur] && steps[cur].getAttribute('data-step-name') === 'seo' && !$('#seoTitle').value.trim()) { suggest(); }
            serp();
        };
        btns.forEach(function (b, i) { b.addEventListener('click', function () { go(i); }); });
        $$('[data-next]', form).forEach(function (b) { b.addEventListener('click', function () { go(cur + 1); }); });
        $$('[data-prev]', form).forEach(function (b) { b.addEventListener('click', function () { go(cur - 1); }); });
        go(parseInt(form.getAttribute('data-start') || '0', 10));

        var slugify = function (t) { return t.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 90); };
        var title = $('#docTitle'), slug = $('#docSlug'), slugTouched = !!(slug && slug.value);
        if (slug) { slug.addEventListener('input', function () { slugTouched = true; serp(); }); }
        if (title) { title.addEventListener('input', function () { if (!slugTouched && slug) { slug.value = slugify(title.value); } serp(); }); }

        var file = $('#docFile');
        if (file) {
            file.addEventListener('change', function () {
                var f = file.files[0]; if (!f) { return; }
                $('#docFileInfo').textContent = f.name + ' — ' + (f.size / 1048576).toFixed(2) + ' MB';
                if (title && !title.value.trim()) {
                    title.value = f.name.replace(/\.[^.]+$/, '').replace(/[_\-]+/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
                    if (!slugTouched && slug) { slug.value = slugify(title.value); }
                }
            });
        }

        var payRadios = $$('input[name="is_free"]', form), priceBox = $('#priceBox');
        var togglePrice = function () { var paid = $('input[name="is_free"]:checked', form); if (priceBox && paid) { priceBox.style.display = paid.value === '0' ? '' : 'none'; } };
        payRadios.forEach(function (r) { r.addEventListener('change', togglePrice); }); togglePrice();

        var cat = $('#docCat'), metaBox = $('#metaFields');
        var loadMeta = function () {
            if (!cat || !metaBox) { return; }
            UI.api(PD.base + 'ajax/admin.php?action=meta_fields&cat=' + encodeURIComponent(cat.value) + '&doc=' + encodeURIComponent(form.getAttribute('data-doc') || 0)).then(function (r) { metaBox.innerHTML = r.ok ? (r.html || '<div class="help">No extra fields for this category.</div>') : ''; });
        };
        if (cat) { cat.addEventListener('change', loadMeta); if (!form.getAttribute('data-meta-loaded')) { loadMeta(); } }

        function suggest() {
            var d = new FormData(form); d.set('action', 'seo_suggest');
            UI.api(PD.base + 'ajax/admin.php', { method: 'POST', data: d }).then(function (r) {
                if (!r.ok) { return; }
                $('#seoTitle').value = r.seo_title; $('#seoDesc').value = r.meta_description; $('#seoKeywords').value = r.keywords;
                if (!slugTouched && slug && !slug.value) { slug.value = r.slug; }
                serp();
            });
        }
        var sb = $('#seoSuggest'); if (sb) { sb.addEventListener('click', function () { suggest(); }); }
        function serp() {
            var t = $('#seoTitle'), d = $('#seoDesc'), s = $('#docSlug');
            if ($('#serpTitle')) { $('#serpTitle').textContent = (t && t.value) || (title && title.value) || 'Document title'; }
            if ($('#serpDesc')) { $('#serpDesc').textContent = ((d && d.value) || '').slice(0, 160); }
            if ($('#serpUrl')) { $('#serpUrl').textContent = (form.getAttribute('data-site') || '') + '/…/' + ((s && s.value) || 'document-slug'); }
        }
        ['seoTitle', 'seoDesc', 'seoKeywords'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.addEventListener('input', serp); } });

        /* Warn before leaving with unsaved changes */
        var dirty = false; form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
    }

    /* ---------------- Collection document picker ---------------- */
    var colSearch = $('#colSearch');
    if (colSearch) {
        var box = $('#colDocs'), res = $('#colResults'), t = null;
        var chip = function (id, title) {
            var d = document.createElement('div'); d.className = 'node'; d.setAttribute('data-id', id);
            d.innerHTML = '<input type="hidden" name="doc_ids[]" value="' + id + '"><span class="nm">' + esc(title) + '</span><span class="grow"></span>' +
                '<button type="button" class="btn-classic btn-sm" data-up>▲</button><button type="button" class="btn-classic btn-sm" data-down>▼</button><button type="button" class="btn-classic btn-sm danger" data-del>✕</button>';
            box.appendChild(d);
        };
        (window.PD_COL_DOCS || []).forEach(function (d) { chip(d.id, d.title); });
        colSearch.addEventListener('input', function () {
            clearTimeout(t); var q = colSearch.value.trim();
            if (q.length < 2) { res.innerHTML = ''; return; }
            t = setTimeout(function () {
                UI.api(PD.base + 'ajax/admin.php?action=doc_search&q=' + encodeURIComponent(q)).then(function (r) {
                    res.innerHTML = (r.docs || []).map(function (d) { return '<div class="node"><span class="nm">' + esc(d.title) + '</span><span class="meta">' + esc(d.meta) + '</span><span class="grow"></span><button type="button" class="btn-classic btn-sm success" data-add="' + d.id + '" data-title="' + esc(d.title) + '">＋ ADD</button></div>'; }).join('') || '<div class="help">No published documents match.</div>';
                });
            }, 250);
        });
        res.addEventListener('click', function (e) {
            var b = e.target.closest('[data-add]'); if (!b) { return; }
            if (!box.querySelector('[data-id="' + b.getAttribute('data-add') + '"]')) { chip(b.getAttribute('data-add'), b.getAttribute('data-title')); }
        });
        box.addEventListener('click', function (e) {
            var n = e.target.closest('.node'); if (!n) { return; }
            if (e.target.closest('[data-del]')) { n.remove(); }
            else if (e.target.closest('[data-up]') && n.previousElementSibling) { box.insertBefore(n, n.previousElementSibling); }
            else if (e.target.closest('[data-down]') && n.nextElementSibling) { box.insertBefore(n.nextElementSibling, n); }
        });
    }

    /* ---------------- Charts (design: green bars / navy line) ---------------- */
    function charts() {
        var d = window.PD_CHARTS;
        if (!d || !window.Chart) { return; }
        var navy = '#0a4b8c', green = 'rgba(16,185,129,0.8)';
        var mk = function (id, type, set, color) {
            var el = document.getElementById(id); if (!el) { return; }
            var line = type === 'line';
            new window.Chart(el, {
                type: type,
                data: { labels: set.labels, datasets: [{ data: set.values, backgroundColor: line ? 'rgba(10,75,140,0.1)' : color, borderColor: line ? navy : color, borderWidth: line ? 3 : 0, borderRadius: line ? 0 : 4, tension: 0.3, fill: line, pointRadius: line ? 3 : 0 }] },
                options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
            });
        };
        mk('chartDownloads', 'bar', d.downloads, green); mk('chartRevenue', 'line', d.revenue);
        mk('chartViews', 'line', d.views); mk('chartPurchases', 'bar', d.purchases, 'rgba(230,126,34,0.85)');
        mk('chartContrib', 'bar', d.contributions, 'rgba(16,132,208,0.85)'); mk('chartSearches', 'line', d.searches);
    }
    window.PDAdmin = { charts: charts };
})();
