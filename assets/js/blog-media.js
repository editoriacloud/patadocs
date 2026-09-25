/* PATADOCS — Admin → Media library: save alt text on change, delete (warns when the image is used). */
(function () {
    'use strict';
    var ajax = window.PD_BLOG_AJAX, csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    if (!ajax) { return; }
    function post(action, data) {
        var fd = new FormData(); fd.append('action', action); fd.append('csrf', csrf);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(ajax, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}})
            .then(function (r) { return r.json(); }).catch(function () { return {ok: false, message: 'Network error.'}; });
    }
    document.addEventListener('change', function (e) {
        var i = e.target.closest('.media-alt'); if (!i) { return; }
        var id = i.closest('[data-id]').getAttribute('data-id');
        post('media_alt', {id: id, alt: i.value.trim()}).then(function (r) { i.style.outline = r.ok ? '2px solid #008000' : '2px solid #c00'; setTimeout(function () { i.style.outline = ''; }, 1200); });
    });
    document.addEventListener('click', function (e) {
        var b = e.target.closest('[data-media-del]'); if (!b) { return; }
        var card = b.closest('[data-id]'), id = card.getAttribute('data-id');
        if (!window.confirm('Delete this image?')) { return; }
        post('media_delete', {id: id}).then(function (r) {
            if (!r.ok && r.in_use) {
                if (!window.confirm(r.message)) { return; }
                return post('media_delete', {id: id, force: '1'});
            }
            return r;
        }).then(function (r) { if (!r) { return; } if (r.ok) { card.remove(); } else { window.alert(r.message); } });
    });
})();
