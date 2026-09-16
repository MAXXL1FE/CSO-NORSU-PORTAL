<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer']);

$is_admin = $_SESSION['role'] === 'admin';
$uid = $_SESSION['user_id'];

function can_manage_event($event, $is_admin, $uid) {
    return $is_admin || (int) $event['created_by'] === (int) $uid;
}

// ---------------------------------------------------------
// Handle create / update / delete
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title       = clean($_POST['title']);
        $description = clean($_POST['description']);
        $event_date  = clean($_POST['event_date']);
        $event_time  = clean($_POST['event_time']);
        $venue       = clean($_POST['venue']);
        $qr_token    = bin2hex(random_bytes(16));

        if (empty($title) || empty($event_date) || empty($event_time) || empty($venue)) {
            set_flash('error', 'Please fill in title, date, time, and venue.');
        } else {
            $upload = handle_image_upload('cover_image', 'events');
            if (!$upload['ok']) {
                set_flash('error', $upload['error']);
            } else {
                $cover = $upload['path'];
                $stmt = $conn->prepare("INSERT INTO events (title, description, event_date, event_time, venue, cover_image, qr_token, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssssi', $title, $description, $event_date, $event_time, $venue, $cover, $qr_token, $uid);
                $stmt->execute();
                set_flash('success', 'Event created.');
            }
        }
    }

    if ($action === 'update') {
        $event_id = (int) $_POST['event_id'];
        $chk = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
        $chk->bind_param('i', $event_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if (!$existing) {
            set_flash('error', 'Event not found.');
        } elseif (!can_manage_event($existing, $is_admin, $uid)) {
            header('Location: ' . BASE_URL . '/unauthorized.php');
            exit;
        } else {
            $title       = clean($_POST['title']);
            $description = clean($_POST['description']);
            $event_date  = clean($_POST['event_date']);
            $event_time  = clean($_POST['event_time']);
            $venue       = clean($_POST['venue']);

            $upload = handle_image_upload('cover_image', 'events');
            if (!$upload['ok']) {
                set_flash('error', $upload['error']);
            } else {
                if ($upload['path']) {
                    if (!empty($existing['cover_image'])) delete_uploaded_image($existing['cover_image']);
                    $stmt = $conn->prepare("UPDATE events SET title=?, description=?, event_date=?, event_time=?, venue=?, cover_image=? WHERE event_id=?");
                    $stmt->bind_param('ssssssi', $title, $description, $event_date, $event_time, $venue, $upload['path'], $event_id);
                } else {
                    $stmt = $conn->prepare("UPDATE events SET title=?, description=?, event_date=?, event_time=?, venue=? WHERE event_id=?");
                    $stmt->bind_param('sssssi', $title, $description, $event_date, $event_time, $venue, $event_id);
                }
                $stmt->execute();
                set_flash('success', 'Event updated.');
            }
        }
    }

    if ($action === 'delete') {
        $event_id = (int) $_POST['event_id'];
        $chk = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
        $chk->bind_param('i', $event_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if ($existing && can_manage_event($existing, $is_admin, $uid)) {
            if (!empty($existing['cover_image'])) delete_uploaded_image($existing['cover_image']);
            $gp = $conn->prepare("SELECT image_path FROM event_photos WHERE event_id = ?");
            $gp->bind_param('i', $event_id);
            $gp->execute();
            $gp_res = $gp->get_result();
            while ($g = $gp_res->fetch_assoc()) delete_uploaded_image($g['image_path']);

            $stmt = $conn->prepare("DELETE FROM events WHERE event_id = ?");
            $stmt->bind_param('i', $event_id);
            $stmt->execute();
            set_flash('success', 'Event deleted.');
        } elseif ($existing) {
            header('Location: ' . BASE_URL . '/unauthorized.php');
            exit;
        }
    }

    header('Location: ' . BASE_URL . '/dashboard/events.php');
    exit;
}

// ---------------------------------------------------------
// List + single-event views
// ---------------------------------------------------------
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$qr_id   = isset($_GET['qr']) ? (int) $_GET['qr'] : 0;

$events = $conn->query("
    SELECT e.*, u.first_name, u.last_name,
           (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id) AS reg_count,
           (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.event_id AND a.status = 'present') AS present_count
    FROM events e
    LEFT JOIN users u ON u.user_id = e.created_by
    ORDER BY e.event_date DESC
");

$edit_row = null;
if ($edit_id > 0) {
    $es = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
    $es->bind_param('i', $edit_id);
    $es->execute();
    $edit_row = $es->get_result()->fetch_assoc();
    if ($edit_row && !can_manage_event($edit_row, $is_admin, $uid)) {
        header('Location: ' . BASE_URL . '/unauthorized.php');
        exit;
    }
}

$qr_row = null;
if ($qr_id > 0) {
    $qs = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
    $qs->bind_param('i', $qr_id);
    $qs->execute();
    $qr_row = $qs->get_result()->fetch_assoc();
}

$page_title = 'Events';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Events</h1>
        <p class="muted">Create events, generate check-in QR codes, and manage the ones you're responsible for.</p>
    </div>
</div>

<?php if ($qr_row):
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $checkin_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . '/checkin.php?event_id=' . $qr_row['event_id'] . '&token=' . $qr_row['qr_token'];
    $qr_img = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($checkin_url);
?>
<div class="panel" style="text-align:center; max-width:420px; margin-left:auto; margin-right:auto;">
    <h2><?php echo htmlspecialchars($qr_row['title']); ?> — Check-In QR</h2>
    <p class="muted">Members scan this while logged in on <?php echo date('F j, Y', strtotime($qr_row['event_date'])); ?> to mark themselves present.</p>
    <img src="<?php echo htmlspecialchars($qr_img); ?>" alt="Check-in QR code" style="margin:16px auto; border:1px solid var(--line); border-radius:var(--radius); padding:12px; background:#fff;">
    <p class="muted" style="font-size:0.78rem; word-break:break-all;"><?php echo htmlspecialchars($checkin_url); ?></p>
    <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="btn btn-outline btn-sm">Close</a>
</div>
<?php endif; ?>

<?php if ($edit_row): ?>
<div class="edit-panel">
    <h3>Edit Event</h3>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="event_id" value="<?php echo $edit_row['event_id']; ?>">
        <div class="form-row"><label>Title</label><input type="text" name="title" value="<?php echo htmlspecialchars($edit_row['title']); ?>" required></div>
        <div class="form-row"><label>Description</label><textarea name="description"><?php echo htmlspecialchars($edit_row['description']); ?></textarea></div>
        <div class="form-row form-row-split">
            <div><label>Date</label><input type="date" name="event_date" value="<?php echo htmlspecialchars($edit_row['event_date']); ?>" required></div>
            <div><label>Time</label><input type="time" name="event_time" value="<?php echo htmlspecialchars(substr($edit_row['event_time'], 0, 5)); ?>" required></div>
        </div>
        <div class="form-row"><label>Venue</label><input type="text" name="venue" value="<?php echo htmlspecialchars($edit_row['venue']); ?>" required></div>
        <div class="form-row">
            <label>Cover Image</label>
            <?php if (!empty($edit_row['cover_image'])): ?>
                <img src="<?php echo asset_v($edit_row['cover_image']); ?>" alt="" style="max-width:220px; border-radius:8px; margin-bottom:8px; display:block;">
            <?php endif; ?>
            <input type="file" name="cover_image" accept="image/png,image/jpeg,image/webp,image/gif">
            <p class="muted" style="font-size:0.78rem; margin:6px 0 0;">Leave blank to keep the current image.</p>
        </div>
        <div class="table-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2>Create Event</h2></div>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row"><label>Title</label><input type="text" name="title" required></div>
        <div class="form-row"><label>Description</label><textarea name="description" placeholder="Optional details about the event"></textarea></div>
        <div class="form-row form-row-split">
            <div><label>Date</label><input type="date" name="event_date" required></div>
            <div><label>Time</label><input type="time" name="event_time" required></div>
        </div>
        <div class="form-row"><label>Venue</label><input type="text" name="venue" required></div>
        <div class="form-row">
            <label>Cover Image (optional)</label>
            <input type="file" name="cover_image" accept="image/png,image/jpeg,image/webp,image/gif">
        </div>
        <button type="submit" class="btn btn-primary">Create Event</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head"><h2>All Events</h2></div>
    <?php if ($events->num_rows > 0): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th></th><th>Event</th><th>Date</th><th>Venue</th><th>Registered</th><th>Present</th><th>Organizer</th><th></th></tr></thead>
                <tbody>
                <?php while ($e = $events->fetch_assoc()): $mine = can_manage_event($e, $is_admin, $uid); ?>
                    <tr>
                        <td>
                            <?php if (!empty($e['cover_image'])): ?>
                                <img src="<?php echo asset_v($e['cover_image']); ?>" alt="" style="width:40px; height:40px; border-radius:6px; object-fit:cover;">
                            <?php else: ?>
                                <span style="display:inline-flex; width:40px; height:40px; border-radius:6px; background:var(--line-soft);"></span>
                            <?php endif; ?>
                        </td>
                        <td><a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $e['event_id']; ?>"><?php echo htmlspecialchars($e['title']); ?></a></td>
                        <td><?php echo date('M j, Y', strtotime($e['event_date'])); ?></td>
                        <td><?php echo htmlspecialchars($e['venue']); ?></td>
                        <td><?php echo (int) $e['reg_count']; ?></td>
                        <td><?php echo (int) $e['present_count']; ?></td>
                        <td><?php echo htmlspecialchars(trim($e['first_name'] . ' ' . $e['last_name'])) ?: '—'; ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="<?php echo BASE_URL; ?>/dashboard/events.php?qr=<?php echo $e['event_id']; ?>" class="btn btn-outline btn-sm">QR</a>
                                <a href="<?php echo BASE_URL; ?>/dashboard/attendance.php?event_id=<?php echo $e['event_id']; ?>" class="btn btn-outline btn-sm">Attendance</a>
                                <a href="<?php echo BASE_URL; ?>/dashboard/gallery.php?event_id=<?php echo $e['event_id']; ?>" class="btn btn-outline btn-sm">Photos</a>
                                <?php if ($mine): ?>
                                    <a href="<?php echo BASE_URL; ?>/dashboard/events.php?edit=<?php echo $e['event_id']; ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form method="POST" onsubmit="return confirm('Delete this event? Registrations and attendance records go with it.');">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="event_id" value="<?php echo $e['event_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No events yet</h3><p>Create your first event above.</p></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
