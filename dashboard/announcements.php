<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/features.php';
require_role(['admin', 'officer', 'moderator']);

$is_admin = $_SESSION['role'] === 'admin';
$can_post = in_array($_SESSION['role'], ['admin', 'moderator'], true);   // who may start a new post
$uid = $_SESSION['user_id'];

function can_manage_ann($ann, $is_admin, $uid) {
    return $is_admin || (int) $ann['created_by'] === (int) $uid;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Photos bigger than the server's post_max_size arrive as an empty POST — say so instead of a confusing security error.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        set_flash('error', 'That upload was too large for the server. Try fewer or smaller photos.');
        header('Location: ' . BASE_URL . '/dashboard/announcements.php');
        exit;
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if (!$can_post) {
            header('Location: ' . BASE_URL . '/unauthorized.php');
            exit;
        }
        $title = clean($_POST['title'] ?? '');
        $content = clean($_POST['content'] ?? '');
        $deadline = !empty($_POST['deadline']) ? str_replace('T', ' ', clean($_POST['deadline'])) . ':00' : null;
        if ($title === '' || $content === '') {
            set_flash('error', 'Title and content are required.');
        } else {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, deadline, created_by) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                set_flash('error', 'Could not save the announcement — the database schema may be out of date. Refresh the page once; if this keeps happening, ask whoever manages the database to run the files in /database.');
            } else {
                $stmt->bind_param('sssi', $title, $content, $deadline, $uid);
                $stmt->execute();
                $new_id = (int) $conn->insert_id;
                $mail_note = '';
                if (!empty($_POST['email_members'])) {
                    $short = mb_strlen($content) > 300 ? mb_substr($content, 0, 300) . '…' : $content;
                    $sent = alert_members($conn, 'CSO announcement: ' . $title, "$title\n\n$short\n\nRead it here: " . site_url('/announcement.php?id=' . $new_id) . "\n\nYou can turn these emails off on your Notifications page.");
                    $mail_note = $sent > 0 ? " Emailed $sent member" . ($sent === 1 ? '' : 's') . '.' : '';
                }
                $r = save_post_images($conn, 'announcement', $new_id, 'announcements');
                if ($r['error']) {
                    set_flash('error', 'Announcement posted, but: ' . $r['error']);
                } else {
                    set_flash('success', 'Announcement posted.' . $mail_note);
                }
            }
        }
    }

    if ($action === 'update') {
        $ann_id = (int) ($_POST['announcement_id'] ?? 0);
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
            $title = clean($_POST['title'] ?? '');
            $content = clean($_POST['content'] ?? '');
            $deadline = !empty($_POST['deadline']) ? str_replace('T', ' ', clean($_POST['deadline'])) . ':00' : null;
            if ($title === '' || $content === '') {
                set_flash('error', 'Title and content are required.');
            } else {
                $stmt = $conn->prepare("UPDATE announcements SET title=?, content=?, deadline=? WHERE announcement_id=?");
                $stmt->bind_param('sssi', $title, $content, $deadline, $ann_id);
                $stmt->execute();
                if (!empty($_POST['remove_images']) && is_array($_POST['remove_images'])) {
                    remove_post_images($conn, 'announcement', $ann_id, $_POST['remove_images']);
                }
                $r = save_post_images($conn, 'announcement', $ann_id, 'announcements');
                if ($r['error']) {
                    set_flash('error', 'Announcement updated, but: ' . $r['error']);
                } else {
                    set_flash('success', 'Announcement updated.');
                }
            }
        }
    }

    if ($action === 'delete') {
        $ann_id = (int) ($_POST['announcement_id'] ?? 0);
        $chk = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
        $chk->bind_param('i', $ann_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if ($existing && can_manage_ann($existing, $is_admin, $uid)) {
            delete_post_images_all($conn, 'announcement', $ann_id);
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
$edit_images = [];
if ($edit_id > 0) {
    $es = $conn->prepare("SELECT * FROM announcements WHERE announcement_id = ?");
    $es->bind_param('i', $edit_id);
    $es->execute();
    $edit_row = $es->get_result()->fetch_assoc();
    if ($edit_row && !can_manage_ann($edit_row, $is_admin, $uid)) {
        header('Location: ' . BASE_URL . '/unauthorized.php');
        exit;
    }
    if ($edit_row) $edit_images = post_images_for($conn, 'announcement', $edit_id, $edit_row['image'] ?? null);
}

$rows = [];
$res = $conn->query("
    SELECT a.*, " . post_author_select() . "
    FROM announcements a
    LEFT JOIN users u ON u.user_id = a.created_by
    ORDER BY a.created_at DESC
");
if ($res) $rows = $res->fetch_all(MYSQLI_ASSOC);
$img_map = post_images_map($conn, 'announcement', array_column($rows, 'announcement_id'));

$page_title = 'Announcements';
require_once __DIR__ . '/../includes/header.php';

$first_name = $_SESSION['first_name'] ?? '';
$deadline_field = function ($value, $field_id) {
    return '<label for="' . e($field_id) . '">Deadline</label><input type="datetime-local" id="' . e($field_id) . '" name="deadline" value="' . e($value) . '">';
};
?>

<div class="page-head">
    <div>
        <h1>Announcements</h1>
        <p class="muted">Post updates for all members. You can edit or delete only what you created<?php echo $is_admin ? ' — as admin you can manage all of it' : ''; ?>.</p>
    </div>
</div>

<div class="feed">
    <?php if ($can_post): ?>
        <div class="post-launcher">
            <?php render_post_avatar($_SESSION['profile_photo'] ?? null, $first_name, $_SESSION['last_name'] ?? ''); ?>
            <button type="button" class="post-launcher-input" data-composer-open="postComposer">What's on your mind, <?php echo e($first_name !== '' ? $first_name : 'there'); ?>?</button>
            <button type="button" class="post-launcher-photo" data-composer-open="postComposer" data-composer-photos aria-label="Add photos"><svg class="icon" aria-hidden="true"><use href="#i-photos"/></svg><span>Photo</span></button>
        </div>
    <?php endif; ?>

    <?php if ($rows): ?>
        <?php foreach ($rows as $a):
            $mine = can_manage_ann($a, $is_admin, $uid);
            $opts = [];
            if ($mine) {
                $opts['edit_url'] = BASE_URL . '/dashboard/announcements.php?edit=' . (int) $a['announcement_id'];
                $opts['delete_fields'] = ['action' => 'delete', 'announcement_id' => (int) $a['announcement_id']];
            }
            render_fb_post($a, 'announcement', $img_map[(int) $a['announcement_id']] ?? [], $opts);
        endforeach; ?>
    <?php else: ?>
        <div class="empty-state feed-empty"><h3>No announcements yet</h3><?php if ($can_post): ?><p>Use the box above to post the first one.</p><?php endif; ?></div>
    <?php endif; ?>
</div>

<?php if ($can_post):
    render_composer([
        'id'            => 'postComposer',
        'heading'       => 'Create post',
        'button'        => 'Post',
        'hidden'        => ['action' => 'create'],
        'title_value'   => '',
        'body_name'     => 'content',
        'body_value'    => '',
        'body_required' => true,
        'tools'         => [[
            'key' => 'deadline', 'label' => 'Add a deadline', 'icon' => 'i-clock', 'open' => false,
            'html' => $deadline_field('', 'ann-deadline-new'),
        ], [
            'key' => 'mail', 'label' => 'Email members', 'icon' => 'i-message', 'open' => false,
            'html' => '<label class="check-row" style="display:flex; gap:8px; align-items:center;"><input type="checkbox" name="email_members" value="1"' . (alerts_available() ? '' : ' disabled') . '> Email members about this post' . (alerts_available() ? '' : ' (email is not set up yet)') . '</label>',
        ]],
    ]);
endif; ?>

<?php if ($edit_row):
    $dl = !empty($edit_row['deadline']) ? date('Y-m-d\TH:i', strtotime($edit_row['deadline'])) : '';
    render_composer([
        'id'            => 'editComposer',
        'open'          => true,
        'close_url'     => BASE_URL . '/dashboard/announcements.php',
        'heading'       => 'Edit post',
        'button'        => 'Save',
        'hidden'        => ['action' => 'update', 'announcement_id' => (int) $edit_row['announcement_id']],
        'title_value'   => $edit_row['title'],
        'body_name'     => 'content',
        'body_value'    => $edit_row['content'],
        'body_required' => true,
        'images'        => $edit_images,
        'tools'         => [[
            'key' => 'deadline', 'label' => 'Add a deadline', 'icon' => 'i-clock', 'open' => $dl !== '',
            'html' => $deadline_field($dl, 'ann-deadline-edit'),
        ]],
    ]);
endif; ?>

<script src="<?php echo e(asset_v('/assets/js/composer.js')); ?>" defer></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
