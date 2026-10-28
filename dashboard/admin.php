<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role('admin');

// Sign-up setting: turn "approve new members" on or off.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_member_approval') {
    csrf_check();
    $require = (($_POST['require_approval'] ?? '1') === '1') ? '1' : '0';
    if (set_app_setting($conn, 'require_member_approval', $require)) {
        set_flash('success', $require === '1'
            ? 'Approval is ON. New student sign-ups wait for an officer or admin to approve them.'
            : 'Approval is OFF. New student sign-ups can log in right away.');
    } else {
        set_flash('error', 'Could not save the setting. Please try again.');
    }
    header('Location: ' . BASE_URL . '/dashboard/admin.php#signup-settings');
    exit;
}
// Home page setting: moving (gliding) strips on or off.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_home_marquee') {
    csrf_check();
    $mq = (($_POST['home_marquee'] ?? '1') === '1') ? '1' : '0';
    if (set_app_setting($conn, 'home_marquee', $mq)) {
        set_flash('success', $mq === '1' ? 'Moving strips are ON. Events, officers and faculty glide sideways on the Home page.' : 'Moving strips are OFF. The Home page cards stay still.');
    } else {
        set_flash('error', 'Could not save the setting. Please try again.');
    }
    header('Location: ' . BASE_URL . '/dashboard/admin.php#signup-settings');
    exit;
}
$marquee_on = home_marquee_enabled($conn);
$approval_on   = member_approval_required($conn);
$pending_count = (int) $conn->query("SELECT COUNT(*) c FROM users WHERE approval_pending = 1")->fetch_assoc()['c'];

$total_members   = (int) $conn->query("SELECT COUNT(*) c FROM users WHERE role = 'member'")->fetch_assoc()['c'];
$total_officers  = (int) $conn->query("SELECT COUNT(*) c FROM users WHERE role = 'officer'")->fetch_assoc()['c'];
$active_users    = (int) $conn->query("SELECT COUNT(*) c FROM users WHERE status = 'active'")->fetch_assoc()['c'];
$upcoming_events = (int) $conn->query("SELECT COUNT(*) c FROM events WHERE event_date >= CURDATE()")->fetch_assoc()['c'];
$announcements_c = (int) $conn->query("SELECT COUNT(*) c FROM announcements")->fetch_assoc()['c'];
$feedback_c      = (int) $conn->query("SELECT COUNT(*) c FROM feedback")->fetch_assoc()['c'];

$next_events = $conn->query("SELECT event_id, title, event_date, event_time, venue FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 5");
$recent_members = $conn->query("SELECT first_name, middle_name, last_name, suffix, student_id, course, created_at FROM users WHERE role = 'member' ORDER BY created_at DESC LIMIT 5");

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

<div class="panel" id="signup-settings">
    <div class="panel-head">
        <h2>Site settings</h2>
    </div>
    <form method="POST" action="<?php echo BASE_URL; ?>/dashboard/admin.php" class="signup-setting">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="toggle_member_approval">
        <input type="hidden" name="require_approval" value="<?php echo $approval_on ? '0' : '1'; ?>">
        <button type="submit" class="setting-row" role="switch" aria-checked="<?php echo $approval_on ? 'true' : 'false'; ?>" aria-describedby="approval-help">
            <span>
                Require approval for new members
                <small id="approval-help"><?php echo $approval_on
                    ? 'ON: every new student sign-up waits for an officer or admin to approve it.'
                    : 'OFF: new student sign-ups can log in right away, with no approval step.'; ?></small>
            </span>
            <span class="switch" aria-hidden="true"></span>
        </button>
    </form>
    <form method="POST" action="<?php echo BASE_URL; ?>/dashboard/admin.php" class="signup-setting" style="margin-top:10px;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="toggle_home_marquee">
        <input type="hidden" name="home_marquee" value="<?php echo $marquee_on ? '0' : '1'; ?>">
        <button type="submit" class="setting-row" role="switch" aria-checked="<?php echo $marquee_on ? 'true' : 'false'; ?>" aria-describedby="marquee-help">
            <span>
                Moving strips on the Home page
                <small id="marquee-help"><?php echo $marquee_on
                    ? 'ON: events, officers and faculty & staff glide sideways in a loop.'
                    : 'OFF: the Home page cards stay still (no gliding or looping).'; ?></small>
            </span>
            <span class="switch" aria-hidden="true"></span>
        </button>
    </form>
    <p class="muted signup-setting-note">
        Students must still be on the member list (roster) when one is uploaded, and faculty accounts always need admin approval.
        <?php if ($pending_count > 0): ?>
            <a href="<?php echo BASE_URL; ?>/dashboard/members.php?status=pending"><?php echo $pending_count; ?> sign-up<?php echo $pending_count === 1 ? ' is' : 's are'; ?> still waiting</a>
            &mdash; turning approval off does not approve them automatically.
        <?php endif; ?>
    </p>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>Next Up</h2>
        <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="btn btn-outline btn-sm">Manage Events</a>
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
                <thead><tr><th scope="col">Name</th><th scope="col">Student ID</th><th scope="col">Program</th><th scope="col">Joined</th></tr></thead>
                <tbody>
                <?php while ($m = $recent_members->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars(full_name($m)); ?></td>
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
