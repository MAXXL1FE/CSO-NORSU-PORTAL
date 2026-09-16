<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role('member');

$uid = $_SESSION['user_id'];

$upcoming = $conn->prepare("
    SELECT e.event_id, e.title, e.event_date, e.event_time, e.venue, e.cover_image,
           (r.registration_id IS NOT NULL) AS is_registered,
           (a.attendance_id IS NOT NULL) AS is_checked_in
    FROM events e
    LEFT JOIN event_registrations r ON r.event_id = e.event_id AND r.user_id = ?
    LEFT JOIN attendance a ON a.event_id = e.event_id AND a.user_id = ? AND a.status = 'present'
    WHERE e.event_date >= CURDATE()
    ORDER BY e.event_date ASC
    LIMIT 6
");
$upcoming->bind_param('ii', $uid, $uid);
$upcoming->execute();
$upcoming_res = $upcoming->get_result();

$attended_stmt = $conn->prepare("SELECT COUNT(*) c FROM attendance WHERE user_id = ? AND status = 'present'");
$attended_stmt->bind_param('i', $uid);
$attended_stmt->execute();
$attended_count = (int) $attended_stmt->get_result()->fetch_assoc()['c'];

$registered_stmt = $conn->prepare("SELECT COUNT(*) c FROM event_registrations WHERE user_id = ?");
$registered_stmt->bind_param('i', $uid);
$registered_stmt->execute();
$registered_count = (int) $registered_stmt->get_result()->fetch_assoc()['c'];

$announcements = $conn->query("SELECT title, content, image, created_at FROM announcements ORDER BY created_at DESC LIMIT 3");

$reminders = $conn->query("
    SELECT r.message, r.created_at, e.title AS event_title, e.event_date
    FROM attendance_reminders r
    LEFT JOIN events e ON e.event_id = r.event_id
    WHERE r.event_id IS NULL OR e.event_date >= CURDATE()
    ORDER BY r.created_at DESC
    LIMIT 5
");

$page_title = 'My Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Hi, <?php echo htmlspecialchars($_SESSION['first_name']); ?> 👋</h1>
        <p class="muted">Here's what's coming up for you in CSO.</p>
    </div>
</div>

<?php if ($reminders->num_rows > 0): ?>
<div class="panel">
    <div class="panel-head"><h2>🔔 Attendance Reminders</h2></div>
    <?php while ($rm = $reminders->fetch_assoc()): ?>
        <div class="alert alert-success" style="text-align:left; margin-bottom:8px;">
            <?php echo htmlspecialchars($rm['message']); ?>
            <?php if ($rm['event_title']): ?>
                <span class="muted"> — <?php echo htmlspecialchars($rm['event_title']); ?> (<?php echo date('M j', strtotime($rm['event_date'])); ?>)</span>
            <?php endif; ?>
        </div>
    <?php endwhile; ?>
</div>
<?php endif; ?>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Events Registered</div>
        <div class="stat-value"><?php echo $registered_count; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Events Attended</div>
        <div class="stat-value"><?php echo $attended_count; ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Upcoming Events</h2>
        <a href="<?php echo BASE_URL; ?>/home.php#events" class="btn btn-outline btn-sm">Browse All</a>
    </div>
    <?php if ($upcoming_res->num_rows > 0): ?>
        <div class="card-grid">
            <?php while ($e = $upcoming_res->fetch_assoc()): ?>
                <a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $e['event_id']; ?>" class="card card-link post-card">
                    <?php if (!empty($e['cover_image'])): ?>
                        <img src="<?php echo asset_v($e['cover_image']); ?>" alt="" class="post-card-img">
                    <?php endif; ?>
                    <h3><?php echo htmlspecialchars($e['title']); ?></h3>
                    <p><?php echo date('F j, Y', strtotime($e['event_date'])); ?> · <?php echo date('g:i A', strtotime($e['event_time'])); ?></p>
                    <p><?php echo htmlspecialchars($e['venue']); ?></p>
                    <?php if ($e['is_checked_in']): ?>
                        <span class="badge">Checked in</span>
                    <?php elseif ($e['is_registered']): ?>
                        <span class="badge badge-outline">Registered</span>
                    <?php endif; ?>
                </a>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No upcoming events right now</h3><p>Check back soon, or browse announcements below.</p></div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head"><h2>Latest Announcements</h2></div>
    <?php if ($announcements->num_rows > 0): ?>
        <div class="card-grid">
            <?php while ($a = $announcements->fetch_assoc()): ?>
                <div class="card post-card">
                    <?php if (!empty($a['image'])): ?>
                        <img src="<?php echo asset_v($a['image']); ?>" alt="" class="post-card-img">
                    <?php endif; ?>
                    <h3><?php echo htmlspecialchars($a['title']); ?></h3>
                    <p><?php echo htmlspecialchars(mb_substr($a['content'], 0, 130)); ?><?php echo mb_strlen($a['content']) > 130 ? '…' : ''; ?></p>
                    <p class="muted" style="font-size:0.78rem;"><?php echo date('M j, Y', strtotime($a['created_at'])); ?></p>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No announcements yet</h3></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
