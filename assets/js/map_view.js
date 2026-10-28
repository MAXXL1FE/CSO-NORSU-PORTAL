/**
 * Interactive campus map: zoom in / out, drag to move, pinch with two fingers on phones.
 *
 * Markup (map.php):
 *   #map-viewport  = the window you look through (clips the picture)
 *   #map-world     = the picture + footpaths + route + pins. Only this element is moved and scaled.
 *
 * Lines are drawn in SVG layers that use the picture's own pixel size as units (2048 x 2018).
 * After every zoom, every element marked data-px="N" is given a stroke that is N screen pixels wide,
 * so lines and dashes keep the same thickness on screen at any zoom level.
 * Pins are shrunk by the inverse of the zoom (CSS variable --inv) so they stay the same size too.
 *
 * Public API: window.CSOMapView = { layer, restyle, zoomIn, zoomOut, reset, focus, fit, onChange }
 */
(function () {
    'use strict';

    var IMG_W = 2048, IMG_H = 2018, MIN = 1, MAX = 5, NS = 'http://www.w3.org/2000/svg';
    var vp = document.getElementById('map-viewport');
    var world = document.getElementById('map-world');
    if (!vp || !world) return;

    var s = 1, tx = 0, ty = 0;
    var listeners = [];
    var btnIn = document.querySelector('[data-zoom="in"]');
    var btnOut = document.querySelector('[data-zoom="out"]');
    var btnReset = document.querySelector('[data-zoom="reset"]');

    function box() { return { w: vp.clientWidth || 1, h: vp.clientHeight || 1 }; }

    function clampPos() {
        var b = box();
        tx = Math.min(0, Math.max(b.w - b.w * s, tx));
        ty = Math.min(0, Math.max(b.h - b.h * s, ty));
    }

    /* Give every line its on-screen thickness (px) at the current zoom. */
    function restyle() {
        var b = box(), u = (IMG_W / b.w) / s;            // picture units per screen pixel
        world.style.setProperty('--u', String(u));
        var els = world.querySelectorAll('[data-px]');
        for (var i = 0; i < els.length; i++) {
            var el = els[i], px = parseFloat(el.getAttribute('data-px'));
            el.style.strokeWidth = String(px * u);
            var dash = el.getAttribute('data-dash');
            if (dash) {
                el.style.strokeDasharray = dash.split(/\s+/).map(function (n) { return String(parseFloat(n) * u); }).join(' ');
            }
        }
    }

    function apply(animate) {
        clampPos();
        world.classList.toggle('is-anim', !!animate);
        world.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + s + ')';
        world.style.setProperty('--inv', String(1 / s));
        restyle();
        if (btnIn) btnIn.disabled = s >= MAX - 0.001;
        if (btnOut) btnOut.disabled = s <= MIN + 0.001;
        if (btnReset) btnReset.disabled = s <= MIN + 0.001 && tx === 0 && ty === 0;
        for (var i = 0; i < listeners.length; i++) listeners[i](s);
    }

    /* Zoom to scale ns while the point (cx, cy) of the viewport stays where it is. */
    function zoomAt(cx, cy, ns, animate) {
        ns = Math.max(MIN, Math.min(MAX, ns));
        tx = cx - (cx - tx) * ns / s;
        ty = cy - (cy - ty) * ns / s;
        s = ns;
        apply(animate);
    }

    function zoomBy(f) { var b = box(); zoomAt(b.w / 2, b.h / 2, s * f, true); }
    function reset() { s = 1; tx = 0; ty = 0; apply(true); }

    /* Centre the picture on a point (in % of the picture), zooming in if we are zoomed out. */
    function focus(xPct, yPct, minZoom) {
        var b = box(), ns = Math.max(s, minZoom || 2);
        s = Math.min(MAX, ns);
        tx = b.w / 2 - (xPct / 100) * b.w * s;
        ty = b.h / 2 - (yPct / 100) * b.h * s;
        apply(true);
    }

    /* Zoom so that a rectangle (in % of the picture) fills the view, with some air around it. */
    function fit(x1, y1, x2, y2) {
        var b = box(), bw = Math.max(0.04, (x2 - x1) / 100), bh = Math.max(0.04, (y2 - y1) / 100);
        s = Math.max(MIN, Math.min(MAX, Math.min(1 / (bw * 1.45), 1 / (bh * 1.45))));
        tx = b.w / 2 - ((x1 + x2) / 200) * b.w * s;
        ty = b.h / 2 - ((y1 + y2) / 200) * b.h * s;
        apply(true);
    }

    /* ---- Mouse wheel / trackpad ---- */
    vp.addEventListener('wheel', function (e) {
        e.preventDefault();
        var r = vp.getBoundingClientRect();
        var k = e.ctrlKey ? 0.01 : (e.deltaMode === 1 ? 0.05 : 0.0016);   // ctrl+wheel = trackpad pinch
        zoomAt(e.clientX - r.left, e.clientY - r.top, s * Math.exp(-e.deltaY * k), false);
    }, { passive: false });

    /* ---- Drag with one pointer, pinch with two ---- */
    var ptrs = {}, drag = null, pinch = null, moved = false, swallow = false, lastTap = null;

    function ids() { return Object.keys(ptrs); }
    function local(p) { var r = vp.getBoundingClientRect(); return { x: p.x - r.left, y: p.y - r.top }; }

    function startPinch() {
        var k = ids(), a = ptrs[k[0]], c = ptrs[k[1]];
        var mid = local({ x: (a.x + c.x) / 2, y: (a.y + c.y) / 2 });
        pinch = { d: Math.max(10, Math.hypot(a.x - c.x, a.y - c.y)), s: s, wx: (mid.x - tx) / s, wy: (mid.y - ty) / s };
        drag = null; moved = true;
        k.forEach(function (id) { try { vp.setPointerCapture(Number(id)); } catch (err) { /* ignore */ } });
        vp.classList.add('is-grabbing');
    }

    vp.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'mouse' && e.button !== 0) return;
        ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
        world.classList.remove('is-anim');
        if (ids().length === 1) { drag = { x: e.clientX, y: e.clientY, tx: tx, ty: ty }; moved = false; }
        else if (ids().length === 2) startPinch();
    });

    vp.addEventListener('pointermove', function (e) {
        if (!ptrs[e.pointerId]) return;
        ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
        if (pinch && ids().length >= 2) {
            var k = ids(), a = ptrs[k[0]], c = ptrs[k[1]];
            var ns = Math.max(MIN, Math.min(MAX, pinch.s * Math.hypot(a.x - c.x, a.y - c.y) / pinch.d));
            var mid = local({ x: (a.x + c.x) / 2, y: (a.y + c.y) / 2 });
            s = ns; tx = mid.x - pinch.wx * s; ty = mid.y - pinch.wy * s;
            apply(false);
        } else if (drag) {
            var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
            if (!moved && Math.hypot(dx, dy) > 6) {
                moved = true;
                try { vp.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
                vp.classList.add('is-grabbing');
            }
            if (moved) { tx = drag.tx + dx; ty = drag.ty + dy; apply(false); }
        }
    });

    function release(e) {
        if (!ptrs[e.pointerId]) return;
        var wasTap = !moved && ids().length === 1 && e.type === 'pointerup';
        delete ptrs[e.pointerId];
        var left = ids();
        if (left.length === 1) {                                   // one finger lifted: carry on dragging with the other
            var p = ptrs[left[0]];
            drag = { x: p.x, y: p.y, tx: tx, ty: ty }; pinch = null; moved = true;
        } else if (left.length === 0) {
            if (moved) { swallow = true; setTimeout(function () { swallow = false; }, 0); }
            vp.classList.remove('is-grabbing');
            drag = null; pinch = null;
            /* double tap on empty map (touch) = zoom in around the finger */
            if (wasTap && e.pointerType === 'touch' && !(e.target.closest && e.target.closest('.map-pin'))) {
                var now = Date.now(), r = vp.getBoundingClientRect();
                if (lastTap && now - lastTap.t < 320 && Math.hypot(e.clientX - lastTap.x, e.clientY - lastTap.y) < 28) {
                    zoomAt(e.clientX - r.left, e.clientY - r.top, s * 2, true); lastTap = null;
                } else { lastTap = { t: now, x: e.clientX, y: e.clientY }; }
            }
        }
    }
    vp.addEventListener('pointerup', release);
    vp.addEventListener('pointercancel', release);
    vp.addEventListener('lostpointercapture', function (e) { if (ptrs[e.pointerId] && e.pointerType !== 'mouse') release(e); });

    /* a drag that ends on a pin must not count as a tap on that pin */
    vp.addEventListener('click', function (e) { if (swallow) { e.stopPropagation(); e.preventDefault(); } }, true);

    /* mouse: double click zooms in on empty map */
    vp.addEventListener('dblclick', function (e) {
        if (e.target.closest && e.target.closest('.map-pin')) return;
        var r = vp.getBoundingClientRect();
        zoomAt(e.clientX - r.left, e.clientY - r.top, s * 2, true);
    });

    /* keyboard: + - 0 and arrow keys when the map has focus */
    vp.addEventListener('keydown', function (e) {
        if (e.target !== vp) return;
        var step = 60, handled = true;
        if (e.key === '+' || e.key === '=') zoomBy(1.4);
        else if (e.key === '-' || e.key === '_') zoomBy(1 / 1.4);
        else if (e.key === '0') reset();
        else if (e.key === 'ArrowLeft') { tx += step; apply(false); }
        else if (e.key === 'ArrowRight') { tx -= step; apply(false); }
        else if (e.key === 'ArrowUp') { ty += step; apply(false); }
        else if (e.key === 'ArrowDown') { ty -= step; apply(false); }
        else handled = false;
        if (handled) e.preventDefault();
    });

    /* ---- On-screen buttons ---- */
    if (btnIn) btnIn.addEventListener('click', function () { zoomBy(1.5); });
    if (btnOut) btnOut.addEventListener('click', function () { zoomBy(1 / 1.5); });
    if (btnReset) btnReset.addEventListener('click', reset);

    /* ---- Legend open / close ---- */
    var lgBtn = document.getElementById('legend-toggle'), lg = document.getElementById('map-legend');
    if (lgBtn && lg) {
        function setLegend(open) { lg.hidden = !open; lgBtn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        setLegend(false);                                         // always starts closed; opens only when the Legend button is clicked
        lgBtn.addEventListener('click', function () { setLegend(lg.hidden); });
    }

    window.addEventListener('resize', function () { apply(false); });

    /* ---- A drawing layer (SVG) that moves and zooms with the picture ---- */
    function layer(cls) {
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + IMG_W + ' ' + IMG_H);
        svg.setAttribute('preserveAspectRatio', 'none');
        svg.setAttribute('class', 'map-svg ' + cls);
        svg.setAttribute('aria-hidden', 'true');
        var pins = document.getElementById('map-pins');
        world.insertBefore(svg, pins || null);
        return svg;
    }

    window.CSOMapView = {
        IMG_W: IMG_W, IMG_H: IMG_H,
        layer: layer, restyle: restyle,
        zoomIn: function () { zoomBy(1.5); }, zoomOut: function () { zoomBy(1 / 1.5); },
        reset: reset, focus: focus, fit: fit,
        onChange: function (fn) { listeners.push(fn); },
        scale: function () { return s; }
    };
    apply(false);
})();
