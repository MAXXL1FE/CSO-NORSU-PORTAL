<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_login();

$uid = $_SESSION['user_id'];
$role = $_SESSION['role'];
$can_see_all = in_array($role, ['admin', 'officer'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $subject = clean($_POST['subject']);
        $message = clean($_POST['message']);
        $rating = isset($_POST['rating']) && $_POST['rating'] !== '' ? (int) $_POST['rating'] : null;
        if ($rating !== null) $rating = max(1, min(5, $rating));

        if (empty($subject) || empty($message)) {
            set_flash('error', 'Please fill in a subject and message.');
        } else {
            $stmt = $conn->prepare("INSERT INTO feedback (user_id, subject, message, rating) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('issi', $uid, $subject, $message, $rating);
            $stmt->execute();
            set_flash('success', 'Thanks — your feedback was submitted.');
        }
    }

    if ($action === 'delete' && $can_see_all) {
        $fid = (int) $_POST['feedback_id'];
        $stmt = $conn->prepare("DELETE FROM feedback WHERE feedback_id = ?");
        $stmt->bind_param('i', $fid);
        $stmt->execute();
        set_flash('success', 'Feedback entry deleted.');
    }

    header('Location: ' . BASE_URL . '/dashboard/feedback.php');
    exit;
}

if ($can_see_all) {
    $rows = $conn->query("
        SELECT f.*, u.first_name, u.last_name, u.student_id
        FROM feedback f JOIN users u ON u.user_id = f.user_id
        ORDER BY f.created_at DESC
    ");
} else {
    $stmt = $conn->prepare("SELECT * FROM feedback WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result();
}

function render_stars($rating) {
    if ($rating === null) return '';
    $html = '<span class="star-rating">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<span class="' . ($i <= $rating ? 'filled' : '') . '">&#9733;</span>';
    }
    return $html . '</span>';
}

$page_title = 'Feedback';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Feedback</h1>
        <p class="muted"><?php echo $can_see_all ? 'See what members are saying about CSO.' : 'Tell us what\'s working and what could be better.'; ?></p>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2>Send Feedback</h2></div>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row"><label>Subject</label><input type="text" name="subject" required></div>
        <div class="form-row"><label>Message</label><textarea name="message" required></textarea></div>
        <div class="form-row">
            <label>Rating (optional)</label>
            <select name="rating">
                <option value="">No rating</option>
                <option value="5">5 — Excellent</option>
                <option value="4">4 — Good</option>
                <option value="3">3 — Okay</option>
                <option value="2">2 — Needs work</option>
                <option value="1">1 — Poor</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Submit Feedback</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head"><h2><?php echo $can_see_all ? 'All Feedback' : 'Your Submissions'; ?></h2></div>
    <?php if ($rows->num_rows > 0): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <?php if ($can_see_all): ?><th>From</th><?php endif; ?>
                        <th>Subject</th><th>Message</th><th>Rating</th><th>Date</th><?php if ($can_see_all): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php while ($f = $rows->fetch_assoc()): ?>
                    <tr>
                        <?php if ($can_see_all): ?>
                            <td><?php echo htmlspecialchars($f['first_name'] . ' ' . $f['last_name']); ?><br><span class="muted" style="font-size:0.78rem;"><?php echo htmlspecialchars($f['student_id']); ?></span></td>
                        <?php endif; ?>
                        <td><?php echo htmlspecialchars($f['subject']); ?></td>
                        <td style="max-width:280px;"><?php echo nl2br(htmlspecialchars($f['message'])); ?></td>
                        <td><?php echo render_stars($f['rating']); ?></td>
                        <td><?php echo date('M j, Y', strtotime($f['created_at'])); ?></td>
                        <?php if ($can_see_all): ?>
                        <td>
                            <form method="POST" onsubmit="return confirm('Delete this feedback entry?');">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="feedback_id" value="<?php echo $f['feedback_id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No feedback yet</h3></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
