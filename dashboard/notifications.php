<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_login();

$reminders = $conn->query("
    SELECT r.message, r.created_at, e.event_id, e.title AS event_title, e.event_date
    FROM attendance_reminders r
    LEFT JOIN events e ON e.event_id = r.event_id
    ORDER BY r.created_at DESC
    LIMIT 20
");

$announcements = $conn->query("
    SELECT title, content, created_at
    FROM announcements
    ORDER BY created_at DESC
    LIMIT 10
");

$page_title = 'Notifications';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Notifications</h1>
        <p class="muted">Reminders and announcements from CSO.</p>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2>🔔 Reminders</h2></div>
    <?php if ($reminders->num_rows > 0): ?>
        <?php while ($rm = $reminders->fetch_assoc()): ?>
            <div class="alert alert-success" style="text-align:left; margin-bottom:8px;">
                <?php echo htmlspecialchars($rm['message']); ?>
                <?php if ($rm['event_title']): ?>
                    <span class="muted"> — <a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $rm['event_id']; ?>"><?php echo htmlspecialchars($rm['event_title']); ?></a> (<?php echo date('M j', strtotime($rm['event_date'])); ?>)</span>
                <?php endif; ?>
                <span class="muted" style="display:block; font-size:0.75rem; margin-top:4px;"><?php echo date('M j, Y · g:i A', strtotime($rm['created_at'])); ?></span>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="empty-state"><h3>No reminders right now</h3></div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head"><h2>📣 Announcements</h2></div>
    <?php if ($announcements->num_rows > 0): ?>
        <div class="card-grid">
            <?php while ($a = $announcements->fetch_assoc()): ?>
                <div class="card post-card">
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
