<?php
/**
 * Shared header. Renders the top nav for every page, and — when the
 * current script lives under /dashboard/ — a left sidebar too.
 * Paired with includes/footer.php which closes whatever this opens.
 */
if (!function_exists('is_logged_in')) {
    require_once __DIR__ . '/functions.php';
}

$page_title    = $page_title ?? 'CSO NORSU Portal';
$is_dashboard  = strpos($_SERVER['SCRIPT_NAME'], '/dashboard/') !== false;
$current_file  = basename($_SERVER['SCRIPT_NAME']);
$role          = $_SESSION['role'] ?? null;
$flash         = function_exists('get_flash') ? get_flash() : null;
$my_photo      = $_SESSION['profile_photo'] ?? null;

// Notification badge count (reminders board) — only when we actually
// have a logged-in user and a live DB connection on this page.
$notif_count = 0;
$my_student_id = null;
if (is_logged_in() && isset($conn) && $conn instanceof mysqli) {
    if ($res = $conn->query("SELECT COUNT(*) c FROM attendance_reminders")) {
        $notif_count = (int) $res->fetch_assoc()['c'];
    }
    $uid_h = $_SESSION['user_id'];
    if ($id_stmt = $conn->prepare("SELECT student_id FROM users WHERE user_id = ?")) {
        $id_stmt->bind_param('i', $uid_h);
        $id_stmt->execute();
        $id_row = $id_stmt->get_result()->fetch_assoc();
        $my_student_id = $id_row['student_id'] ?? null;
        $id_stmt->close();
    }
}
$my_qr_img = $my_student_id ? 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&data=' . urlencode($my_student_id) : null;

function nav_active($file, $current) {
    return $file === $current ? ' active' : '';
}

// Cache-busting: append the file's last-modified time so a replaced
// logo/banner/CSS shows up immediately instead of the browser serving
// a stale cached copy of the old file.
function asset_v($relative_path) {
    $abs = __DIR__ . '/..' . $relative_path;
    $v = file_exists($abs) ? filemtime($abs) : time();
    return BASE_URL . $relative_path . '?v=' . $v;
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> — CSO NORSU Portal</title>
    <link rel="icon" type="image/png" href="<?php echo asset_v('/assets/images/logo.png'); ?>">
    <link rel="stylesheet" href="<?php echo asset_v('/assets/css/style.css'); ?>">
</head>
<body class="<?php echo $is_dashboard ? 'has-sidebar' : ''; ?>">

<header class="topbar">
    <div class="topbar-inner">
        <a href="<?php echo BASE_URL; ?>/<?php echo is_logged_in() ? 'dashboard/' . $role . '.php' : 'home.php'; ?>" class="brand">
            <span class="brand-mark">
                <img src="<?php echo asset_v('/assets/images/logo.png'); ?>" alt="CSO NORSU logo" onerror="this.style.display='none'; this.parentElement.textContent='CS';">
            </span>
            <span class="brand-text">
                <strong>CSO NORSU</strong>
                <small>Management &amp; Information System</small>
            </span>
        </a>

        <?php if (!$is_dashboard): ?>
        <nav class="topnav">
            <a href="<?php echo BASE_URL; ?>/home.php" class="<?php echo trim('topnav-link' . nav_active('home.php', $current_file)); ?>">Home</a>
            <a href="<?php echo BASE_URL; ?>/officers.php" class="<?php echo trim('topnav-link' . nav_active('officers.php', $current_file)); ?>">Officers</a>
            <a href="<?php echo BASE_URL; ?>/home.php#events" class="topnav-link">Events</a>
        </nav>
        <?php endif; ?>

        <div class="topbar-actions">
            <?php if (is_logged_in()): ?>
                <div class="user-menu">
                    <button type="button" class="user-menu-toggle" id="userMenuToggle" aria-haspopup="true" aria-expanded="false" aria-controls="userMenuPanel">
                        <span class="topbar-user">
                            <?php if ($my_photo): ?>
                                <img src="<?php echo asset_v($my_photo); ?>" alt="" class="avatar-chip" style="object-fit:cover;">
                            <?php else: ?>
                                <span class="avatar-chip"><?php echo htmlspecialchars(initials($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '')); ?></span>
                            <?php endif; ?>
                            <span class="topbar-user-name"><?php echo htmlspecialchars($_SESSION['first_name'] ?? ''); ?></span>
                        </span>
                        <?php if ($notif_count > 0): ?>
                            <span class="user-menu-dot" aria-hidden="true"></span>
                        <?php endif; ?>
                    </button>

                    <div class="user-menu-panel" id="userMenuPanel" role="menu">
                        <div class="user-menu-account">
                            <?php if ($my_photo): ?>
                                <img src="<?php echo asset_v($my_photo); ?>" alt="" class="avatar-chip avatar-chip-lg" style="object-fit:cover;">
                            <?php else: ?>
                                <span class="avatar-chip avatar-chip-lg"><?php echo htmlspecialchars(initials($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '')); ?></span>
                            <?php endif; ?>
                            <div>
                                <strong><?php echo htmlspecialchars(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))); ?></strong>
                                <span class="user-menu-role"><?php echo htmlspecialchars(ucfirst($role ?? '')); ?></span>
                            </div>
                        </div>

                        <div class="user-menu-links">
                            <a href="<?php echo BASE_URL; ?>/dashboard/notifications.php" class="user-menu-link" role="menuitem">
                                <span>🔔 Notifications</span>
                                <?php if ($notif_count > 0): ?><span class="badge"><?php echo $notif_count; ?></span><?php endif; ?>
                            </a>
                            <a href="<?php echo BASE_URL; ?>/dashboard/my_attendance.php" class="user-menu-link" role="menuitem">
                                <span>📋 Attendance Attended</span>
                            </a>
                            <?php if ($my_qr_img): ?>
                            <a href="#" class="user-menu-link" role="menuitem" onclick="openQrModal(event)">
                                <span>▦ My QR Code</span>
                            </a>
                            <?php endif; ?>
                            <a href="<?php echo BASE_URL; ?>/dashboard/profile.php" class="user-menu-link" role="menuitem">
                                <span>✏️ Edit Profile</span>
                            </a>
                        </div>

                        <div class="user-menu-links">
                            <a href="<?php echo BASE_URL; ?>/logout.php" class="user-menu-link user-menu-link-danger" role="menuitem">
                                <span>🚪 Log Out</span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php elseif ($current_file !== 'admin_login.php'): ?>
                <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-outline btn-sm">Log In</a>
                <a href="<?php echo BASE_URL; ?>/register.php" class="btn btn-primary btn-sm">Join CSO</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if (is_logged_in()): ?>
<script>
(function () {
    var toggle = document.getElementById('userMenuToggle');
    var panel  = document.getElementById('userMenuPanel');
    if (!toggle || !panel) return;

    function closeMenu() {
        panel.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
    }
    function openMenu() {
        panel.classList.add('open');
        toggle.setAttribute('aria-expanded', 'true');
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.contains('open') ? closeMenu() : openMenu();
    });
    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && !toggle.contains(e.target)) closeMenu();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeMenu();
    });
})();
</script>
<?php endif; ?>

<?php if (is_logged_in() && $my_qr_img): ?>
<div class="modal-overlay" id="qrModalOverlay" onclick="if (event.target === this) closeQrModal();">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="qrModalTitle" style="max-width:360px;">
        <div class="modal-header">
            <h3 id="qrModalTitle">My QR Code</h3>
            <button type="button" class="modal-close" aria-label="Close" onclick="closeQrModal()">&times;</button>
        </div>
        <div class="modal-body" style="text-align:center;">
            <img src="<?php echo htmlspecialchars($my_qr_img); ?>" alt="QR code for student ID <?php echo htmlspecialchars($my_student_id); ?>" style="width:100%; max-width:280px; background:#fff; border:1px solid var(--line); border-radius:var(--radius); padding:16px;">
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
    <aside class="sidebar">
        <nav class="sidebar-nav">
            <p class="sidebar-heading">Overview</p>
            <a href="<?php echo BASE_URL; ?>/dashboard/<?php echo $role; ?>.php" class="sidebar-link<?php echo nav_active($role . '.php', $current_file); ?>">
                <span class="sidebar-icon">&#9632;</span> Dashboard
            </a>

            <?php if (in_array($role, ['admin', 'officer'], true)): ?>
            <p class="sidebar-heading">Manage</p>
            <a href="<?php echo BASE_URL; ?>/dashboard/members.php" class="sidebar-link<?php echo nav_active('members.php', $current_file); ?>">
                <span class="sidebar-icon">&#9632;</span> Members
            </a>
            <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="sidebar-link<?php echo nav_active('events.php', $current_file); ?>">
                <span class="sidebar-icon">&#9632;</span> Events
            </a>
            <a href="<?php echo BASE_URL; ?>/dashboard/announcements.php" class="sidebar-link<?php echo nav_active('announcements.php', $current_file); ?>">
                <span class="sidebar-icon">&#9632;</span> Announcements
            </a>
            <a href="<?php echo BASE_URL; ?>/dashboard/attendance.php" class="sidebar-link<?php echo nav_active('attendance.php', $current_file); ?>">
                <span class="sidebar-icon">&#9632;</span> Attendance
            </a>
            <?php endif; ?>

            <?php if ($role === 'admin'): ?>
            <a href="<?php echo BASE_URL; ?>/dashboard/info.php" class="sidebar-link<?php echo nav_active('info.php', $current_file); ?>">
                <span class="sidebar-icon">&#9632;</span> About CSO Section
            </a>
            <?php endif; ?>

            <?php if ($role === 'member'): ?>
            <p class="sidebar-heading">My CSO</p>
            <a href="<?php echo BASE_URL; ?>/home.php#events" class="sidebar-link">
                <span class="sidebar-icon">&#9632;</span> Browse Events
            </a>
            <?php endif; ?>

            <p class="sidebar-heading">Account</p>
            <a href="<?php echo BASE_URL; ?>/dashboard/profile.php" class="sidebar-link<?php echo nav_active('profile.php', $current_file); ?>">
                <span class="sidebar-icon">&#9632;</span> My Profile
            </a>
            <a href="<?php echo BASE_URL; ?>/dashboard/feedback.php" class="sidebar-link<?php echo nav_active('feedback.php', $current_file); ?>">
                <span class="sidebar-icon">&#9632;</span> Feedback
            </a>
            <a href="<?php echo BASE_URL; ?>/logout.php" class="sidebar-link sidebar-link-danger">
                <span class="sidebar-icon">&#9632;</span> Log Out
            </a>
        </nav>
    </aside>
    <main class="app-main">
        <div class="app-main-inner">
            <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
            <?php endif; ?>
<?php else: ?>
    <?php if ($flash): ?>
        <div class="container" style="padding-top:20px;">
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
        </div>
    <?php endif; ?>
<?php endif; ?>
