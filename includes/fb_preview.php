<?php
/**
 * Reads the public preview of a Facebook link (the same data Facebook gives
 * to link-preview crawlers): title, description, image, and — when the page
 * exposes it — the event start/end date and time.
 *
 * Best effort only: Facebook decides what it shows to outsiders. Private or
 * login-only posts return nothing. Every function fails quietly (returns
 * null / false) so a bad fetch never breaks saving an event.
 */

function fb_host_ok($url) {
    $p = parse_url($url);
    if (!$p || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    return (bool) preg_match('~(^|\.)(facebook\.com|fb\.com|fb\.me|fb\.watch|fbcdn\.net|fbsbx\.com)$~i', $p['host']);
}

/** GET with manual redirect handling so every hop is checked against the Facebook-only host list. */
function fb_http_get($url, $max_bytes = 2097152) {
    if (!function_exists('curl_init')) return null;
    for ($hop = 0; $hop < 5; $hop++) {
        if (!fb_host_ok($url)) return null;
        $body = null;
        foreach ([true, false] as $verify) {   // XAMPP on Windows often has no CA bundle; retry without it (public data only, nothing secret is sent)
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT      => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uagent.php)',
                CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            if ($raw !== false) { $body = [$code, substr($raw, 0, $hsize), substr($raw, $hsize)]; break; }
            if ($errno !== 60 && $errno !== 35 && $errno !== 77) break;   // only retry certificate-style failures
        }
        if (!$body) return null;
        list($code, $headers, $content) = $body;
        if ($code >= 300 && $code < 400 && preg_match('~^Location:\s*(\S+)~im', $headers, $m)) {
            $url = fb_absolute_url($m[1], $url);
            continue;
        }
        if ($code !== 200 || strlen($content) > $max_bytes) return null;
        return $content;
    }
    return null;
}

function fb_absolute_url($loc, $base) {
    if (preg_match('~^https?://~i', $loc)) return $loc;
    $p = parse_url($base);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . ($loc[0] === '/' ? '' : '/') . $loc;
}

/** Returns ['title','description','image_url','date','time','end_date','end_time'] (any may be null), or null if nothing could be read. */
function fb_preview($url) {
    $html = fb_http_get($url);
    if (!$html) return null;

    $out = ['title' => null, 'description' => null, 'image_url' => null, 'date' => null, 'time' => null, 'end_date' => null, 'end_time' => null];

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    foreach ($dom->getElementsByTagName('meta') as $m) {
        $prop = strtolower($m->getAttribute('property') ?: $m->getAttribute('name'));
        $val = trim($m->getAttribute('content'));
        if ($val === '') continue;
        if ($prop === 'og:title' && !$out['title']) $out['title'] = $val;
        if (($prop === 'og:description' || $prop === 'description') && !$out['description']) $out['description'] = $val;
        if ($prop === 'og:image' && !$out['image_url']) $out['image_url'] = html_entity_decode($val);
    }

    // Event date/time, when the page includes structured data
    $start = $end = null;
    foreach ($dom->getElementsByTagName('script') as $s) {
        if (strtolower($s->getAttribute('type')) !== 'application/ld+json') continue;
        $j = json_decode($s->textContent, true);
        if (!$j) continue;
        $items = isset($j['@type']) ? [$j] : (is_array($j) ? $j : []);
        foreach ($items as $it) {
            if (is_array($it) && !empty($it['startDate'])) {
                $start = $it['startDate'];
                $end = $it['endDate'] ?? null;
                if (!$out['title'] && !empty($it['name'])) $out['title'] = $it['name'];
                if (!$out['description'] && !empty($it['description'])) $out['description'] = $it['description'];
                break 2;
            }
        }
    }
    if (!$start && preg_match('~"startDate"\s*:\s*"([^"]+)"~', $html, $m)) $start = $m[1];
    if (!$end && preg_match('~"endDate"\s*:\s*"([^"]+)"~', $html, $m)) $end = $m[1];
    if ($start) {
        try {
            $tz = new DateTimeZone(date_default_timezone_get());
            $d = new DateTime($start); $d->setTimezone($tz);
            $out['date'] = $d->format('Y-m-d');
            $out['time'] = $d->format('H:i:s');
            if ($end) { $e = new DateTime($end); $e->setTimezone($tz); $out['end_date'] = $e->format('Y-m-d'); $out['end_time'] = $e->format('H:i:s'); }
        } catch (Exception $ex) { /* leave date blank */ }
    }

    if ($out['description']) $out['description'] = trim(html_entity_decode($out['description'], ENT_QUOTES, 'UTF-8'));
    if ($out['title']) $out['title'] = trim(html_entity_decode($out['title'], ENT_QUOTES, 'UTF-8'));

    return array_filter($out) ? $out : null;
}

/** Downloads the preview image into assets/uploads/events/ and returns its site path, or null. */
function fb_save_image($image_url) {
    if (!$image_url) return null;
    $data = fb_http_get($image_url, 6 * 1024 * 1024);
    if (!$data) return null;
    $info = @getimagesizefromstring($data);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$info['mime'] ?? ''] ?? null;
    if (!$info || !$ext || $info[0] < 1 || $info[0] > 8000 || $info[1] > 8000) return null;
    $dir = __DIR__ . '/../assets/uploads/events/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'fb_' . bin2hex(random_bytes(10)) . '.' . $ext;
    if (@file_put_contents($dir . $name, $data) === false) return null;
    return '/assets/uploads/events/' . $name;
}
