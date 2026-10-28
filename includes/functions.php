<?php
/**
 * Shared helper functions: session bootstrap, auth guards, CSRF,
 * sanitization, and BASE_URL auto-detection.
 */

// ---------------------------------------------------------------
// Production hardening: never show PHP errors to visitors (they can reveal
// file paths and database details). On a local machine they stay visible.
// ---------------------------------------------------------------
$cso_is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$cso_is_local) {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
}

// ---------------------------------------------------------------
// Security headers, sent on every page.
//  - frame-ancestors / X-Frame-Options: nobody can put this site inside a frame (clickjacking)
//  - nosniff: browsers must not guess file types
//  - CSP: scripts, styles, fonts and images only load from this site and the few hosts listed
//  - camera is allowed for this site only (QR scanning); microphone, location etc. are off
// ---------------------------------------------------------------
if (!headers_sent()) {
    $hdr_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com data:; "
         . "img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; "
         . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    if ($hdr_https) header('Strict-Transport-Security: max-age=15552000');
}

if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.use_strict_mode', '1');    // the server refuses session IDs it did not create
    @ini_set('session.use_only_cookies', '1');   // never accept a session ID from the URL
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
             || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,      // JavaScript cannot read the session cookie
        'secure'   => $is_https, // HTTPS only, when the site is served over HTTPS
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Format a user's full name from first/middle/last/suffix, skipping
 * whichever pieces are empty. $row can be a DB row array or $_SESSION-style
 * array as long as the keys match.
 */
function full_name($row, $with_suffix = true) {
    $parts = [];
    if (!empty($row['first_name'])) $parts[] = $row['first_name'];
    if (!empty($row['middle_name'])) $parts[] = mb_substr($row['middle_name'], 0, 1) . '.';
    if (!empty($row['last_name'])) $parts[] = $row['last_name'];
    $name = implode(' ', $parts);
    if ($with_suffix && !empty($row['suffix'])) {
        $name .= ', ' . $row['suffix'];
    }
    return $name;
}

// ---------------------------------------------------------------
// BASE_URL auto-detection — works whether the app lives at
// http://localhost/CSO-MIS or at the web root http://localhost/
// ---------------------------------------------------------------
if (!defined('BASE_URL')) {
    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // Normalize: strip a trailing /dashboard if functions.php was required from there
    $script_dir = preg_replace('#/dashboard$#', '', $script_dir);
    $script_dir = rtrim($script_dir, '/');
    define('BASE_URL', $script_dir);
}

// ---------------------------------------------------------------
// Sanitization
// ---------------------------------------------------------------
function clean($value) {
    if ($value === null) return '';
    return trim(strip_tags($value));
}

// ---------------------------------------------------------------
// Auth helpers
// ---------------------------------------------------------------
function is_logged_in() {
    if (!isset($_SESSION['user_id'])) return false;
    static $verified = null;
    if ($verified !== null) return $verified;
    global $conn;
    // Fail closed: without a database connection no session can be verified.
    if (!($conn instanceof mysqli)) return $verified = false;
    // Sessions from before this check, or whose password was changed, are signed out.
    if (!isset($_SESSION['tv'])) { unset($_SESSION['user_id']); return $verified = false; }
    // Automatic sign-out after 2 hours without activity, or 12 hours after logging in.
    $now = time();
    if (!isset($_SESSION['started'])) $_SESSION['started'] = $now;
    if (($now - ($_SESSION['last_active'] ?? $now)) > 7200 || ($now - $_SESSION['started']) > 43200) {
        unset($_SESSION['user_id'], $_SESSION['tv'], $_SESSION['started'], $_SESSION['last_active']);
        return $verified = false;
    }
    $_SESSION['last_active'] = $now;
    $stmt = $conn->prepare("SELECT token_version, status, role FROM users WHERE user_id = ?");
    if (!$stmt) return $verified = false;
    $uid = (int) $_SESSION['user_id'];
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $ok = $row && $row['status'] === 'active' && (int) $row['token_version'] === (int) $_SESSION['tv'];
    if (!$ok) { unset($_SESSION['user_id'], $_SESSION['tv']); return $verified = false; }
    // Always use the role stored in the database, so a promotion or demotion applies immediately.
    $_SESSION['role'] = $row['role'];
    return $verified = true;
}

function require_login() {
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function require_role($roles) {
    require_login();
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    if (!in_array($_SESSION['role'], $roles, true)) {
        header('Location: ' . BASE_URL . '/unauthorized.php');
        exit;
    }
}

/** Where everyone lands after logging in (or if they open a guest-only page while logged in). */
function redirect_home() {
    header('Location: ' . BASE_URL . '/home.php');
    exit;
}

/**
 * After a successful login: go to the Home page. A deep link the person
 * was originally heading to (e.g. an event page) is honoured, but a
 * dashboard page never is — logging in always starts at Home.
 */
function redirect_after_login() {
    $target = '/home.php';
    if (!empty($_SESSION['redirect_after_login'])) {
        $r = (string) $_SESSION['redirect_after_login'];
        unset($_SESSION['redirect_after_login']);
        if (BASE_URL !== '' && strpos($r, BASE_URL) === 0) {
            $r = substr($r, strlen(BASE_URL));
        }
        $safe = strlen($r) > 1 && $r[0] === '/' && $r[1] !== '/' && $r[1] !== '\\' && !preg_match('/[\x00-\x1f\\\\]/', $r);
        if ($safe && strpos($r, '/dashboard/') === false && strpos($r, 'login.php') === false && strpos($r, 'logout.php') === false) {
            $target = $r;
        }
    }
    header('Location: ' . BASE_URL . $target);
    exit;
}

// ---------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_check() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Security check failed. Please go back and try again.');
    }
}

// ---------------------------------------------------------------
// Flash messages (survive one redirect)
// ---------------------------------------------------------------
/** Builds an onsubmit attribute that asks before a destructive action. Text is escaped for HTML and JS. */
function confirm_attr($message) {
    return 'onsubmit="return confirm(' . htmlspecialchars(json_encode($message), ENT_QUOTES) . ');"';
}

// ---------------------------------------------------------------
// Small on/off settings kept in the app_state table.
// member_approval_required(): true means every new student sign-up must be approved by an
// officer/admin. It is OFF by default; admins can turn it on or off on the Admin dashboard.
// ---------------------------------------------------------------
function app_setting($conn, $key, $default = null) {
    $stmt = $conn->prepare("SELECT v FROM app_state WHERE k = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['v'] : $default;
}

function set_app_setting($conn, $key, $value) {
    $value = (string) $value;
    $stmt = $conn->prepare("INSERT INTO app_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// Home page: officers, events and faculty glide sideways in a loop. Admins can switch that off
// (Admin dashboard or the sidebar Settings). Default: ON.
function home_marquee_enabled($conn) {
    return app_setting($conn, 'home_marquee', '1') !== '0';
}

function member_approval_required($conn) {
    // Default is OFF (no approval step) until an admin saves the setting on the Admin dashboard.
    return app_setting($conn, 'require_member_approval', '0') === '1';
}

function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    if (empty($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// ---------------------------------------------------------------
// Image uploads (profile photos, event covers, announcement images,
// event galleries, About-CSO image) — all funnel through here so
// validation, naming, and storage stay consistent everywhere.
// ---------------------------------------------------------------
function handle_image_upload($field_name, $subdir, $max_bytes = 3 * 1024 * 1024) {
    if (!isset($_FILES[$field_name]) || $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null]; // nothing uploaded — not an error
    }
    return handle_image_upload_file($_FILES[$field_name], $subdir, $max_bytes);
}

// Same validation/storage logic, but takes a single-file $_FILES-shaped
// array directly — used for multi-file inputs like the event gallery,
// where each entry has to be pulled out of the PHP multi-file structure
// before this runs.
function handle_image_upload_file($file, $subdir, $max_bytes = 3 * 1024 * 1024) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The image failed to upload. Please try again.'];
    }
    if ($file['size'] > $max_bytes) {
        $mb = round($max_bytes / 1024 / 1024, 1);
        return ['ok' => false, 'error' => "Image is too large — please keep it under {$mb}MB."];
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Please upload a JPG, PNG, WEBP, or GIF image.'];
    }

    // The bytes must also parse as a real image (blocks files that only fake an image header).
    $dims = @getimagesize($file['tmp_name']);
    if ($dims === false || $dims[0] < 1 || $dims[1] < 1 || $dims[0] > 8000 || $dims[1] > 8000) {
        return ['ok' => false, 'error' => 'That file is not a valid image.'];
    }

    $ext = $allowed[$mime];
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest_dir = __DIR__ . '/../assets/uploads/' . $subdir . '/';
    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest_dir . $filename)) {
        return ['ok' => false, 'error' => 'Could not save the uploaded image.'];
    }

    return ['ok' => true, 'path' => '/assets/uploads/' . $subdir . '/' . $filename];
}

function delete_uploaded_image($relative_path) {
    if (empty($relative_path)) return;
    $abs = __DIR__ . '/..' . $relative_path;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

// ---------------------------------------------------------------
// Small display helpers
// ---------------------------------------------------------------
function initials($first, $last) {
    $a = mb_substr(trim($first), 0, 1);
    $b = mb_substr(trim($last), 0, 1);
    return mb_strtoupper($a . $b);
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------
// Event / announcement display helpers (used by the detail pages and
// the shared card renderers in includes/cards.php)
// ---------------------------------------------------------------
/** Date for display; the fallback when an event has no date set. */
function fmt_date($d, $fmt = 'M j, Y', $fallback = 'Date TBA') {
    return empty($d) ? $fallback : date($fmt, strtotime($d));
}
/** Clock time for display; empty string when no time is set. */
function fmt_clock($t, $fallback = '') {
    return empty($t) ? $fallback : date('g:i A', strtotime($t));
}
/** "Oct 26, 2026 · 2:00 PM", or just the date, or "Date TBA". */
function fmt_when($d, $t, $fmt = 'M j, Y', $sep = ' · ', $fallback = 'Date TBA') {
    $out = fmt_date($d, $fmt, $fallback);
    if (!empty($d) && !empty($t)) $out .= $sep . fmt_clock($t);
    return $out;
}

function event_range_text($ev) {
    if (empty($ev['event_date'])) return 'Date to be announced';
    $text = date('F j, Y', strtotime($ev['event_date']));
    if (!empty($ev['event_end_date']) && $ev['event_end_date'] !== $ev['event_date']) {
        $text .= ' – ' . date('F j, Y', strtotime($ev['event_end_date']));
    }
    return $text;
}

function event_time_text($ev) {
    if (empty($ev['event_time'])) return '';
    $text = date('g:i A', strtotime($ev['event_time']));
    if (!empty($ev['event_end_time'])) {
        $text .= ' – ' . date('g:i A', strtotime($ev['event_end_time']));
    }
    return $text;
}

/** [start_timestamp, end_timestamp] for an event row. */
function event_timestamps($ev) {
    if (empty($ev['event_date'])) return [0, 0];
    $start    = strtotime($ev['event_date'] . ' ' . (!empty($ev['event_time']) ? $ev['event_time'] : '00:00:00'));
    $end_date = !empty($ev['event_end_date']) ? $ev['event_end_date'] : $ev['event_date'];
    $end      = !empty($ev['event_end_time'])
        ? strtotime($end_date . ' ' . $ev['event_end_time'])
        : strtotime($end_date . ' 23:59:59');
    if ($end < $start) $end = $start + 3600;
    return [$start, $end];
}

/** 'tba' (no date yet) | 'upcoming' | 'ongoing' | 'ended' */
function event_state($ev) {
    if (empty($ev['event_date'])) return 'tba';
    list($start, $end) = event_timestamps($ev);
    $now = time();
    if ($now < $start) return 'upcoming';
    return $now <= $end ? 'ongoing' : 'ended';
}

function event_state_chip($ev) {
    $state = event_state($ev);
    $map = [
        'upcoming' => ['chip', 'Upcoming'],
        'ongoing'  => ['chip chip-live', 'Happening now'],
        'ended'    => ['chip chip-muted', 'Ended'],
        'tba'      => ['chip chip-muted', 'Date to be announced'],
    ];
    return '<span class="' . $map[$state][0] . '">' . $map[$state][1] . '</span>';
}

/** "Today", "Tomorrow", "In 5 days", "3 days ago"… for an event's start date. */
function event_relative_text($ev) {
    if (empty($ev['event_date'])) return '';
    $days = (int) round((strtotime($ev['event_date']) - strtotime(date('Y-m-d'))) / 86400);
    if ($days === 0)  return 'Today';
    if ($days === 1)  return 'Tomorrow';
    if ($days === -1) return 'Yesterday';
    return $days > 0 ? 'In ' . $days . ' days' : abs($days) . ' days ago';
}

function time_left_text($ts) {
    $diff = $ts - time();
    if ($diff <= 0) return 'Closed';
    $d = (int) floor($diff / 86400);
    $h = (int) floor(($diff % 86400) / 3600);
    if ($d >= 1) return $d . ' day' . ($d > 1 ? 's' : '') . ' left';
    if ($h >= 1) return $h . ' hour' . ($h > 1 ? 's' : '') . ' left';
    return 'Less than an hour left';
}

// ---------------------------------------------------------------
// Notifications: a reminder counts as "unread" until the person opens
// the Notifications page. Opening it stamps users.notif_seen_at, which
// clears the badge, the red dot and the dashboard reminder panel.
// ---------------------------------------------------------------
function notif_seen_at($conn) {
    $seen = '1970-01-01 00:00:00';
    if (is_logged_in() && $conn instanceof mysqli) {
        $uid = (int) $_SESSION['user_id'];
        $res = @$conn->query("SELECT notif_seen_at FROM users WHERE user_id = $uid");
        if ($res && ($row = $res->fetch_assoc()) && !empty($row['notif_seen_at'])) {
            $seen = $row['notif_seen_at'];
        }
    }
    return $seen;
}

/** When this user last cleared their notification history (items before it are hidden for them only). */
function notif_cleared_at($conn) {
    $cleared = '1970-01-01 00:00:00';
    if (is_logged_in() && $conn instanceof mysqli) {
        $uid = (int) $_SESSION['user_id'];
        $res = @$conn->query("SELECT notif_cleared_at FROM users WHERE user_id = $uid");
        if ($res && ($row = $res->fetch_assoc()) && !empty($row['notif_cleared_at'])) {
            $cleared = $row['notif_cleared_at'];
        }
    }
    return $cleared;
}

function clear_notification_history($conn) {
    if (is_logged_in() && $conn instanceof mysqli) {
        $uid = (int) $_SESSION['user_id'];
        @$conn->query("UPDATE users SET notif_cleared_at = NOW(), notif_seen_at = NOW() WHERE user_id = $uid");
    }
}

function mark_notifications_seen($conn) {
    if (is_logged_in() && $conn instanceof mysqli) {
        $uid = (int) $_SESSION['user_id'];
        @$conn->query("UPDATE users SET notif_seen_at = NOW() WHERE user_id = $uid");
    }
}

/* ---------- Dashboard metric helpers (design system v5) ---------- */

require_once __DIR__ . '/post_ui.php';


/** Verified badges, granted only by an admin. Faculty get the faculty badge, officers the officer badge, other students the member badge. */
function verified_options($user_type, $role = 'member') {
    $allowed = ['none' => 'No badge'];
    if ($user_type === 'faculty') {
        $allowed['faculty'] = 'Verified faculty';
    } elseif ($role === 'officer') {
        $allowed['officer'] = 'Verified officer';
    } else {
        $allowed['member'] = 'Verified member';
    }
    return $allowed;
}

function verified_badge_html($verified_as) {
    if ($verified_as === 'member') {
        return '<span class="vbadge vbadge-member" title="Verified CSO member. Checked by a CSO admin.">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12l5 5 9-10"/></svg>'
            . 'Verified member</span>';
    }
    if ($verified_as === 'faculty') {
        return '<span class="vbadge vbadge-faculty" title="Verified CSO faculty or adviser. Checked by a CSO admin.">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6z"/></svg>'
            . 'Verified faculty</span>';
    }
    if ($verified_as === 'officer') {
        return '<span class="vbadge vbadge-officer" title="Verified CSO officer. Checked by a CSO admin.">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><path d="M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.5 6.6 19.5l1.2-6-4.5-4.2 6.1-.7z"/></svg>'
            . 'Verified officer</span>';
    }
    return '';
}
