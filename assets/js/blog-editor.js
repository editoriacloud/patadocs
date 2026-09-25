/* PATADOCS — article editor (Admin → Blog → Add new / Edit).
 * TinyMCE 6 (bundled in assets/js/vendor/tinymce, MIT licence) + media library + live SEO analysis. */
(function () {
    'use strict';
    var cfg = window.PD_BLOG; if (!cfg) { return; }
    var $ = function (s, r) { return (r || document).querySelector(s); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); };
    var debounce = function (fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; };
    var form = $('#postForm'), titleEl = $('#postTitle'), slugEl = $('#postSlug'), statusEl = $('#editorStatus');
    var editor = null, dirty = false, submitting = false;

    function call(action, data, isGet) {
        var opts = {credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': cfg.csrf}};
        var url = cfg.ajax + '?action=' + encodeURIComponent(action);
        if (isGet) { Object.keys(data || {}).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }); }
        else {
            var fd = data instanceof FormData ? data : new FormData();
            if (!(data instanceof FormData)) { Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); }); }
            fd.append('action', action); fd.append('csrf', cfg.csrf);
            opts.method = 'POST'; opts.body = fd;
        }
        return fetch(url, opts).then(function (r) { return r.json().catch(function () { return {ok: false, message: 'Server error (' + r.status + ').'}; }); })
            .catch(function () { return {ok: false, message: 'Network error — check your connection.'}; });
    }
    function slugify(s) {
        return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 120).replace(/-+$/, '');
    }
    function setStatus(msg) { if (statusEl) { statusEl.textContent = msg; } }

    /* ---------------- Media library (modal) ---------------- */
    var media = {cb: null, page: 1, q: ''};
    var modal = $('#mediaModal'), grid = $('#mediaGrid');
    function mediaCard(m) {
        return '<button type="button" class="media-item" data-media=\'' + esc(JSON.stringify(m)) + '\' title="' + esc(m.name + ' · ' + m.width + '×' + m.height + ' · ' + m.size) + '">' +
            '<img src="' + esc(m.thumb) + '" alt="' + esc(m.alt) + '" loading="lazy"><span>' + esc(m.alt || m.name) + '</span></button>';
    }
    function mediaLoad(reset) {
        if (reset) { media.page = 1; grid.innerHTML = '<p class="help">Loading…</p>'; }
        call('media_list', {q: media.q, page: media.page}, true).then(function (r) {
            if (reset) { grid.innerHTML = ''; }
            if (!r.ok) { grid.innerHTML = '<p class="alert alert-error">' + esc(r.message) + '</p>'; return; }
            grid.insertAdjacentHTML('beforeend', r.items.map(mediaCard).join('') || (reset ? '<p class="help">No images yet — upload one.</p>' : ''));
            $('#mediaMore').classList.toggle('hidden', !r.more);
        });
    }
    function mediaOpen(cb) {
        media.cb = cb; modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); mediaLoad(true);
        setTimeout(function () { $('#mediaSearch').focus(); }, 50);
    }
    function mediaClose() { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); media.cb = null; }
    function upload(file, alt) {
        var fd = new FormData(); fd.append('file', file, file.name || 'image.png'); if (alt) { fd.append('alt', alt); }
        if (file.size > cfg.maxMb * 1048576) { return Promise.resolve({ok: false, message: 'The image is larger than ' + cfg.maxMb + ' MB.'}); }
        return call('upload', fd);
    }
    if (modal) {
        $('#mediaClose').addEventListener('click', mediaClose);
        modal.addEventListener('click', function (e) { if (e.target === modal) { mediaClose(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && modal.classList.contains('open')) { mediaClose(); } });
        $('#mediaMore').addEventListener('click', function () { media.page++; mediaLoad(false); });
        $('#mediaSearch').addEventListener('input', debounce(function () { media.q = this.value.trim(); mediaLoad(true); }, 300));
        grid.addEventListener('click', function (e) {
            var b = e.target.closest('[data-media]'); if (!b) { return; }
            var m = JSON.parse(b.getAttribute('data-media'));
            if (!m.alt) { var a = window.prompt('Describe this image for screen readers and Google (alt text):', ''); if (a) { m.alt = a.trim(); call('media_alt', {id: m.id, alt: m.alt}); } }
            var cb = media.cb; mediaClose(); if (cb) { cb(m); }
        });
        $('#mediaUpload').addEventListener('change', function () {
            var files = Array.prototype.slice.call(this.files || []); this.value = '';
            files.reduce(function (p, f) {
                return p.then(function () {
                    grid.insertAdjacentHTML('afterbegin', '<p class="help up-note">Uploading ' + esc(f.name) + '…</p>');
                    return upload(f).then(function (r) {
                        var n = grid.querySelector('.up-note'); if (n) { n.remove(); }
                        if (r.ok) { grid.insertAdjacentHTML('afterbegin', mediaCard(r.media)); } else { window.alert(r.message); }
                    });
                });
            }, Promise.resolve());
        });
    }

    /* ---------------- Featured image ---------------- */
    var coverPath = $('#coverPath'), coverPrev = $('#coverPreview'), coverAlt = $('#coverAlt');
    function setCover(m) {
        coverPath.value = m ? m.path : '';
        coverPrev.innerHTML = m ? '<img src="' + esc(m.url) + '" alt="">' : '<span class="muted">No image.</span>';
        if (m && m.alt && !coverAlt.value) { coverAlt.value = m.alt; }
        $('#coverRemove').classList.toggle('hidden', !m); markDirty(); analyze();
        if (m && m.width && m.width < 1200) { setStatus('Tip: the featured image is ' + m.width + 'px wide — 1200px or more is recommended for Google Discover.'); }
    }
    $('#coverPick').addEventListener('click', function () { mediaOpen(setCover); });
    $('#coverRemove').addEventListener('click', function () { setCover(null); $('#coverFile').value = ''; });
    $('#coverFile').addEventListener('change', function () {
        var f = this.files && this.files[0]; if (!f) { return; }
        var r = new FileReader(); r.onload = function () { coverPrev.innerHTML = '<img src="' + r.result + '" alt="">'; }; r.readAsDataURL(f);
        coverPath.value = ''; $('#coverRemove').classList.remove('hidden'); markDirty();
    });

    /* ---------------- Category quick add ---------------- */
    var addCat = $('#addCatBtn');
    if (addCat) {
        addCat.addEventListener('click', function () {
            var n = $('#newCatName'); if (n.value.trim().length < 2) { n.focus(); return; }
            call('category_add', {name: n.value.trim()}).then(function (r) {
                if (!r.ok) { window.alert(r.message); return; }
                var o = document.createElement('option'); o.value = r.id; o.textContent = r.name; o.selected = true; $('#catSel').appendChild(o); n.value = '';
            });
        });
    }

    /* ---------------- Documents to promote ---------------- */
    var docIds = $('#docIdsField'), docList = $('#docPickList'), docRes = $('#docPickResults');
    function docChip(id, title) {
        if (docList.querySelector('[data-id="' + id + '"]')) { return; }
        docList.insertAdjacentHTML('beforeend', '<div class="node" data-id="' + id + '"><span class="nm">' + esc(title) + '</span><span class="grow"></span><button type="button" class="btn-classic btn-sm danger" data-del aria-label="Remove">✕</button></div>');
        syncDocs();
    }
    function syncDocs() { docIds.value = Array.prototype.map.call(docList.querySelectorAll('[data-id]'), function (n) { return n.getAttribute('data-id'); }).join(','); markDirty(); }
    (cfg.docs || []).forEach(function (d) { docChip(d.id, d.title); }); dirty = false;
    $('#docPickSearch').addEventListener('input', debounce(function () {
        var q = this.value.trim(); if (q.length < 2) { docRes.innerHTML = ''; return; }
        call('doc_search', {q: q}, true).then(function (r) {
            docRes.innerHTML = (r.docs || []).map(function (d) { return '<div class="node"><span class="nm">' + esc(d.title) + '</span><span class="meta">' + esc(d.meta) + '</span><span class="grow"></span><button type="button" class="btn-classic btn-sm success" data-add="' + d.id + '" data-title="' + esc(d.title) + '">＋</button></div>'; }).join('') || '<div class="help">No published documents match.</div>';
        });
    }, 250));
    docRes.addEventListener('click', function (e) { var b = e.target.closest('[data-add]'); if (b) { docChip(b.getAttribute('data-add'), b.getAttribute('data-title')); } });
    docList.addEventListener('click', function (e) { if (e.target.closest('[data-del]')) { e.target.closest('.node').remove(); syncDocs(); } });

    /* ---------------- Title → slug ---------------- */
    var slugTouched = slugEl.value !== '';
    slugEl.addEventListener('input', function () { slugTouched = true; });
    slugEl.addEventListener('blur', function () { slugEl.value = slugify(slugEl.value); analyze(); });
    titleEl.addEventListener('input', function () { if (!slugTouched && !cfg.id) { slugEl.value = slugify(titleEl.value); } });

    /* ---------------- SEO analysis ---------------- */
    var seoTitle = $('#seoTitle'), metaDesc = $('#metaDesc'), focusKw = $('#focusKw'), excerptEl = $('#postExcerpt');
    function editorHtml() { return editor ? editor.getContent() : ($('#postContent').value || ''); }
    function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim(); }
    function countOf(hay, kw) { if (!kw) { return 0; } var n = 0, i = 0; hay = ' ' + norm(hay).replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ') + ' '; kw = ' ' + norm(kw).replace(/[^a-z0-9 ]/g, ' ').replace(/\s+/g, ' ').trim() + ' '; while ((i = hay.indexOf(kw, i)) !== -1) { n++; i += kw.length - 1; } return n; }
    function has(hay, kw) { return countOf(hay, kw) > 0; }
    function analyze() {
        var html = editorHtml();
        var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
        var text = (doc.body.textContent || '').replace(/\s+/g, ' ').trim();
        var words = text ? text.split(' ').length : 0;
        var kw = focusKw.value.trim();
        var title = titleEl.value.trim(), st = seoTitle.value.trim() || title;
        var fullTitle = st.toLowerCase().indexOf(cfg.siteName.toLowerCase()) !== -1 ? st : st + cfg.titleSuffix;
        var desc = metaDesc.value.trim() || excerptEl.value.trim() || text.slice(0, 160);
        var slug = slugEl.value || slugify(title);
        var paras = Array.prototype.map.call(doc.querySelectorAll('p'), function (p) { return p.textContent.trim(); }).filter(Boolean);
        var heads = Array.prototype.map.call(doc.querySelectorAll('h2,h3,h4'), function (h) { return h.textContent; });
        var imgs = doc.querySelectorAll('img');
        var noAlt = Array.prototype.filter.call(imgs, function (i) { return !(i.getAttribute('alt') || '').trim(); }).length;
        var altKw = Array.prototype.some.call(imgs, function (i) { return has(i.getAttribute('alt'), kw); }) || has($('#coverAlt').value, kw);
        var links = doc.querySelectorAll('a[href]'), internal = 0, outbound = 0;
        Array.prototype.forEach.call(links, function (a) { var h = a.getAttribute('href') || ''; if (/^https?:\/\//i.test(h) && h.toLowerCase().indexOf('//' + cfg.host.toLowerCase()) === -1) { outbound++; } else if (h && h[0] !== '#') { internal++; } });
        internal += (html.match(/\[document\s/gi) || []).length + ($('#docIdsField').value ? 1 : 0);
        var sentences = text.split(/[.!?]+\s/).filter(function (s) { return s.trim().split(' ').length > 2; });
        var longSent = sentences.filter(function (s) { return s.split(' ').length > 25; }).length;
        var longParas = paras.filter(function (p) { return p.split(/\s+/).length > 150; }).length;
        var kwCount = countOf(text, kw), density = words ? kwCount / words * 100 : 0;
        var first = paras.length ? paras[0] + ' ' + (paras[1] || '') : text.slice(0, 400);
        var C = [];
        var add = function (state, weight, msg) { C.push({s: state, w: weight, m: msg}); };
        if (!kw) { add('bad', 10, 'Set a focus keyphrase — the search term this article should rank for.'); }
        else {
            add(has(st, kw) ? (norm(st).indexOf(norm(kw)) === 0 ? 'good' : 'ok') : 'bad', 10, has(st, kw) ? (norm(st).indexOf(norm(kw)) === 0 ? 'Keyphrase at the start of the SEO title.' : 'Keyphrase in the SEO title — even better at the beginning.') : 'Put the keyphrase in the SEO title.');
            add(has(desc, kw) ? 'good' : 'bad', 6, has(desc, kw) ? 'Keyphrase in the meta description.' : 'Use the keyphrase in the meta description.');
            add(has(slug.replace(/-/g, ' '), kw) ? 'good' : 'ok', 4, has(slug.replace(/-/g, ' '), kw) ? 'Keyphrase in the URL.' : 'Consider using the keyphrase in the URL (slug).');
            add(has(first, kw) ? 'good' : 'bad', 8, has(first, kw) ? 'Keyphrase in the introduction.' : 'Use the keyphrase in the first paragraph.');
            add(heads.some(function (h) { return has(h, kw); }) ? 'good' : 'ok', 5, heads.some(function (h) { return has(h, kw); }) ? 'Keyphrase in a subheading.' : 'Use the keyphrase (or a variation) in at least one H2/H3 subheading.');
            add(density >= 0.5 && density <= 3 ? 'good' : (kwCount ? 'ok' : 'bad'), 6, 'Keyphrase used ' + kwCount + ' time' + (kwCount === 1 ? '' : 's') + ' (density ' + density.toFixed(1) + '%)' + (density < 0.5 ? ' — use it a bit more.' : density > 3 ? ' — that is too often; write naturally.' : ' — good.'));
            if (imgs.length || $('#coverPath').value) { add(altKw ? 'good' : 'ok', 3, altKw ? 'Keyphrase in an image alt text.' : 'Describe at least one image with the keyphrase in its alt text.'); }
        }
        add(fullTitle.length >= 35 && fullTitle.length <= 65 ? 'good' : (fullTitle.length < 35 ? 'ok' : 'bad'), 6, 'SEO title is ' + fullTitle.length + ' characters' + (fullTitle.length > 65 ? ' — Google will cut it off (aim for ≤ 60–65).' : fullTitle.length < 35 ? ' — a little short; add a benefit or detail.' : '.'));
        var dl = (metaDesc.value.trim() || excerptEl.value.trim()).length;
        add(dl >= 120 && dl <= 160 ? 'good' : (dl ? 'ok' : 'bad'), 6, dl ? 'Meta description is ' + dl + ' characters' + (dl > 160 ? ' — will be cut off (aim for 120–160).' : dl < 120 ? ' — could be longer (120–160).' : '.') : 'Write a meta description (or an excerpt) — it is the text people see in Google.');
        add(words >= 600 ? 'good' : (words >= 300 ? 'ok' : 'bad'), 10, words + ' words' + (words < 300 ? ' — too thin to rank; aim for 600+.' : words < 600 ? ' — fine; 600+ words covers a topic better.' : ' — good length.'));
        add(heads.length >= 2 ? 'good' : (heads.length ? 'ok' : 'bad'), 5, heads.length ? heads.length + ' subheadings.' + (heads.length < 2 ? ' Add more to structure the text.' : '') : 'Add H2 subheadings to structure the article.');
        add(internal ? 'good' : 'bad', 6, internal ? internal + ' internal link(s) / document card(s).' : 'Link to related documents or articles on this site (🔗 Link list or 📄 Document button).');
        add(outbound ? 'good' : 'ok', 2, outbound ? outbound + ' outbound link(s) to sources.' : 'Link to an authoritative source (e.g. KICD, KRA, a ministry) where it helps readers.');
        add($('#coverPath').value || $('#coverFile').value ? 'good' : 'ok', 4, $('#coverPath').value || $('#coverFile').value ? 'Featured image set.' : 'Add a featured image (used on Google Discover, WhatsApp and Facebook previews).');
        if (imgs.length) { add(noAlt ? 'bad' : 'good', 4, noAlt ? noAlt + ' image(s) without alt text.' : 'All images have alt text.'); }
        if (sentences.length > 5) { var pct = Math.round(longSent / sentences.length * 100); add(pct <= 25 ? 'good' : 'ok', 3, pct + '% of sentences are longer than 25 words' + (pct > 25 ? ' — shorten some for easier reading.' : '.')); }
        if (longParas) { add('ok', 2, longParas + ' paragraph(s) longer than 150 words — split them.'); }
        var total = 0, got = 0;
        C.forEach(function (c) { total += c.w; got += c.s === 'good' ? c.w : (c.s === 'ok' ? c.w / 2 : 0); });
        var score = total ? Math.round(got / total * 100) : 0;
        $('#seoScoreField').value = score;
        var pill = $('#seoScorePill'); pill.textContent = score + '/100'; pill.className = 'seo-score-pill ' + (score >= 75 ? 'good' : score >= 50 ? 'ok' : 'bad');
        C.sort(function (a, b) { var o = {bad: 0, ok: 1, good: 2}; return o[a.s] - o[b.s]; });
        $('#seoChecks').innerHTML = C.map(function (c) { return '<li class="' + c.s + '"><span class="dot" aria-hidden="true"></span><span class="sr-only">' + (c.s === 'good' ? 'Good: ' : c.s === 'ok' ? 'Improve: ' : 'Problem: ') + '</span>' + esc(c.m) + '</li>'; }).join('');
        // snippet preview + counters
        var shown = fullTitle.length > 62 ? fullTitle.slice(0, 60).replace(/\s+\S*$/, '') + ' …' : fullTitle;
        $('#serpTitle').textContent = shown || 'Article title';
        $('#serpUrl').textContent = ' › ' + cfg.permBase.replace(/^https?:\/\/[^/]+/, '').replace(/^\/|\/$/g, '').replace(/[/?=]/g, ' › ').replace(/\s›\s*$/, '') + ' › ' + (slug || '…');
        $('#serpDesc').textContent = desc.length > 158 ? desc.slice(0, 155).replace(/\s+\S*$/, '') + ' …' : desc;
        var d = $('#pubAt').value ? new Date($('#pubAt').value) : new Date();
        $('#serpDate').textContent = d.toLocaleDateString('en-GB', {day: 'numeric', month: 'short', year: 'numeric'}) + ' — ';
        $('#seoTitleCount').textContent = '(' + fullTitle.length + ' chars with site name)';
        $('#metaDescCount').textContent = '(' + metaDesc.value.trim().length + '/160)';
        var wc = $('#wordCountSide'); if (wc) { wc.textContent = words; }
    }
    var analyzeSoon = debounce(analyze, 400);
    [titleEl, slugEl, seoTitle, metaDesc, focusKw, excerptEl, $('#coverAlt'), $('#pubAt')].forEach(function (el) { if (el) { el.addEventListener('input', function () { markDirty(); analyzeSoon(); }); } });
    focusKw.addEventListener('change', function () {
        var kw = focusKw.value.trim(), out = $('#kwUsed'); out.innerHTML = '';
        if (!kw) { return; }
        call('kw_check', {kw: kw, id: cfg.id}, true).then(function (r) {
            if (r.ok && r.used.length) { out.innerHTML = '⚠ Already the focus of: ' + r.used.map(function (p) { return '<a href="' + esc(p.url) + '">' + esc(p.title) + '</a>'; }).join(', ') + ' — two articles competing for one search hurts both.'; }
        });
    });

    /* ---------------- Dirty guard + autosave ---------------- */
    function markDirty() { dirty = true; }
    form.addEventListener('change', markDirty);
    form.addEventListener('submit', function (e) {
        var b = e.submitter;
        if (b && b.getAttribute('formtarget')) { if (editor) { editor.save(); } return; }   // preview: stays on this page
        submitting = true; if (editor) { editor.save(); }
    });
    window.addEventListener('beforeunload', function (e) {
        if (submitting || !(dirty || (editor && editor.isDirty()))) { return; }
        e.preventDefault(); e.returnValue = '';
    });
    var lastAuto = '';
    setInterval(function () {
        if (!cfg.id || !editor || submitting) { return; }
        var content = editor.getContent(), sig = titleEl.value + '\u0000' + content + '\u0000' + excerptEl.value;
        if (sig === lastAuto || !(dirty || editor.isDirty())) { return; }
        lastAuto = sig;
        call('autosave', {id: cfg.id, title: titleEl.value, excerpt: excerptEl.value, content: content}).then(function (r) { setStatus(r.ok ? '✔ ' + r.message : '⚠ Autosave failed: ' + r.message); });
    }, 60000);

    /* ---------------- TinyMCE ---------------- */
    function insertDocument(ed) {
        ed.windowManager.open({
            title: 'Insert a document card', size: 'normal',
            body: {type: 'panel', items: [{type: 'input', name: 'q', label: 'Search published documents (title)'}, {type: 'htmlpanel', html: '<p style="margin:6px 0 0;color:#555;">Readers see a card with the title, format, price and a GET IT button.</p>'}]},
            buttons: [{type: 'cancel', text: 'Cancel'}, {type: 'submit', text: 'Search', primary: true}],
            onSubmit: function (api) {
                var q = api.getData().q.trim(); if (q.length < 2) { return; }
                api.block('Searching…');
                call('doc_search', {q: q}, true).then(function (r) {
                    api.close();
                    var docs = r.docs || [];
                    if (!docs.length) { ed.windowManager.alert('No published document matches “' + q + '”.'); return; }
                    ed.windowManager.open({
                        title: 'Choose the document', body: {type: 'panel', items: [{type: 'selectbox', name: 'doc', label: docs.length + ' found', items: docs.map(function (d) { return {value: String(d.id), text: d.title + ' — ' + d.meta}; })},
                            {type: 'selectbox', name: 'how', label: 'Insert as', items: [{value: 'card', text: 'Document card (recommended)'}, {value: 'link', text: 'Text link'}]}]},
                        buttons: [{type: 'cancel', text: 'Cancel'}, {type: 'submit', text: 'Insert', primary: true}],
                        onSubmit: function (a2) {
                            var v = a2.getData(), d = docs.filter(function (x) { return String(x.id) === v.doc; })[0];
                            if (v.how === 'link') { ed.insertContent('<a href="' + esc(d.url) + '">' + esc(ed.selection.getContent({format: 'text'}) || d.title) + '</a>'); }
                            else { ed.insertContent('<p class="doc-shortcode">[document id="' + d.id + '"]</p><p></p>'); }
                            a2.close(); analyze();
                        }
                    });
                });
            }
        });
    }
    function stickyOffset() {                               // keep the editor toolbar below the admin's own sticky top bar
        var tb = document.querySelector('.aspx-toolbar'); if (!tb) { return 0; }
        var pos = window.getComputedStyle(tb).position;
        return pos === 'fixed' || pos === 'sticky' ? tb.offsetHeight : 0;
    }
    function startEditor() {
        var dark = document.body.classList.contains('dark-theme');
        window.tinymce.init({
            selector: '#postContent', base_url: cfg.base + 'assets/js/vendor/tinymce', suffix: '.min', promotion: false, branding: false,
            height: 680, min_height: 420, resize: true, skin: dark ? 'oxide-dark' : 'oxide', content_css: ['default', cfg.contentCss],
            menubar: 'file edit view insert format tools table help',
            plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks visualchars code fullscreen insertdatetime media table help wordcount codesample emoticons accordion quickbars autosave directionality nonbreaking',
            toolbar: 'undo redo | blocks styles | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link pdmedia image media table | pddoc blockquote codesample hr accordion | charmap emoticons | removeformat | searchreplace visualblocks code fullscreen help',
            toolbar_mode: 'sliding', toolbar_sticky: true, toolbar_sticky_offset: stickyOffset(),
            block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4; Preformatted=pre',
            style_formats: [
                {title: 'Lead paragraph', block: 'p', classes: 'lead'},
                {title: 'Info box', block: 'div', classes: 'callout callout-info', wrapper: true},
                {title: 'Tip box', block: 'div', classes: 'callout callout-tip', wrapper: true},
                {title: 'Warning box', block: 'div', classes: 'callout callout-warning', wrapper: true},
                {title: 'Danger box', block: 'div', classes: 'callout callout-danger', wrapper: true},
                {title: 'Button link (call to action)', selector: 'a', classes: 'btn-link-cta'},
                {title: 'Image: float left', selector: 'img,figure', classes: 'align-left'},
                {title: 'Image: float right', selector: 'img,figure', classes: 'align-right'}
            ],
            relative_urls: false, remove_script_host: true, convert_urls: true, document_base_url: cfg.base,
            image_caption: true, image_advtab: true, image_description: true, image_dimensions: true, image_title: false,
            automatic_uploads: true, paste_data_images: true, images_file_types: 'jpg,jpeg,png,webp', images_reuse_filename: false,
            images_upload_handler: function (blobInfo) {
                return upload(blobInfo.blob(), '').then(function (r) { if (!r.ok) { throw {message: r.message, remove: true}; } return r.location; });
            },
            file_picker_types: 'image',
            file_picker_callback: function (cb) { mediaOpen(function (m) { cb(m.url, {alt: m.alt, width: String(m.width), height: String(m.height)}); }); },
            link_list: cfg.ajax + '?action=link_list', link_assume_external_targets: 'https', link_context_toolbar: true,
            link_target_list: [{title: 'Same window', value: ''}, {title: 'New window', value: '_blank'}],
            link_rel_list: [{title: 'Normal', value: ''}, {title: 'No follow', value: 'nofollow'}, {title: 'Sponsored / paid', value: 'sponsored nofollow'}],
            media_alt_source: false, media_poster: false, media_live_embeds: true,
            extended_valid_elements: 'iframe[src|width|height|title|allow|allowfullscreen|frameborder|loading|style|class]',
            invalid_elements: 'script,style,object,embed,form,input,button,h1',
            quickbars_selection_toolbar: 'bold italic | quicklink h2 h3 blockquote', quickbars_insert_toolbar: false,
            contextmenu: 'link image table', browser_spellcheck: true,
            autosave_ask_before_unload: false, autosave_interval: '20s', autosave_retention: '1440m', autosave_restore_when_empty: true,
            autosave_prefix: 'pd-post-' + (cfg.id || 'new') + '-',
            codesample_languages: [{text: 'HTML/XML', value: 'markup'}, {text: 'JavaScript', value: 'javascript'}, {text: 'CSS', value: 'css'}, {text: 'PHP', value: 'php'}, {text: 'Python', value: 'python'}, {text: 'SQL', value: 'sql'}],
            setup: function (ed) {
                ed.ui.registry.addButton('pdmedia', {icon: 'gallery', tooltip: 'Media library (choose or upload images)', onAction: function () {
                    mediaOpen(function (m) { ed.insertContent('<img src="' + esc(m.url) + '" alt="' + esc(m.alt) + '" width="' + m.width + '" height="' + m.height + '">'); analyze(); });
                }});
                ed.ui.registry.addButton('pddoc', {text: '📄 Document', tooltip: 'Insert a document from the store (card or link)', onAction: function () { insertDocument(ed); }});
                ed.ui.registry.addMenuItem('pddoc', {text: 'Document card…', onAction: function () { insertDocument(ed); }});
                ed.ui.registry.addMenuItem('pdmedia', {text: 'Media library…', icon: 'gallery', onAction: function () { mediaOpen(function (m) { ed.insertContent('<img src="' + esc(m.url) + '" alt="' + esc(m.alt) + '">'); }); }});
                ed.on('init', function () { editor = ed; analyze(); });
                ed.on('input change undo redo SetContent', analyzeSoon);
                ed.on('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); var b = form.querySelector('button[value="draft"]') || form.querySelector('#publishBtn'); if (b) { b.click(); } } });
            },
            menu: {insert: {title: 'Insert', items: 'pdmedia image link media pddoc codesample inserttable accordion | charmap emoticons hr | anchor insertdatetime nonbreaking'}}
        });
    }
    if (window.tinymce) { startEditor(); }
    else {
        var s = document.createElement('script'); s.src = cfg.tinymce; s.onload = startEditor;
        s.onerror = function () { setStatus('The rich editor could not load — you can still write HTML in the box.'); analyze(); };
        document.head.appendChild(s);
    }
    analyze();
})();
