<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer']);

$is_admin = $_SESSION['role'] === 'admin';
$uid = $_SESSION['user_id'];

function can_manage_ann($ann, $is_admin, $uid) {
    return $is_admin || (int) $ann['created_by'] === (int) $uid;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = clean($_POST['title']);
        $content = clean($_POST['content']);
        if (empty($title) || empty($content)) {
            set_flash('error', 'Title and content are required.');
        } else {
            $upload = handle_image_upload('image', 'announcements');
            if (!$upload['ok']) {
                set_flash('error', $upload['error']);
            } else {
                $stmt = $conn->prepare("INSERT INTO announcements (title, content, image, created_by) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    set_flash('error', 'Could not save the announcement — the database schema may be out of date. Try refreshing the page once; if this keeps happening, ask whoever manages the database to run database/upgrade_v2_media.sql.');
                } else {
                    $stmt->bind_param('sssi', $title, $content, $upload['path'], $uid);
                    $stmt->execute();
                    set_flash('success', 'Announcement posted.');
                }
            }
        }
    }

    if ($action === 'update') {
        $ann_id = (int) $_POST['announcement_id'];
        $chk = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
        $chk->bind_param('i', $ann_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if (!$existing) {
            set_flash('error', 'Announcement not found.');
        } elseif (!can_manage_ann($existing, $is_admin, $uid)) {
            header('Location: ' . BASE_URL . '/unauthorized.php');
            exit;
        } else {
            $title = clean($_POST['title']);
            $content = clean($_POST['content']);
            $upload = handle_image_upload('image', 'announcements');
            if (!$upload['ok']) {
                set_flash('error', $upload['error']);
            } else {
                if ($upload['path']) {
                    if (!empty($existing['image'])) delete_uploaded_image($existing['image']);
                    $stmt = $conn->prepare("UPDATE announcements SET title=?, content=?, image=? WHERE announcement_id=?");
                    $stmt->bind_param('sssi', $title, $content, $upload['path'], $ann_id);
                } else {
                    $stmt = $conn->prepare("UPDATE announcements SET title=?, content=? WHERE announcement_id=?");
                    $stmt->bind_param('ssi', $title, $content, $ann_id);
                }
                $stmt->execute();
                set_flash('success', 'Announcement updated.');
            }
        }
    }

    if ($action === 'delete') {
        $ann_id = (int) $_POST['announcement_id'];
        $chk = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
        $chk->bind_param('i', $ann_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if ($existing && can_manage_ann($existing, $is_admin, $uid)) {
            if (!empty($existing['image'])) delete_uploaded_image($existing['image']);
            $stmt = $conn->prepare("DELETE FROM announcements WHERE announcement_id = ?");
            $stmt->bind_param('i', $ann_id);
            $stmt->execute();
            set_flash('success', 'Announcement deleted.');
        } elseif ($existing) {
            header('Location: ' . BASE_URL . '/unauthorized.php');
            exit;
        }
    }

    header('Location: ' . BASE_URL . '/dashboard/announcements.php');
    exit;
}

$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$edit_row = null;
if ($edit_id > 0) {
    $es = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
    $es->bind_param('i', $edit_id);
    $es->execute();
    $edit_row = $es->get_result()->fetch_assoc();
    if ($edit_row && !can_manage_ann($edit_row, $is_admin, $uid)) {
        header('Location: ' . BASE_URL . '/unauthorized.php');
        exit;
    }
}

$announcements = $conn->query("
    SELECT a.*, u.first_name, u.last_name
    FROM announcements a
    LEFT JOIN users u ON u.user_id = a.created_by
    ORDER BY a.created_at DESC
");

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Announcements</h1>
        <p class="muted">Post updates for all members. You can edit or delete only what you created<?php echo $is_admin ? ' — as admin you can manage all of it' : ''; ?>.</p>
    </div>
</div>

<?php if ($edit_row): ?>
<div class="edit-panel">
    <h3>Edit Announcement</h3>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="announcement_id" value="<?php echo $edit_row['announcement_id']; ?>">
        <div class="form-row"><label>Title</label><input type="text" name="title" value="<?php echo htmlspecialchars($edit_row['title']); ?>" required></div>
        <div class="form-row"><label>Content</label><textarea name="content" required><?php echo htmlspecialchars($edit_row['content']); ?></textarea></div>
        <div class="form-row">
            <label>Image</label>
            <?php if (!empty($edit_row['image'])): ?>
                <img src="<?php echo asset_v($edit_row['image']); ?>" alt="" style="max-width:220px; border-radius:8px; margin-bottom:8px; display:block;">
            <?php endif; ?>
            <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
            <p class="muted" style="font-size:0.78rem; margin:6px 0 0;">Leave blank to keep the current image.</p>
        </div>
        <div class="table-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="<?php echo BASE_URL; ?>/dashboard/announcements.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2>Post Announcement</h2></div>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row"><label>Title</label><input type="text" name="title" required></div>
        <div class="form-row"><label>Content</label><textarea name="content" required></textarea></div>
        <div class="form-row">
            <label>Image (optional)</label>
            <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
        </div>
        <button type="submit" class="btn btn-primary">Post Announcement</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head"><h2>All Announcements</h2></div>
    <?php if ($announcements->num_rows > 0): ?>
        <div class="card-grid">
            <?php while ($a = $announcements->fetch_assoc()): $mine = can_manage_ann($a, $is_admin, $uid); ?>
                <div class="card post-card">
                    <?php if (!empty($a['image'])): ?>
                        <img src="<?php echo asset_v($a['image']); ?>" alt="" class="post-card-img">
                    <?php endif; ?>
                    <h3><?php echo htmlspecialchars($a['title']); ?></h3>
                    <p><?php echo nl2br(htmlspecialchars(mb_substr($a['content'], 0, 160))); ?><?php echo mb_strlen($a['content']) > 160 ? '…' : ''; ?></p>
                    <p class="muted" style="font-size:0.78rem;">
                        By <?php echo htmlspecialchars(trim($a['first_name'] . ' ' . $a['last_name'])) ?: 'CSO'; ?> · <?php echo date('M j, Y', strtotime($a['created_at'])); ?>
                    </p>
                    <?php if ($mine): ?>
                        <div class="table-actions" style="margin:0 20px 16px;">
                            <a href="<?php echo BASE_URL; ?>/dashboard/announcements.php?edit=<?php echo $a['announcement_id']; ?>" class="btn btn-outline btn-sm">Edit</a>
                            <form method="POST" onsubmit="return confirm('Delete this announcement?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="announcement_id" value="<?php echo $a['announcement_id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No announcements yet</h3></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
