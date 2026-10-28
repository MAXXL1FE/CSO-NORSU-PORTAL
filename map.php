<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/map_lib.php';

/* Public page: new students and visitors can find a room without logging in. */

$buildings = map_load_all($conn);

// A logged-in student reports a problem (wrong room info, closed place, broken sign ...). Max 5 a day each.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'report_problem') {
    require_login();
    csrf_check();
    $me  = (int) $_SESSION['user_id'];
    $msg = trim(mb_substr(strip_tags((string) ($_POST['message'] ?? '')), 0, 500));
    $bid = (int) ($_POST['building_id'] ?? 0);
    $today = (int) $conn->query("SELECT COUNT(*) c FROM map_reports WHERE user_id = $me AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetch_assoc()['c'];
    if (mb_strlen($msg) < 5) {
        set_flash('error', 'Please describe the problem in a few words.');
    } elseif ($today >= 5) {
        set_flash('error', 'You already sent several reports today. Thank you, please try again tomorrow.');
    } else {
        $bnull = $bid > 0 ? $bid : null;
        $place = null;
        if ($bid > 0) { $r = $conn->query("SELECT name FROM map_buildings WHERE building_id = $bid")->fetch_assoc(); $place = $r['name'] ?? null; }
        $stmt = $conn->prepare("INSERT INTO map_reports (user_id, building_id, place_text, message) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiss', $me, $bnull, $place, $msg);
        $stmt->execute();
        set_flash('success', 'Thank you! Your report was sent to the CSO officers.');
    }
    header('Location: ' . BASE_URL . '/map.php#report');
    exit;
}

$last_updated = null;
$res = $conn->query("SELECT GREATEST(
        COALESCE((SELECT MAX(updated_at) FROM map_buildings), '1970-01-01'),
        COALESCE((SELECT MAX(updated_at) FROM map_rooms), '1970-01-01')) AS m");
if ($res) $last_updated = $res->fetch_assoc()['m'];

$page_title = 'Campus Map';
require_once __DIR__ . '/includes/header.php';
?>

<?php
$group_names = ['building' => 'Buildings', 'facility' => 'Facilities', 'office' => 'Offices', 'restroom' => 'Restrooms', 'gate' => 'Gates'];
$cat_counts = [];
foreach ($buildings as $b) { $cat_counts[$b['category']] = ($cat_counts[$b['category']] ?? 0) + 1; }
?>
<div class="mapx">
    <header class="mapx-head">
        <div>
            <h1>Campus Map</h1>
            <p>Find a building, office or room, and check whether it is open right now.</p>
        </div>
        <?php if ($last_updated && $last_updated !== '1970-01-01 00:00:00'): ?>
            <span class="mapx-updated"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg>Updated <?php echo e(date('M j, Y g:i A', strtotime($last_updated))); ?></span>
        <?php endif; ?>
    </header>

    <div class="mapx-layout">
        <section class="mapx-find" aria-label="Find a place">
            <div class="mapx-search">
                <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
                <input type="search" id="map-search" autocomplete="off" placeholder="Search a room, office or building" aria-label="Search a room, office or building">
            </div>
            <div class="mapx-chips" id="map-chips" role="group" aria-label="Browse by type">
                <?php foreach ($group_names as $cat => $cat_label): if (empty($cat_counts[$cat])) continue; ?>
                    <button type="button" class="mapx-chip" data-cat="<?php echo e($cat); ?>" aria-pressed="false"><?php echo e($cat_label); ?> <b><?php echo (int) $cat_counts[$cat]; ?></b></button>
                <?php endforeach; ?>
            </div>
            <label for="map-jump" class="sr-only">Or jump to a building</label>
            <select id="map-jump">
                <option value="">Jump to a place&hellip;</option>
                <?php foreach ($group_names as $cat => $cat_label): ?>
                    <?php $group = array_filter($buildings, fn($b) => $b['category'] === $cat); ?>
                    <?php if ($group): ?>
                        <optgroup label="<?php echo e($cat_label); ?>">
                            <?php foreach ($group as $b): ?>
                                <option value="<?php echo (int) $b['id']; ?>"><?php echo e($b['name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <div id="map-results" class="map-results" aria-live="polite"></div>
        </section>

        <div class="mapx-map">
    <div class="campus-map" id="campus-map">
        <div class="map-viewport" id="map-viewport" tabindex="0" role="application" aria-label="Interactive campus map. Drag to move, pinch or use the plus and minus buttons to zoom. Keyboard: plus, minus, zero and arrow keys.">
            <div class="map-world" id="map-world">
                <img src="<?php echo asset_v('/assets/images/campus-map.jpg'); ?>" alt="Map of NORSU Main Campus 1. Select a pin to see that building." width="2048" height="2018" draggable="false">
                <div id="map-pins" class="map-pins"></div>
            </div>
        </div>

        <button type="button" class="map-ctrl map-legend-toggle" id="legend-toggle" aria-expanded="false" aria-controls="map-legend">
            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 11v6M12 7.5v.01" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
            Legend
        </button>
        <button type="button" class="map-ctrl map-paths-toggle" id="paths-toggle" aria-pressed="true">Footpaths: on</button>

        <div class="map-ctrl map-zoom" role="group" aria-label="Zoom">
            <button type="button" data-zoom="in" aria-label="Zoom in" title="Zoom in">+</button>
            <button type="button" data-zoom="out" aria-label="Zoom out" title="Zoom out" disabled>&minus;</button>
            <button type="button" data-zoom="reset" aria-label="Reset map view" title="Reset view" disabled>
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </div>

        <div class="map-ctrl map-legend" id="map-legend" role="region" hidden aria-label="Map legend">
            <h3>Legend</h3>
            <ul class="lg-list">
                <li><span class="lg-sw"><svg class="lg-pin lg-pin-default" viewBox="0 0 24 32" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12c0 8.4 10.2 18.5 11.2 19.5a1.1 1.1 0 0 0 1.6 0C13.8 30.5 24 20.4 24 12 24 5.37 18.63 0 12 0z" fill="currentColor"/><circle cx="12" cy="12" r="4.6" fill="#fff"/></svg></span> Place. Tap it for details</li>
                <li><span class="lg-sw"><svg class="lg-pin" style="color:#B45309" viewBox="0 0 24 32" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12c0 8.4 10.2 18.5 11.2 19.5a1.1 1.1 0 0 0 1.6 0C13.8 30.5 24 20.4 24 12 24 5.37 18.63 0 12 0z" fill="currentColor"/><circle cx="12" cy="12" r="4.6" fill="#fff"/></svg></span> Selected place</li>
                <li><span class="lg-sw"><svg class="lg-pin" style="color:#16A34A" viewBox="0 0 24 32" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12c0 8.4 10.2 18.5 11.2 19.5a1.1 1.1 0 0 0 1.6 0C13.8 30.5 24 20.4 24 12 24 5.37 18.63 0 12 0z" fill="currentColor"/><circle cx="12" cy="12" r="4.6" fill="#fff"/></svg></span> From: the place you start. Also the green label on the pin</li>
                <li><span class="lg-sw"><svg class="lg-pin" style="color:#E11D48" viewBox="0 0 24 32" aria-hidden="true"><path d="M12 0C5.37 0 0 5.37 0 12c0 8.4 10.2 18.5 11.2 19.5a1.1 1.1 0 0 0 1.6 0C13.8 30.5 24 20.4 24 12 24 5.37 18.63 0 12 0z" fill="currentColor"/><circle cx="12" cy="12" r="4.6" fill="#fff"/></svg></span> To: where you are going. Also the red label on the pin</li>
                <li><span class="lg-sw"><svg viewBox="0 0 40 12" aria-hidden="true"><line x1="2" y1="6" x2="38" y2="6" stroke="#fff" stroke-width="6" stroke-linecap="round" opacity=".9"/><line x1="2" y1="6" x2="38" y2="6" stroke="#2563EB" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="7 5"/></svg></span> Campus footpath</li>
                <li><span class="lg-sw"><svg viewBox="0 0 40 12" aria-hidden="true"><line x1="2" y1="6" x2="38" y2="6" stroke="#fff" stroke-width="9" stroke-linecap="round"/><line x1="2" y1="6" x2="38" y2="6" stroke="#E11D48" stroke-width="5" stroke-linecap="round"/></svg></span> Shortest walking route</li>
                <li><span class="lg-sw"><svg viewBox="0 0 40 12" aria-hidden="true"><line x1="2" y1="6" x2="38" y2="6" stroke="#E11D48" stroke-width="3" stroke-linecap="round" stroke-dasharray="5 5"/></svg></span> Short link from a pin to the footpath</li>
            </ul>
            <h4>Place status</h4>
            <div class="lg-status">
                <span class="status-badge status-open">Open</span>
                <span class="status-badge status-limited">Limited</span>
                <span class="status-badge status-maintenance">Maintenance</span>
                <span class="status-badge status-closed">Closed</span>
            </div>
            <p class="lg-tip">Drag to move. Pinch or use + / &minus; to zoom. Double-tap to zoom in.</p>
        </div>

        <p class="map-credit">Map: MC1 Guide Map, illustration by Jervine Alvarico (NORSU CAS Student Government).</p>
    </div>

        </div>

        <div class="mapx-info">
            <section class="map-detail" id="map-detail" tabindex="-1" aria-label="Selected building">
                <div class="mapx-empty">
                    <span class="mapx-empty-ico"><svg class="icon" aria-hidden="true"><use href="#i-pin"/></svg></span>
                    <h2>Choose a place</h2>
                    <p>Tap a pin on the map, browse by type, or search for a room. Floors and rooms will appear here.</p>
                </div>
            </section>

    <details class="panel mapx-card" id="directions" open>
        <summary><strong>Walking directions between two places</strong></summary>
        <div class="map-tools-row">
            <label class="dir-lab dir-lab-from"><span class="dir-lab-t">From</span>
                <select id="dir-from"><option value="">Choose a place</option>
                    <?php foreach ($buildings as $b): ?><option value="<?php echo (int) $b['id']; ?>"><?php echo e($b['name']); ?></option><?php endforeach; ?>
                </select></label>
            <div class="dir-swap-row"><button type="button" class="dir-swap" id="dir-swap" title="Swap From and To" aria-label="Swap From and To"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path d="M7 4v16M7 20l-3-3M7 20l3-3M17 20V4M17 4l-3 3M17 4l3 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></button></div>
            <label class="dir-lab dir-lab-to"><span class="dir-lab-t">To</span>
                <select id="dir-to"><option value="">Choose a place</option>
                    <?php foreach ($buildings as $b): ?><option value="<?php echo (int) $b['id']; ?>"><?php echo e($b['name']); ?></option><?php endforeach; ?>
                </select></label>
            <button type="button" class="btn btn-primary" id="dir-go">Find shortest route</button>
            <button type="button" class="btn btn-outline" id="dir-clear" hidden>Clear</button>
        </div>
        <p class="muted" id="dir-note" role="status" style="font-size:.85rem; margin:8px 0 0;">Pick two places. The map draws the shortest walking route along the campus footpaths and lists the turns.</p>
        <ol class="route-steps" id="dir-steps" aria-live="polite" hidden></ol>
    </details>


    <details class="panel mapx-card" id="report">
        <summary><strong>Report a problem</strong></summary>
        <?php if (is_logged_in()): ?>
            <form method="POST" class="form" style="margin-top:12px;">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="report_problem">
                <div class="form-row">
                    <label for="rp-place">Which place? (optional)</label>
                    <select id="rp-place" name="building_id"><option value="0">Not sure / somewhere else</option>
                        <?php foreach ($buildings as $b): ?><option value="<?php echo (int) $b['id']; ?>"><?php echo e($b['name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label for="rp-msg">What is wrong?</label>
                    <textarea id="rp-msg" name="message" rows="3" maxlength="500" required placeholder="e.g. Room 204 is now the Tech Room, or the Canteen is closed on Fridays"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Send report</button>
            </form>
        <?php else: ?>
            <p class="muted" style="margin-top:10px;"><a href="<?php echo BASE_URL; ?>/login.php">Log in</a> to tell the CSO about wrong room info or a closed place.</p>
        <?php endif; ?>
    </details>
        </div>
    </div>
</div>

<noscript>
    <div class="panel" style="margin-top:20px;">
        <h2>All places</h2>
        <?php foreach ($buildings as $b): ?>
            <h3 style="margin-top:14px;"><?php echo e($b['name']); ?> <span class="status-badge status-<?php echo e($b['status']); ?>"><?php echo e($b['label']); ?></span></h3>
            <?php if ($b['mode'] === 'rooms_only'): ?>
                <p><?php echo $b['rooms'] ? implode(', ', array_map(fn($r) => e($r['name']) . ' (' . e($r['label']) . ')', $b['rooms'])) : 'no rooms added yet'; ?></p>
            <?php endif; ?>
            <?php foreach ($b['floors'] as $f): ?>
                <p><strong>Floor <?php echo e($f['label']); ?>:</strong>
                <?php echo $f['rooms'] ? implode(', ', array_map(fn($r) => e($r['name']) . ' (' . e($r['label']) . ')', $f['rooms'])) : 'no rooms added yet'; ?></p>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</noscript>

<script>
window.CSO_MAP = <?php echo json_encode($buildings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="<?php echo asset_v('/assets/js/map_view.js'); ?>"></script>
<script>
(function () {
    var B = window.CSO_MAP || [];
    var search = document.getElementById('map-search');
    var jump = document.getElementById('map-jump');
    var results = document.getElementById('map-results');
    var pinsEl = document.getElementById('map-pins');
    var detail = document.getElementById('map-detail');
    var current = null;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function pill(status, label) {
        return '<span class="status-badge status-' + esc(status) + '">' + esc(label) + '</span>';
    }
    function building(id) {
        for (var i = 0; i < B.length; i++) if (B[i].id === id) return B[i];
        return null;
    }

    /* Pins */
    B.forEach(function (b) {
        var pin = document.createElement('button');
        pin.type = 'button';
        pin.className = 'map-pin';
        pin.innerHTML = '<svg viewBox="0 0 24 32" aria-hidden="true" focusable="false">' +
            '<path d="M12 0C5.37 0 0 5.37 0 12c0 8.4 10.2 18.5 11.2 19.5a1.1 1.1 0 0 0 1.6 0C13.8 30.5 24 20.4 24 12 24 5.37 18.63 0 12 0z" fill="currentColor"/>' +
            '<circle cx="12" cy="12" r="4.6" fill="#ffffff"/></svg>';
        pin.style.left = b.x + '%';
        pin.style.top = b.y + '%';
        pin.setAttribute('data-id', b.id);
        pin.setAttribute('aria-label', b.name + ': ' + b.label);
        pin.setAttribute('aria-pressed', 'false');
        pin.title = b.name + ' - ' + b.label;
        pin.addEventListener('click', function () { select(b.id, null, null); });
        pinsEl.appendChild(pin);
    });

    /* Search: rooms and buildings, shown as you type */
    function renderResults() {
        var q = search.value.trim().toLowerCase();
        if (q === '') { results.innerHTML = ''; return; }
        var hits = [];
        B.forEach(function (b) {
            if (b.name.toLowerCase().indexOf(q) !== -1 || (b.code && b.code.toLowerCase().indexOf(q) !== -1)) {
                hits.push({ kind: 'Building', title: b.name, sub: b.label, status: b.status, label: b.label, bid: b.id, fid: null, rid: null });
            }
            b.floors.forEach(function (f) {
                f.rooms.forEach(function (r) {
                    if (r.name.toLowerCase().indexOf(q) !== -1) {
                        hits.push({ kind: 'Room', title: r.name, sub: b.name + ', floor ' + f.label, status: r.status, label: r.label, bid: b.id, fid: f.id, rid: r.id });
                    }
                });
            });
            (b.rooms || []).forEach(function (r) {
                if (r.name.toLowerCase().indexOf(q) !== -1) {
                    hits.push({ kind: 'Room', title: r.name, sub: b.name, status: r.status, label: r.label, bid: b.id, fid: null, rid: r.id });
                }
            });
        });
        if (!hits.length) {
            results.innerHTML = '<p class="muted">No match for "' + esc(search.value) + '". Try a shorter word.</p>';
            return;
        }
        results.innerHTML = hits.slice(0, 12).map(function (h, i) {
            return '<button type="button" class="map-result" data-i="' + i + '">' +
                '<span class="map-result-main"><strong>' + esc(h.title) + '</strong>' +
                '<span class="muted">' + esc(h.kind) + ' &middot; ' + esc(h.sub) + '</span></span>' +
                pill(h.status, h.label) + '</button>';
        }).join('') + (hits.length > 12 ? '<p class="muted">Showing 12 of ' + hits.length + '. Keep typing to narrow it down.</p>' : '');
        Array.prototype.forEach.call(results.querySelectorAll('.map-result'), function (btn) {
            btn.addEventListener('click', function () {
                var h = hits[Number(btn.getAttribute('data-i'))];
                select(h.bid, h.fid, h.rid);
            });
        });
    }
    search.addEventListener('input', renderResults);
    jump.addEventListener('change', function () {
        if (jump.value) select(Number(jump.value), null, null);
    });

    /* Selecting a building: show its floors, and its rooms for the chosen floor */
    function select(bid, fid, rid) {
        var b = building(bid);
        if (!b) return;
        current = { b: b, fid: fid, rid: rid };
        if (!fid && rid) {
            b.floors.forEach(function (f) {
                f.rooms.forEach(function (r) { if (r.id === rid) current.fid = f.id; });
            });
        }
        if (!current.fid && b.floors.length) current.fid = b.floors[0].id;
        jump.value = String(b.id);
        Array.prototype.forEach.call(pinsEl.querySelectorAll('.map-pin'), function (p) {
            var on = Number(p.getAttribute('data-id')) === bid;
            p.classList.toggle('is-active', on);
            p.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        draw();
        if (window.CSOMapView) CSOMapView.focus(b.x, b.y, 2.2);
        if (window.matchMedia && window.matchMedia('(max-width: 900px)').matches) {
            detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        detail.focus({ preventScroll: true });
    }

    function draw() {
        var b = current.b;
        var html = '<div class="map-detail-head"><h2>' + esc(b.name) + '</h2>' +
            pill(b.status, b.label) + '</div>';
        if (b.detail) html += '<p class="map-detail-note">' + esc(b.detail) + '</p>';
        html += '<p class="detail-dir-wrap"><button type="button" class="btn btn-primary btn-lg detail-dir-btn" id="detail-dir-to"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M3 11l18-8-8 18-2-8-8-2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>Get directions to here</button></p>';
        var facts = [];
        if (b.hours) facts.push('Hours: ' + b.hours);
        if (b.window) facts.push(b.window);
        if (facts.length) html += '<p class="muted map-detail-facts">' + esc(facts.join(' \u00b7 ')) + '</p>';

        function roomRows(rooms) {
            var out = '<ul class="room-list">';
            rooms.forEach(function (r) {
                var hit = r.id === current.rid ? ' is-hit' : '';
                out += '<li class="room-row' + hit + '"><div class="room-row-main"><strong>' + esc(r.name) + '</strong>' +
                    '<span class="muted">' + esc(r.type) + (r.hours ? ' \u00b7 ' + esc(r.hours) : '') + '</span>' +
                    (r.detail ? '<span class="muted room-detail">' + esc(r.detail) + '</span>' : '') +
                    '</div>' + pill(r.status, r.label) + '</li>';
            });
            return out + '</ul>';
        }

        if (b.mode === 'none') {
            /* No floors or rooms for this place: nothing more to show. */
        } else if (b.mode === 'rooms_only') {
            if (b.rooms.length) html += roomRows(b.rooms);
        } else if (!b.floors.length) {
            /* Nothing added for this place yet: show no placeholder text. */
        } else {
            html += '<div class="floor-tabs" role="group" aria-label="Floors">';
            b.floors.forEach(function (f) {
                var on = f.id === current.fid;
                html += '<button type="button" class="floor-tab" data-fid="' + f.id + '" aria-pressed="' + on + '">Floor ' + esc(f.label) + '</button>';
            });
            html += '</div>';
            var floor = null;
            b.floors.forEach(function (f) { if (f.id === current.fid) floor = f; });
            if (floor && floor.rooms.length) {
                html += roomRows(floor.rooms);
            } else {
                html += '<p class="muted">No rooms added on this floor yet.</p>';
            }
        }
        detail.innerHTML = html;
        var dirBtn = document.getElementById('detail-dir-to');
        if (dirBtn) dirBtn.addEventListener('click', function () {
            document.dispatchEvent(new CustomEvent('cso:directions-to', { detail: { id: b.id } }));
        });
        Array.prototype.forEach.call(detail.querySelectorAll('.floor-tab'), function (t) {
            t.addEventListener('click', function () {
                current.fid = Number(t.getAttribute('data-fid'));
                current.rid = null;
                draw();
            });
        });
        var hit = detail.querySelector('.is-hit');
        if (hit && hit.scrollIntoView) hit.scrollIntoView({ block: 'nearest' });
    }
})();
</script>
<script>
/* Browse by type: the chips list every place of that type; clicking one opens it. */
(function () {
    var B = window.CSO_MAP || [];
    var chips = document.querySelectorAll('.mapx-chip');
    var results = document.getElementById('map-results');
    var search = document.getElementById('map-search');
    var jump = document.getElementById('map-jump');
    if (!chips.length || !results) return;
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function clearChips() { Array.prototype.forEach.call(chips, function (c) { c.setAttribute('aria-pressed', 'false'); }); }
    Array.prototype.forEach.call(chips, function (chip) {
        chip.addEventListener('click', function () {
            var on = chip.getAttribute('aria-pressed') === 'true';
            clearChips();
            if (on) { results.innerHTML = ''; return; }
            chip.setAttribute('aria-pressed', 'true');
            search.value = '';
            var cat = chip.getAttribute('data-cat');
            var list = B.filter(function (b) { return b.category === cat; });
            results.innerHTML = list.map(function (b) {
                return '<button type="button" class="map-result" data-id="' + b.id + '"><span class="map-result-main"><strong>' + esc(b.name) + '</strong></span>' +
                    '<span class="status-badge status-' + esc(b.status) + '">' + esc(b.label) + '</span></button>';
            }).join('');
            Array.prototype.forEach.call(results.querySelectorAll('.map-result'), function (btn) {
                btn.addEventListener('click', function () {
                    jump.value = btn.getAttribute('data-id');
                    jump.dispatchEvent(new Event('change'));
                });
            });
        });
    });
    search.addEventListener('input', clearChips);
})();
</script>
<script src="<?php echo asset_v('/assets/js/map_paths.js'); ?>"></script>
<script src="<?php echo asset_v('/assets/js/map_route.js'); ?>"></script>
<script>
/* Shortest walking route: A* over the footpath graph (assets/js/map_paths.js + map_route.js).
   Lines are drawn in SVG layers inside #map-world, so they zoom and move together with the picture. */
(function () {
    var B = window.CSO_MAP || [];
    var from = document.getElementById('dir-from'), to = document.getElementById('dir-to');
    var go = document.getElementById('dir-go'), clr = document.getElementById('dir-clear'), swp = document.getElementById('dir-swap');
    var note = document.getElementById('dir-note'), stepsEl = document.getElementById('dir-steps');
    var panel = document.getElementById('directions');
    var stage = document.getElementById('campus-map');
    var tog = document.getElementById('paths-toggle');
    if (!from || !to || !go || !stage) return;

    function fail(msg) {
        go.addEventListener('click', function () { note.textContent = msg; });
        if (tog) { tog.textContent = 'Footpaths: unavailable'; tog.disabled = true; }
        if (window.console) console.error(msg);
    }
    if (!window.CSOMapRoute || !window.CSO_PATHS) { fail('The route files did not load. Upload assets/js/map_paths.js and assets/js/map_route.js, then refresh the page.'); return; }
    if (!window.CSOMapView) { fail('The map view file did not load. Upload assets/js/map_view.js, then refresh the page.'); return; }

    var NS = 'http://www.w3.org/2000/svg';
    var W = CSOMapView.IMG_W, H = CSOMapView.IMG_H;
    var router = new CSOMapRoute.Router(window.CSO_PATHS);
    var routeSvg = null;

    function px(p) { return (p[0] * W / 100).toFixed(1) + ',' + (p[1] * H / 100).toFixed(1); }
    function stroke(el, color, widthPx, dash) {
        el.setAttribute('fill', 'none');
        el.setAttribute('stroke', color);
        el.setAttribute('stroke-linecap', 'round');
        el.setAttribute('stroke-linejoin', 'round');
        el.setAttribute('data-px', String(widthPx));            // map_view.js turns this into the right thickness at every zoom
        if (dash) el.setAttribute('data-dash', dash);
        return el;
    }
    function el(tag, attrs) {
        var e = document.createElementNS(NS, tag);
        for (var k in attrs) e.setAttribute(k, attrs[k]);
        return e;
    }

    /* ---- Footpath network: white outline + blue dashed line, so it is visible on any part of the picture ---- */
    var net = null;
    try {
        net = CSOMapView.layer('paths-layer');
        var nodes = window.CSO_PATHS.nodes, d = [];
        window.CSO_PATHS.edges.forEach(function (e) {
            var p = nodes[e[0]], q = nodes[e[1]];
            if (p && q) d.push('M' + px(p) + 'L' + px(q));
        });
        d = d.join('');
        net.appendChild(stroke(el('path', { d: d, stroke: '#fff', 'stroke-opacity': '0.9' }), '#ffffff', 7));
        net.appendChild(stroke(el('path', { d: d }), '#2563EB', 3, '8 6'));
        CSOMapView.restyle();
    } catch (err) {
        fail('The footpaths could not be drawn: ' + err.message);
    }
    if (tog && net) tog.addEventListener('click', function () {
        var on = net.hasAttribute('hidden') || net.style.display === 'none';
        net.style.display = on ? '' : 'none';
        tog.setAttribute('aria-pressed', on ? 'true' : 'false');
        tog.textContent = 'Footpaths: ' + (on ? 'on' : 'off');
    });

    function find(id) { for (var i = 0; i < B.length; i++) if (String(B[i].id) === String(id)) return B[i]; return null; }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    var pinsEl = document.getElementById('map-pins');
    function markPin(id, color) {
        var p = pinsEl && pinsEl.querySelector('.map-pin[data-id="' + id + '"]');
        if (!p) return;
        p.setAttribute('data-route', '1');
        p.setAttribute('data-role', color === '#16A34A' ? 'from' : 'to');
        p.setAttribute('data-tag', color === '#16A34A' ? 'FROM' : 'TO');
        p.classList.add('is-route');
        p.style.color = color;
    }
    function resetPins() {
        if (!pinsEl) return;
        Array.prototype.forEach.call(pinsEl.querySelectorAll('.map-pin[data-route]'), function (p) {
            p.removeAttribute('data-route'); p.removeAttribute('data-role'); p.removeAttribute('data-tag');
            p.classList.remove('is-route'); p.style.color = '';
        });
    }
    // Colour the two chosen places on the map straight away (green = From, red = To),
    // before the route is even requested, so it is clear which pin is which.
    function syncRolePins() {
        resetPins();
        if (from.value) markPin(from.value, '#16A34A');
        if (to.value) markPin(to.value, '#E11D48');
        from.classList.toggle('has-value', !!from.value);
        to.classList.toggle('has-value', !!to.value);
    }
    function onPick() {
        if (routeSvg && routeSvg.parentNode) routeSvg.parentNode.removeChild(routeSvg);
        routeSvg = null; stepsEl.hidden = true; stepsEl.innerHTML = '';
        syncRolePins();
        var a = find(from.value), b = find(to.value);
        if (a && b && a.id !== b.id) CSOMapView.fit(Math.min(a.x, b.x) - 4, Math.min(a.y, b.y) - 4, Math.max(a.x, b.x) + 4, Math.max(a.y, b.y) + 4);
        else if (a || b) { var q = a || b; CSOMapView.focus(q.x, q.y, 2.2); }
    }
    from.addEventListener('change', onPick);
    to.addEventListener('change', onPick);
    function clearRoute() {
        resetPins();
        if (routeSvg && routeSvg.parentNode) routeSvg.parentNode.removeChild(routeSvg);
        routeSvg = null; clr.hidden = true; stepsEl.hidden = true; stepsEl.innerHTML = '';
    }
    function poly(pts, color, widthPx, dash, cls) {
        var e = el('polyline', { points: pts.map(px).join(' '), 'class': cls || '' });
        return stroke(e, color, widthPx, dash);
    }

    function show(a, b) {
        clearRoute();
        var E = window.CSO_ENTRANCES || {};
        var r = router.route([a.x, a.y], [b.x, b.y], { from: E[a.name], to: E[b.name] });
        if (!r) { note.textContent = 'No walking route was found between these two places.'; return; }
        var pts = r.points;

        routeSvg = CSOMapView.layer('route-layer');
        var main = pts.slice(1, pts.length - 1);                      // along the footpaths
        if (main.length > 1) {
            routeSvg.appendChild(poly(main, '#ffffff', 10));            // white outline
            routeSvg.appendChild(poly(main, '#E11D48', 5.5));           // red route
            routeSvg.appendChild(poly(main, '#ffffff', 2, '2 14', 'route-flow'));   // moving white dashes
        }
        routeSvg.appendChild(poly(pts.slice(0, 2), '#E11D48', 3, '5 5'));            // pin -> footpath
        routeSvg.appendChild(poly(pts.slice(-2), '#E11D48', 3, '5 5'));
        CSOMapView.restyle();

        clr.hidden = false;
        markPin(a.id, '#16A34A');   // start = green
        markPin(b.id, '#E11D48');   // destination = red

        // zoom the map so the whole route is in view
        var xs = pts.map(function (q) { return q[0]; }), ys = pts.map(function (q) { return q[1]; });
        CSOMapView.fit(Math.min.apply(null, xs), Math.min.apply(null, ys), Math.max.apply(null, xs), Math.max.apply(null, ys));

        var steps = CSOMapRoute.describe(pts, B, a.name, b.name);
        stepsEl.innerHTML = steps.map(function (s, i) {
            return '<li><button type="button" class="route-step" data-i="' + i + '" title="Show this step on the map">' + esc(s.text) + '</button></li>';
        }).join('');
        Array.prototype.forEach.call(stepsEl.querySelectorAll('.route-step'), function (btn) {
            btn.addEventListener('click', function () {
                var st = steps[Number(btn.getAttribute('data-i'))];
                Array.prototype.forEach.call(stepsEl.querySelectorAll('.route-step'), function (x) { x.classList.toggle('is-on', x === btn); });
                if (st && st.at) { CSOMapView.focus(st.at[0], st.at[1], 3.2); stage.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }
            });
        });
        stepsEl.hidden = false;
        var len = CSOMapRoute.lengthLabel(r.length);
        note.textContent = 'Shortest walking route from ' + a.name + ' to ' + b.name + (len ? ' (' + len + ')' : '') + '. Green pin = start, red pin = destination, red line = route, dashed blue = campus footpaths.';
        stage.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    go.addEventListener('click', function () {
        var a = find(from.value), b = find(to.value);
        if (!a || !b) { note.textContent = 'Choose both places first.'; return; }
        if (a.id === b.id) { note.textContent = 'These are the same place.'; return; }
        try { show(a, b); } catch (err) { note.textContent = 'The route could not be drawn: ' + err.message; if (window.console) console.error(err); }
    });
    swp.addEventListener('click', function () {
        var t = from.value; from.value = to.value; to.value = t;
        syncRolePins();
        if (from.value && to.value && from.value !== to.value) go.click();
    });
    clr.addEventListener('click', function () {
        clearRoute();
        from.value = ''; to.value = '';
        syncRolePins();
        CSOMapView.reset();
        note.textContent = 'Pick two places. The map draws the shortest walking route along the campus footpaths and lists the turns.';
    });

    // "Directions to here" button in the place panel
    document.addEventListener('cso:directions-to', function (e) {
        to.value = String(e.detail.id);
        syncRolePins();
        if (panel) { panel.open = true; panel.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        if (from.value && from.value !== to.value) go.click(); else { from.focus(); note.textContent = 'Now choose where you are starting from.'; }
    });
})();
</script>
