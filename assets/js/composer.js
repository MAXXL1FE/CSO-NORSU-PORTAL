/*
 * "Create post" popup (events + announcements).
 *  - picks several photos, shows previews, lets you remove them before posting
 *  - shrinks big phone photos in the browser first, so a 6 MB photo uploads as ~400 KB
 *  - drag & drop / paste photos, optional sections (deadline, end time), disabled Post until valid
 * Markup comes from render_composer() in includes/post_ui.php.
 */
(function () {
    'use strict';

    var MAX_SIDE = 1920;               // longest side after shrinking (px)
    var MAX_BYTES = 3 * 1024 * 1024;   // the server's per-photo limit
    var ACCEPT = /^image\/(jpeg|png|webp|gif)$/;

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /* ---------- open / close ---------- */
    function lock(on) { document.body.style.overflow = on ? 'hidden' : ''; }

    /* Keep the sheet inside the *visible* viewport (browser toolbars, on-screen
       keyboard) so the tools row and the Post button are never cut off. */
    function syncViewport() {
        var vv = window.visualViewport;
        var h = vv ? vv.height : window.innerHeight;
        var top = vv ? vv.offsetTop : 0;
        $$('.composer-overlay').forEach(function (o) {
            o.style.setProperty('--cmp-vh', Math.round(h) + 'px');
            o.style.setProperty('--cmp-top', Math.round(top) + 'px');
        });
    }
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncViewport);
        window.visualViewport.addEventListener('scroll', syncViewport);
    }
    window.addEventListener('resize', syncViewport);
    window.addEventListener('orientationchange', syncViewport);
    syncViewport();

    function openOverlay(o) {
        syncViewport();
        o.classList.add('open');
        lock(true);
        var t = $('.composer-title', o);
        if (t) setTimeout(function () { t.focus(); }, 80);
    }
    function closeOverlay(o) {
        o.classList.remove('open');
        lock(false);
        var root = $('[data-composer]', o);
        if (root && root._reset) root._reset();
    }
    function dismiss(o, silent) {
        var root = $('[data-composer]', o);
        if (root && root._dirty && !silent && !window.confirm('Discard this post? Your changes will be lost.')) return;
        var url = root && root.getAttribute('data-close-url');
        if (url) { window.location.href = url; return; }
        closeOverlay(o);
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-composer-open]');
        if (opener) {
            var o = document.getElementById(opener.getAttribute('data-composer-open'));
            if (o) {
                openOverlay(o);
                if (opener.hasAttribute('data-composer-photos')) {
                    var pick = $('[data-composer-pick]', o);
                    if (pick) pick.click();
                }
            }
            return;
        }
        var cancel = e.target.closest('[data-composer-cancel]');
        if (cancel) {
            e.preventDefault();
            dismiss(cancel.closest('[data-composer-overlay]'));
            return;
        }
        if (e.target.matches && e.target.matches('[data-composer-overlay]')) {
            var root = $('[data-composer]', e.target);
            if (!root || !root._dirty) dismiss(e.target, true);   // a stray click never throws away typed text
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var o = $('.composer-overlay.open');
        if (o) dismiss(o);
    });

    /* ---------- photo shrinking ---------- */
    function loadBitmap(file) {
        if (window.createImageBitmap) {
            return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return viaImage(file); });
        }
        return viaImage(file);
    }
    function viaImage(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file), img = new Image();
            img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode')); };
            img.src = url;
        });
    }
    function toBlob(canvas, quality) {
        return new Promise(function (resolve) { canvas.toBlob(resolve, 'image/jpeg', quality); });
    }
    function optimize(file) {
        if (file.type === 'image/gif') return Promise.resolve(file);   // keep animation
        return loadBitmap(file).then(function (bmp) {
            var w = bmp.width || bmp.naturalWidth, h = bmp.height || bmp.naturalHeight;
            var scale = Math.min(1, MAX_SIDE / Math.max(w, h));
            var c = document.createElement('canvas');
            c.width = Math.max(1, Math.round(w * scale));
            c.height = Math.max(1, Math.round(h * scale));
            var ctx = c.getContext('2d');
            ctx.fillStyle = '#fff';                                      // PNG transparency -> white, not black
            ctx.fillRect(0, 0, c.width, c.height);
            ctx.drawImage(bmp, 0, 0, c.width, c.height);
            if (bmp.close) bmp.close();
            var tries = [0.86, 0.74, 0.62];
            function attempt(i) {
                return toBlob(c, tries[i]).then(function (blob) {
                    if (!blob) return file;
                    if (blob.size > MAX_BYTES * 0.95 && i < tries.length - 1) return attempt(i + 1);
                    if (scale === 1 && blob.size >= file.size && file.size <= MAX_BYTES) return file;   // already small
                    var name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                    return new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() });
                });
            }
            return attempt(0);
        }).catch(function () { return file; });
    }

    /* ---------- one composer ---------- */
    function init(root) {
        var overlay = root.closest('[data-composer-overlay]');
        var form = $('form', root);
        var fileInput = $('[data-composer-files]', root);
        var grid = $('[data-composer-grid]', root);
        var hint = $('[data-composer-hint]', root);
        var postBtn = $('.composer-post', root);
        var pickBtns = $$('[data-composer-pick]', root);
        var text = $('.composer-text', root);
        var max = parseInt(root.getAttribute('data-max'), 10) || 10;
        var items = [];          // newly chosen photos: { file, url, el }
        var busy = 0;
        root._dirty = false;

        function existingLeft() { return $$('[data-existing]', grid).filter(function (el) { return !el.hidden; }).length; }
        function total() { return existingLeft() + items.length; }
        function setDirty() { root._dirty = true; }

        function say(msg, isError) {
            hint.textContent = msg || '';
            hint.classList.toggle('is-error', !!isError);
        }
        function updateHint() {
            var n = total();
            if (!n) { say(''); return; }
            say(n + ' of ' + max + ' photos' + (n === 1 ? ' — the first photo is the cover.' : ' — the first photo is the cover.'));
        }
        function validate() {
            postBtn.disabled = busy > 0 || !form.checkValidity();
            pickBtns.forEach(function (b) { b.classList.toggle('is-full', total() >= max); });
            root.classList.toggle('has-photos', total() > 0);
        }
        function syncInput() {
            if (!window.DataTransfer) return;
            var dt = new DataTransfer();
            items.forEach(function (it) { dt.items.add(it.file); });
            fileInput.files = dt.files;
        }
        function addTile(it) {
            var d = document.createElement('div');
            d.className = 'cx-item';
            d.setAttribute('data-new', '1');
            var img = document.createElement('img');
            img.src = it.url; img.alt = '';
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'cx-remove'; b.setAttribute('aria-label', 'Remove photo'); b.innerHTML = '&times;';
            b.addEventListener('click', function () {
                URL.revokeObjectURL(it.url);
                items.splice(items.indexOf(it), 1);
                if (it.el.parentNode) it.el.parentNode.removeChild(it.el);
                syncInput(); setDirty(); updateHint(); validate();
            });
            d.appendChild(img); d.appendChild(b);
            it.el = d;
            grid.appendChild(d);
        }
        function addFiles(list) {
            var all = Array.prototype.slice.call(list || []);
            var files = all.filter(function (f) { return ACCEPT.test(f.type); });
            if (!files.length) { if (all.length) say('Please choose JPG, PNG, WEBP or GIF photos.', true); return; }
            var room = max - total();
            if (room <= 0) { say('A post holds up to ' + max + ' photos.', true); return; }
            var skipped = files.length - Math.min(files.length, room);
            files = files.slice(0, room);
            busy++; root.classList.add('is-busy'); say('Getting your photos ready…'); validate();
            Promise.all(files.map(optimize)).then(function (out) {
                out.forEach(function (f) {
                    if (f.size > MAX_BYTES) { say('"' + f.name + '" is over 3 MB even after shrinking — try a smaller one.', true); return; }
                    var it = { file: f, url: URL.createObjectURL(f), el: null };
                    items.push(it); addTile(it);
                });
                syncInput(); setDirty();
            }).then(function () {
                busy--; root.classList.remove('is-busy');
                if (skipped > 0) say('Only ' + max + ' photos fit in one post — ' + skipped + ' skipped.', true); else if (!hint.classList.contains('is-error')) updateHint();
                validate();
            });
        }

        /* choosing photos */
        pickBtns.forEach(function (b) { b.addEventListener('click', function () { if (total() < max) fileInput.click(); else say('A post holds up to ' + max + ' photos.', true); }); });
        fileInput.addEventListener('change', function () {
            var chosen = Array.prototype.slice.call(fileInput.files || []);
            fileInput.value = '';                        // the real list is rebuilt from `items`
            syncInput();
            addFiles(chosen);
        });
        /* remove a photo that is already saved (edit mode) */
        grid.addEventListener('click', function (e) {
            var rm = e.target.closest('[data-remove-existing]');
            if (!rm) return;
            var tile = rm.closest('[data-existing]');
            tile.hidden = true;
            var h = document.createElement('input');
            h.type = 'hidden'; h.name = 'remove_images[]'; h.value = rm.getAttribute('data-remove-existing');
            form.appendChild(h);
            setDirty(); updateHint(); validate();
        });
        /* drag & drop, paste */
        ['dragenter', 'dragover'].forEach(function (ev) {
            root.addEventListener(ev, function (e) {
                if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') > -1) { e.preventDefault(); root.classList.add('is-drop'); }
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            root.addEventListener(ev, function (e) {
                if (ev === 'dragleave' && root.contains(e.relatedTarget)) return;
                root.classList.remove('is-drop');
            });
        });
        root.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) { e.preventDefault(); addFiles(e.dataTransfer.files); }
        });
        text.addEventListener('paste', function (e) {
            var files = e.clipboardData && e.clipboardData.files;
            if (files && files.length) { e.preventDefault(); addFiles(files); }
        });

        /* optional sections (deadline, end time …) */
        function section(key) { return $('[data-section="' + key + '"]', root); }
        function toggleSection(key, on) {
            var s = section(key); if (!s) return;
            s.hidden = !on;
            $$('[data-section-toggle="' + key + '"]', root).forEach(function (b) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
            if (!on) $$('input, select, textarea', s).forEach(function (f) { f.value = ''; });
            else { var f = $('input, select, textarea', s); if (f) f.focus(); }
            setDirty(); validate();
        }
        $$('[data-section-toggle]', root).forEach(function (b) {
            b.addEventListener('click', function () {
                var key = b.getAttribute('data-section-toggle'), s = section(key);
                toggleSection(key, !!(s && s.hidden));
            });
        });
        $$('[data-section-off]', root).forEach(function (b) {
            b.addEventListener('click', function () { toggleSection(b.getAttribute('data-section-off'), false); });
        });

        /* text box grows with what you type, like Facebook */
        function grow() { text.style.height = 'auto'; text.style.height = Math.min(Math.max(text.scrollHeight, 96), Math.round(window.innerHeight * 0.4)) + 'px'; }
        text.addEventListener('input', grow);

        form.addEventListener('input', function () { setDirty(); validate(); });
        form.addEventListener('change', function () { setDirty(); validate(); });
        form.addEventListener('submit', function (e) {
            if (busy > 0 || !form.checkValidity()) { e.preventDefault(); form.reportValidity(); return; }
            postBtn.disabled = true;
            postBtn.textContent = 'Posting…';
        });

        root._reset = function () {
            items.forEach(function (it) { URL.revokeObjectURL(it.url); if (it.el && it.el.parentNode) it.el.parentNode.removeChild(it.el); });
            items = [];
            form.reset();
            $$('[data-section]', root).forEach(function (s) { if (!s.hasAttribute('data-keep')) { s.hidden = true; } });
            $$('[data-section-toggle]', root).forEach(function (b) { b.setAttribute('aria-pressed', 'false'); });
            if (window.DataTransfer) fileInput.files = new DataTransfer().files;
            root._dirty = false;
            say(''); grow(); validate();
        };

        grow(); updateHint(); validate();
        if (overlay && overlay.classList.contains('open')) { lock(true); }
    }

    $$('[data-composer]').forEach(init);
})();
