<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_login();

$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$token    = isset($_GET['token']) ? clean($_GET['token']) : '';

$status  = 'error';   // error | success | already | wrong_day
$message = 'This check-in link is invalid.';
$event   = null;
$me      = null;

// Always load the scanning student's own info, so we can show it back to them
$user_id = $_SESSION['user_id'];
$me_stmt = $conn->prepare("SELECT student_id, first_name, last_name, course, year_level FROM users WHERE user_id = ?");
$me_stmt->bind_param('i', $user_id);
$me_stmt->execute();
$me = $me_stmt->get_result()->fetch_assoc();

if ($event_id > 0 && $token !== '') {
    $stmt = $conn->prepare("SELECT * FROM events WHERE event_id = ? AND qr_token = ?");
    $stmt->bind_param('is', $event_id, $token);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();

    if (!$event) {
        $message = 'This check-in link does not match any event. Ask an officer for the correct QR code.';
    } else {
        // Only allow check-in on the event's actual date — catches someone scanning
        // an old printed QR code or the wrong event's code by mistake.
        $today = date('Y-m-d');
        if ($event['event_date'] !== $today) {
            $status = 'wrong_day';
            $when = strtotime($event['event_date']) > strtotime($today) ? 'is coming up on' : 'was on';
            $message = "This event $when " . date('F j, Y', strtotime($event['event_date'])) . ", not today. Check-in only works on the event day.";
        } else {
            // Was this student already checked in? If so, treat this scan as
            // a time-out instead of rejecting it outright.
            $chk = $conn->prepare("SELECT status, time_out FROM attendance WHERE event_id = ? AND user_id = ?");
            $chk->bind_param('ii', $event_id, $user_id);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();

            if ($existing && $existing['status'] === 'present' && $existing['time_out']) {
                $status = 'already';
                $message = "You've already timed in and out for this event.";
            } elseif ($existing && $existing['status'] === 'present') {
                $out = $conn->prepare("UPDATE attendance SET time_out = NOW() WHERE event_id = ? AND user_id = ?");
                $out->bind_param('ii', $event_id, $user_id);
                $out->execute();

                $status = 'timed_out';
                $message = 'You are timed out — see you next time!';
            } else {
                $ins = $conn->prepare("
                    INSERT INTO attendance (event_id, user_id, status, recorded_by, checked_in_at)
                    VALUES (?, ?, 'present', ?, NOW())
                    ON DUPLICATE KEY UPDATE status = 'present', recorded_by = VALUES(recorded_by), checked_in_at = NOW()
                ");
                $ins->bind_param('iii', $event_id, $user_id, $user_id);
                $ins->execute();

                $status = 'success';
                $message = 'You are timed in!';
            }
        }
    }
}

$page_title = 'Event Check-In';
require_once __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container narrow" style="text-align:center;">
        <?php if ($status === 'success' || $status === 'already' || $status === 'timed_out'): ?>
            <div class="checkin-badge checkin-success">✓</div>
            <h2><?php echo htmlspecialchars($message); ?></h2>

            <div class="checkin-card">
                <div class="checkin-row">
                    <span class="checkin-label">Student</span>
                    <span class="checkin-value"><?php echo htmlspecialchars($me['first_name'] . ' ' . $me['last_name']); ?></span>
                </div>
                <div class="checkin-row">
                    <span class="checkin-label">Student ID</span>
                    <span class="checkin-value"><?php echo htmlspecialchars($me['student_id']); ?></span>
                </div>
                <div class="checkin-row">
                    <span class="checkin-label">Course / Year</span>
                    <span class="checkin-value"><?php echo htmlspecialchars($me['course'] . ' — ' . $me['year_level']); ?></span>
                </div>
                <hr>
                <div class="checkin-row">
                    <span class="checkin-label">Event</span>
                    <span class="checkin-value"><?php echo htmlspecialchars($event['title']); ?></span>
                </div>
                <div class="checkin-row">
                    <span class="checkin-label">Date &amp; Time</span>
                    <span class="checkin-value"><?php echo date('F j, Y g:i A', strtotime($event['event_date'] . ' ' . $event['event_time'])); ?></span>
                </div>
                <div class="checkin-row">
                    <span class="checkin-label">Venue</span>
                    <span class="checkin-value"><?php echo htmlspecialchars($event['venue']); ?></span>
                </div>
                <div class="checkin-row">
                    <span class="checkin-label"><?php echo $status === 'timed_out' ? 'Timed out at' : 'Timed in at'; ?></span>
                    <span class="checkin-value"><?php echo date('g:i A'); ?></span>
                </div>
            </div>
        <?php elseif ($status === 'wrong_day'): ?>
            <div class="checkin-badge checkin-error">!</div>
            <h2>Wrong Day</h2>
            <p><?php echo htmlspecialchars($message); ?></p>
            <p style="color:var(--muted); font-size:0.9rem;"><?php echo htmlspecialchars($event['title']); ?> — <?php echo htmlspecialchars($event['venue']); ?></p>
        <?php else: ?>
            <div class="checkin-badge checkin-error">✕</div>
            <h2>Check-In Failed</h2>
            <p><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <a href="<?php echo BASE_URL; ?>/dashboard/member.php" class="btn btn-primary" style="margin-top:20px;">Back to Dashboard</a>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
