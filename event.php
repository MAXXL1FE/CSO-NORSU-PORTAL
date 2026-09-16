<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$event_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$message = '';

// Handle register / unregister (members only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && is_logged_in()) {
    csrf_check();
    $user_id = $_SESSION['user_id'];
    if ($_POST['action'] === 'register') {
        $stmt = $conn->prepare("INSERT IGNORE INTO event_registrations (event_id, user_id) VALUES (?, ?)");
        $stmt->bind_param('ii', $event_id, $user_id);
        $stmt->execute();
        $message = "You're registered for this event.";
    } elseif ($_POST['action'] === 'unregister') {
        $stmt = $conn->prepare("DELETE FROM event_registrations WHERE event_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $event_id, $user_id);
        $stmt->execute();
        $message = 'Registration cancelled.';
    }
}

$stmt = $conn->prepare("
    SELECT e.*, u.first_name AS organizer_first, u.last_name AS organizer_last,
           (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id) AS registration_count
    FROM events e
    LEFT JOIN users u ON u.user_id = e.created_by
    WHERE e.event_id = ?
");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();

$is_registered = false;
if ($event && is_logged_in()) {
    $chk = $conn->prepare("SELECT registration_id FROM event_registrations WHERE event_id = ? AND user_id = ?");
    $chk->bind_param('ii', $event_id, $_SESSION['user_id']);
    $chk->execute();
    $is_registered = $chk->get_result()->num_rows > 0;
}

$page_title = $event ? $event['title'] : 'Event Not Found';
require_once __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container narrow">
        <?php if (!$event): ?>
            <h2>Event Not Found</h2>
            <p>This event may have been removed.</p>
            <a href="<?php echo BASE_URL; ?>/home.php" class="btn btn-outline">Back to Home</a>
        <?php else: ?>
            <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

            <?php if (!empty($event['cover_image'])): ?>
                <img src="<?php echo asset_v($event['cover_image']); ?>" alt="" class="event-cover">
            <?php endif; ?>

            <h2><?php echo htmlspecialchars($event['title']); ?></h2>

            <table class="detail-table">
                <tr><th>Date</th><td><?php echo date('F j, Y', strtotime($event['event_date'])); ?></td></tr>
                <tr><th>Time</th><td><?php echo date('g:i A', strtotime($event['event_time'])); ?></td></tr>
                <tr><th>Venue</th><td><?php echo htmlspecialchars($event['venue']); ?></td></tr>
                <tr><th>Organized by</th><td><?php echo htmlspecialchars(trim($event['organizer_first'] . ' ' . $event['organizer_last'])) ?: 'CSO'; ?></td></tr>
                <tr><th>Registered</th><td><?php echo (int) $event['registration_count']; ?> member(s)</td></tr>
            </table>

            <?php if (!empty($event['description'])): ?>
                <h3 style="margin-top:24px;">About this event</h3>
                <p><?php echo nl2br(htmlspecialchars($event['description'])); ?></p>
            <?php endif; ?>

            <?php
            $photos = $conn->prepare("SELECT image_path, caption FROM event_photos WHERE event_id = ? ORDER BY uploaded_at DESC");
            $photos->bind_param('i', $event_id);
            $photos->execute();
            $photos_res = $photos->get_result();
            ?>
            <?php if ($photos_res->num_rows > 0): ?>
                <h3 style="margin-top:24px;">Event Photos</h3>
                <div class="photo-grid">
                    <?php while ($p = $photos_res->fetch_assoc()): ?>
                        <a href="<?php echo asset_v($p['image_path']); ?>" target="_blank" class="photo-grid-item">
                            <img src="<?php echo asset_v($p['image_path']); ?>" alt="<?php echo htmlspecialchars($p['caption'] ?? ''); ?>">
                        </a>
                    <?php endwhile; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top:24px;">
                <?php if (!is_logged_in()): ?>
                    <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary">Log in to Register</a>
                <?php elseif ($_SESSION['role'] === 'member'): ?>
                    <form method="POST">
                <?php echo csrf_field(); ?>
                        <input type="hidden" name="event_id" value="<?php echo $event['event_id']; ?>">
                        <?php if ($is_registered): ?>
                            <input type="hidden" name="action" value="unregister">
                            <button type="submit" class="btn btn-outline">Cancel My Registration</button>
                        <?php else: ?>
                            <input type="hidden" name="action" value="register">
                            <button type="submit" class="btn btn-primary">Register for this Event</button>
                        <?php endif; ?>
                    </form>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="btn btn-outline">Manage this Event</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
