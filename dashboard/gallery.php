<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer', 'moderator']);

$is_admin = $_SESSION['role'] === 'admin';
$uid = $_SESSION['user_id'];
$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

$es = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
$es->bind_param('i', $event_id);
$es->execute();
$event = $es->get_result()->fetch_assoc();

if (!$event) {
    header('Location: ' . BASE_URL . '/dashboard/events.php');
    exit;
}

$can_manage = $is_admin || (int) $event['created_by'] === (int) $uid;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload' && $can_manage) {
        $caption = clean($_POST['caption'] ?? '');
        $files = $_FILES['photos'] ?? null;
        $uploaded = 0;
        $errors = [];

        if ($files && isset($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                $single = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i],
                ];
                $upload = handle_image_upload_file($single, 'gallery');
                if (!$upload['ok']) {
                    $errors[] = $upload['error'];
                } elseif ($upload['path']) {
                    $stmt = $conn->prepare("INSERT INTO event_photos (event_id, image_path, caption, uploaded_by) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('issi', $event_id, $upload['path'], $caption, $uid);
                    $stmt->execute();
                    $uploaded++;
                }
            }
        }

        if ($uploaded > 0) {
            set_flash('success', $uploaded . ' photo(s) added to the gallery.');
        }
        if (!empty($errors)) {
            set_flash('error', implode(' ', array_unique($errors)));
        }
        if ($uploaded === 0 && empty($errors)) {
            set_flash('error', 'Please choose at least one image to upload.');
        }
    }

    if ($action === 'delete' && $can_manage) {
        $photo_id = (int) $_POST['photo_id'];
        $ps = $conn->prepare("SELECT image_path FROM event_photos WHERE photo_id = ? AND event_id = ?");
        $ps->bind_param('ii', $photo_id, $event_id);
        $ps->execute();
        $photo = $ps->get_result()->fetch_assoc();
        if ($photo) {
            delete_uploaded_image($photo['image_path']);
            $del = $conn->prepare("DELETE FROM event_photos WHERE photo_id = ?");
            $del->bind_param('i', $photo_id);
            $del->execute();
            set_flash('success', 'Photo removed.');
        }
    }

    header('Location: ' . BASE_URL . '/dashboard/gallery.php?event_id=' . $event_id);
    exit;
}

$photos = $conn->prepare("
    SELECT p.*, u.first_name, u.last_name
    FROM event_photos p LEFT JOIN users u ON u.user_id = p.uploaded_by
    WHERE p.event_id = ? ORDER BY p.uploaded_at DESC
");
$photos->bind_param('i', $event_id);
$photos->execute();
$photos_res = $photos->get_result();

$page_title = 'Event Photos';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?php echo htmlspecialchars($event['title']); ?> — Photos</h1>
        <p class="muted">Document the event, Facebook-album style — upload a batch, add an optional caption, done.</p>
    </div>
    <a href="<?php echo BASE_URL; ?>/dashboard/attendance.php?event_id=<?php echo $event_id; ?>" class="btn btn-outline btn-sm">Back to Attendance</a>
</div>

<?php if ($can_manage): ?>
<div class="panel">
    <div class="panel-head"><h2>Upload Photos</h2></div>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="upload">
        <div class="form-row">
            <label for="fld-1">Photos</label>
            <input id="fld-1" type="file" name="photos[]" accept="image/png,image/jpeg,image/webp,image/gif" multiple required>
            <p class="muted" style="font-size:0.78rem; margin:6px 0 0;">Select multiple files at once — JPG, PNG, WEBP, or GIF, up to 3MB each.</p>
        </div>
        <div class="form-row"><label for="fld-2">Caption (optional, applies to this batch)</label><input id="fld-2" type="text" name="caption" placeholder="e.g. General Assembly, September 2026"></div>
        <button type="submit" class="btn btn-primary">Upload</button>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2>Gallery (<?php echo $photos_res->num_rows; ?>)</h2></div>
    <?php if ($photos_res->num_rows > 0): ?>
        <div class="photo-grid">
            <?php while ($p = $photos_res->fetch_assoc()): ?>
                <div class="photo-grid-item photo-grid-item-managed">
                    <a href="<?php echo asset_v($p['image_path']); ?>" target="_blank">
                        <img src="<?php echo asset_v($p['image_path']); ?>" alt="<?php echo htmlspecialchars($p['caption'] ?? ''); ?>">
                    </a>
                    <?php if ($can_manage): ?>
                        <form method="POST" class="photo-delete-form" <?php echo confirm_attr('Delete this photo? This cannot be undone.'); ?>>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="photo_id" value="<?php echo $p['photo_id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm photo-delete-btn" aria-label="Delete photo"><svg class="icon" aria-hidden="true" style="width:14px;height:14px;"><use href="#i-close"/></svg></button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No photos yet</h3><p>Upload your first batch above.</p></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
