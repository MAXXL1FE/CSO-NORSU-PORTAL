<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role('admin');

$uid = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = clean($_POST['title']);
    $content = clean($_POST['content']);

    if (empty($title) || empty($content)) {
        set_flash('error', 'Title and content are required.');
    } else {
        $upload = handle_image_upload('image', 'info');
        if (!$upload['ok']) {
            set_flash('error', $upload['error']);
        } else {
            if ($upload['path']) {
                $old = $conn->query("SELECT image FROM org_info WHERE id = 1")->fetch_assoc();
                if (!empty($old['image'])) delete_uploaded_image($old['image']);
                $stmt = $conn->prepare("UPDATE org_info SET title=?, content=?, image=?, updated_by=? WHERE id = 1");
                $stmt->bind_param('sssi', $title, $content, $upload['path'], $uid);
            } else {
                $stmt = $conn->prepare("UPDATE org_info SET title=?, content=?, updated_by=? WHERE id = 1");
                $stmt->bind_param('ssi', $title, $content, $uid);
            }
            $stmt->execute();
            set_flash('success', 'About CSO section updated.');
        }
    }
    header('Location: ' . BASE_URL . '/dashboard/info.php');
    exit;
}

$info_result = $conn->query("SELECT * FROM org_info WHERE id = 1");
$info = $info_result ? $info_result->fetch_assoc() : null;
if (!$info) {
    $info = ['id' => 1, 'title' => 'About CSO', 'content' => '', 'image' => null];
}

$page_title = 'About CSO';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>About CSO Section</h1>
        <p class="muted">This is what visitors see on the public homepage, right below the hero banner.</p>
    </div>
</div>

<div class="panel" style="max-width:640px;">
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <div class="form-row"><label for="fld-1">Title</label><input id="fld-1" type="text" name="title" value="<?php echo htmlspecialchars($info['title']); ?>" required></div>
        <div class="form-row"><label for="fld-2">Content</label><textarea id="fld-2" name="content" required style="min-height:160px;"><?php echo htmlspecialchars($info['content']); ?></textarea></div>
        <div class="form-row">
            <label>Image</label>
            <?php if (!empty($info['image'])): ?>
                <img src="<?php echo asset_v($info['image']); ?>" alt="" style="max-width:240px; border-radius:8px; margin-bottom:8px; display:block;">
            <?php endif; ?>
            <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif">
            <p class="muted" style="font-size:0.78rem; margin:6px 0 0;">Leave blank to keep the current image. Shown beside the text on the homepage.</p>
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
