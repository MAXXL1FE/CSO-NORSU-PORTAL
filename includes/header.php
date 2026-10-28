<?php
/**
 * Shared header. Renders the top bar for every page, plus the right-hand
 * slide-in menu drawer when a user is logged in.
 * Paired with includes/footer.php which closes whatever this opens.
 */
if (!function_exists('is_logged_in')) {
    require_once __DIR__ . '/functions.php';
}

$page_title    = $page_title ?? 'CSO-NORSU Portal';
$is_dashboard  = strpos($_SERVER['SCRIPT_NAME'], '/dashboard/') !== false;
$current_file  = basename($_SERVER['SCRIPT_NAME']);
$role          = $_SESSION['role'] ?? null;
$flash         = function_exists('get_flash') ? get_flash() : null;
$my_photo      = $_SESSION['profile_photo'] ?? null;

// Notification badge count (reminders board) — only when we actually
// have a logged-in user and a live DB connection on this page.
$notif_count = 0;
$pending_signups = 0;
$my_student_id = null;
$my_user_type = 'student';
$my_position  = '';
if (is_logged_in() && isset($conn) && $conn instanceof mysqli) {
    $seen_at_h = $conn->real_escape_string(notif_seen_at($conn));
    if ($res = $conn->query("SELECT COUNT(*) c FROM attendance_reminders WHERE created_at > '$seen_at_h'")) {
        $notif_count = (int) $res->fetch_assoc()['c'];
    }
    $my_id_h = (int) $_SESSION['user_id'];
    if ($res = @$conn->query("SELECT COUNT(*) c FROM user_notices WHERE user_id = $my_id_h AND created_at > '$seen_at_h'")) {
        $notif_count += (int) $res->fetch_assoc()['c'];
    }
    $pending_signups = 0;
    if (in_array($_SESSION['role'] ?? '', ['admin', 'officer', 'moderator'], true)
        && ($pr = @$conn->query("SELECT COUNT(*) c FROM users WHERE approval_pending = 1"))) {
        $pending_signups = (int) $pr->fetch_assoc()['c'];
    }
    require_once __DIR__ . '/features.php';
    @run_due_reminders($conn);
    $map_reports_open = 0;
    if (in_array($_SESSION['role'] ?? '', ['admin', 'officer', 'moderator'], true) && ($mr = @$conn->query("SELECT COUNT(*) c FROM map_reports WHERE status = 'open'"))) {
        $map_reports_open = (int) $mr->fetch_assoc()['c'];
    }
    $uid_h = $_SESSION['user_id'];
    try {
        $id_stmt = $conn->prepare("SELECT student_id, user_type, faculty_position FROM users WHERE user_id = ?");
    } catch (Throwable $e) {
        $id_stmt = false;   // PHP 8.1+ throws when a column is missing
    }
    if (!$id_stmt) {
        $id_stmt = $conn->prepare("SELECT student_id FROM users WHERE user_id = ?");   // older schema fallback
    }
    if ($id_stmt) {
        $id_stmt->bind_param('i', $uid_h);
        $id_stmt->execute();
        $id_row = $id_stmt->get_result()->fetch_assoc();
        $my_student_id = $id_row['student_id'] ?? null;
        $my_user_type  = $id_row['user_type'] ?? 'student';
        $my_position   = $id_row['faculty_position'] ?? '';
        $id_stmt->close();
    }
}
// Whether this member has chosen to appear in the Members list (Settings switch).
$my_show_in_members = false;
if (is_logged_in() && isset($conn) && $conn instanceof mysqli) {
    try {
        $sm = $conn->prepare("SELECT show_in_members FROM users WHERE user_id = ?");
        if ($sm) {
            $sm_uid = (int) $_SESSION['user_id'];
            $sm->bind_param('i', $sm_uid);
            $sm->execute();
            $sm_row = $sm->get_result()->fetch_assoc();
            $my_show_in_members = !empty($sm_row['show_in_members']);
            $sm->close();
        }
    } catch (Throwable $e) { /* column not there yet: stays off */ }
}
$my_qr_img = $my_student_id ? $my_student_id : null;   // the QR image is drawn in the browser (assets/js/qr.js)

// Cache-busting: append the file's last-modified time so a replaced
// logo/banner/CSS shows up immediately instead of the browser serving
// a stale cached copy of the old file.
function asset_v($relative_path) {
    $abs = __DIR__ . '/..' . $relative_path;
    $v = file_exists($abs) ? filemtime($abs) : time();
    return BASE_URL . $relative_path . '?v=' . $v;
}

// Drawer link renderer — numbers each item so the entrance animation staggers.
function drawer_link($href, $icon, $label, $is_active = false, $badge = 0, &$i = 0) {
    $i++;
    $cls = 'drawer-link' . ($is_active ? ' active' : '');
    echo '<a href="' . htmlspecialchars($href) . '" class="' . $cls . '" style="--i:' . $i . '" title="' . htmlspecialchars($label) . '"'
       . ($is_active ? ' aria-current="page"' : '') . '>'
       . '<svg class="icon" aria-hidden="true"><use href="#i-' . $icon . '"/></svg>'
       . '<span>' . htmlspecialchars($label) . '</span>'
       . ($badge > 0 ? '<span class="badge">' . (int) $badge . '</span>' : '')
       . '</a>';
}
function drawer_heading($label, &$i = 0) {
    $i++;
    echo '<p class="drawer-heading" style="--i:' . $i . '">' . htmlspecialchars($label) . '</p>';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?php echo htmlspecialchars($page_title); ?> — CSO-NORSU Portal</title>
    <?php
    $meta_desc = isset($page_description) && $page_description !== '' ? $page_description
        : 'Official portal of the Computer Science Organization of Negros Oriental State University: events, announcements, attendance, the BSCS curriculum and the campus map.';
    $meta_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $meta_base = $meta_scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;
    ?>
    <meta name="description" content="<?php echo htmlspecialchars($meta_desc); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="CSO-NORSU Portal">
    <meta property="og:title" content="<?php echo htmlspecialchars($page_title); ?> — CSO-NORSU Portal">
    <meta property="og:description" content="<?php echo htmlspecialchars($meta_desc); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($meta_base . '/assets/images/home-hero.jpg'); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#FFFFFF">
    <script>
    /* Apply the saved theme before first paint so there is no light/dark flash. */
    (function () {
        try {
            var d = document.documentElement, s = window.localStorage;
            var pref = s.getItem('cso-theme') || 'system';
            var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            d.setAttribute('data-theme', dark ? 'dark' : 'light');
            d.setAttribute('data-theme-pref', pref);
            if (s.getItem('cso-glass') === 'off') d.setAttribute('data-glass', 'off');
            if (s.getItem('cso-motion') === 'reduced') d.setAttribute('data-motion', 'reduced');
            var ac = s.getItem('cso-accent'); if (ac && ac !== 'green') d.setAttribute('data-accent', ac);
            var tx = s.getItem('cso-text');   if (tx === 'sm' || tx === 'lg') d.setAttribute('data-text', tx);
            if (s.getItem('cso-density') === 'compact') d.setAttribute('data-density', 'compact');
            if (s.getItem('cso-sidebar') === 'left') d.setAttribute('data-sidebar', 'left');
            d.setAttribute('data-rail', 'expanded');   // the sidebar no longer collapses
            d.setAttribute('data-rail-open', s.getItem('cso-rail-open') === '1' ? '1' : '0');
            document.querySelector('meta[name="theme-color"]').setAttribute('content', dark ? '#12241B' : '#FFFFFF');
        } catch (e) { document.documentElement.setAttribute('data-theme', 'light'); }
    })();
    </script>
    <link rel="icon" type="image/png" href="<?php echo asset_v('/assets/images/logo.png'); ?>">
    <link rel="manifest" href="<?php echo BASE_URL; ?>/manifest.php">
    <link rel="apple-touch-icon" href="<?php echo BASE_URL; ?>/assets/icons/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="CSO-NORSU">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?php echo asset_v('/assets/css/style.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_v('/assets/css/ui.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_v('/assets/css/professional.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_v('/assets/css/mobile.css'); ?>" media="(max-width: 640px)">
    <?php if (($page_title ?? '') === 'Home'): ?><link rel="stylesheet" href="<?php echo asset_v('/assets/css/home.css'); ?>"><?php endif; ?>
    <script src="<?php echo asset_v('/assets/js/qr.js'); ?>" defer></script>
    <script src="<?php echo asset_v('/assets/js/app.js'); ?>" defer></script>
    <noscript><style>.page-loader{display:none!important}</style></noscript>
</head>
<body class="<?php echo trim(($is_dashboard ? 'has-dashboard ' : '') . (!empty($auth_layout) ? 'auth-body ' : '') . (is_logged_in() && empty($auth_layout) ? 'has-rail' : '')); ?>">
    <a class="skip-link" href="#main">Skip to main content</a>
    <!-- Skeleton placeholder shaped like the page that is loading (see includes/skeleton.php). -->
    <?php require_once __DIR__ . '/skeleton.php'; skeleton_html(); ?>
    <div id="nav-progress" class="nav-progress" aria-hidden="true"></div>

<!-- Icon sprite -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></symbol>
    <symbol id="i-compass" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><polygon points="16.2 7.8 14.1 14.1 7.8 16.2 9.9 9.9 16.2 7.8"/></symbol>
    <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
    <symbol id="i-briefcase" viewBox="0 0 24 24"><rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/></symbol>
    <symbol id="i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/></symbol>
    <symbol id="i-idcard" viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2.5"/><circle cx="8.5" cy="11" r="2"/><path d="M5.5 16c.6-1.6 1.8-2.3 3-2.3s2.4.7 3 2.3M14.5 10h4M14.5 14h3"/></symbol>
    <symbol id="i-clipboard" viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4h6v3H9zM9 12h6M9 16h4"/></symbol>
    <symbol id="i-award" viewBox="0 0 24 24"><circle cx="12" cy="9" r="5.5"/><path d="M8.5 13.5L7 21l5-2.8 5 2.8-1.5-7.5"/></symbol>
    <symbol id="i-printer" viewBox="0 0 24 24"><path d="M7 9V3h10v6M7 17H5a2 2 0 0 1-2-2v-4a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4a2 2 0 0 1-2 2h-2"/><rect x="7" y="14" width="10" height="7"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></symbol>
    <symbol id="i-chart" viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/></symbol>
    <symbol id="i-book" viewBox="0 0 24 24"><path d="M4 20V5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M8 7h8M8 11h5"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></symbol>
    <symbol id="i-message" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></symbol>
    <symbol id="i-checkin" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></symbol>
    <symbol id="i-qr" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 14h3v3h-3zM20 14v3M14 20h3M20 20h1"/></symbol>
    <symbol id="i-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M22 21a7 7 0 0 0-4-6.3"/></symbol>
    <symbol id="i-megaphone" viewBox="0 0 24 24"><path d="M3 11v2a1 1 0 0 0 1 1h3l8 4V6L7 10H4a1 1 0 0 0-1 1z"/><path d="M19 8a5 5 0 0 1 0 8"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></symbol>
    <symbol id="i-home" viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10a1 1 0 0 0 1 1h3v-6h6v6h3a1 1 0 0 0 1-1V10"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
    <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></symbol>
    <symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></symbol>
    <symbol id="i-monitor" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12" rx="2.5"/><path d="M8 20h8M12 16v4"/></symbol>
    <symbol id="i-sparkle" viewBox="0 0 24 24"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 17l.7 1.8 1.8.7-1.8.7L19 22l-.7-1.8-1.8-.7 1.8-.7z"/></symbol>
    <symbol id="i-zap" viewBox="0 0 24 24"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="i-map" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2z"/><path d="M9 4v14M15 6v14"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s7-5.6 7-11a7 7 0 0 0-14 0c0 5.4 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/></symbol>
    <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18"/><path d="M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.5 6.6A17 17 0 0 0 2 12s3.6 7 10 7a9.6 9.6 0 0 0 4.2-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></symbol>
    <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><path d="M10.3 3.9L2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></symbol>
    <symbol id="i-camera" viewBox="0 0 24 24"><path d="M3 8a2 2 0 0 1 2-2h2l1.5-2h7L17 6h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.5"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16"/><path d="M3 12A9 9 0 0 1 18.5 5.8L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/></symbol>
    <symbol id="i-text" viewBox="0 0 24 24"><path d="M4 7V5h16v2M9 19h6M12 5v14"/></symbol>
    <symbol id="i-palette" viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.8-.8 1.8-1.8 0-.5-.2-.9-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1 .8-1.8 1.8-1.8H17a4 4 0 0 0 4-4C21 6.4 17 3 12 3z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10" cy="7" r="1"/><circle cx="15" cy="7" r="1"/></symbol>
    <symbol id="i-verified" viewBox="0 0 24 24"><path d="M12 2l2.4 1.9 3-.3 1.2 2.8 2.7 1.4-.4 3 1.4 2.6-2 2.3.2 3-2.9.9L15.5 21 12 19.7 8.5 21l-1.8-2.4-2.9-.9.2-3-2-2.3 1.4-2.6-.4-3 2.7-1.4 1.2-2.8 3 .3z"/><path d="M8.3 12.2l2.5 2.5 4.9-5"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-pencil" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>
    <symbol id="i-chevron" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3l8 3v6c0 5-3.4 8.4-8 9-4.6-.6-8-4-8-9V6z"/><path d="M9 12l2.2 2.2L15.5 10"/></symbol>
    <symbol id="i-code" viewBox="0 0 24 24"><path d="M8 7l-5 5 5 5M16 7l5 5-5 5M14 4l-4 16"/></symbol>
    <symbol id="i-settings" viewBox="0 0 24 24"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-photos" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2.5"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15.5l-5-5L5 21"/></symbol>
    <symbol id="i-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.6 2.6 4 5.9 4 9.5s-1.4 6.9-4 9.5c-2.6-2.6-4-5.9-4-9.5s1.4-6.9 4-9.5z"/></symbol>
    <symbol id="i-more" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.8" fill="currentColor"/><circle cx="12" cy="12" r="1.8" fill="currentColor"/><circle cx="19" cy="12" r="1.8" fill="currentColor"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14M10 10v6M14 10v6"/></symbol>
    <symbol id="i-upload" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/></symbol>
    <symbol id="i-close" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
    <symbol id="i-chevrons" viewBox="0 0 24 24"><path d="M13 6l6 6-6 6M5 6l6 6-6 6"/></symbol>
</svg>

<?php if (empty($auth_layout)): ?>
<header class="topbar">
    <div class="topbar-inner">
        <a href="<?php echo BASE_URL; ?>/home.php" class="brand">
            <span class="brand-mark">
                <img src="<?php echo asset_v('/assets/images/logo.png'); ?>" alt="CSO-NORSU logo" onerror="this.style.display='none'; this.parentElement.textContent='CS';">
            </span>
            <span class="brand-text">
                <strong>CSO-NORSU</strong>
                <small>Student Portal</small>
            </span>
        </a>

        <?php $quick_notifications_current = 'notifications.php' === $current_file; ?>

        <?php if ($is_dashboard): ?>
            <span class="topbar-page"><?php echo htmlspecialchars($page_title); ?></span>
        <?php endif; ?>

        <div class="topbar-actions">
            <button type="button" class="search-toggle" id="searchToggle" aria-label="Open search" aria-expanded="false" aria-controls="topbarSearch" title="Search">
                <svg class="icon" aria-hidden="true"><use href="#i-search"/></svg>
            </button>
            <?php if (is_logged_in()): ?>
                <a class="topbar-notif<?php echo $quick_notifications_current ? ' is-current' : ''; ?>" href="<?php echo BASE_URL; ?>/dashboard/notifications.php" aria-label="Notifications<?php echo $notif_count > 0 ? ', ' . (int) $notif_count . ' unread' : ''; ?>" title="Notifications"<?php echo $quick_notifications_current ? ' aria-current="page"' : ''; ?>>
                    <svg class="icon" aria-hidden="true"><use href="#i-bell"/></svg>
                    <?php if ($notif_count > 0): ?><span class="topbar-badge" aria-hidden="true"><?php echo $notif_count > 9 ? '9+' : (int) $notif_count; ?></span><?php endif; ?>
                </a>
                <a href="<?php echo BASE_URL; ?>/dashboard/profile.php" class="topbar-user" aria-label="My Profile">
                    <?php if ($my_photo): ?>
                        <img src="<?php echo asset_v($my_photo); ?>" alt="" class="avatar-chip">
                    <?php else: ?>
                        <span class="avatar-chip"><?php echo htmlspecialchars(initials($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '')); ?></span>
                    <?php endif; ?>
                    <span class="topbar-user-name"><?php echo htmlspecialchars($_SESSION['first_name'] ?? ''); ?></span>
                </a>
                <button type="button" class="user-menu-toggle" id="userMenuToggle" aria-haspopup="dialog" aria-expanded="false" aria-controls="drawer" aria-label="Open menu">
                    <span class="burger" aria-hidden="true"><i></i><i></i><i></i></span>
                    <?php if ($notif_count > 0): ?><span class="user-menu-dot" aria-hidden="true"></span><?php endif; ?>
                </button>
            <?php elseif (!in_array($current_file, ['admin_login.php', 'faculty_login.php'], true)): ?>
                <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-outline btn-sm">Log in</a>
                <a href="<?php echo BASE_URL; ?>/register.php" class="btn btn-primary btn-sm">Join CSO</a>
            <?php endif; ?>
        </div>

        <!-- Expanding search bar (opens over the header from the search icon) -->
        <form class="topbar-search" id="topbarSearch" action="<?php echo BASE_URL; ?>/search.php" method="get" role="search" aria-hidden="true">
            <svg class="icon ts-icon" aria-hidden="true"><use href="#i-search"/></svg>
            <input type="search" name="q" id="topbarSearchInput" placeholder="Search events, announcements<?php echo is_logged_in() ? ', members' : ''; ?>&hellip;" aria-label="Search the site" autocomplete="off" maxlength="80" tabindex="-1">
            <span class="ts-sep" aria-hidden="true"></span>
            <button type="submit" class="ts-go" tabindex="-1">Search</button>
            <button type="button" class="ts-close" id="searchClose" aria-label="Close search" tabindex="-1">
                <svg class="icon" aria-hidden="true"><use href="#i-close"/></svg>
            </button>
        </form>
    </div>
</header>
<?php endif; ?>

<?php if (is_logged_in()): ?>
<div class="drawer-backdrop" id="drawerBackdrop"></div>
<aside class="drawer" id="drawer" role="dialog" aria-modal="true" aria-label="Main menu" aria-hidden="true">
    <div class="drawer-head">
        <button type="button" class="drawer-close" id="drawerClose" aria-label="Close menu">
            <svg class="icon" aria-hidden="true"><use href="#i-close"/></svg>
        </button>
    </div>

    <nav class="drawer-body" aria-label="Main navigation">
        <?php
        /* Facebook-style order: everyday links -> staff tools -> Settings last. */
        $n = 0;
        $is_staff = in_array($role, ['admin', 'officer', 'moderator'], true);
        $on_public = !$is_dashboard;

        drawer_link(BASE_URL . '/home.php', 'home', 'Home', 'home.php' === $current_file, 0, $n);
        drawer_link(BASE_URL . '/dashboard/' . $role . '.php', 'grid',      'Dashboard',     $role . '.php' === $current_file, 0, $n);
        drawer_link(BASE_URL . '/events.php',                  'compass',   'Events',        $on_public && in_array($current_file, ['events.php', 'event.php'], true), 0, $n);
        drawer_link(BASE_URL . '/announcements.php',           'megaphone', 'Announcements', $on_public && in_array($current_file, ['announcements.php', 'announcement.php'], true), 0, $n);
        drawer_link(BASE_URL . '/calendar.php',                'calendar',  'Calendar',      $on_public && 'calendar.php' === $current_file, 0, $n);
        drawer_link(BASE_URL . '/map.php',                     'map',       'Map',           $on_public && 'map.php' === $current_file, 0, $n);
        drawer_link(BASE_URL . '/directory.php',               'briefcase', 'Faculty & Staff', $on_public && 'directory.php' === $current_file, 0, $n);
        drawer_link(BASE_URL . '/officers.php',                'award',     'Officers',      $on_public && in_array($current_file, ['officers.php', 'officer.php'], true), 0, $n);
        drawer_link(BASE_URL . '/members.php',                 'user',      'Members',       $on_public && 'members.php' === $current_file, 0, $n);
        drawer_link(BASE_URL . '/curriculum.php',              'book',      'Curriculum',    $on_public && 'curriculum.php' === $current_file, 0, $n);
        drawer_link(BASE_URL . '/dashboard/notifications.php', 'bell',      'Notifications', 'notifications.php' === $current_file, $notif_count, $n);
        drawer_link(BASE_URL . '/dashboard/my_attendance.php', 'checkin',   'Attendance Attended', 'my_attendance.php' === $current_file, 0, $n);
        if ($my_qr_img) {
            $n++;
            echo '<button type="button" class="drawer-link" data-action="qr" title="My QR Code" style="--i:' . $n . '">'
               . '<svg class="icon" aria-hidden="true"><use href="#i-qr"/></svg><span>My QR Code</span></button>';
        }
        drawer_link(BASE_URL . '/dashboard/feedback.php', 'message', 'Feedback',     'feedback.php' === $current_file, 0, $n);
        drawer_link(BASE_URL . '/instructions.php',       'help',    'Instructions', 'instructions.php' === $current_file, 0, $n);

        if ($is_staff) {
            drawer_heading('Manage', $n);
            drawer_link(BASE_URL . '/dashboard/members.php',       'users',     'Members',              'members.php' === $current_file, $pending_signups, $n);
            drawer_link(BASE_URL . '/dashboard/events.php',        'calendar',  'Manage Events',        $is_dashboard && 'events.php' === $current_file, 0, $n);
            drawer_link(BASE_URL . '/dashboard/announcements.php', 'megaphone', 'Manage Announcements', $is_dashboard && 'announcements.php' === $current_file, 0, $n);
            drawer_link(BASE_URL . '/dashboard/attendance.php',    'clipboard', 'Attendance',           'attendance.php' === $current_file, 0, $n);
            drawer_link(BASE_URL . '/dashboard/attendance_stats.php', 'chart',    'Attendance Charts',    'attendance_stats.php' === $current_file, 0, $n);
            drawer_link(BASE_URL . '/dashboard/student_report.php',   'user',     'Student Reports',      'student_report.php' === $current_file, 0, $n);
            drawer_link(BASE_URL . '/dashboard/map_reports.php', 'message', 'Map Reports', 'map_reports.php' === $current_file, $map_reports_open, $n);
            drawer_link(BASE_URL . '/dashboard/map.php',           'map',       'Manage Map',           $is_dashboard && 'map.php' === $current_file, 0, $n);
            if (in_array($role, ['admin', 'officer'], true)) {
                    drawer_link(BASE_URL . '/dashboard/roster.php', 'idcard', 'Student ID List', 'roster.php' === $current_file, 0, $n);
                    drawer_link(BASE_URL . '/dashboard/reset_codes.php', 'shield', 'Reset Codes', 'reset_codes.php' === $current_file, 0, $n);
            }
            if ($role === 'admin') {
                drawer_link(BASE_URL . '/dashboard/info.php', 'info', 'About CSO Section', 'info.php' === $current_file, 0, $n);
            }
        }

        $n++;
        ?>
        <button type="button" class="drawer-link settings-link" id="settingsToggle" aria-expanded="false" aria-controls="settingsGroup" aria-label="Show settings" title="Settings" style="--i:<?php echo $n; ?>">
            <svg class="icon" aria-hidden="true"><use href="#i-settings"/></svg>
            <span>Settings</span>
            <svg class="icon chevron" aria-hidden="true"><use href="#i-chevron"/></svg>
        </button>
        <div class="settings-collapse" id="settingsGroup"><div class="settings-collapse-inner">
        <div class="setting-block" style="--i:<?php echo ++$n; ?>">
            <p class="setting-label"><svg class="icon" aria-hidden="true"><use href="#i-sun"/></svg>Appearance</p>
            <div class="seg" id="themeSeg" role="radiogroup" aria-label="Appearance">
                <span class="seg-thumb" aria-hidden="true"></span>
                <button type="button" class="seg-btn" role="radio" aria-checked="false" data-theme-pref="light"><svg class="icon" aria-hidden="true"><use href="#i-sun"/></svg>Light</button>
                <button type="button" class="seg-btn" role="radio" aria-checked="false" data-theme-pref="dark"><svg class="icon" aria-hidden="true"><use href="#i-moon"/></svg>Dark</button>
                <button type="button" class="seg-btn" role="radio" aria-checked="false" data-theme-pref="system"><svg class="icon" aria-hidden="true"><use href="#i-monitor"/></svg>System</button>
            </div>
        </div>
        <div class="setting-block setting-inline" style="--i:<?php echo ++$n; ?>">
            <p class="setting-label"><svg class="icon" aria-hidden="true"><use href="#i-palette"/></svg>Accent color</p>
            <div class="swatches" role="radiogroup" aria-label="Accent color">
                <button type="button" class="swatch" role="radio" aria-checked="true"  data-accent="green"  style="--sw1:#34D17A;--sw2:#0F8A4B" aria-label="Green"  title="Green"></button>
                <button type="button" class="swatch" role="radio" aria-checked="false" data-accent="lime"   style="--sw1:#A3E635;--sw2:#5B8C0A" aria-label="Lime"   title="Lime"></button>
                <button type="button" class="swatch" role="radio" aria-checked="false" data-accent="forest" style="--sw1:#4ADE80;--sw2:#166534" aria-label="Forest" title="Forest"></button>
            </div>
        </div>
        <div class="setting-block" style="--i:<?php echo ++$n; ?>">
            <p class="setting-label"><svg class="icon" aria-hidden="true"><use href="#i-text"/></svg>Text size</p>
            <div class="seg" id="textSeg" role="radiogroup" aria-label="Text size">
                <span class="seg-thumb" aria-hidden="true"></span>
                <button type="button" class="seg-btn" role="radio" aria-checked="false" data-text-size="sm" style="font-size:.78rem;">Small</button>
                <button type="button" class="seg-btn" role="radio" aria-checked="true"  data-text-size="md">Default</button>
                <button type="button" class="seg-btn" role="radio" aria-checked="false" data-text-size="lg" style="font-size:.95rem;">Large</button>
            </div>
        </div>
        <div class="setting-block" style="--i:<?php echo ++$n; ?>">
            <p class="setting-label"><svg class="icon" aria-hidden="true"><use href="#i-grid"/></svg>Sidebar side</p>
            <div class="seg" id="sideSeg" role="radiogroup" aria-label="Sidebar side">
                <span class="seg-thumb" aria-hidden="true"></span>
                <button type="button" class="seg-btn" role="radio" aria-checked="false" data-sidebar-side="left">Left</button>
                <button type="button" class="seg-btn" role="radio" aria-checked="true"  data-sidebar-side="right">Right</button>
            </div>
        </div>
        <div class="setting-block setting-list" style="--i:<?php echo ++$n; ?>">
            <button type="button" class="setting-row" role="switch" aria-checked="true" data-setting="glass">
                <svg class="icon" aria-hidden="true"><use href="#i-sparkle"/></svg>
                <span>Glass effects<small>Frosted blur and glow</small></span>
                <span class="switch" aria-hidden="true"></span>
            </button>
            <button type="button" class="setting-row" role="switch" aria-checked="false" data-setting="density">
                <svg class="icon" aria-hidden="true"><use href="#i-grid"/></svg>
                <span>Compact layout<small>Tighter spacing</small></span>
                <span class="switch" aria-hidden="true"></span>
            </button>
            <button type="button" class="setting-row" role="switch" aria-checked="false" data-setting="motion">
                <svg class="icon" aria-hidden="true"><use href="#i-zap"/></svg>
                <span>Reduce animations<small>Calmer, faster screens</small></span>
                <span class="switch" aria-hidden="true"></span>
            </button>
            <button type="button" class="setting-row" role="switch" aria-checked="<?php echo $my_show_in_members ? 'true' : 'false'; ?>" data-members-toggle data-endpoint="<?php echo BASE_URL; ?>/dashboard/members_visibility.php" data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>">
                <svg class="icon" aria-hidden="true"><use href="#i-users"/></svg>
                <span>Show me in Members<small>Visible in the directory</small></span>
                <span class="switch" aria-hidden="true"></span>
            </button>
            <?php if (($_SESSION['role'] ?? '') === 'admin' && function_exists('member_approval_required')): $appr_on = member_approval_required($conn); ?>
            <button type="button" class="setting-row" role="switch" aria-checked="<?php echo $appr_on ? 'true' : 'false'; ?>" data-approval-toggle
                    data-endpoint="<?php echo BASE_URL; ?>/dashboard/approval_toggle.php" data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>"
                    data-on-text="On: new students need approval to log in" data-off-text="Off: new students can log in right away">
                <svg class="icon" aria-hidden="true"><use href="#i-check"/></svg>
                <span>Approve new members<small><?php echo $appr_on ? 'On: new students need approval to log in' : 'Off: new students can log in right away'; ?></small></span>
                <span class="switch" aria-hidden="true"></span>
            </button>
            <?php endif; ?>
            <?php if (($_SESSION['role'] ?? '') === 'admin' && function_exists('home_marquee_enabled')): $mq_on = home_marquee_enabled($conn); ?>
            <button type="button" class="setting-row" role="switch" aria-checked="<?php echo $mq_on ? 'true' : 'false'; ?>" data-approval-toggle
                    data-endpoint="<?php echo BASE_URL; ?>/dashboard/marquee_toggle.php" data-csrf="<?php echo htmlspecialchars(csrf_token()); ?>"
                    data-on-text="On: Home cards glide sideways in a loop" data-off-text="Off: Home cards stay still">
                <svg class="icon" aria-hidden="true"><use href="#i-grid"/></svg>
                <span>Moving Home strips<small><?php echo $mq_on ? 'On: Home cards glide sideways in a loop' : 'Off: Home cards stay still'; ?></small></span>
                <span class="switch" aria-hidden="true"></span>
            </button>
            <?php endif; ?>
            <button type="button" class="setting-row setting-reset" data-setting-reset>
                <svg class="icon" aria-hidden="true"><use href="#i-refresh"/></svg>
                <span>Reset settings<small>Back to the defaults</small></span>
            </button>
        </div>
        </div></div>
        <?php $n++; ?>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="drawer-link drawer-link-danger drawer-logout" title="Log Out" style="--i:<?php echo $n + 1; ?>">
            <svg class="icon" aria-hidden="true"><use href="#i-logout"/></svg><span>Log Out</span>
        </a>
        <div class="drawer-end-space" aria-hidden="true"></div>
    </nav>

</aside>
<?php endif; ?>

<?php if (is_logged_in() && $my_qr_img): ?>
<div class="modal-overlay" id="qrModalOverlay" onclick="if (event.target === this) closeQrModal();">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="qrModalTitle" style="max-width:360px;">
        <div class="modal-header">
            <h3 id="qrModalTitle">My QR Code</h3>
            <button type="button" class="modal-close" aria-label="Close" onclick="closeQrModal()">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;">
            <img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-qr="<?php echo htmlspecialchars($my_qr_img); ?>" alt="QR code for student ID <?php echo htmlspecialchars($my_student_id); ?>" style="width:100%; max-width:280px; margin:0 auto; background:#fff; border:1px solid var(--line); border-radius:var(--radius); padding:16px;">
            <p style="margin-top:14px; margin-bottom:0; font-weight:700; letter-spacing:0.02em;"><?php echo htmlspecialchars($my_student_id); ?></p>
            <p class="muted" style="font-size:0.82rem;">Hold this up for officers to scan at check-in.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary btn-sm" onclick="closeQrModal()">Done</button>
        </div>
    </div>
</div>
<script>
function openQrModal(e) {
    if (e && e.preventDefault) e.preventDefault();
    var overlay = document.getElementById('qrModalOverlay');
    if (!overlay) return;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeQrModal() {
    var overlay = document.getElementById('qrModalOverlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeQrModal();
});
</script>
<?php endif; ?>

<?php if ($is_dashboard): ?>
<div class="app-shell">
    <main class="app-main" id="main" tabindex="-1">
        <div class="app-main-inner">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" role="<?php echo $flash['type'] === 'error' ? 'alert' : 'status'; ?>" aria-live="<?php echo $flash['type'] === 'error' ? 'assertive' : 'polite'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
            <?php endif; ?>
<?php elseif (empty($auth_layout)): ?>
    <?php if ($flash): ?>
        <div class="container" style="padding-top:20px;">
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>" role="<?php echo $flash['type'] === 'error' ? 'alert' : 'status'; ?>" aria-live="<?php echo $flash['type'] === 'error' ? 'assertive' : 'polite'; ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
        </div>
    <?php endif; ?>
<?php endif; ?>
