<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer']);

$is_admin = $_SESSION['role'] === 'admin';
$uid = (int) $_SESSION['user_id'];

/**
 * Reads pasted or uploaded text. One student per line: "Student ID" or "Student ID, Full name".
 * Commas, tabs and semicolons all work, so a CSV saved from Excel can be used as is.
 * Returns a list of [student_id, full_name|null].
 */
function roster_parse($text, $max = 5000) {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $cells = array_map(fn($c) => trim($c, " \t\"'"), preg_split('/[,;\t]/', $line));
        $id = strip_tags($cells[0] ?? '');
        if ($id === '' || preg_match('/^student\s*(id|no|number)?$/i', $id)) continue;   // skips a header row
        if (strlen($id) > 30) continue;
        $name = isset($cells[1]) ? trim(strip_tags(implode(' ', array_slice($cells, 1)))) : '';
        $out[] = [$id, $name === '' ? null : substr($name, 0, 150)];
        if (count($out) >= $max) break;
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'import') {
        $text = (string) ($_POST['ids'] ?? '');
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name']) && $_FILES['file']['size'] <= 1048576) {
            $text .= "\n" . file_get_contents($_FILES['file']['tmp_name']);
        }
        $rows = roster_parse($text);
        if (!$rows) {
            set_flash('error', 'Paste or upload at least one Student ID.');
        } else {
            $stmt = $conn->prepare("INSERT INTO student_roster (student_id, full_name, added_by) VALUES (?, ?, ?)
                                    ON DUPLICATE KEY UPDATE full_name = COALESCE(VALUES(full_name), full_name)");
            $n = 0;
            foreach ($rows as [$sid, $nm]) {
                $stmt->bind_param('ssi', $sid, $nm, $uid);
                $stmt->execute();
                $n++;
            }
            set_flash('success', $n . ' Student ID' . ($n === 1 ? '' : 's') . ' saved to the list.');
        }
    }

    if ($action === 'remove') {
        $sid = clean($_POST['student_id'] ?? '');
        $stmt = $conn->prepare("DELETE FROM student_roster WHERE student_id = ?");
        $stmt->bind_param('s', $sid);
        $stmt->execute();
        set_flash('success', 'Removed from the list.');
    }

    if ($action === 'clear_all' && $is_admin) {
        $conn->query("DELETE FROM student_roster");
        set_flash('success', 'The Student ID list was emptied. Anyone can sign up again (officers still approve each one).');
    }

    $keep = array_filter(['q' => $_GET['q'] ?? ''], fn($v) => $v !== '');
    header('Location: ' . BASE_URL . '/dashboard/roster.php' . ($keep ? '?' . http_build_query($keep) : ''));
    exit;
}

$q = trim($_GET['q'] ?? '');
$total = (int) $conn->query("SELECT COUNT(*) AS c FROM student_roster")->fetch_assoc()['c'];
$registered = (int) $conn->query("SELECT COUNT(*) AS c FROM student_roster r JOIN users u ON u.student_id = r.student_id")->fetch_assoc()['c'];

if ($q !== '') {
    $like = '%' . $q . '%';
    $stmt = $conn->prepare("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.student_id = r.student_id) AS has_account
                            FROM student_roster r WHERE r.student_id LIKE ? OR r.full_name LIKE ? ORDER BY r.student_id LIMIT 300");
    $stmt->bind_param('ss', $like, $like);
} else {
    $stmt = $conn->prepare("SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.student_id = r.student_id) AS has_account
                            FROM student_roster r ORDER BY r.student_id LIMIT 300");
}
$stmt->execute();
$rows = $stmt->get_result();

$page_title = 'Student ID List';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Student ID List</h1>
        <p class="muted">Only Student IDs on this list can sign up as students. Officers still approve each sign-up in Members.</p>
    </div>
</div>

<?php if ($total === 0): ?>
    <div class="alert alert-warning" role="status">The list is empty, so the ID check is <strong>off</strong> and any Student ID can sign up (officers still approve each one). Add the IDs below to turn the check on.</div>
<?php else: ?>
    <div class="alert alert-success" role="status">The ID check is <strong>on</strong>: <?php echo $total; ?> Student ID<?php echo $total === 1 ? '' : 's'; ?> on the list, <?php echo $registered; ?> already have an account.</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2>Add Student IDs</h2></div>
    <form method="POST" enctype="multipart/form-data" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="import">
        <div class="form-row">
            <label for="ids">Paste IDs (one per line, with the name if you have it)</label>
            <textarea id="ids" name="ids" rows="6" placeholder="2026001, Juan Dela Cruz&#10;2026002, Maria Santos&#10;2026003"></textarea>
            <p class="muted" style="font-size:0.82rem; margin-top:6px;">The name is optional. It is shown next to a pending sign-up so the officer can compare it with what the student typed.</p>
        </div>
        <div class="form-row">
            <label for="file">Or upload a CSV / text file</label>
            <input type="file" id="file" name="file" accept=".csv,.txt,text/csv,text/plain">
            <p class="muted" style="font-size:0.82rem; margin-top:6px;">First column: Student ID. Second column (optional): full name. A header row is skipped. IDs already on the list are kept.</p>
        </div>
        <button type="submit" class="btn btn-primary">Add to list</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>On the list (<?php echo $total; ?>)</h2>
        <?php if ($is_admin && $total > 0): ?>
        <form method="POST" <?php echo confirm_attr('Empty the whole Student ID list? The ID check will turn off until you add IDs again.'); ?>>
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" class="btn btn-danger btn-sm">Empty the list</button>
        </form>
        <?php endif; ?>
    </div>

    <form method="GET" class="list-controls" role="search" style="margin-bottom:12px;">
        <input type="search" name="q" value="<?php echo e($q); ?>" placeholder="Search ID or name" aria-label="Search the Student ID list">
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($q !== ''): ?><a href="<?php echo BASE_URL; ?>/dashboard/roster.php" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
    </form>

    <?php if ($rows->num_rows > 0): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th scope="col">Student ID</th><th scope="col">Name</th><th scope="col">Account</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody>
            <?php while ($r = $rows->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo e($r['student_id']); ?></strong></td>
                    <td><?php echo $r['full_name'] ? e($r['full_name']) : '<span class="muted">&mdash;</span>'; ?></td>
                    <td><?php echo (int) $r['has_account'] > 0 ? 'Signed up' : '<span class="muted">Not yet</span>'; ?></td>
                    <td>
                        <form method="POST" <?php echo confirm_attr('Remove ' . $r['student_id'] . ' from the list? This does not delete an existing account.'); ?>>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="student_id" value="<?php echo e($r['student_id']); ?>">
                            <button type="submit" class="btn btn-outline btn-sm">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php if ($total > 300 && $q === ''): ?><p class="muted" style="margin-top:10px;">Showing the first 300. Use the search to find a specific ID.</p><?php endif; ?>
    <?php else: ?>
        <div class="empty-state"><h3><?php echo $q !== '' ? 'No match' : 'No Student IDs yet'; ?></h3></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
