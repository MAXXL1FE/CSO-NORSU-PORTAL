<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_login();

$uid = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT e.event_id, e.title, e.event_date, e.event_time, e.venue,
           a.status, a.checked_in_at, a.time_out
    FROM attendance a
    JOIN events e ON e.event_id = a.event_id
    WHERE a.user_id = ?
    ORDER BY e.event_date DESC, e.event_time DESC
");
$stmt->bind_param('i', $uid);
$stmt->execute();
$records = $stmt->get_result();

$total_attended = $records->num_rows;

$page_title = 'My Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Attendance</h1>
        <p class="muted">Every event you've checked in to.</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Events Attended</div>
        <div class="stat-value"><?php echo $total_attended; ?></div>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2>Attendance History</h2></div>

    <?php if ($total_attended > 0): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Time In</th>
                        <th>Time Out</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($r = $records->fetch_assoc()): ?>
                    <tr>
                        <td><a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $r['event_id']; ?>"><?php echo htmlspecialchars($r['title']); ?></a></td>
                        <td><?php echo date('M j, Y', strtotime($r['event_date'])); ?></td>
                        <td><?php echo htmlspecialchars($r['venue']); ?></td>
                        <td><?php echo date('g:i A', strtotime($r['checked_in_at'])); ?></td>
                        <td><?php echo $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '<span class="muted">—</span>'; ?></td>
                        <td>
                            <?php if ($r['status'] === 'present'): ?>
                                <span class="badge">Present</span>
                            <?php else: ?>
                                <span class="badge badge-outline">Absent</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <h3>No attendance recorded yet</h3>
            <p>Once you check in to an event, it'll show up here.</p>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
