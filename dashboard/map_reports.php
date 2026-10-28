<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/features.php';
require_role(['admin', 'officer', 'moderator']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int) ($_POST['report_id'] ?? 0);
    $me = (int) $_SESSION['user_id'];
    $action = $_POST['action'] ?? '';
    if ($action === 'resolve') {
        $r = $conn->query("SELECT r.user_id, r.place_text FROM map_reports r WHERE r.report_id = $id AND r.status = 'open'")->fetch_assoc();
        if ($r) {
            $conn->query("UPDATE map_reports SET status = 'resolved', resolved_by = $me WHERE report_id = $id");
            add_notice($conn, $r['user_id'], 'Thanks for your map report' . ($r['place_text'] ? ' about ' . $r['place_text'] : '') . ': it was reviewed.');
            set_flash('success', 'Marked as done. The student was notified.');
        }
    } elseif ($action === 'reopen') {
        $conn->query("UPDATE map_reports SET status = 'open', resolved_by = NULL WHERE report_id = $id");
        set_flash('success', 'Reopened.');
    } elseif ($action === 'delete' && $_SESSION['role'] === 'admin') {
        $conn->query("DELETE FROM map_reports WHERE report_id = $id");
        set_flash('success', 'Report deleted.');
    }
    header('Location: ' . BASE_URL . '/dashboard/map_reports.php' . (($_GET['show'] ?? '') === 'resolved' ? '?show=resolved' : ''));
    exit;
}

$show = ($_GET['show'] ?? 'open') === 'resolved' ? 'resolved' : 'open';
$stmt = $conn->prepare("SELECT r.*, u.first_name, u.middle_name, u.last_name, u.suffix, u.student_id
                        FROM map_reports r JOIN users u ON u.user_id = r.user_id
                        WHERE r.status = ? ORDER BY r.created_at DESC LIMIT 200");
$stmt->bind_param('s', $show);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$open_n = (int) $conn->query("SELECT COUNT(*) c FROM map_reports WHERE status = 'open'")->fetch_assoc()['c'];

$page_title = 'Map Reports';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Map Reports</h1>
        <p class="muted">Problems students reported on the campus map. Fix the place in Manage Map, then mark the report as done.</p>
    </div>
    <a class="btn btn-outline btn-sm" href="<?php echo BASE_URL; ?>/dashboard/map.php">Manage Map</a>
</div>

<div class="tabs" style="margin-bottom:14px;">
    <a class="tab<?php echo $show === 'open' ? ' active' : ''; ?>" href="?show=open">Open (<?php echo $open_n; ?>)</a>
    <a class="tab<?php echo $show === 'resolved' ? ' active' : ''; ?>" href="?show=resolved">Done</a>
</div>

<?php if ($rows): foreach ($rows as $r): ?>
<div class="panel">
    <div class="panel-head">
        <h2 style="font-size:1.05rem;"><?php echo $r['place_text'] ? e($r['place_text']) : 'Somewhere on the map'; ?></h2>
        <span class="muted" style="font-size:.82rem;"><?php echo e(date('M j, Y g:i A', strtotime($r['created_at']))); ?></span>
    </div>
    <p style="margin:0 0 8px;"><?php echo nl2br(e($r['message'])); ?></p>
    <p class="muted" style="font-size:.85rem; margin:0 0 10px;">From <?php echo e(full_name($r)); ?> (<?php echo e($r['student_id']); ?>)</p>
    <form method="POST" style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="report_id" value="<?php echo (int) $r['report_id']; ?>">
        <?php if ($r['status'] === 'open'): ?>
            <button type="submit" name="action" value="resolve" class="btn btn-primary btn-sm">Mark as done</button>
        <?php else: ?>
            <button type="submit" name="action" value="reopen" class="btn btn-outline btn-sm">Reopen</button>
        <?php endif; ?>
        <?php if ($_SESSION['role'] === 'admin'): ?><button type="submit" name="action" value="delete" class="btn btn-outline btn-sm" onclick="return confirm('Delete this report?')">Delete</button><?php endif; ?>
    </form>
</div>
<?php endforeach; else: ?>
<div class="empty-state"><svg class="icon empty-icon" aria-hidden="true"><use href="#i-map"/></svg><h3><?php echo $show === 'open' ? 'No open reports' : 'Nothing marked done yet'; ?></h3><p>Reports from students will show up here.</p></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
