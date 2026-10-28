/**
 * CSO-NORSU Portal — UI behaviour
 *  - right-hand drawer on mobile (open / close / focus handling)
 *  - collapsible icon sidebar on desktop (state saved in localStorage)
 *  - click ripple on buttons and nav items
 *  - drawer links play a press animation, then navigate
 *  - theme (light / dark / system) with a circular reveal, plus the
 *    "glass effects" and "reduce animations" switches. All three are
 *    remembered in localStorage and applied before first paint by the
 *    snippet in includes/header.php.
 */
(function () {
    'use strict';

    var doc      = document;
    var toggle   = doc.getElementById('userMenuToggle');
    var drawer   = doc.getElementById('drawer');
    var backdrop = doc.getElementById('drawerBackdrop');
    var closeBtn = doc.getElementById('drawerClose');
    var lastFocus = null;

    function isOpen() { return drawer && drawer.classList.contains('open'); }

    function openDrawer() {
        if (!drawer || isOpen()) return;
        lastFocus = doc.activeElement;
        drawer.classList.remove('leaving');
        // restart the staggered entrance each time
        drawer.querySelectorAll('.drawer-link.is-pressed').forEach(function (l) { l.classList.remove('is-pressed'); });
        drawer.classList.add('open');
        if (backdrop) backdrop.classList.add('open');
        doc.body.classList.add('drawer-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'true');
        drawer.setAttribute('aria-hidden', 'false');
        setTimeout(function () { if (closeBtn) closeBtn.focus({ preventScroll: true }); }, 120);
    }

    function closeDrawer(returnFocus) {
        if (!drawer || !isOpen()) return;
        drawer.classList.remove('open');
        if (backdrop) backdrop.classList.remove('open');
        doc.body.classList.remove('drawer-open');
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        drawer.setAttribute('aria-hidden', 'true');
        if (returnFocus !== false && lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
    }

    if (toggle) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (railActive()) { setRailOpen(!railOpen()); return; }   // desktop: show / hide the sidebar
            isOpen() ? closeDrawer() : openDrawer();
        });
    }
    if (backdrop) backdrop.addEventListener('click', function () { closeDrawer(); });
    if (closeBtn) closeBtn.addEventListener('click', function () { closeDrawer(); });

    doc.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) { closeDrawer(); return; }
        // keep Tab inside the open drawer
        if (e.key === 'Tab' && isOpen()) {
            var items = drawer.querySelectorAll('a[href], button:not([disabled])');
            if (!items.length) return;
            var first = items[0], last = items[items.length - 1];
            if (e.shiftKey && doc.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && doc.activeElement === last) { e.preventDefault(); first.focus(); }
        }
    });

    /* ---------- desktop sidebar rail ---------- */
    /* The rail is always the full-width (expanded) sidebar: the burger shows and
       hides it, and there is no collapse / expand control any more. */
    var railMQ = window.matchMedia ? window.matchMedia('(min-width: 1025px)') : null;
    var rootEl = doc.documentElement;

    function railOpen() { return rootEl.getAttribute('data-rail-open') === '1'; }
    function setRailOpen(open, persist) {
        rootEl.setAttribute('data-rail-open', open ? '1' : '0');
        if (persist !== false) { try { localStorage.setItem('cso-rail-open', open ? '1' : '0'); } catch (e) {} }
        syncRailUI();
    }
    function railActive() { return !!(drawer && railMQ && railMQ.matches && doc.body.classList.contains('has-rail')); }

    function syncRailUI() {
        if (!drawer) return;
        var active = railActive();
        // On desktop the menu is a normal navigation landmark; on mobile it is the modal drawer again.
        if (active) {
            drawer.setAttribute('role', 'navigation');
            drawer.removeAttribute('aria-modal');
            drawer.setAttribute('aria-hidden', railOpen() ? 'false' : 'true');
            if (isOpen()) closeDrawer(false);
            if (toggle) {
                toggle.setAttribute('aria-expanded', railOpen() ? 'true' : 'false');
                toggle.setAttribute('aria-label', railOpen() ? 'Close menu' : 'Open menu');
            }
        } else {
            drawer.setAttribute('role', 'dialog');
            drawer.setAttribute('aria-modal', 'true');
            if (!isOpen()) drawer.setAttribute('aria-hidden', 'true');
            if (toggle) { toggle.setAttribute('aria-expanded', isOpen() ? 'true' : 'false'); toggle.setAttribute('aria-label', isOpen() ? 'Close menu' : 'Open menu'); }
        }
    }

    if (railMQ) {
        if (railMQ.addEventListener) railMQ.addEventListener('change', syncRailUI); else if (railMQ.addListener) railMQ.addListener(syncRailUI);
    }
    syncRailUI();

    /* ---------- ripple ---------- */
    function ripple(el, e) {
        var r = el.getBoundingClientRect();
        var size = Math.max(r.width, r.height) * 2;
        var span = doc.createElement('span');
        span.className = 'ripple';
        span.style.width = span.style.height = size + 'px';
        span.style.left = (e.clientX - r.left - size / 2) + 'px';
        span.style.top  = (e.clientY - r.top  - size / 2) + 'px';
        el.appendChild(span);
        setTimeout(function () { if (span.parentNode) span.parentNode.removeChild(span); }, 700);
    }

    doc.addEventListener('click', function (e) {
        var t = e.target.closest('.btn, .drawer-link, .user-menu-toggle');
        if (t && !t.disabled && e.clientX !== undefined) ripple(t, e);

        var link = e.target.closest('.drawer-link');
        if (!link || !drawer || !drawer.contains(link)) return;

        // "My QR Code" opens a modal instead of navigating
        if (link.getAttribute('data-action') === 'qr') {
            link.classList.add('is-pressed');
            setTimeout(function () {
                closeDrawer(false);
                if (typeof window.openQrModal === 'function') window.openQrModal();
            }, 260);
            return;
        }

        var href = link.getAttribute('href');
        // opening Notifications marks everything read — clear the badge and the red dot right away
        if (href && /notifications\.php/.test(href)) {
            var nb = link.querySelector('.badge'); if (nb) nb.classList.add('badge-out');
            var nd = doc.querySelector('.user-menu-dot'); if (nd) nd.classList.add('dot-out');
        }
        if (!href || href.charAt(0) === '#') return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        if (link.getAttribute('target') === '_blank') return;

        if (reducedMotion()) return;   // navigate straight away

        // let the press animation play, slide the drawer away, then go
        e.preventDefault();
        link.classList.add('is-pressed');
        setTimeout(function () { drawer.classList.add('leaving'); }, 140);
        setTimeout(function () { window.location.href = link.href; }, 340);
    });


    /* ---------- theme, glass, motion ---------- */
    var root = doc.documentElement;
    var mq   = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function store(key, val) { try { if (val === null) localStorage.removeItem(key); else localStorage.setItem(key, val); } catch (e) {} }
    function reducedMotion() {
        return root.getAttribute('data-motion') === 'reduced' ||
               (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }
    function resolveDark(pref) { return pref === 'dark' || (pref === 'system' && !!(mq && mq.matches)); }

    function syncThemeUI(pref) {
        var seg = doc.getElementById('themeSeg');
        if (!seg) return;
        var order = ['light', 'dark', 'system'];
        seg.style.setProperty('--seg-i', Math.max(0, order.indexOf(pref)));
        seg.querySelectorAll('.seg-btn').forEach(function (b) {
            b.setAttribute('aria-checked', b.getAttribute('data-theme-pref') === pref ? 'true' : 'false');
        });
    }
    function applyTheme(pref) {
        var dark = resolveDark(pref);
        root.setAttribute('data-theme', dark ? 'dark' : 'light');
        root.setAttribute('data-theme-pref', pref);
        var meta = doc.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', dark ? '#12241B' : '#FFFFFF');
        syncThemeUI(pref);
    }
    function setTheme(pref, originEl) {
        store('cso-theme', pref);
        var current = root.getAttribute('data-theme');
        if (current === (resolveDark(pref) ? 'dark' : 'light')) { applyTheme(pref); return; }

        // circular reveal from the clicked control (View Transitions API)
        if (doc.startViewTransition && !reducedMotion() && originEl) {
            var r = originEl.getBoundingClientRect();
            var x = r.left + r.width / 2, y = r.top + r.height / 2;
            var radius = Math.hypot(Math.max(x, window.innerWidth - x), Math.max(y, window.innerHeight - y));
            var vt = doc.startViewTransition(function () { applyTheme(pref); });
            vt.ready.then(function () {
                root.animate(
                    { clipPath: ['circle(0px at ' + x + 'px ' + y + 'px)', 'circle(' + radius + 'px at ' + x + 'px ' + y + 'px)'] },
                    { duration: 700, easing: 'cubic-bezier(.22,1,.36,1)', pseudoElement: '::view-transition-new(root)' }
                );
            }).catch(function () {});
            return;
        }
        // fallback: cross-fade the colours
        root.classList.add('theme-switching');
        applyTheme(pref);
        setTimeout(function () { root.classList.remove('theme-switching'); }, 500);
    }

    // top-bar sun / moon button flips between light and dark
    var themeToggle = doc.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', themeToggle);
        });
    }
    // Light / Dark / System in the drawer
    doc.querySelectorAll('.seg-btn[data-theme-pref]').forEach(function (btn) {
        btn.addEventListener('click', function () { setTheme(btn.getAttribute('data-theme-pref'), btn); });
    });
    // follow the operating system while "System" is selected
    if (mq) {
        var onSystemChange = function () { if (root.getAttribute('data-theme-pref') === 'system') applyTheme('system'); };
        if (mq.addEventListener) mq.addEventListener('change', onSystemChange); else if (mq.addListener) mq.addListener(onSystemChange);
    }
    syncThemeUI(root.getAttribute('data-theme-pref') || 'system');

    // ---- accent colour, text size, switches, reset ----
    function setAttrPref(attr, key, value, defaultValue) {
        if (value === defaultValue) { root.removeAttribute(attr); store(key, null); }
        else { root.setAttribute(attr, value); store(key, value); }
    }
    function syncSegment(segId, current) {
        var seg = doc.getElementById(segId);
        if (!seg) return;
        var btns = seg.querySelectorAll('.seg-btn'), idx = 0;
        btns.forEach(function (b, i) {
            var on = (b.getAttribute('data-text-size') || b.getAttribute('data-theme-pref') || b.getAttribute('data-sidebar-side')) === current;
            b.setAttribute('aria-checked', on ? 'true' : 'false');
            if (on) idx = i;
        });
        seg.style.setProperty('--seg-i', idx);
    }
    function syncSwatches() {
        var cur = root.getAttribute('data-accent') || 'green';
        doc.querySelectorAll('.swatch[data-accent]').forEach(function (s) {
            s.setAttribute('aria-checked', s.getAttribute('data-accent') === cur ? 'true' : 'false');
        });
    }
    function syncSwitch(row) {
        var key = row.getAttribute('data-setting'), on = false;
        if (key === 'glass')   on = root.getAttribute('data-glass') !== 'off';
        if (key === 'motion')  on = root.getAttribute('data-motion') === 'reduced';
        if (key === 'density') on = root.getAttribute('data-density') === 'compact';
        row.setAttribute('aria-checked', on ? 'true' : 'false');
    }
    function syncAllSettings() {
        syncThemeUI(root.getAttribute('data-theme-pref') || 'system');
        syncSegment('textSeg', root.getAttribute('data-text') || 'md');
        syncSegment('sideSeg', root.getAttribute('data-sidebar') === 'left' ? 'left' : 'right');
        syncSwatches();
        doc.querySelectorAll('.setting-row[data-setting]').forEach(syncSwitch);
    }

    doc.querySelectorAll('.swatch[data-accent]').forEach(function (sw) {
        sw.addEventListener('click', function () {
            setAttrPref('data-accent', 'cso-accent', sw.getAttribute('data-accent'), 'green');
            syncSwatches();
        });
    });
    doc.querySelectorAll('.seg-btn[data-text-size]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var v = btn.getAttribute('data-text-size');
            setAttrPref('data-text', 'cso-text', v, 'md');
            syncSegment('textSeg', v);
        });
    });
    doc.querySelectorAll('.setting-row[data-setting]').forEach(function (row) {
        row.addEventListener('click', function () {
            var on = row.getAttribute('aria-checked') !== 'true';
            var key = row.getAttribute('data-setting');
            if (key === 'glass')   { if (on) { root.removeAttribute('data-glass'); store('cso-glass', null); } else { root.setAttribute('data-glass', 'off'); store('cso-glass', 'off'); } }
            if (key === 'motion')  { if (on) { root.setAttribute('data-motion', 'reduced'); store('cso-motion', 'reduced'); } else { root.removeAttribute('data-motion'); store('cso-motion', null); } }
            if (key === 'density') { if (on) { root.setAttribute('data-density', 'compact'); store('cso-density', 'compact'); } else { root.removeAttribute('data-density'); store('cso-density', null); } }
            row.setAttribute('aria-checked', on ? 'true' : 'false');
        });
    });
    /* ---------- Sidebar side: left or right. If the sidebar is open it slides out the old
       side, swaps, and slides back in from the new side. ---------- */
    function applySidebarSide(side) {
        if (side === 'left') { root.setAttribute('data-sidebar', 'left'); store('cso-sidebar', 'left'); }
        else { root.removeAttribute('data-sidebar'); store('cso-sidebar', null); }
        syncSegment('sideSeg', side === 'left' ? 'left' : 'right');
    }
    var sideBusy = false;
    doc.querySelectorAll('.seg-btn[data-sidebar-side]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var side = btn.getAttribute('data-sidebar-side') === 'left' ? 'left' : 'right';
            var current = root.getAttribute('data-sidebar') === 'left' ? 'left' : 'right';
            if (side === current || sideBusy) return;
            var onRail = railActive();
            var wasOpen = drawer && (onRail ? railOpen() : drawer.classList.contains('open'));
            if (!wasOpen || !toggle) { applySidebarSide(side); return; }
            sideBusy = true;
            syncSegment('sideSeg', side);                 // move the switch right away
            toggle.click();                               // slide out toward the old side
            setTimeout(function () {
                root.classList.add('side-swap');          // no animation while it changes sides
                applySidebarSide(side);
                void drawer.offsetWidth;
                root.classList.remove('side-swap');
                setTimeout(function () { toggle.click(); sideBusy = false; }, 40);   // slide back in from the new side
            }, onRail ? 520 : 600);
        });
    });
    var resetBtn = doc.querySelector('[data-setting-reset]');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            ['cso-theme', 'cso-glass', 'cso-motion', 'cso-accent', 'cso-text', 'cso-density', 'cso-sidebar'].forEach(function (k) { store(k, null); });
            ['data-glass', 'data-motion', 'data-accent', 'data-text', 'data-density', 'data-sidebar'].forEach(function (a) { root.removeAttribute(a); });
            store('cso-settings-open', null); setSettingsOpen(false, false);
            setTheme('system', resetBtn);
            syncAllSettings();
        });
    }
    syncAllSettings();

    /* ---------- "Copy link" buttons on detail pages ---------- */
    doc.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-copy-link]');
        if (!btn) return;
        var url = window.location.href.split('#')[0];
        var done = function () {
            var old = btn.getAttribute('data-label') || btn.textContent;
            btn.setAttribute('data-label', old);
            btn.textContent = 'Link copied';
            setTimeout(function () { btn.textContent = old; }, 1800);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, done);
        } else {
            var ta = doc.createElement('textarea');
            ta.value = url; doc.body.appendChild(ta); ta.select();
            try { doc.execCommand('copy'); } catch (err) {}
            doc.body.removeChild(ta); done();
        }
    });

    /* ---------- settings gear: show / hide appearance, accent, text size ---------- */
    var sToggle = doc.getElementById('settingsToggle');
    var sGroup  = doc.getElementById('settingsGroup');
    function setSettingsOpen(open, persist) {
        if (!sToggle || !sGroup) return;
        sGroup.classList.toggle('open', open);
        sToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        sToggle.setAttribute('aria-label', open ? 'Hide settings' : 'Show settings');
        if (persist) store('cso-settings-open', open ? '1' : null);
    }
    if (sToggle && sGroup) {
        var savedOpen = false;
        try { savedOpen = localStorage.getItem('cso-settings-open') === '1'; } catch (err) {}
        setSettingsOpen(savedOpen, false);
        sToggle.addEventListener('click', function () { setSettingsOpen(sToggle.getAttribute('aria-expanded') !== 'true', true); });
    }

    /* ---------- login: "Forgot password?" ---------- */
    doc.querySelectorAll('[data-forgot-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var box = doc.getElementById(btn.getAttribute('aria-controls'));
            var open = btn.getAttribute('aria-expanded') !== 'true';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (box) { box.hidden = !open; if (open) box.classList.add('show'); else box.classList.remove('show'); }
        });
    });

    /* ---------- profile: status-note editor + options menu ---------- */
    doc.querySelectorAll('[data-note-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var form = doc.getElementById(btn.getAttribute('aria-controls'));
            var open = btn.getAttribute('aria-expanded') !== 'true';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (form) {
                form.hidden = !open;
                if (open) { var inp = form.querySelector('input[type="text"]'); if (inp) { inp.focus(); inp.select(); } }
            }
        });
    });
    doc.querySelectorAll('[data-menu-toggle]').forEach(function (btn) {
        var menu = btn.parentElement.querySelector('.fb-menu');
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = btn.getAttribute('aria-expanded') !== 'true';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (menu) menu.hidden = !open;
        });
        if (menu) menu.addEventListener('click', function () { btn.setAttribute('aria-expanded', 'false'); menu.hidden = true; });
    });
    doc.addEventListener('click', function (e) {
        doc.querySelectorAll('.fb-menu:not([hidden])').forEach(function (m) {
            if (!m.parentElement.contains(e.target)) {
                m.hidden = true;
                var b = m.parentElement.querySelector('[data-menu-toggle]');
                if (b) b.setAttribute('aria-expanded', 'false');
            }
        });
    });

    // bfcache: coming back with the Back button should not show a half-closed drawer
    window.addEventListener('pageshow', function (e) {
        if (e.persisted && drawer) {
            drawer.classList.remove('open', 'leaving');
            if (backdrop) backdrop.classList.remove('open');
            doc.body.classList.remove('drawer-open');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }
        if (typeof syncRailUI === 'function') syncRailUI();
    });

    /* ---------- file inputs: icon button instead of the native "Choose file" ---------- */
    doc.querySelectorAll('input[type="file"]:not([hidden])').forEach(function (input) {
        if (input.closest('.file-pick')) return;
        var wrap = doc.createElement('span');
        wrap.className = 'file-pick';
        var btn = doc.createElement('span');
        btn.className = 'file-pick-btn';
        btn.setAttribute('aria-hidden', 'true');
        btn.innerHTML = '<svg class="icon"><use href="#i-upload"/></svg>';
        var name = doc.createElement('span');
        name.className = 'file-pick-name';
        name.textContent = input.multiple ? 'No files chosen' : 'No file chosen';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);
        wrap.appendChild(btn);
        wrap.appendChild(name);
        if (!input.getAttribute('aria-label') && !input.id) input.setAttribute('aria-label', input.multiple ? 'Choose files' : 'Choose file');
        input.title = input.multiple ? 'Choose files' : 'Choose file';
        input.addEventListener('change', function () {
            var n = input.files ? input.files.length : 0;
            name.textContent = n === 0 ? (input.multiple ? 'No files chosen' : 'No file chosen')
                             : n === 1 ? input.files[0].name : n + ' files selected';
            wrap.classList.toggle('has-file', n > 0);
        });
    });

    /* ---------- page loader + navigation progress bar ---------- */
    (function () {
        var loader = doc.getElementById('page-loader');
        var bar = doc.getElementById('nav-progress');
        var started = (window.performance && performance.now) ? performance.now() : 0;
        var MIN_SHOW = 350;   /* ms: the skeleton stays up just long enough to avoid a flash */
        var hidden = false;
        function hideLoader() {
            if (hidden || !loader) return;
            hidden = true;
            loader.classList.add('is-done');
            setTimeout(function () { if (loader.parentNode) loader.parentNode.removeChild(loader); }, 600);
        }
        function whenReady() {
            var elapsed = (window.performance && performance.now) ? performance.now() - started : MIN_SHOW;
            setTimeout(hideLoader, Math.max(0, MIN_SHOW - elapsed));
        }
        if (doc.readyState === 'complete') whenReady();
        else window.addEventListener('load', whenReady);
        setTimeout(hideLoader, 8000);   /* never trap anyone behind the loader */

        function startBar() { if (!bar) return; bar.classList.remove('is-done'); bar.classList.add('is-active'); clearTimeout(startBar.t); startBar.t = setTimeout(resetBar, 12000); }
        function resetBar() { if (!bar) return; bar.classList.remove('is-active'); bar.classList.add('is-done'); }
        window.addEventListener('pageshow', function (e) { if (e.persisted) { hideLoader(); resetBar(); } });

        doc.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a[href]') : null;
            if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            if (a.target && a.target !== '_self') return;
            if (a.hasAttribute('download') || /export|download/i.test(a.getAttribute('href'))) return;
            var url; try { url = new URL(a.href, location.href); } catch (err) { return; }
            if (url.origin !== location.origin || !/^https?:$/.test(url.protocol)) return;
            if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
            startBar();
        });
        doc.addEventListener('submit', function (e) {
            var f = e.target;
            if (e.defaultPrevented || (f.target && f.target !== '_self')) return;
            startBar();
        });
    })();

    /* ---------- photo viewer (lightbox) for post collages + "..." menus ---------- */
    (function () {
        var lb = null, imgs = [], idx = 0, startX = null, lastFocus = null;
        function show(i) {
            idx = (i + imgs.length) % imgs.length;
            lb.querySelector('img').src = imgs[idx];
            lb.querySelector('.lb-count').textContent = (idx + 1) + ' / ' + imgs.length;
            lb.classList.toggle('lb-single', imgs.length < 2);
        }
        function close() {
            if (!lb) return;
            doc.removeEventListener('keydown', onKey);
            lb.parentNode.removeChild(lb); lb = null;
            doc.body.style.overflow = '';
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }
        function onKey(e) {
            if (e.key === 'Escape') close();
            else if (e.key === 'ArrowLeft') show(idx - 1);
            else if (e.key === 'ArrowRight') show(idx + 1);
        }
        function open(list, start, trigger) {
            imgs = list; lastFocus = trigger;
            lb = doc.createElement('div');
            lb.className = 'lb';
            lb.setAttribute('role', 'dialog'); lb.setAttribute('aria-modal', 'true'); lb.setAttribute('aria-label', 'Photo viewer');
            lb.innerHTML = '<button type="button" class="lb-close" aria-label="Close">&times;</button>' +
                '<button type="button" class="lb-prev" aria-label="Previous photo">&#8249;</button>' +
                '<img alt="">' +
                '<button type="button" class="lb-next" aria-label="Next photo">&#8250;</button>' +
                '<span class="lb-count"></span>';
            doc.body.appendChild(lb);
            doc.body.style.overflow = 'hidden';
            show(start);
            lb.addEventListener('click', function (e) {
                if (e.target.closest('.lb-close') || e.target === lb) close();
                else if (e.target.closest('.lb-prev')) show(idx - 1);
                else if (e.target.closest('.lb-next') || e.target.tagName === 'IMG') show(idx + 1);
            });
            lb.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
            lb.addEventListener('touchend', function (e) {
                if (startX === null) return;
                var dx = e.changedTouches[0].clientX - startX; startX = null;
                if (Math.abs(dx) > 50) show(dx < 0 ? idx + 1 : idx - 1);
            });
            doc.addEventListener('keydown', onKey);
            lb.querySelector('.lb-close').focus();
        }
        doc.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a.pg-item') : null;
            if (a && !(e.metaKey || e.ctrlKey || e.shiftKey)) {
                e.preventDefault();
                var wrap = a.closest('[data-gallery]');
                var list = [].map.call(wrap.querySelectorAll('a.pg-item'), function (x) { return x.getAttribute('href'); });
                open(list, [].indexOf.call(wrap.querySelectorAll('a.pg-item'), a), a);
                return;
            }
            // close any open "..." menu when clicking elsewhere
            doc.querySelectorAll('details.fbpost-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
        });
        doc.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') doc.querySelectorAll('details.fbpost-menu[open]').forEach(function (d) { d.removeAttribute('open'); });
        });
    })();

    /* ---------- post "See more" / "See less": expands the full text in place ---------- */
    doc.addEventListener('click', function (e) {
        var more = e.target.closest ? e.target.closest('[data-see-more]') : null;
        if (!more) return;
        e.preventDefault();
        var box = more.closest('.fbpost-text');
        var full = box.querySelector('.fbpost-full'), short = box.querySelector('.fbpost-short');
        var expand = full.hidden;
        full.hidden = !expand;
        short.hidden = expand;
        more.textContent = expand ? 'See less' : 'See more';
        more.setAttribute('aria-expanded', expand ? 'true' : 'false');
    });
})();

/* ---------- Settings: "Show me in Members" switch (saved on the server) ---------- */
(function () {
    var row = document.querySelector('[data-members-toggle]');
    if (!row) return;
    var busy = false;
    row.addEventListener('click', function () {
        if (busy) return;
        var was = row.getAttribute('aria-checked') === 'true';
        var next = !was;
        row.setAttribute('aria-checked', next ? 'true' : 'false');   // instant feedback
        busy = true;
        var body = new URLSearchParams();
        body.set('csrf_token', row.getAttribute('data-csrf') || '');
        body.set('value', next ? '1' : '0');
        fetch(row.getAttribute('data-endpoint'), {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) throw new Error((d && d.error) || 'Failed');
        }).catch(function () {
            row.setAttribute('aria-checked', was ? 'true' : 'false'); // put it back if saving failed
            alert('Could not save that setting. Please try again.');
        }).then(function () { busy = false; });
    });
})();

/* ---------- Admin Settings switches saved on the server (approve new members, moving Home strips) ---------- */
(function () {
    document.querySelectorAll('[data-approval-toggle]').forEach(function (row) {
        var note = row.querySelector('small');
        var busy = false;
        function say(on) { if (note) note.textContent = row.getAttribute(on ? 'data-on-text' : 'data-off-text') || ''; }
        row.addEventListener('click', function () {
            if (busy) return;
            var was = row.getAttribute('aria-checked') === 'true';
            var next = !was;
            row.setAttribute('aria-checked', next ? 'true' : 'false');   // instant feedback
            say(next);
            busy = true;
            var body = new URLSearchParams();
            body.set('csrf_token', row.getAttribute('data-csrf') || '');
            body.set('value', next ? '1' : '0');
            fetch(row.getAttribute('data-endpoint'), {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString()
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.ok) throw new Error((d && d.error) || 'Failed');
            }).catch(function () {
                row.setAttribute('aria-checked', was ? 'true' : 'false'); // put it back if saving failed
                say(was);
                alert('Could not save that setting. Please try again.');
            }).then(function () { busy = false; });
        });
    });
})();

/* ---------- Install as an app: save the Map and Curriculum for offline use ---------- */
(function () {
    'use strict';
    if (!('serviceWorker' in navigator)) return;
    var secure = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if (!secure) return;
    var base = (document.querySelector('link[rel="manifest"]') || {}).href || '';
    base = base.replace(/manifest\.php.*$/, '');
    window.addEventListener('load', function () {
        navigator.serviceWorker.register(base + 'sw.js').catch(function () { /* the site works without it */ });
    });
})();


/* ---------- Print: open every curriculum year first, then put them back ---------- */
(function () {
    'use strict';
    var reopened = [];
    window.addEventListener('beforeprint', function () {
        reopened = [];
        document.querySelectorAll('details:not([open])').forEach(function (d) {
            if (d.classList.contains('curr-year')) { d.setAttribute('open', ''); reopened.push(d); }
        });
    });
    window.addEventListener('afterprint', function () {
        reopened.forEach(function (d) { d.removeAttribute('open'); });
        reopened = [];
    });
})();


/* ---------- Password fields: show the rules up front and tick them off as the person types ---------- */
(function () {
    'use strict';
    document.querySelectorAll('input[data-pw-new]').forEach(function (pw) {
        var list = document.getElementById(pw.getAttribute('data-pw-rules'));
        var conf = document.getElementById(pw.getAttribute('data-pw-confirm'));
        if (!list) return;
        var len = list.querySelector('[data-rule="len"]');
        var match = list.querySelector('[data-rule="match"]');
        function mark(li, state, label) {
            if (!li) return;
            li.className = state === null ? '' : (state ? 'ok' : 'bad');
            li.setAttribute('data-state', state === null ? 'pending' : (state ? 'Done' : 'Needed'));
        }
        function check() {
            var typed = pw.value.length > 0 || (conf && conf.value.length > 0);
            if (!typed) { mark(len, null); mark(match, null); if (conf) conf.setCustomValidity(''); return; }
            mark(len, pw.value.length >= 10);
            if (conf && conf.value.length > 0) {
                var same = pw.value === conf.value;
                mark(match, same);
                conf.setCustomValidity(same ? '' : 'The two passwords do not match.');
            } else { mark(match, null); }
        }
        pw.addEventListener('input', check);
        if (conf) conf.addEventListener('input', check);
    });
})();


/* ---------- Header search: icon opens an expanding search bar ---------- */
(function () {
    var toggle = document.getElementById('searchToggle');
    var form = document.getElementById('topbarSearch');
    if (!toggle || !form) return;
    var bar = toggle.closest('.topbar');
    var input = document.getElementById('topbarSearchInput');
    var focusables = form.querySelectorAll('input, button');

    function setOpen(open) {
        bar.classList.toggle('search-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        form.setAttribute('aria-hidden', open ? 'false' : 'true');
        focusables.forEach(function (el) { el.tabIndex = open ? 0 : -1; });
        if (open) { setTimeout(function () { input.focus(); input.select(); }, 60); }
        else { toggle.focus(); }
    }
    toggle.addEventListener('click', function () { setOpen(!bar.classList.contains('search-open')); });
    document.getElementById('searchClose').addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && bar.classList.contains('search-open')) setOpen(false);
        // "/" jumps to search when you are not typing in a field
        if (e.key === '/' && !bar.classList.contains('search-open') && !/^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '') && !(document.activeElement || {}).isContentEditable) {
            e.preventDefault(); setOpen(true);
        }
    });
    form.addEventListener('submit', function (e) {
        if (input.value.trim().length < 1) { e.preventDefault(); input.focus(); }
    });
})();
