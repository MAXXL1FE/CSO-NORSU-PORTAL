/**
 * Campus walking routes (shortest path) for the Campus Map.
 *
 * The walkways of the MC1 guide map are stored as a graph in map_paths.js:
 *   node = a point on a footpath, edge = a footpath piece between two nodes.
 * A pin is usually inside a building, so each pin is first "snapped" to the nearest
 * point on a footpath (an entrance link). The shortest way between the two snapped
 * points is then found with the A* algorithm (Dijkstra + a straight-line guess that
 * makes the search faster). Because every edge is as long as the straight line between
 * its ends, the guess never overestimates, so A* always returns the true shortest route.
 *
 * Works in the browser (window.CSOMapRoute) and in Node (module.exports) for testing.
 */
(function (root) {
    'use strict';

    // Size of the map picture in pixels. Used so 1% across and 1% down are measured fairly.
    var IMG_W = 2048, IMG_H = 2018;

    // Optional real-world scale: how many metres the picture is wide. 0 = unknown (no metres shown).
    var METRES_ACROSS_MAP = 0;
    var WALK_METRES_PER_MIN = 80;

    function dist(ax, ay, bx, by) {
        var dx = (ax - bx) * IMG_W / 100, dy = (ay - by) * IMG_H / 100;
        return Math.sqrt(dx * dx + dy * dy);
    }

    /* Small binary min-heap for the A* open list. */
    function Heap() { this.a = []; }
    Heap.prototype.push = function (item, pr) {
        var a = this.a; a.push([pr, item]);
        var i = a.length - 1;
        while (i > 0) {
            var p = (i - 1) >> 1;
            if (a[p][0] <= a[i][0]) break;
            var t = a[p]; a[p] = a[i]; a[i] = t; i = p;
        }
    };
    Heap.prototype.pop = function () {
        var a = this.a, top = a[0], last = a.pop();
        if (a.length) {
            a[0] = last;
            var i = 0, n = a.length;
            for (;;) {
                var l = 2 * i + 1, r = l + 1, m = i;
                if (l < n && a[l][0] < a[m][0]) m = l;
                if (r < n && a[r][0] < a[m][0]) m = r;
                if (m === i) break;
                var t = a[m]; a[m] = a[i]; a[i] = t; i = m;
            }
        }
        return top[1];
    };
    Heap.prototype.size = function () { return this.a.length; };

    function Router(data) {
        this.nodes = data.nodes;          // [[x, y], ...]
        this.edges = data.edges;          // [[a, b], ...]
    }

    /* The closest point on any footpath to (x, y): which edge, and where on it. */
    Router.prototype.snap = function (x, y) {
        var best = null;
        for (var i = 0; i < this.edges.length; i++) {
            var A = this.nodes[this.edges[i][0]], B = this.nodes[this.edges[i][1]];
            var ax = A[0] * IMG_W / 100, ay = A[1] * IMG_H / 100;
            var bx = B[0] * IMG_W / 100, by = B[1] * IMG_H / 100;
            var px = x * IMG_W / 100, py = y * IMG_H / 100;
            var vx = bx - ax, vy = by - ay, len2 = vx * vx + vy * vy;
            var t = len2 ? ((px - ax) * vx + (py - ay) * vy) / len2 : 0;
            t = Math.max(0, Math.min(1, t));
            var qx = ax + t * vx, qy = ay + t * vy;
            var d = Math.sqrt((px - qx) * (px - qx) + (py - qy) * (py - qy));
            if (!best || d < best.d) {
                best = { d: d, edge: i, t: t, x: qx * 100 / IMG_W, y: qy * 100 / IMG_H };
            }
        }
        return best;
    };

    /**
     * Shortest walking route between two points of the map (in % of the picture).
     * Returns { points: [[x, y], ...], length: pixels, entranceA: [[x,y],[x,y]], entranceB: [...] } or null.
     */
    Router.prototype.route = function (from, to, via) {
        via = via || {};
        var nodes = this.nodes.slice(), adj = [], i;
        for (i = 0; i < nodes.length; i++) adj.push([]);
        function link(a, b) {
            var w = dist(nodes[a][0], nodes[a][1], nodes[b][0], nodes[b][1]);
            adj[a].push([b, w]); adj[b].push([a, w]);
        }
        for (i = 0; i < this.edges.length; i++) link(this.edges[i][0], this.edges[i][1]);

        // Add the two places as extra nodes: place -> snapped point on a footpath -> both ends of that footpath piece.
        var self = this;
        function attach(p, entrance) {
            var s = self.snap(entrance ? entrance[0] : p[0], entrance ? entrance[1] : p[1]);   // entrance = where people really walk in
            var snapId = nodes.length; nodes.push([s.x, s.y]); adj.push([]);
            var placeId = nodes.length; nodes.push([p[0], p[1]]); adj.push([]);
            link(snapId, self.edges[s.edge][0]);
            link(snapId, self.edges[s.edge][1]);
            link(placeId, snapId);
            return { place: placeId, snap: snapId };
        }
        var A = attach(from, via.from), B = attach(to, via.to);
        var start = A.place, goal = B.place;

        // A*
        var gx = nodes[goal][0], gy = nodes[goal][1];
        var g = {}, prev = {}, closed = {};
        var open = new Heap();
        g[start] = 0;
        open.push(start, dist(nodes[start][0], nodes[start][1], gx, gy));
        while (open.size()) {
            var u = open.pop();
            if (closed[u]) continue;
            closed[u] = true;
            if (u === goal) break;
            for (var k = 0; k < adj[u].length; k++) {
                var v = adj[u][k][0], nd = g[u] + adj[u][k][1];
                if (g[v] === undefined || nd < g[v]) {
                    g[v] = nd; prev[v] = u;
                    open.push(v, nd + dist(nodes[v][0], nodes[v][1], gx, gy));
                }
            }
        }
        if (g[goal] === undefined) return null;

        var ids = [goal];
        while (ids[0] !== start) ids.unshift(prev[ids[0]]);
        var pts = ids.map(function (id) { return [nodes[id][0], nodes[id][1]]; });
        return { points: pts, length: g[goal], snapA: A, snapB: B, nodes: nodes, ids: ids };
    };

    /* ---- Turn-by-turn text ---------------------------------------------------------------- */

    var COMPASS = ['north', 'northeast', 'east', 'southeast', 'south', 'southwest', 'west', 'northwest'];

    function bearing(a, b) {                       // 0 = up (north) on the map picture
        var dx = (b[0] - a[0]) * IMG_W / 100, dy = (b[1] - a[1]) * IMG_H / 100;
        return (Math.atan2(dx, -dy) * 180 / Math.PI + 360) % 360;
    }
    function compass(deg) { return COMPASS[Math.round(deg / 45) % 8]; }

    /** Merge near-straight pieces, then describe each straight stretch and each turn. */
    function describe(points, landmarks, fromName, toName) {
        // 1) drop duplicate points
        var p = [points[0]];
        for (var i = 1; i < points.length; i++) {
            if (dist(p[p.length - 1][0], p[p.length - 1][1], points[i][0], points[i][1]) > 0.5) p.push(points[i]);
        }
        if (p.length < 2) return [];
        // 2) join pieces that go in nearly the same direction (less than 25 degrees of bend)
        var keep = [p[0]];
        for (var j = 1; j < p.length - 1; j++) {
            var a = bearing(keep[keep.length - 1], p[j]), b = bearing(p[j], p[j + 1]);
            var turn = Math.abs(((b - a + 540) % 360) - 180);
            if (turn >= 25) keep.push(p[j]);
        }
        keep.push(p[p.length - 1]);

        function near(pt) {                         // closest named place within about 5% of the map
            var best = null, bd = 1e9;
            (landmarks || []).forEach(function (l) {
                if (l.name === fromName || l.name === toName) return;
                var d = dist(pt[0], pt[1], l.x, l.y);
                if (d < bd) { bd = d; best = l; }
            });
            return best && bd < 0.05 * IMG_W ? best.name : null;
        }

        var steps = [], prevB = null;
        for (var k = 0; k < keep.length - 1; k++) {
            var brg = bearing(keep[k], keep[k + 1]);
            var len = dist(keep[k][0], keep[k][1], keep[k + 1][0], keep[k + 1][1]);
            if (len < 0.012 * IMG_W && k > 0 && k < keep.length - 2) continue;   // ignore tiny zig-zags
            var text;
            if (prevB === null) {
                text = 'Leave ' + fromName + ' and head ' + compass(brg) + ' to the footpath.';
            } else {
                var d = ((brg - prevB + 540) % 360) - 180;       // + = right, - = left
                var side = Math.abs(d) < 25 ? 'continue straight' :
                    (Math.abs(d) > 150 ? 'make a U-turn' :
                        'turn ' + (Math.abs(d) < 60 ? 'slightly ' : (Math.abs(d) > 120 ? 'sharply ' : '')) + (d > 0 ? 'right' : 'left'));
                var lm = near(keep[k]);
                text = (lm ? 'At ' + lm + ', ' : 'Then ') + side + ' and walk ' + compass(brg) + '.';
            }
            steps.push({ text: text, length: len, at: keep[k] });
            prevB = brg;
        }
        steps.push({ text: 'Arrive at ' + toName + '.', length: 0, at: keep[keep.length - 1] });
        return steps;
    }

    function lengthLabel(px) {
        if (!METRES_ACROSS_MAP) return '';
        var m = px / IMG_W * METRES_ACROSS_MAP;
        var mins = Math.max(1, Math.round(m / WALK_METRES_PER_MIN));
        return 'about ' + Math.round(m / 10) * 10 + ' m, around ' + mins + ' min on foot';
    }

    var api = { Router: Router, describe: describe, lengthLabel: lengthLabel, dist: dist, IMG_W: IMG_W, IMG_H: IMG_H };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.CSOMapRoute = api;
})(typeof window !== 'undefined' ? window : globalThis);
