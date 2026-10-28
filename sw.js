/* CSO-NORSU Portal service worker.
 * Saves the public Campus Map and Curriculum (and the files they need) so they open without
 * internet. Logged-in pages are never saved, so nothing private stays on a shared phone. */
var VERSION = 'cso-v7';
var PAGES   = 'cso-pages-' + VERSION;
var STATIC  = 'cso-static-' + VERSION;
var BASE    = self.location.pathname.replace(/sw\.js$/, '');          // e.g. /CSO-MIS/
var OFFLINE = BASE + 'offline.html';
var SAVED_PAGES = [BASE + 'map.php', BASE + 'curriculum.php'];

self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(STATIC).then(function (c) {
        return c.addAll([OFFLINE, BASE + 'assets/icons/icon-192.png']).catch(function () {});
    }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== PAGES && k !== STATIC; })
            .map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

function isSavedPage(url) { return SAVED_PAGES.indexOf(url.pathname) !== -1; }
function isStaticAsset(url) { return /\/assets\/(css|js|images|icons)\//.test(url.pathname); }

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Map and Curriculum: try the network first, keep a copy, fall back to the copy offline.
    if (req.mode === 'navigate' && isSavedPage(url)) {
        e.respondWith(fetch(req).then(function (res) {
            // A redirect (for example to the login page) is never saved.
            if (res.ok && !res.redirected) { var copy = res.clone(); caches.open(PAGES).then(function (c) { c.put(url.pathname, copy); }); }
            return res;
        }).catch(function () {
            return caches.match(url.pathname).then(function (hit) { return hit || caches.match(OFFLINE); });
        }));
        return;
    }

    // Any other page: network only, with a friendly offline page if there is no connection.
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE); }));
        return;
    }

    // Styles, scripts, images: show the saved copy fast, refresh it in the background.
    if (isStaticAsset(url)) {
        e.respondWith(caches.open(STATIC).then(function (c) {
            return c.match(req).then(function (hit) {
                var net = fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; }).catch(function () { return hit; });
                return hit || net;
            });
        }));
    }
});
