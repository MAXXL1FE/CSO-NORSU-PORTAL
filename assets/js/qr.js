/**
 * Small offline QR code generator (byte mode, error correction level M, versions 1 to 10).
 * It replaces the third-party QR image service, so student IDs and the secret
 * event check-in token never leave this website.
 *
 * Use: <img data-qr="text to encode" alt="..."> becomes a QR image automatically.
 * Or call CSOQR.svg(text) to get SVG markup.
 */
(function (global) {
    'use strict';

    var ECC_PER_BLOCK = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26];   // level M, versions 1 to 10
    var NUM_BLOCKS    = [1, 1, 1, 2, 2, 4, 4, 4, 5, 5];

    function utf8(str) {
        var out = [], s = unescape(encodeURIComponent(str));
        for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i));
        return out;
    }

    function rawModules(ver) {
        var r = (16 * ver + 128) * ver + 64;
        if (ver >= 2) {
            var n = Math.floor(ver / 7) + 2;
            r -= (25 * n - 10) * n - 55;
            if (ver >= 7) r -= 36;
        }
        return r;
    }

    function dataCodewords(ver) {
        return Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[ver - 1] * NUM_BLOCKS[ver - 1];
    }

    function gfMul(x, y) {
        var z = 0;
        for (var i = 7; i >= 0; i--) {
            z = (z << 1) ^ ((z >>> 7) * 0x11D);
            z ^= ((y >>> i) & 1) * x;
        }
        return z;
    }

    function rsDivisor(degree) {
        var result = [], i, j;
        for (i = 0; i < degree - 1; i++) result.push(0);
        result.push(1);
        var root = 1;
        for (i = 0; i < degree; i++) {
            for (j = 0; j < result.length; j++) {
                result[j] = gfMul(result[j], root);
                if (j + 1 < result.length) result[j] ^= result[j + 1];
            }
            root = gfMul(root, 0x02);
        }
        return result;
    }

    function rsRemainder(data, divisor) {
        var result = divisor.map(function () { return 0; });
        data.forEach(function (b) {
            var factor = b ^ result.shift();
            result.push(0);
            divisor.forEach(function (coef, i) { result[i] ^= gfMul(coef, factor); });
        });
        return result;
    }

    function buildCodewords(bytes, ver) {
        var bits = [];
        function put(val, len) { for (var i = len - 1; i >= 0; i--) bits.push((val >>> i) & 1); }
        put(0x4, 4);                              // byte mode
        put(bytes.length, ver <= 9 ? 8 : 16);     // character count
        bytes.forEach(function (b) { put(b, 8); });
        var capacity = dataCodewords(ver) * 8;
        put(0, Math.min(4, capacity - bits.length));
        while (bits.length % 8) bits.push(0);
        for (var pad = 0xEC; bits.length < capacity; pad ^= 0xEC ^ 0x11) put(pad, 8);
        var data = [];
        for (var i = 0; i < bits.length; i += 8) {
            var v = 0;
            for (var j = 0; j < 8; j++) v = (v << 1) | bits[i + j];
            data.push(v);
        }

        var numBlocks = NUM_BLOCKS[ver - 1], eccLen = ECC_PER_BLOCK[ver - 1];
        var raw = Math.floor(rawModules(ver) / 8);
        var numShort = numBlocks - (raw % numBlocks);
        var shortLen = Math.floor(raw / numBlocks);
        var divisor = rsDivisor(eccLen), blocks = [], k = 0;
        for (i = 0; i < numBlocks; i++) {
            var dat = data.slice(k, k + shortLen - eccLen + (i < numShort ? 0 : 1));
            k += dat.length;
            var ecc = rsRemainder(dat, divisor);
            if (i < numShort) dat.push(0);
            blocks.push(dat.concat(ecc));
        }
        var result = [];
        for (i = 0; i < blocks[0].length; i++) {
            for (var b = 0; b < blocks.length; b++) {
                if (i !== shortLen - eccLen || b >= numShort) result.push(blocks[b][i]);
            }
        }
        return result;
    }

    function alignPositions(ver) {
        if (ver === 1) return [];
        var n = Math.floor(ver / 7) + 2, size = ver * 4 + 17;
        var step = Math.ceil((ver * 4 + 4) / (n * 2 - 2)) * 2;
        var res = [6];
        for (var pos = size - 7; res.length < n; pos -= step) res.splice(1, 0, pos);
        return res;
    }

    function makeMatrix(ver, codewords) {
        var size = ver * 4 + 17, mod = [], fn = [], x, y, i;
        for (y = 0; y < size; y++) { mod.push(new Array(size).fill(false)); fn.push(new Array(size).fill(false)); }
        function setFn(px, py, dark) { mod[py][px] = dark; fn[py][px] = true; }

        for (i = 0; i < size; i++) { setFn(6, i, i % 2 === 0); setFn(i, 6, i % 2 === 0); }
        function finder(cx, cy) {
            for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) {
                var d = Math.max(Math.abs(dx), Math.abs(dy)), xx = cx + dx, yy = cy + dy;
                if (xx >= 0 && xx < size && yy >= 0 && yy < size) setFn(xx, yy, d !== 2 && d !== 4);
            }
        }
        finder(3, 3); finder(size - 4, 3); finder(3, size - 4);
        var ap = alignPositions(ver), na = ap.length;
        for (i = 0; i < na; i++) for (var j = 0; j < na; j++) {
            if ((i === 0 && j === 0) || (i === 0 && j === na - 1) || (i === na - 1 && j === 0)) continue;
            for (var dy = -2; dy <= 2; dy++) for (var dx = -2; dx <= 2; dx++) {
                setFn(ap[i] + dx, ap[j] + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
            }
        }

        function drawFormat(mask) {
            var data = (0 << 3) | mask, rem = data;      // level M has format bits 0
            for (var n = 0; n < 10; n++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
            var bitsF = ((data << 10) | rem) ^ 0x5412;
            function bit(n) { return ((bitsF >>> n) & 1) !== 0; }
            for (var a = 0; a <= 5; a++) setFn(8, a, bit(a));
            setFn(8, 7, bit(6)); setFn(8, 8, bit(7)); setFn(7, 8, bit(8));
            for (a = 9; a < 15; a++) setFn(14 - a, 8, bit(a));
            for (a = 0; a < 8; a++) setFn(size - 1 - a, 8, bit(a));
            for (a = 8; a < 15; a++) setFn(8, size - 15 + a, bit(a));
            setFn(8, size - 8, true);
        }
        drawFormat(0);   // reserves the format areas before data is placed

        if (ver >= 7) {
            var rem = ver;
            for (i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
            var vb = (ver << 12) | rem;
            for (i = 0; i < 18; i++) {
                var dark = ((vb >>> i) & 1) !== 0, a = size - 11 + (i % 3), b = Math.floor(i / 3);
                setFn(a, b, dark); setFn(b, a, dark);
            }
        }

        var idx = 0;
        for (var right = size - 1; right >= 1; right -= 2) {
            if (right === 6) right = 5;
            for (var vert = 0; vert < size; vert++) {
                for (var jj = 0; jj < 2; jj++) {
                    x = right - jj;
                    var upward = ((right + 1) & 2) === 0;
                    y = upward ? size - 1 - vert : vert;
                    if (!fn[y][x] && idx < codewords.length * 8) {
                        mod[y][x] = ((codewords[idx >>> 3] >>> (7 - (idx & 7))) & 1) !== 0;
                        idx++;
                    }
                }
            }
        }

        function applyMask(mask) {
            for (var yy = 0; yy < size; yy++) for (var xx = 0; xx < size; xx++) {
                var inv;
                switch (mask) {
                    case 0: inv = (xx + yy) % 2 === 0; break;
                    case 1: inv = yy % 2 === 0; break;
                    case 2: inv = xx % 3 === 0; break;
                    case 3: inv = (xx + yy) % 3 === 0; break;
                    case 4: inv = (Math.floor(xx / 3) + Math.floor(yy / 2)) % 2 === 0; break;
                    case 5: inv = (xx * yy) % 2 + (xx * yy) % 3 === 0; break;
                    case 6: inv = ((xx * yy) % 2 + (xx * yy) % 3) % 2 === 0; break;
                    default: inv = ((xx + yy) % 2 + (xx * yy) % 3) % 2 === 0;
                }
                if (!fn[yy][xx] && inv) mod[yy][xx] = !mod[yy][xx];
            }
        }

        function penalty() {
            var score = 0, r, c, run, dark = 0;
            for (r = 0; r < size; r++) {
                run = 1;
                for (c = 1; c < size; c++) {
                    if (mod[r][c] === mod[r][c - 1]) { run++; if (run === 5) score += 3; else if (run > 5) score++; } else run = 1;
                }
            }
            for (c = 0; c < size; c++) {
                run = 1;
                for (r = 1; r < size; r++) {
                    if (mod[r][c] === mod[r - 1][c]) { run++; if (run === 5) score += 3; else if (run > 5) score++; } else run = 1;
                }
            }
            for (r = 0; r < size - 1; r++) for (c = 0; c < size - 1; c++) {
                var v = mod[r][c];
                if (v === mod[r][c + 1] && v === mod[r + 1][c] && v === mod[r + 1][c + 1]) score += 3;
            }
            for (r = 0; r < size; r++) for (c = 0; c < size; c++) if (mod[r][c]) dark++;
            var total = size * size;
            score += (Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
            return score;
        }

        var best = 0, bestScore = Infinity;
        for (var m = 0; m < 8; m++) {
            applyMask(m); drawFormat(m);
            var p = penalty();
            if (p < bestScore) { bestScore = p; best = m; }
            applyMask(m);   // undo
        }
        applyMask(best); drawFormat(best);
        return mod;
    }

    function encode(text) {
        var bytes = utf8(String(text));
        for (var ver = 1; ver <= 10; ver++) {
            var need = 4 + (ver <= 9 ? 8 : 16) + bytes.length * 8;
            if (need <= dataCodewords(ver) * 8) return makeMatrix(ver, buildCodewords(bytes, ver));
        }
        throw new Error('Text is too long for this QR generator.');
    }

    function svg(text, quiet) {
        var m = encode(text), n = m.length, q = quiet === undefined ? 4 : quiet, d = [];
        for (var y = 0; y < n; y++) for (var x = 0; x < n; x++) {
            if (m[y][x]) d.push('M' + (x + q) + ',' + (y + q) + 'h1v1h-1z');
        }
        var s = n + q * 2;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + s + ' ' + s + '" shape-rendering="crispEdges">' +
               '<rect width="100%" height="100%" fill="#fff"/><path d="' + d.join('') + '" fill="#000"/></svg>';
    }

    function render() {
        var imgs = document.querySelectorAll('img[data-qr]');
        for (var i = 0; i < imgs.length; i++) {
            try {
                imgs[i].src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg(imgs[i].getAttribute('data-qr')));
            } catch (e) { imgs[i].style.display = 'none'; }
        }
    }

    global.CSOQR = { svg: svg, render: render };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render);
    else render();
})(window);
