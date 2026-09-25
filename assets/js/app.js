/* ============================================================
   PATADOCS — public site JavaScript (vanilla JS, no dependencies)
   Live search · filters · preview viewer · M-Pesa payment flow ·
   free downloads · request/report forms · saved documents (localStorage)
   Written in ES5 so it runs on older phones too.
   ============================================================ */
(function () {
    'use strict';
    var PD = window.PD || { base: '/', csrf: '', pages: {} };
    var DOC = window.PD_DOC || null;

    function $(s, c) { return (c || document).querySelector(s); }
    function $$(s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); }
    function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }
    function escRe(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
    function money(n) { n = Number(n) || 0; return PD.currency + ' ' + (Math.floor(n) === n ? n.toLocaleString('en-US') : n.toFixed(2)); }

    /** fetch wrapper: always returns parsed JSON ({ok:false,...} on any failure). */
    function api(url, opts) {
        opts = opts || {};
        var headers = { 'X-Requested-With': 'fetch', 'X-CSRF-Token': PD.csrf, 'Accept': 'application/json' };
        var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
        if (opts.data) {
            if (typeof FormData !== 'undefined' && opts.data instanceof FormData) { init.body = opts.data; }
            else {
                var p = new URLSearchParams();
                Object.keys(opts.data).forEach(function (k) { p.append(k, opts.data[k]); });
                init.body = p; headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
            }
            if (init.method === 'GET') { init.method = 'POST'; }
        }
        return fetch(url, init).then(function (r) {
            return r.text().then(function (t) {
                var j; try { j = JSON.parse(t); } catch (e) { j = { ok: false, message: 'Unexpected response from the server. Please try again.' }; }
                j._status = r.status; return j;
            });
        }).catch(function () { return { ok: false, message: 'Network problem. Please check your connection and try again.' }; });
    }

    /* ---------------- Hamburger + theme (from the design) ---------------- */
    var body = document.body, hamburger = $('#hamburgerBtn'), menu = $('#mobileMenu'), themeBtn = $('#themeToggleBtn');
    if (hamburger && menu) {
        hamburger.setAttribute('aria-controls', 'mobileMenu'); hamburger.setAttribute('aria-expanded', 'false');
        hamburger.addEventListener('click', function (e) { e.stopPropagation(); menu.classList.toggle('open'); hamburger.classList.toggle('active'); hamburger.setAttribute('aria-expanded', menu.classList.contains('open') ? 'true' : 'false'); });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.aspx-menu') && !e.target.closest('.hamburger')) { menu.classList.remove('open'); hamburger.classList.remove('active'); }
        });
    }
    /* ---------------- Main navigation: one row, overflow goes into "More ▾" (priority+) ---------------- */
    (function () {
        var nav = $('#mainNav'), more = nav && $('.nav-more', nav), list = more && $('.nav-more-list', more), btn = more && $('.nav-more-btn', more);
        if (!nav || !more) { return; }
        var items = Array.prototype.filter.call(nav.children, function (li) { return li !== more; }).map(function (li, i) { return { li: li, i: i, prio: parseInt(li.getAttribute('data-prio'), 10) || 5 }; });
        var closeMore = function () { more.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); };
        var layout = function () {
            closeMore();
            items.forEach(function (it) { nav.insertBefore(it.li, more); });          // everything back in the bar, original order
            more.hidden = true;
            if (hamburger && getComputedStyle(hamburger).display !== 'none') { return; }  // phone: the hamburger drawer shows all
            var box = menu.getBoundingClientRect().width, moved = [];
            var fits = function () { return nav.scrollWidth <= box + 1; };
            if (fits()) { return; }
            more.hidden = false;
            var order = items.slice().sort(function (a, b) { return b.prio - a.prio || b.i - a.i; });   // least important first
            for (var k = 0; k < order.length && !fits(); k++) { moved.push(order[k]); nav.removeChild(order[k].li); }
            moved.sort(function (a, b) { return a.i - b.i; }).forEach(function (it) { list.appendChild(it.li); });
            btn.classList.toggle('active', !!$('.active', list));
        };
        btn.addEventListener('click', function (e) { e.stopPropagation(); var o = !more.classList.contains('open'); more.classList.toggle('open', o); btn.setAttribute('aria-expanded', o ? 'true' : 'false'); if (o) { var f = $('a', list); if (f) { f.focus(); } } });
        document.addEventListener('click', function (e) { if (!e.target.closest('.nav-more')) { closeMore(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && more.classList.contains('open')) { closeMore(); btn.focus(); } });
        more.addEventListener('focusout', function (e) { if (!more.contains(e.relatedTarget)) { closeMore(); } });
        var t; window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(layout, 80); });
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(layout); }
        layout();
    })();

    /* ---------------- Top bar: rotating announcements + close (managed in Admin → Top Bar) ---------------- */
    (function () {
        var bar = $('#pdTopbar');
        if (!bar || bar.classList.contains('is-preview')) { return; }
        var close = $('.tb-close', bar);
        if (close) { close.addEventListener('click', function () { bar.hidden = true; try { localStorage.setItem('pd-topbar-closed', bar.getAttribute('data-key')); } catch (e) { } }); }
        var msgs = $$('.tb-msg', bar);
        if (msgs.length < 2) { return; }
        var i = 0, paused = false, secs = parseInt(bar.getAttribute('data-rotate'), 10) || 6;
        bar.addEventListener('mouseenter', function () { paused = true; }); bar.addEventListener('mouseleave', function () { paused = false; });
        bar.addEventListener('focusin', function () { paused = true; }); bar.addEventListener('focusout', function () { paused = false; });
        setInterval(function () {
            if (paused || document.hidden) { return; }
            msgs[i].classList.remove('is-on'); msgs[i].setAttribute('aria-hidden', 'true');
            i = (i + 1) % msgs.length;
            msgs[i].classList.add('is-on'); msgs[i].removeAttribute('aria-hidden');
        }, secs * 1000);
    })();

    function setTheme(t) {
        body.classList.remove('light-theme', 'dark-theme'); body.classList.add(t + '-theme');
        if (themeBtn) {
            if (t === 'dark') { themeBtn.textContent = '☀️ LIGHT'; themeBtn.style.background = '#ddd'; themeBtn.style.color = '#000'; }
            else { themeBtn.textContent = '🌙 DARK'; themeBtn.style.background = '#444'; themeBtn.style.color = '#fff'; }
        }
        try { localStorage.setItem('patadocs-theme', t); } catch (e) { }
    }
    var savedTheme = 'light'; try { savedTheme = localStorage.getItem('patadocs-theme') || 'light'; } catch (e) { }
    setTheme(savedTheme);
    if (themeBtn) { themeBtn.addEventListener('click', function () { setTheme(body.classList.contains('dark-theme') ? 'light' : 'dark'); }); }

    /* ---------------- Modal (same markup as the design) ---------------- */
    var overlay = $('#modalOverlay'), mTitle = $('#modalTitle'), mDyn = $('#modalDynamicContent'), mTotalSec = $('#modalTotalSection'),
        mTotal = $('#modalTotalDisplay'), mClose = $('#modalCloseBtn'), mOk = $('#modalActionBtn');
    function closeModal() { if (overlay) { overlay.classList.remove('open'); } }
    function openModal(title, html, o) {
        o = o || {};
        mTitle.textContent = title; mDyn.innerHTML = html;
        if (o.total) { mTotal.textContent = o.total; mTotalSec.style.display = ''; } else { mTotalSec.style.display = 'none'; }
        mClose.textContent = o.closeText || 'CLOSE'; mOk.textContent = o.okText || 'OK';
        mClose.onclick = function () { closeModal(); if (o.onClose) { o.onClose(); } };
        mOk.onclick = o.onOk || closeModal;
        overlay.classList.add('open');
        return mDyn;
    }
    function showMessage(msg, title) {
        openModal(title || 'NOTIFICATION', '<div class="modal-section" style="border-bottom:none;"><div style="font-size:1.3rem; padding:12px 0; text-align:center;">' + esc(msg) + '</div></div>');
    }
    window.PDUI = { openModal: openModal, closeModal: closeModal, showMessage: showMessage, api: api, esc: esc };

    /* ---------------- Tabs (home lists, admin) ---------------- */
    document.addEventListener('click', function (e) {
        var t = e.target.closest('.tab[data-tab]');
        if (!t) { return; }
        var group = t.closest('.tabs');
        $$('.tab', group).forEach(function (x) { x.classList.remove('active'); });
        t.classList.add('active');
        var scope = group.getAttribute('data-scope') ? $(group.getAttribute('data-scope')) : document;
        $$('.tab-pane', scope).forEach(function (p) { p.classList.toggle('active', p.id === t.getAttribute('data-tab')); });
        try { if (group.getAttribute('data-remember')) { history.replaceState(null, '', '#' + t.getAttribute('data-tab')); } } catch (er) { }
    });
    (function openTabFromHash() {
        var h = (location.hash || '').replace('#', '');
        if (h) { var t = $('.tab[data-tab="' + h + '"]'); if (t) { t.click(); } }
    })();

    /* ---------------- Copy-to-clipboard buttons ---------------- */
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-copy]');
        if (!b) { return; }
        var text = b.getAttribute('data-copy');
        var done = function () { var old = b.textContent; b.textContent = '✓ Copied'; setTimeout(function () { b.textContent = old; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done); }
        else { var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (er) { } document.body.removeChild(ta); }
    });

    /* ---------------- Live search suggestions (design markup) ---------------- */
    function highlight(text, q) {
        var out = esc(text);
        q.toLowerCase().split(/\s+/).filter(function (w) { return w.length > 0; }).forEach(function (w) {
            out = out.replace(new RegExp('(' + escRe(esc(w)) + ')', 'gi'), '<span class="suggestion-highlight">$1</span>');
        });
        return out;
    }
    $$('.search-hero').forEach(function (hero) {
        if (hero.getAttribute('data-nosuggest')) { return; }
        var form = hero.closest('form'), input = $('.search-input', hero), clear = $('.search-clear-btn', hero), box = $('.suggestions-box', hero);
        var sid = hero.getAttribute('data-search-id'), timer = null, logTimer = null, seq = 0, hi = -1, items = [];
        function close() { box.classList.remove('open'); hi = -1; }
        function render(q, r) {
            items = r.docs || []; hi = -1;
            var html = '';
            if (!items.length && !(r.cats && r.cats.length)) {
                html = '<div class="suggestion-empty"><div style="font-size:2.5rem; margin-bottom:10px;">🔍</div>' +
                    '<div style="font-weight:bold; font-size:1.1rem; margin-bottom:6px;">No documents found for "<em>' + esc(q) + '</em>"</div>' +
                    '<div style="font-size:0.95rem;">Try different keywords or <a href="' + esc(PD.pages.request + (PD.pages.request.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q)) + '" style="color:#000080; font-weight:bold;">request this document</a>.</div></div>';
            } else {
                if (r.cats && r.cats.length) {
                    html += '<div class="suggestions-header">Categories</div>';
                    r.cats.forEach(function (c) {
                        html += '<div class="suggestion-item" data-url="' + esc(c.url) + '"><div class="sug-icon">' + esc(c.icon) + '</div><div class="sug-content"><div class="sug-title">' + highlight(c.name, q) + '</div><div class="sug-meta"><span>📁 ' + esc(c.count) + ' documents</span></div></div></div>';
                    });
                }
                if (items.length) {
                    html += '<div class="suggestions-header">' + r.total + ' result' + (r.total > 1 ? 's' : '') + ' found</div>';
                    items.forEach(function (d, i) {
                        html += '<div class="suggestion-item" data-index="' + i + '" data-url="' + esc(d.url) + '"><div class="sug-icon">' + d.icon + '</div><div class="sug-content"><div class="sug-title">' + highlight(d.title, q) + '</div>' +
                            '<div class="sug-meta"><span>📁 ' + esc(d.category) + '</span><span>📄 ' + esc(d.format) + '</span>' + (d.pages ? '<span>📖 ' + d.pages + (d.pages === 1 ? ' page' : ' pages') + '</span>' : '') + '</div></div><div class="sug-price">' + esc(d.price) + '</div></div>';
                    });
                }
                html += '<div class="suggestions-footer">Press <strong>Enter</strong> for all results · <strong>Esc</strong> to close</div>';
            }
            box.innerHTML = html; box.classList.add('open');
        }
        function run(q, silent) {
            var my = ++seq;
            api(PD.base + 'ajax/search.php?mode=suggest&q=' + encodeURIComponent(q) + (silent ? '&log=1' : '')).then(function (r) { if (!silent && my === seq && r.ok) { render(q, r); } });
        }
        input.addEventListener('input', function () {
            var v = this.value; clear.classList.toggle('visible', v.length > 0);
            clearTimeout(timer); clearTimeout(logTimer);
            if (v.trim().length < 2) { close(); return; }
            timer = setTimeout(function () { run(v.trim(), false); }, 150);
            logTimer = setTimeout(function () { if (v.trim().length >= 3) { run(v.trim(), true); } }, 1400);
        });
        input.addEventListener('focus', function () { if (this.value.trim().length >= 2 && !box.classList.contains('open') && !items.length) { run(this.value.trim(), false); } else if (items.length && this.value.trim().length >= 2) { box.classList.add('open'); } });
        input.addEventListener('keydown', function (e) {
            var els = $$('.suggestion-item', box);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!els.length) { return; } e.preventDefault();
                if (hi >= 0 && els[hi]) { els[hi].classList.remove('highlighted'); }
                hi += (e.key === 'ArrowDown' ? 1 : -1); if (hi < 0) { hi = els.length - 1; } if (hi >= els.length) { hi = 0; }
                els[hi].classList.add('highlighted'); els[hi].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                if (hi >= 0 && els[hi]) { e.preventDefault(); window.location.href = els[hi].getAttribute('data-url'); }
            } else if (e.key === 'Escape') { close(); }
        });
        box.addEventListener('click', function (e) { var it = e.target.closest('.suggestion-item'); if (it && it.getAttribute('data-url')) { window.location.href = it.getAttribute('data-url'); } });
        clear.addEventListener('click', function () { input.value = ''; clear.classList.remove('visible'); close(); input.focus(); if (sid === 'browse') { input.dispatchEvent(new Event('pd-live')); } });
        input.addEventListener('input', function () { if (sid === 'browse') { input.dispatchEvent(new Event('pd-live')); } });
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.search-hero')) { $$('.suggestions-box.open').forEach(function (b) { b.classList.remove('open'); }); } });

    /* ---------------- Browse page: AJAX filters + results ---------------- */
    var zone = $('#searchZone');
    if (zone) {
        var qInput = $('.search-input'), liveTimer = null;
        var collect = function (page) {
            var p = new URLSearchParams();
            if (qInput && qInput.value.trim()) { p.set('q', qInput.value.trim()); }
            $$('[data-filter]', zone).forEach(function (el) { if (el.value !== '') { p.set(el.name, el.value); } });
            if (page > 1) { p.set('page', page); }
            return p;
        };
        var load = function (page) {
            var p = collect(page); zone.style.opacity = '.55';
            api(PD.base + 'ajax/search.php?mode=results&' + p.toString()).then(function (r) {
                zone.style.opacity = '1';
                if (r.ok && r.html) { zone.innerHTML = r.html; try { history.replaceState(null, '', PD.pages.search + (p.toString() ? (PD.pages.search.indexOf('?') > -1 ? '&' : '?') + p.toString() : '')); } catch (e) { } }
            });
        };
        zone.addEventListener('change', function (e) {
            if (!e.target.matches('[data-filter]')) { return; }
            if (e.target.name === 'cat') { $$('select[name^="f["]', zone).forEach(function (s) { s.value = ''; }); }
            load(1);
        });
        zone.addEventListener('input', function (e) { if (e.target.matches('input[data-filter]')) { clearTimeout(liveTimer); liveTimer = setTimeout(function () { load(1); }, 500); } });
        zone.addEventListener('click', function (e) {
            var a = e.target.closest('.gv-pager a');
            if (a) { e.preventDefault(); load(parseInt(a.getAttribute('data-page'), 10) || 1); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
            if (e.target.id === 'filterReset') { $$('[data-filter]', zone).forEach(function (el) { el.value = ''; }); load(1); }
        });
        if (qInput) { qInput.addEventListener('pd-live', function () { clearTimeout(liveTimer); liveTimer = setTimeout(function () { load(1); }, 400); }); }
    }

    /* ---------------- Generic AJAX forms: <form data-ajax> ---------------- */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f.matches || !f.matches('form[data-ajax]')) { return; }
        e.preventDefault();
        var btn = $('[type=submit]', f), old = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Please wait…'; }
        api(f.getAttribute('action') || location.href, { method: 'POST', data: new FormData(f) }).then(function (r) {
            if (btn) { btn.disabled = false; btn.innerHTML = old; }
            if (r.ok) {
                if (r.redirect) { window.location.href = r.redirect; return; }
                if (f.getAttribute('data-reset') !== null) { f.reset(); }
                var box = $('#formResult'); 
                if (box && f.getAttribute('data-inline') !== null) { box.innerHTML = '<div class="alert alert-success">' + esc(r.message) + '</div>'; box.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                else { showMessage(r.message || 'Done.'); }
            } else {
                var msg = r.message || 'Something went wrong.';
                if (r.errors && r.errors.length) { msg = r.errors.join(' '); }
                var box2 = $('#formResult');
                if (box2 && f.getAttribute('data-inline') !== null) { box2.innerHTML = '<div class="alert alert-error">' + esc(msg) + '</div>'; box2.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                else { showMessage(msg, 'PLEASE CHECK'); }
            }
        });
    });

    /* ---------------- Saved documents (browser only, no account) ---------------- */
    var SKEY = 'patadocs-saved';
    function getSaved() { try { return JSON.parse(localStorage.getItem(SKEY) || '[]') || []; } catch (e) { return []; } }
    function setSaved(a) { try { localStorage.setItem(SKEY, JSON.stringify(a)); } catch (e) { } paintSaved(); }
    function isSaved(id) { return getSaved().some(function (d) { return d.id === id; }); }
    function paintSaved() {
        var c = $('#savedCount'); if (c) { c.textContent = getSaved().length; }
        var b = $('#saveBtn'); if (b && DOC) { var s = isSaved(DOC.id); b.innerHTML = s ? '★ SAVED' : '☆ SAVE'; b.classList.toggle('active-toggle', s); }
    }
    var saveBtn = $('#saveBtn');
    if (saveBtn && DOC) {
        saveBtn.addEventListener('click', function () {
            var a = getSaved();
            if (isSaved(DOC.id)) { a = a.filter(function (d) { return d.id !== DOC.id; }); }
            else { a.unshift({ id: DOC.id, title: DOC.title, url: DOC.url, price: DOC.price, format: DOC.format, ts: Date.now() }); }
            setSaved(a);
        });
    }
    var savedList = $('#savedList');
    if (savedList) {
        var renderSaved = function () {
            var a = getSaved();
            if (!a.length) { savedList.innerHTML = '<div class="alert alert-info">You have not saved any documents yet. Open a document and press <strong>☆ SAVE</strong> — saved documents stay in this browser only.</div>'; return; }
            var h = '<div class="gv-wrap"><table class="gv-table"><thead><tr><th>Document Title</th><th>Format</th><th>Price</th><th>Action</th></tr></thead><tbody>';
            a.forEach(function (d) {
                h += '<tr><td class="doc-title"><a href="' + esc(d.url) + '">' + esc(d.title) + '</a></td><td><span class="doc-format">' + esc(d.format) + '</span></td><td class="doc-price">' + esc(d.price) + '</td>' +
                    '<td class="actions"><a class="btn-classic primary btn-sm" href="' + esc(d.url) + '">VIEW</a> <button type="button" class="btn-classic danger btn-sm" data-remove="' + d.id + '">REMOVE</button></td></tr>';
            });
            savedList.innerHTML = h + '</tbody></table></div>';
        };
        savedList.addEventListener('click', function (e) {
            var b = e.target.closest('[data-remove]'); if (!b) { return; }
            var id = parseInt(b.getAttribute('data-remove'), 10);
            setSaved(getSaved().filter(function (d) { return d.id !== id; })); renderSaved();
        });
        renderSaved();
    }
    paintSaved();

    /* ---------------- Preview viewer ---------------- */
    var viewer = $('#previewViewer');
    if (viewer) {
        var pages = [], idx = 0, zoom = 1, img = $('#pvImg'), count = $('#pvCount'), zv = $('#pvZoom'), cont = $('#previewContainer'), pageBox = $('#previewPage');
        try { pages = JSON.parse(viewer.getAttribute('data-pages') || '[]'); } catch (e) { pages = []; }
        var show = function () {
            img.src = pages[idx]; count.textContent = 'Page ' + (idx + 1) + ' / ' + pages.length;
            img.style.width = (zoom * 100) + '%'; zv.textContent = Math.round(zoom * 100) + '%';
            $('#pvPrev').disabled = idx === 0; $('#pvNext').disabled = idx === pages.length - 1;
            if (pages[idx + 1]) { var pre = new Image(); pre.src = pages[idx + 1]; }
        };
        if (pages.length) {
            $('#pvPrev').addEventListener('click', function () { if (idx > 0) { idx--; pageBox.scrollTop = 0; show(); } });
            $('#pvNext').addEventListener('click', function () { if (idx < pages.length - 1) { idx++; pageBox.scrollTop = 0; show(); } });
            $('#pvZoomIn').addEventListener('click', function () { zoom = Math.min(3, zoom + 0.25); show(); });
            $('#pvZoomOut').addEventListener('click', function () { zoom = Math.max(0.5, zoom - 0.25); show(); });
            $('#pvFull').addEventListener('click', function () {
                if (document.fullscreenElement) { document.exitFullscreen(); }
                else if (cont.requestFullscreen) { cont.requestFullscreen(); }
                else if (cont.webkitRequestFullscreen) { cont.webkitRequestFullscreen(); }
                else { showMessage('Fullscreen is not supported on this browser.'); }
            });
            document.addEventListener('keydown', function (e) {
                if (!document.fullscreenElement) { return; }
                if (e.key === 'ArrowRight') { $('#pvNext').click(); } if (e.key === 'ArrowLeft') { $('#pvPrev').click(); }
            });
            img.addEventListener('contextmenu', function (e) { e.preventDefault(); });
            show();
            if (DOC && !sessionStorage.getItem('pv' + DOC.id)) {
                try { sessionStorage.setItem('pv' + DOC.id, '1'); } catch (er) { }
                api(PD.base + 'ajax/public.php', { data: { action: 'preview_view', doc: DOC.id } });
            }
        }
    }

    /* ---------------- Free download ---------------- */
    var freeForm = $('#freeDownloadForm');
    if (freeForm) {
        freeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = $('button', freeForm);
            btn.disabled = true;
            api(PD.base + 'ajax/download.php', { data: { doc: DOC.id } }).then(function (r) {
                btn.disabled = false;
                if (r.ok && r.url) { showMessage('⬇ Download started: ' + DOC.title); window.location.href = r.url; }
                else { showMessage(r.message || 'Could not prepare the download. Please try again.'); }
            });
        });
    }

    /* ---------------- Payment modal (M-Pesa via the Payment Hub) ---------------- */
    var checkout = $('#checkoutBtn');
    var payItem = null;   // {type:'doc'|'collection', id, title, format, price, amount}
    function showPayment(item) {
        payItem = item;
        var phone = ''; try { phone = localStorage.getItem('patadocs-phone') || ''; } catch (e) { }
        openModal('SECURE M-PESA PAYMENT',
            '<div class="modal-section"><span class="modal-section-title">📄 ' + (item.type === 'collection' ? 'BUNDLE' : 'DOCUMENT') + '</span>' +
            '<div class="modal-row"><label>Title:</label><span style="font-weight:bold;">' + esc(item.title) + '</span></div>' +
            (item.format ? '<div class="modal-row"><label>Format:</label><span>' + esc(item.format) + '</span></div>' : '') +
            '<div class="modal-row"><label>Price:</label><span style="font-size:1.5rem; font-weight:bold; color:#008000;">' + esc(item.price) + '</span></div></div>' +
            '<div class="modal-section"><span class="modal-section-title">📱 M-PESA PHONE NUMBER</span><div class="result-area">' +
            '<div class="result-row"><label>Phone:</label><input type="tel" id="mpesaPhone" inputmode="tel" autocomplete="tel" placeholder="07XX XXX XXX" value="' + esc(phone) + '" style="font-size:1.2rem; width:200px;"></div>' +
            '<div class="result-row"><label>Email:</label><input type="email" id="mpesaEmail" placeholder="optional — for your receipt" style="font-size:1rem; width:260px;"></div>' +
            '<button type="button" class="modal-btn modal-btn-success" id="sendStkBtn" style="font-size:1.1rem; margin-top:10px;">PAY ' + esc(item.price) + '</button>' +
            '<div id="stkStatus" style="margin-top:14px; font-size:1.1rem;"></div></div>' +
            '<div class="help" style="margin-top:10px;">No account needed. The secure M-Pesa window opens next (STK prompt or PayBill). Keep your Order ID to recover your download later.</div></div>',
            { total: item.price });
        var pb = $('#sendStkBtn'); if ($('#mpesaPhone')) { $('#mpesaPhone').focus(); }
        pb.addEventListener('click', startPayment);
    }
    function startPayment() {
        var status = $('#stkStatus'), btn = $('#sendStkBtn'), phone = $('#mpesaPhone').value.trim();
        if (!/^(\+?254|0)?[17]\d{8}$/.test(phone.replace(/[\s-]/g, ''))) { status.innerHTML = '⚠️ Enter a valid Safaricom number, e.g. 0712 345 678'; return; }
        btn.disabled = true; status.innerHTML = '<span class="spinner"></span> Preparing your secure payment...';
        try { localStorage.setItem('patadocs-phone', phone); } catch (e) { }
        api(PD.base + 'ajax/payment.php', { data: { type: payItem.type, id: payItem.id, phone: phone, email: ($('#mpesaEmail') || {}).value || '' } }).then(function (r) {
            if (!r.ok) {
                btn.disabled = false;
                status.innerHTML = '❌ ' + esc(r.message || 'Could not start the payment.') + (r.recover ? ' <a href="' + esc(PD.pages.recover) + '">Recover purchase</a>' : '');
                return;
            }
            btn.disabled = false;
            openHubPayment(r);
        });
    }
    /**
     * Opens the Payment Hub's modal for a pending order ({order, key, token, pay_url, status_url}).
     * onSuccess / onClose from the modal are only hints: the order is unlocked after the server has
     * confirmed the payment with the Hub (webhook or status check), which poll() waits for.
     */
    function openHubPayment(r) {
        if (!window.EditoriaPay || !r.token) {                     // widget blocked or not loaded → Hub's hosted page / our status page
            window.location.href = r.pay_url || r.status_url;
            return;
        }
        closeModal();
        window.EditoriaPay.open({
            token: r.token,
            onSuccess: function () { confirmPayment(r, '<span class="spinner"></span> Confirming your payment...'); },
            onClose: function () { confirmPayment(r, '<span class="spinner"></span> Checking your payment... If you paid by PayBill, this can take a few seconds.'); }
        });
    }
    function confirmPayment(r, msg) {
        openModal('SECURE M-PESA PAYMENT',
            '<div class="modal-section" style="border-bottom:none;"><div id="stkStatus" style="font-size:1.1rem; padding:8px 0;">' + msg + '</div>' +
            '<div class="help">Order ID: <strong>' + esc(r.order) + '</strong></div></div>',
            { okText: 'PAY NOW', onOk: function () { openHubPayment(r); } });
        poll(r.order, r.key, Date.now(), $('#stkStatus'), null, r);
    }
    function poll(order, key, t0, status, btn, r0) {
        if (!document.body.contains(status)) { return; }          // modal was closed or replaced → stop polling
        api(PD.base + 'ajax/payment-status.php?o=' + encodeURIComponent(order) + '&k=' + encodeURIComponent(key)).then(function (r) {
            if (r.status === 'paid') { status.innerHTML = '✅ Payment Confirmed!' + (r.receipt ? '<br>M-PESA Receipt: ' + esc(r.receipt) : ''); r.key = key; setTimeout(function () { showSuccess(r); }, 900); return; }
            if (r.status === 'failed' || r.status === 'expired' || r.status === 'refunded') {
                if (btn) { btn.disabled = false; }
                if (r0 && payItem) { mOk.textContent = 'TRY AGAIN'; mOk.onclick = function () { showPayment(payItem); }; }   // that invoice is dead: start a new order
                status.innerHTML = '❌ ' + esc(r.message || 'Payment was not completed.') + ' You can try again.'; return;
            }
            if (Date.now() - t0 > (r0 ? 45000 : 150000)) {
                if (btn) { btn.disabled = false; }
                status.innerHTML = '⏳ Still waiting for M-Pesa. If you already paid, <a href="' + esc(PD.base + 'payment.php?o=' + encodeURIComponent(order) + '&k=' + encodeURIComponent(key)) + '"><strong>check your payment status</strong></a>.';
                return;
            }
            setTimeout(function () { poll(order, key, t0, status, btn, r0); }, 3000);
        });
    }
    function showSuccess(r) {
        var links = '';
        (r.downloads || []).forEach(function (d) {
            links += '<div style="padding:14px; background:#e0e0e0; border:1px solid #808080; text-align:left; margin-bottom:12px; border-radius:4px; color:#000;"><div style="font-weight:bold;">' + esc(d.title) + '</div><div style="font-size:1rem;">' + esc(d.format || '') + '</div>' +
                '<a class="modal-btn modal-btn-success" href="' + esc(d.url) + '" style="font-size:1.1rem; width:100%; margin-top:10px; box-sizing:border-box;">⬇ DOWNLOAD NOW</a></div>';
        });
        openModal('✅ PAYMENT SUCCESSFUL',
            '<div class="modal-section" style="text-align:center;"><div style="font-size:4rem; color:#008000; margin-bottom:12px;">✓</div>' +
            '<div style="font-size:1.6rem; font-weight:bold; margin-bottom:8px;">Payment Successful!</div>' +
            '<div style="font-size:1.1rem; color:#404040; margin-bottom:18px;">Your document is ready.</div>' + links +
            '<div style="margin-top:12px; font-size:1rem; color:#666;">Your download will start automatically...</div>' +
            '<div style="margin-top:8px; font-size:0.95rem; color:#666;">Having trouble? Click <strong>Download Now</strong>.</div>' +
            '<div style="margin-top:12px; font-size:0.95rem;">Order ID: <strong>' + esc(r.order) + '</strong> — keep it to recover your download later.</div>' +
            (r.key ? '<div style="margin-top:10px;"><a href="' + esc(PD.base + 'payment-success.php?o=' + encodeURIComponent(r.order) + '&k=' + encodeURIComponent(r.key)) + '#rate">⭐ Rate this document later</a></div>' : '') + '</div>');
        if (r.downloads && r.downloads.length === 1) {
            setTimeout(function () { var f = document.createElement('iframe'); f.style.display = 'none'; f.src = r.downloads[0].url; document.body.appendChild(f); }, 700);
        }
    }
    if (checkout && DOC) {
        checkout.addEventListener('click', function (e) { e.preventDefault(); showPayment({ type: 'doc', id: DOC.id, title: DOC.title, format: DOC.format + (DOC.pages ? ' · ' + DOC.pages + (DOC.pages === 1 ? ' page' : ' pages') : ''), price: DOC.price }); });
    }
    var bundleBtn = $('#bundleBuyBtn');
    if (bundleBtn) {
        bundleBtn.addEventListener('click', function (e) { e.preventDefault(); showPayment({ type: 'collection', id: parseInt(bundleBtn.getAttribute('data-id'), 10), title: bundleBtn.getAttribute('data-title'), format: bundleBtn.getAttribute('data-docs') + ' documents', price: bundleBtn.getAttribute('data-price') }); });
    }

    /* Payment page (payment.php): poll until paid, then go to the success page */
    var payPage = $('#payPage');
    if (payPage) {
        var st = $('#payState'), po = payPage.getAttribute('data-order'), pk = payPage.getAttribute('data-key'), t0 = Date.now();
        var hubPayBtn = $('#hubPayBtn'), ptoken = payPage.getAttribute('data-token');
        var initPayBtn = function () {                     // widget.js is deferred: it exists by DOMContentLoaded
            if (!hubPayBtn || !ptoken || !window.EditoriaPay) { return; }
            hubPayBtn.classList.remove('hidden');
            hubPayBtn.addEventListener('click', function () {
                window.EditoriaPay.open({ token: ptoken, onSuccess: function () { t0 = Date.now(); }, onClose: function () { t0 = Date.now(); } });
            });
        };
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initPayBtn); } else { initPayBtn(); }
        var tick = function () {
            api(PD.base + 'ajax/payment-status.php?o=' + encodeURIComponent(po) + '&k=' + encodeURIComponent(pk)).then(function (r) {
                if (r.status === 'paid') { window.location.href = PD.base + 'payment-success.php?o=' + encodeURIComponent(po) + '&k=' + encodeURIComponent(pk); return; }
                if (r.status === 'failed' || r.status === 'expired' || r.status === 'refunded') { st.innerHTML = '<div class="alert alert-error">❌ ' + esc(r.message || 'Payment was not completed.') + '</div>'; return; }
                if (Date.now() - t0 > 600000) { st.innerHTML = '<div class="alert alert-warn">Still pending. Refresh this page to check again.</div>'; return; }
                setTimeout(tick, 3000);
            });
        };
        tick();
    }
    /* Success page: start the download automatically */
    var autoDl = $('[data-autodownload]');
    if (autoDl) { setTimeout(function () { var f = document.createElement('iframe'); f.style.display = 'none'; f.src = autoDl.getAttribute('href'); document.body.appendChild(f); }, 900); }

    /* ---------------- Verified-buyer review forms ---------------- */
    $$('[data-review-form]').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var btn = $('button[type=submit]', f), msg = $('.review-msg', f);
            if (!$('input[name=rating]:checked', f)) { msg.textContent = 'Choose a star rating first.'; return; }
            btn.disabled = true; msg.textContent = 'Sending...';
            api(f.getAttribute('action'), { data: new FormData(f) }).then(function (r) {
                if (r.ok) { f.innerHTML = '<div class="alert alert-success">' + esc(r.message) + '</div>'; return; }
                btn.disabled = false; msg.textContent = r.message || 'Could not send your review. Please try again.';
            });
        });
    });

    /* ---------------- Request + report modals ---------------- */
    function catSelectHtml() { var t = $('#catTemplate'); return t ? t.innerHTML : '<option value="0">Other</option>'; }
    function showRequest(prefill) {
        openModal('REQUEST A DOCUMENT',
            '<div class="modal-section"><span class="modal-section-title">📝 CAN\'T FIND A DOCUMENT?</span><div class="result-area">' +
            '<div class="result-row"><label>Document:</label><input type="text" id="reqDoc" maxlength="200" placeholder="e.g. Grade 7 German Notes" value="' + esc(prefill || '') + '" style="font-size:1.1rem; width:100%; min-width:240px;"></div>' +
            '<div class="result-row"><label>Category:</label><select id="reqCat" style="font-size:1.05rem;">' + catSelectHtml() + '</select></div>' +
            '<div class="result-row"><label>Phone:</label><input type="text" id="reqPhone" placeholder="07XXXXXXXX (optional)" style="font-size:1.1rem; width:100%; min-width:240px;"></div>' +
            '<button type="button" class="modal-btn modal-btn-primary" id="submitRequestBtn" style="font-size:1.1rem; margin-top:10px;">SUBMIT REQUEST</button>' +
            '<div id="reqStatus" style="margin-top:12px;"></div></div></div>');
        $('#submitRequestBtn').addEventListener('click', function () {
            var t = $('#reqDoc').value.trim(), s = $('#reqStatus');
            if (!t) { s.innerHTML = '⚠️ Please enter the document name.'; return; }
            this.disabled = true;
            api(PD.base + 'ajax/public.php', { data: { action: 'request', title: t, category: $('#reqCat').value, phone: $('#reqPhone').value, hp: '' } }).then(function (r) {
                if (r.ok) { showMessage('✅ ' + r.message); } else { $('#submitRequestBtn').disabled = false; s.innerHTML = '❌ ' + esc(r.message || 'Could not send the request.'); }
            });
        });
    }
    var reqBtn = $('#requestBtn'); if (reqBtn) { reqBtn.addEventListener('click', function (e) { e.preventDefault(); showRequest(''); }); }
    var repBtn = $('#reportBtn');
    if (repBtn && DOC) {
        repBtn.addEventListener('click', function (e) {
            e.preventDefault();
            openModal('REPORT DOCUMENT',
                '<div class="modal-section"><span class="modal-section-title">🚩 WHAT IS WRONG WITH THIS DOCUMENT?</span><div class="result-area">' +
                '<div class="result-row"><label>Reason:</label><select id="repReason" style="font-size:1.05rem;"><option value="copyright">Copyright concern</option><option value="incorrect">Incorrect information</option><option value="broken">Broken file</option><option value="duplicate">Duplicate</option><option value="inappropriate">Inappropriate</option><option value="other">Other</option></select></div>' +
                '<div class="result-row"><label>Details:</label><textarea id="repMsg" maxlength="1000" placeholder="Tell us more (optional)" style="min-height:80px;"></textarea></div>' +
                '<div class="result-row"><label>Email:</label><input type="email" id="repEmail" placeholder="optional — if we may contact you" style="width:100%; min-width:240px;"></div>' +
                '<button type="button" class="modal-btn modal-btn-warning" id="sendReportBtn" style="margin-top:10px;">SEND REPORT</button><div id="repStatus" style="margin-top:12px;"></div></div></div>');
            $('#sendReportBtn').addEventListener('click', function () {
                this.disabled = true;
                api(PD.base + 'ajax/public.php', { data: { action: 'report', doc: DOC.id, reason: $('#repReason').value, message: $('#repMsg').value, email: $('#repEmail').value } }).then(function (r) {
                    if (r.ok) { showMessage('✅ ' + r.message); } else { $('#sendReportBtn').disabled = false; $('#repStatus').innerHTML = '❌ ' + esc(r.message || 'Could not send the report.'); }
                });
            });
        });
    }

    /* ---------------- Contribute page: dynamic metadata fields ---------------- */
    var catSel = $('#contribCat'), metaBox = $('#metaFields');
    if (catSel && metaBox) {
        var loadMeta = function () {
            var id = catSel.value; if (!id || id === '0') { metaBox.innerHTML = ''; return; }
            api(PD.base + 'ajax/public.php?action=meta_fields&cat=' + encodeURIComponent(id)).then(function (r) { metaBox.innerHTML = r.ok ? r.html : ''; });
        };
        catSel.addEventListener('change', loadMeta); loadMeta();
    }

    /* ---------------- Keyboard shortcuts (from the design) ---------------- */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'F1') { var inp = $('.search-input'); if (inp) { e.preventDefault(); inp.focus(); } }
        else if (e.key === 'F2' && DOC) { e.preventDefault(); var pc = $('#previewContainer'); if (pc) { pc.scrollIntoView({ behavior: 'smooth' }); } }
        else if ((e.key === 'F3' || e.key === 'F9') && DOC) {
            e.preventDefault();
            var b = $('#checkoutBtn') || $('#freeDownloadForm button'); if (b) { b.click(); }
        } else if (e.key === 'Escape') {
            if (overlay && overlay.classList.contains('open')) { closeModal(); }
            $$('.suggestions-box.open').forEach(function (b) { b.classList.remove('open'); });
            if (menu) { menu.classList.remove('open'); } if (hamburger) { hamburger.classList.remove('active'); }
        }
    });

    /* Focus the search box on desktop like the design does (home only) */
    if (window.innerWidth > 768 && $('[data-search-id="home"] .search-input')) { setTimeout(function () { $('[data-search-id="home"] .search-input').focus(); }, 400); }
    window.quickSearch = function (term) { window.location.href = PD.pages.search + (PD.pages.search.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(term); };
})();
