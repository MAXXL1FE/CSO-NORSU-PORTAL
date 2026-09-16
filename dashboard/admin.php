<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role('admin');

$total_members   = (int) $conn->query("SELECT COUNT(*) c FROM users WHERE role = 'member'")->fetch_assoc()['c'];
$total_officers  = (int) $conn->query("SELECT COUNT(*) c FROM users WHERE role = 'officer'")->fetch_assoc()['c'];
$active_users    = (int) $conn->query("SELECT COUNT(*) c FROM users WHERE status = 'active'")->fetch_assoc()['c'];
$upcoming_events = (int) $conn->query("SELECT COUNT(*) c FROM events WHERE event_date >= CURDATE()")->fetch_assoc()['c'];
$announcements_c = (int) $conn->query("SELECT COUNT(*) c FROM announcements")->fetch_assoc()['c'];
$feedback_c      = (int) $conn->query("SELECT COUNT(*) c FROM feedback")->fetch_assoc()['c'];

$next_events = $conn->query("SELECT event_id, title, event_date, event_time, venue FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");
$recent_members = $conn->query("SELECT first_name, last_name, student_id, course, created_at FROM users WHERE role = 'member' ORDER BY created_at DESC LIMIT 5");

$page_title = 'Admin Dashboard';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['first_name']); ?></h1>
        <p class="muted">Here's what's happening across CSO right now.</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Members</div>
        <div class="stat-value"><?php echo $total_members; ?></div>
        <div class="stat-sub"><?php echo $active_users; ?> active accounts total</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Officers</div>
        <div class="stat-value"><?php echo $total_officers; ?></div>
        <div class="stat-sub">Managing events &amp; announcements</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Upcoming Events</div>
        <div class="stat-value"><?php echo $upcoming_events; ?></div>
        <div class="stat-sub">Scheduled from today onward</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Announcements</div>
        <div class="stat-value"><?php echo $announcements_c; ?></div>
        <div class="stat-sub"><?php echo $feedback_c; ?> feedback entries received</div>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Next Up</h2>
        <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="btn btn-outline btn-sm">Manage Events</a>
    </div>
    <?php if ($next_events->num_rows > 0): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Event</th><th>Date</th><th>Venue</th></tr></thead>
                <tbody>
                <?php while ($e = $next_events->fetch_assoc()): ?>
                    <tr>
                        <td><a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $e['event_id']; ?>"><?php echo htmlspecialchars($e['title']); ?></a></td>
                        <td><?php echo date('M j, Y · g:i A', strtotime($e['event_date'] . ' ' . $e['event_time'])); ?></td>
                        <td><?php echo htmlspecialchars($e['venue']); ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No upcoming events</h3><p>Create one from the Events page.</p></div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Newest Members</h2>
        <a href="<?php echo BASE_URL; ?>/dashboard/members.php" class="btn btn-outline btn-sm">Manage Members</a>
    </div>
    <?php if ($recent_members->num_rows > 0): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Student ID</th><th>Course</th><th>Joined</th></tr></thead>
                <tbody>
                <?php while ($m = $recent_members->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($m['student_id']); ?></td>
                        <td><?php echo htmlspecialchars($m['course']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($m['created_at'])); ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No members yet</h3></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
