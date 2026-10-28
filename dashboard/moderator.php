<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role('moderator');

$uid = $_SESSION['user_id'];

$total_members   = (int) $conn->query("SELECT COUNT(*) c FROM users WHERE role = 'member'")->fetch_assoc()['c'];
$upcoming_events = (int) $conn->query("SELECT COUNT(*) c FROM events WHERE event_date >= CURDATE()")->fetch_assoc()['c'];

$my_events_stmt = $conn->prepare("SELECT COUNT(*) c FROM events WHERE created_by = ?");
$my_events_stmt->bind_param('i', $uid);
$my_events_stmt->execute();
$my_events_count = (int) $my_events_stmt->get_result()->fetch_assoc()['c'];

$my_ann_stmt = $conn->prepare("SELECT COUNT(*) c FROM announcements WHERE created_by = ?");
$my_ann_stmt->bind_param('i', $uid);
$my_ann_stmt->execute();
$my_ann_count = (int) $my_ann_stmt->get_result()->fetch_assoc()['c'];

$next_events = $conn->query("SELECT event_id, title, event_date, event_time, venue FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");

$seen_sql = $conn->real_escape_string(notif_seen_at($conn));   // only unread reminders
$reminders = $conn->query("
    SELECT r.message, r.created_at, e.title AS event_title, e.event_date
    FROM attendance_reminders r
    LEFT JOIN events e ON e.event_id = r.event_id
    WHERE (r.event_id IS NULL OR e.event_date >= CURDATE()) AND r.created_at > '$seen_sql'
    ORDER BY r.created_at DESC
    LIMIT 5
");

$page_title = 'Moderator Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name']); ?></h1>
        <p class="muted">You can manage events, attendance, and announcements. Account management (roles, removing accounts) is handled by admins.</p>
    </div>
</div>

<?php if ($reminders->num_rows > 0): ?>
<div class="panel">
    <div class="panel-head"><h2>Attendance Reminders</h2></div>
    <?php while ($rm = $reminders->fetch_assoc()): ?>
        <div class="alert alert-success" style="text-align:left; margin-bottom:8px;">
            <?php echo htmlspecialchars($rm['message']); ?>
            <?php if ($rm['event_title']): ?>
                <span class="muted"> — <?php echo htmlspecialchars($rm['event_title']); ?> (<?php echo e(fmt_date($rm['event_date'], 'M j')); ?>)</span>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Total Members</div>
        <div class="stat-value"><?php echo $total_members; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Upcoming Events</div>
        <div class="stat-value"><?php echo $upcoming_events; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Events You Created</div>
        <div class="stat-value"><?php echo $my_events_count; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Your Announcements</div>
        <div class="stat-value"><?php echo $my_ann_count; ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Org-Wide Upcoming Events</h2>
        <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="btn btn-outline btn-sm">Go to Events</a>
    </div>
    <?php if ($next_events->num_rows > 0): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th scope="col">Event</th><th scope="col">Date</th><th scope="col">Venue</th></tr></thead>
                <tbody>
                <?php while ($e = $next_events->fetch_assoc()): ?>
                    <tr>
                        <td><a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $e['event_id']; ?>"><?php echo htmlspecialchars($e['title']); ?></a></td>
                        <td><?php echo e(fmt_when($e['event_date'], $e['event_time'])); ?></td>
                        <td><?php echo htmlspecialchars($e['venue']); ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No upcoming events</h3></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
