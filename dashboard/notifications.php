<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_login();

// "Clear history" hides everything up to now for this user only. Other users still see it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_history') {
    csrf_check();
    clear_notification_history($conn);
    set_flash('success', 'Your notification history was cleared.');
    header('Location: ' . BASE_URL . '/dashboard/notifications.php');
    exit;
}

// Turn emails about new events and announcements on or off for this user.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_alerts') {
    csrf_check();
    $on = !empty($_POST['email_alerts']) ? 1 : 0;
    $stmt = $conn->prepare("UPDATE users SET email_alerts = ? WHERE user_id = ?");
    $me = (int) $_SESSION['user_id'];
    $stmt->bind_param('ii', $on, $me);
    $stmt->execute();
    set_flash('success', $on ? 'Email alerts are on.' : 'Email alerts are off.');
    header('Location: ' . BASE_URL . '/dashboard/notifications.php');
    exit;
}

// Opening this page marks everything as read — that clears the badge, the red dot
// and the reminder panel on the dashboard. $prev_seen lets us still flag what was new.
$prev_seen = notif_seen_at($conn);
mark_notifications_seen($conn);
$cleared_sql = $conn->real_escape_string(notif_cleared_at($conn));

$reminders = $conn->query("
    SELECT r.message, r.created_at, e.event_id, e.title AS event_title, e.event_date
    FROM attendance_reminders r
    LEFT JOIN events e ON e.event_id = r.event_id
    WHERE r.created_at > '$cleared_sql'
    ORDER BY r.created_at DESC
    LIMIT 20
");

$my_id = (int) $_SESSION['user_id'];
$notices = $conn->query("SELECT message, created_at FROM user_notices WHERE user_id = $my_id AND created_at > '$cleared_sql' ORDER BY created_at DESC LIMIT 20");

$announcements = $conn->query("
    SELECT announcement_id, title, content, deadline, image, created_at
    FROM announcements
    WHERE created_at > '$cleared_sql'
    ORDER BY created_at DESC
    LIMIT 10
");

$page_title = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/cards.php';
?>

<div class="page-head notif-head">
    <div>
        <h1>Notifications</h1>
        <p class="muted">Reminders and announcements from CSO.</p>
    </div>
    <?php if ($reminders->num_rows > 0 || $announcements->num_rows > 0 || ($notices && $notices->num_rows > 0)): ?>
    <form method="POST" <?php echo confirm_attr('Clear your notification history? This only clears it for you.'); ?>>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="clear_history">
        <button type="submit" class="btn btn-outline btn-sm">Clear history</button>
    </form>
    <?php endif; ?>
</div>

<?php
$ea = $conn->query("SELECT email_alerts FROM users WHERE user_id = " . (int) $_SESSION['user_id'])->fetch_assoc();
?>
<div class="panel notif-prefs">
    <form method="POST" class="notif-prefs-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="email_alerts">
        <label class="notif-prefs-label">
            <input type="checkbox" name="email_alerts" value="1" <?php echo !empty($ea['email_alerts']) ? 'checked' : ''; ?>>
            <span>Email me about new events, announcements and reminders</span>
        </label>
        <button type="submit" class="btn btn-outline btn-sm">Save</button>
    </form>
</div>

<?php if ($notices && $notices->num_rows > 0): ?>
<div class="panel notif-panel">
    <div class="panel-head"><h2>Your account</h2></div>
    <?php while ($nt = $notices->fetch_assoc()): ?>
        <div class="notif-item">
            <p class="notif-msg"><?php if ($nt['created_at'] > $prev_seen): ?><span class="chip notif-new">New</span><?php endif; ?><?php echo e($nt['message']); ?></p>
            <span class="notif-time"><?php echo date('M j, Y · g:i A', strtotime($nt['created_at'])); ?></span>
        </div>
    <?php endwhile; ?>
</div>
<?php endif; ?>

<div class="panel notif-panel">
    <div class="panel-head"><h2>Reminders</h2></div>
    <?php if ($reminders->num_rows > 0): ?>
        <?php while ($rm = $reminders->fetch_assoc()): ?>
            <div class="notif-item">
                <p class="notif-msg"><?php if ($rm['created_at'] > $prev_seen): ?><span class="chip notif-new">New</span><?php endif; ?><?php echo htmlspecialchars($rm['message']); ?></p>
                <?php if ($rm['event_title']): ?>
                    <a class="notif-link" href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $rm['event_id']; ?>"><?php echo htmlspecialchars($rm['event_title']); ?> &middot; <?php echo date('M j', strtotime($rm['event_date'])); ?></a>
                <?php endif; ?>
                <span class="notif-time"><?php echo date('M j, Y · g:i A', strtotime($rm['created_at'])); ?></span>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state notif-empty"><h3>No reminders right now</h3></div>
    <?php endif; ?>
</div>

<div class="panel notif-panel">
    <div class="panel-head"><h2>Announcements</h2></div>
    <?php if ($announcements->num_rows > 0): ?>
        <div class="card-grid notif-announcements">
            <?php while ($a = $announcements->fetch_assoc()) render_announcement_card($a); ?>
        </div>
    <?php else: ?>
        <div class="empty-state notif-empty"><h3>No announcements yet</h3></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
