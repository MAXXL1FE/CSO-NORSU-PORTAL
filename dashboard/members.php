<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/otp.php';
require_once __DIR__ . '/../includes/features.php';
require_role(['admin', 'officer', 'moderator']);

$is_admin = $_SESSION['role'] === 'admin';
$uid = $_SESSION['user_id'];

// ---------------------------------------------------------
// Handle create / update / delete
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $student_id  = clean($_POST['student_id']);
        $first_name  = clean($_POST['first_name']);
        $middle_name = clean($_POST['middle_name'] ?? '');
        $last_name   = clean($_POST['last_name']);
        $suffix      = clean($_POST['suffix'] ?? '');
        $email      = clean($_POST['email']);
        $course     = clean($_POST['course']);
        $year_level = clean($_POST['year_level']);
        $password   = $_POST['password'];

        if (empty($student_id) || empty($first_name) || empty($last_name) || empty($email) || empty($course) || empty($year_level) || strlen($password) < 10) {
            set_flash('error', 'Please fill in all fields — password must be at least 10 characters.');
        } else {
            $check = $conn->prepare("SELECT user_id FROM users WHERE student_id = ? OR email = ?");
            $check->bind_param('ss', $student_id, $email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                set_flash('error', 'A member with this Student ID or email already exists.');
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (student_id, first_name, middle_name, last_name, suffix, email, password, course, year_level, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'member', 'active')");
                $stmt->bind_param('sssssssss', $student_id, $first_name, $middle_name, $last_name, $suffix, $email, $hashed, $course, $year_level);
                $stmt->execute();
                set_flash('success', 'Member added successfully.');
            }
        }
    }

    if ($action === 'update') {
        $edit_id    = (int) $_POST['user_id'];
        $first_name  = clean($_POST['first_name']);
        $middle_name = clean($_POST['middle_name'] ?? '');
        $last_name   = clean($_POST['last_name']);
        $suffix      = clean($_POST['suffix'] ?? '');
        $email      = clean($_POST['email']);
        $course     = clean($_POST['course']);
        $year_level = clean($_POST['year_level']);
        $status     = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

        // Only admins may change role or committee/position; officers editing keep the existing values
        if ($is_admin && in_array($_POST['role'] ?? '', ['member', 'officer', 'moderator'], true)) {
            $role = $_POST['role'];
            $committee_role = clean($_POST['committee_role'] ?? '');
            $committee_role = $committee_role !== '' ? $committee_role : null;
            $stmt = $conn->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, email=?, course=?, year_level=?, status=?, role=?, committee_role=? WHERE user_id=?");
            $stmt->bind_param('ssssssssssi', $first_name, $middle_name, $last_name, $suffix, $email, $course, $year_level, $status, $role, $committee_role, $edit_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, email=?, course=?, year_level=?, status=? WHERE user_id=? AND role != 'admin'");
            $stmt->bind_param('ssssssssi', $first_name, $middle_name, $last_name, $suffix, $email, $course, $year_level, $status, $edit_id);
        }
        $stmt->execute();
        if ($status === 'active') {
            $cp = $conn->prepare("UPDATE users SET approval_pending = 0 WHERE user_id = ?");
            $cp->bind_param('i', $edit_id);
            $cp->execute();
            $cp->close();
        }

        // Faculty / instructors: the position shown on the Home page
        if (isset($_POST['faculty_position'])) {
            $fpos = clean($_POST['faculty_position']);
            $fstmt = $conn->prepare("UPDATE users SET faculty_position = ? WHERE user_id = ? AND user_type = 'faculty'");
            $fstmt->bind_param('si', $fpos, $edit_id);
            $fstmt->execute();
        }
        set_flash('success', 'Member details updated.');
    }

    if ($action === 'set_badge' && $is_admin) {
        $tid  = (int) ($_POST['user_id'] ?? 0);
        $want = $_POST['verified_as'] ?? 'none';
        $bs = $conn->prepare("SELECT user_type, role FROM users WHERE user_id = ?");
        $bs->bind_param('i', $tid);
        $bs->execute();
        $target = $bs->get_result()->fetch_assoc();
        $bs->close();
        $allowed = $target ? array_keys(verified_options($target['user_type'] ?? 'student', $target['role'] ?? 'member')) : [];
        if ($target && in_array($want, $allowed, true)) {
            $up = $conn->prepare("UPDATE users SET verified_as = ? WHERE user_id = ?");
            $up->bind_param('si', $want, $tid);
            $up->execute();
            $up->close();
            set_flash('success', $want === 'none' ? 'Badge removed.' : 'Badge saved. It now shows on the public pages.');
        } else {
            set_flash('error', 'That badge does not fit this account.');
        }
    }

    // Approve several pending sign-ups at once.
    if ($action === 'bulk_approve') {
        $ids = array_slice(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))), 0, 50);
        $ok = 0; $mailed_n = 0;
        foreach ($ids as $tid) {
            if ($tid <= 0) continue;
            $who = $conn->query("SELECT first_name, middle_name, last_name, suffix, email FROM users WHERE user_id = $tid AND approval_pending = 1 AND role = 'member'")->fetch_assoc();
            if (!$who) continue;
            $conn->query("UPDATE users SET status = 'active', approval_pending = 0 WHERE user_id = $tid");
            $ok++;
            add_notice($conn, $tid, 'Your CSO account was approved. Welcome!');
            if (cso_send_mail($who['email'], full_name($who), 'Your CSO account was approved',
                "Hello " . full_name($who) . ",\n\nA CSO officer approved your account. You can now log in with your Student ID and password.\n\nCSO-NORSU")) $mailed_n++;
        }
        set_flash($ok ? 'success' : 'error', $ok ? "Approved $ok sign-up" . ($ok === 1 ? '' : 's') . '.' . ($mailed_n ? " Emailed $mailed_n." : '') : 'Tick at least one pending sign-up first.');
    }

    // Import members from a CSV (admins only). They get a random password and set their own with "Forgot password".
    if ($action === 'import_csv' && $is_admin) {
        $added = 0; $skipped = 0; $bad = 0;
        $tmp = $_FILES['csv']['tmp_name'] ?? '';
        if ($tmp !== '' && is_uploaded_file($tmp) && ($_FILES['csv']['size'] ?? 0) <= 1048576 && ($fh = fopen($tmp, 'r'))) {
            $head = fgetcsv($fh);
            $map = [];
            foreach ((array) $head as $i => $h) $map[strtolower(trim(str_replace(["\xEF\xBB\xBF", ' '], ['', '_'], (string) $h)))] = $i;
            $need = ['student_id', 'first_name', 'last_name', 'email', 'course', 'year_level'];
            if (array_diff($need, array_keys($map))) {
                set_flash('error', 'The first row must have these columns: ' . implode(', ', $need) . '.');
            } else {
                $ins = $conn->prepare("INSERT INTO users (student_id, first_name, middle_name, last_name, suffix, email, password, course, year_level, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'member', 'active')");
                $dup = $conn->prepare("SELECT 1 FROM users WHERE student_id = ? OR email = ?");
                $n = 0;
                while (($row = fgetcsv($fh)) !== false && $n < 500) {
                    $n++;
                    $g = fn($k) => isset($map[$k], $row[$map[$k]]) ? clean($row[$map[$k]]) : '';
                    $sid = $g('student_id'); $fn = $g('first_name'); $ln = $g('last_name'); $em = $g('email'); $co = $g('course'); $yl = $g('year_level');
                    if ($sid === '' || $fn === '' || $ln === '' || !filter_var($em, FILTER_VALIDATE_EMAIL) || $co === '' || $yl === '') { $bad++; continue; }
                    $dup->bind_param('ss', $sid, $em);
                    $dup->execute();
                    if ($dup->get_result()->num_rows > 0) { $skipped++; continue; }
                    $mn = $g('middle_name'); $sf = $g('suffix');
                    $pw = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    $ins->bind_param('sssssssss', $sid, $fn, $mn, $ln, $sf, $em, $pw, $co, $yl);
                    $ins->execute();
                    $added++;
                }
                set_flash($added ? 'success' : 'error', "Imported $added member" . ($added === 1 ? '' : 's') . ($skipped ? ", $skipped already existed" : '') . ($bad ? ", $bad rows were incomplete" : '') . '. Imported members set their password with "Forgot password".');
            }
            fclose($fh);
        } else {
            set_flash('error', 'Choose a CSV file (up to 1 MB).');
        }
    }

    // Approve or decline a student sign-up that is waiting for review.
    if ($action === 'approve_signup') {
        $tid = (int) ($_POST['user_id'] ?? 0);
        $who = $conn->query("SELECT first_name, middle_name, last_name, suffix, email FROM users WHERE user_id = $tid AND approval_pending = 1 AND role = 'member'")->fetch_assoc();
        $ap = $conn->prepare("UPDATE users SET status = 'active', approval_pending = 0 WHERE user_id = ? AND approval_pending = 1 AND role = 'member'");
        $ap->bind_param('i', $tid);
        $ap->execute();
        $done = $ap->affected_rows > 0;
        $ap->close();
        if ($done && $who) {
            $note = $conn->prepare("INSERT INTO user_notices (user_id, message) VALUES (?, ?)");
            $msg = 'Your CSO account was approved. Welcome!';
            $note->bind_param('is', $tid, $msg);
            $note->execute();
            $note->close();
            $mailed = cso_send_mail($who['email'], full_name($who), 'Your CSO account was approved',
                "Hello " . full_name($who) . ",\n\nA CSO officer approved your account. You can now log in with your Student ID and password.\n\nCSO-NORSU");
        }
        set_flash($done ? 'success' : 'error', $done
            ? 'Approved. They can log in now.' . (!empty($mailed) ? ' An email was sent to them.' : (otp_email_available() ? '' : ' Tell them in person, since email is not set up.'))
            : 'That sign-up was already handled.');
    }
    if ($action === 'decline_signup') {
        $tid = (int) ($_POST['user_id'] ?? 0);
        $who = $conn->query("SELECT first_name, middle_name, last_name, suffix, email FROM users WHERE user_id = $tid AND approval_pending = 1 AND role = 'member'")->fetch_assoc();
        $dc = $conn->prepare("DELETE FROM users WHERE user_id = ? AND approval_pending = 1 AND role = 'member'");
        $dc->bind_param('i', $tid);
        $dc->execute();
        $done = $dc->affected_rows > 0;
        $dc->close();
        if ($done && $who) {
            $mailed = cso_send_mail($who['email'], full_name($who), 'About your CSO sign-up',
                "Hello " . full_name($who) . ",\n\nYour CSO sign-up could not be approved. If you think this is a mistake, please see a CSO officer with your Student ID.\n\nCSO-NORSU");
        }
        set_flash($done ? 'success' : 'error', $done
            ? 'Sign-up declined and removed.' . (!empty($mailed) ? ' An email was sent to them.' : '')
            : 'That sign-up was already handled.');
    }

    // Quick changes straight from the list (no edit form needed)
    if ($action === 'set_status') {
        $tid = (int) ($_POST['user_id'] ?? 0);
        $new = ($_POST['status'] ?? '') === 'active' ? 'active' : 'inactive';
        $qs = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ? AND role != 'admin'");
        $qs->bind_param('si', $new, $tid);
        $qs->execute();
        $qs->close();
        if ($new === 'active') $conn->query("UPDATE users SET approval_pending = 0 WHERE user_id = " . $tid);
        set_flash('success', $new === 'active' ? 'Marked active.' : 'Marked inactive.');
    }

    if ($action === 'set_role' && $is_admin) {
        $tid  = (int) ($_POST['user_id'] ?? 0);
        $want = $_POST['role'] ?? '';
        if ($tid !== $uid && in_array($want, ['member', 'officer', 'moderator'], true)) {
            $qr = $conn->prepare("UPDATE users SET role = ? WHERE user_id = ? AND role != 'admin'");
            $qr->bind_param('si', $want, $tid);
            $qr->execute();
            $qr->close();
            set_flash('success', 'Role changed to ' . $want . '.');
        }
    }

    if ($action === 'delete' && $is_admin) {
        $del_id = (int) $_POST['user_id'];
        if ($del_id !== $uid) {
            $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role != 'admin'");
            $stmt->bind_param('i', $del_id);
            $stmt->execute();
            set_flash('success', 'Member removed.');
        }
    }

    // Come back to the same search / filters, minus the edit popup
    $keep = $_GET;
    unset($keep['edit']);
    header('Location: ' . BASE_URL . '/dashboard/members.php' . ($keep ? '?' . http_build_query($keep) : ''));
    exit;
}

// ---------------------------------------------------------
// List with search + status filter
// ---------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) && in_array($_GET['status'], ['active', 'inactive', 'pending'], true) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) && in_array($_GET['type'], ['student', 'faculty'], true) ? $_GET['type'] : '';
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$where = "role IN ('member','officer','moderator')";
$params = [];
$types = '';
if ($search !== '') {
    $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
    $types .= 'sss';
}
if ($status_filter === 'pending') {
    $where .= " AND approval_pending = 1";
} elseif ($status_filter === 'inactive') {
    $where .= " AND status = 'inactive' AND approval_pending = 0";
} elseif ($status_filter !== '') {
    $where .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($type_filter !== '') {
    $where .= " AND user_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

$sql = "SELECT u.*, (SELECT r.full_name FROM student_roster r WHERE r.student_id = u.student_id) AS roster_name,
               (SELECT COUNT(*) FROM student_roster r2 WHERE r2.student_id = u.student_id) AS on_roster
        FROM users u WHERE $where ORDER BY approval_pending DESC, role ASC, last_name ASC";
$roster_total = (int) ($conn->query("SELECT COUNT(*) AS c FROM student_roster")->fetch_assoc()['c'] ?? 0);
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$members = $stmt->get_result();

// Export the list as currently filtered (search / status / type) to a CSV file.
if (($_GET['export'] ?? '') === '1') {
    $cell = function ($v) { $v = (string) $v; return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; };   // stops spreadsheet formula tricks
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cso-members-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['student_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'email', 'course', 'year_level', 'type', 'role', 'status', 'position']);
    while ($m = $members->fetch_assoc()) {
        fputcsv($out, array_map($cell, [$m['student_id'], $m['first_name'], $m['middle_name'], $m['last_name'], $m['suffix'], $m['email'], $m['course'], $m['year_level'],
            $m['user_type'], $m['role'], !empty($m['approval_pending']) ? 'pending' : $m['status'], $m['user_type'] === 'faculty' ? $m['faculty_position'] : $m['committee_role']]));
    }
    fclose($out);
    exit;
}

$counts = ['all' => 0, 'active' => 0, 'inactive' => 0, 'pending' => 0];
$cr = $conn->query("SELECT status, approval_pending, COUNT(*) AS n FROM users WHERE role IN ('member','officer','moderator') GROUP BY status, approval_pending");
if ($cr) while ($c = $cr->fetch_assoc()) {
    if ((int) $c['approval_pending'] === 1) $counts['pending'] += (int) $c['n'];
    else $counts[$c['status'] === 'active' ? 'active' : 'inactive'] += (int) $c['n'];
    $counts['all'] += (int) $c['n'];
}
$base_q = array_filter(['search' => $search, 'type' => $type_filter], fn($v) => $v !== '');
function mm_url($extra, $base_q) {
    $q = array_filter($base_q + $extra, fn($v) => $v !== '');
    return BASE_URL . '/dashboard/members.php' . ($q ? '?' . http_build_query($q) : '');
}

$edit_row = null;
if ($edit_id > 0) {
    $es = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND role IN ('member','officer','moderator')");
    $es->bind_param('i', $edit_id);
    $es->execute();
    $edit_row = $es->get_result()->fetch_assoc();
}

$page_title = 'Members';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($is_admin): ?>
<div class="panel no-print" id="importBox" hidden>
    <div class="panel-head"><h2>Import members from a CSV</h2></div>
    <form method="POST" enctype="multipart/form-data" class="form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="import_csv">
        <div class="form-row">
            <label for="csv-file">CSV file</label>
            <input type="file" id="csv-file" name="csv" accept=".csv,text/csv" required>
            <p class="muted" style="font-size:.82rem; margin-top:6px;">First row must be: <code>student_id, first_name, last_name, email, course, year_level</code> (optional: <code>middle_name, suffix</code>). Up to 500 rows. People who already exist are skipped. Imported members get a random password and use &ldquo;Forgot password&rdquo; to set their own.</p>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Import</button>
    </form>
</div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1>Members</h1>
        <p class="muted">Change a role or status right from the list<?php echo $is_admin ? '' : ' — admins handle role changes and deletions'; ?>.</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn btn-outline btn-sm print-btn no-print" onclick="window.print()"><svg class="icon" aria-hidden="true"><use href="#i-printer"/></svg>Print</button>
        <a class="btn btn-outline btn-sm no-print" href="<?php echo e(mm_url(['export' => '1', 'status' => $status_filter], $base_q)); ?>">Export CSV</a>
        <?php if ($is_admin): ?><button type="button" class="btn btn-outline btn-sm no-print" onclick="document.getElementById('importBox').hidden = !document.getElementById('importBox').hidden">Import CSV</button><?php endif; ?>
        <button type="button" class="btn btn-primary no-print" onclick="openAddMemberModal()">+ Add Member</button>
    </div>
</div>

<?php if ($edit_row): ?>
<div class="modal-overlay open" id="editMemberModalOverlay">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="editMemberModalTitle" style="max-width:560px;">
        <div class="modal-header">
            <h3 id="editMemberModalTitle">Edit <?php echo htmlspecialchars(full_name($edit_row)); ?></h3>
            <a href="<?php echo BASE_URL; ?>/dashboard/members.php" class="modal-close" aria-label="Close">&times;</a>
        </div>
        <div class="modal-body">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" value="<?php echo $edit_row['user_id']; ?>">
        <div class="form-row form-row-split">
            <div><label for="fld-1">First Name</label><input id="fld-1" type="text" name="first_name" value="<?php echo htmlspecialchars($edit_row['first_name']); ?>" required></div>
            <div><label for="fld-2">Middle Name</label><input id="fld-2" type="text" name="middle_name" value="<?php echo htmlspecialchars($edit_row['middle_name'] ?? ''); ?>"></div>
            <div><label for="fld-3">Last Name</label><input id="fld-3" type="text" name="last_name" value="<?php echo htmlspecialchars($edit_row['last_name']); ?>" required></div>
            <div><label for="fld-4">Suffix</label><input id="fld-4" type="text" name="suffix" value="<?php echo htmlspecialchars($edit_row['suffix'] ?? ''); ?>"></div>
        </div>
        <div class="form-row"><label for="fld-5">Email</label><input id="fld-5" type="email" name="email" value="<?php echo htmlspecialchars($edit_row['email']); ?>" required></div>
        <?php $edit_is_faculty = (($edit_row['user_type'] ?? 'student') === 'faculty'); ?>
        <?php if ($edit_is_faculty): ?>
        <input type="hidden" name="year_level" value="Faculty">
        <div class="form-row form-row-split">
            <div><label for="fld-6">Department / College</label><input id="fld-6" type="text" name="course" value="<?php echo htmlspecialchars($edit_row['course']); ?>" required></div>
            <div><label for="fld-7">Position</label><input id="fld-7" type="text" name="faculty_position" value="<?php echo htmlspecialchars($edit_row['faculty_position'] ?? ''); ?>" placeholder="e.g. Adviser, Instructor, Professor" required></div>
        </div>
        <?php else: ?>
        <div class="form-row form-row-split">
            <div><label for="fld-8">Program</label><input id="fld-8" type="text" name="course" value="<?php echo htmlspecialchars($edit_row['course']); ?>" required></div>
            <div>
                <label for="fld-9">Year Level</label>
                <select id="fld-9" name="year_level" required>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo $edit_row['year_level'] === $yr ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        <div class="form-row form-row-split">
            <div>
                <label for="fld-10">Status</label>
                <select id="fld-10" name="status">
                    <option value="active" <?php echo $edit_row['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $edit_row['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo $edit_is_faculty ? 'Pending / Inactive' : 'Inactive'; ?></option>
                </select>
                <?php if ($edit_is_faculty && $edit_row['status'] === 'inactive'): ?><p class="muted" style="font-size:0.78rem; margin:6px 0 0;">Set to Active to approve this faculty member &mdash; they will then appear on the Home page.</p><?php endif; ?>
            </div>
            <?php if ($is_admin): ?>
            <div>
                <label for="fld-11">Role</label>
                <select id="fld-11" name="role">
                    <option value="member" <?php echo $edit_row['role'] === 'member' ? 'selected' : ''; ?>>Member</option>
                    <option value="officer" <?php echo $edit_row['role'] === 'officer' ? 'selected' : ''; ?>>Officer</option>
                    <option value="moderator" <?php echo $edit_row['role'] === 'moderator' ? 'selected' : ''; ?>>Moderator</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($is_admin): ?>
        <div class="form-row">
            <label for="fld-12">Committee / Position</label>
            <input id="fld-12" type="text" name="committee_role" value="<?php echo htmlspecialchars($edit_row['committee_role'] ?? ''); ?>" placeholder="e.g. Vice President, Events Committee Head — leave blank if none">
            <p class="muted" style="font-size:0.78rem; margin:6px 0 0;">Shown in the Officer Directory and on their dashboard. Type any committee or position name you like.</p>
        </div>
        <?php endif; ?>
        <div class="table-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="<?php echo BASE_URL; ?>/dashboard/members.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal-overlay" id="addMemberModalOverlay" onclick="if (event.target === this) closeAddMemberModal();">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="addMemberModalTitle" style="max-width:560px;">
        <div class="modal-header">
            <h3 id="addMemberModalTitle">Add Member</h3>
            <button type="button" class="modal-close" aria-label="Close" onclick="closeAddMemberModal()">&times;</button>
        </div>
        <div class="modal-body">
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row form-row-split">
            <div><label for="fld-13">Student ID</label><input id="fld-13" type="text" name="student_id" placeholder="e.g. 2026001" required></div>
            <div><label for="fld-14">Email</label><input id="fld-14" type="email" name="email" required></div>
        </div>
        <div class="form-row form-row-split">
            <div><label for="fld-15">First Name</label><input id="fld-15" type="text" name="first_name" required></div>
            <div><label for="fld-16">Middle Name</label><input id="fld-16" type="text" name="middle_name"></div>
            <div><label for="fld-17">Last Name</label><input id="fld-17" type="text" name="last_name" required></div>
            <div><label for="fld-18">Suffix</label><input id="fld-18" type="text" name="suffix"></div>
        </div>
        <div class="form-row form-row-split">
            <div><label for="fld-19">Program</label><input id="fld-19" type="text" name="course" placeholder="e.g. BSCS" required></div>
            <div>
                <label for="fld-20">Year Level</label>
                <select id="fld-20" name="year_level" required>
                    <option value="">Select</option>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yr): ?>
                        <option value="<?php echo $yr; ?>"><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row"><label for="fld-21">Temporary Password</label><input id="fld-21" type="password" name="password" minlength="10" required></div>
        <button type="submit" class="btn btn-primary">Add Member</button>
    </form>
        </div>
    </div>
</div>
<script>
function openAddMemberModal() {
    document.getElementById('addMemberModalOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeAddMemberModal() {
    document.getElementById('addMemberModalOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAddMemberModal(); });
</script>

<div class="mm">
    <div class="mm-bar">
        <div class="mm-pills" role="tablist" aria-label="Filter by status">
            <a class="mm-pill<?php echo $status_filter === '' ? ' is-on' : ''; ?>" href="<?php echo e(mm_url([], $base_q)); ?>">All <b><?php echo $counts['all']; ?></b></a>
            <a class="mm-pill<?php echo $status_filter === 'pending' ? ' is-on' : ''; ?>" href="<?php echo e(mm_url(['status' => 'pending'], $base_q)); ?>">Pending approval <b><?php echo $counts['pending']; ?></b></a>
            <a class="mm-pill<?php echo $status_filter === 'active' ? ' is-on' : ''; ?>" href="<?php echo e(mm_url(['status' => 'active'], $base_q)); ?>">Active <b><?php echo $counts['active']; ?></b></a>
            <a class="mm-pill<?php echo $status_filter === 'inactive' ? ' is-on' : ''; ?>" href="<?php echo e(mm_url(['status' => 'inactive'], $base_q)); ?>">Inactive <b><?php echo $counts['inactive']; ?></b></a>
        </div>
        <form method="GET" class="mm-search" role="search">
            <?php if ($status_filter !== ''): ?><input type="hidden" name="status" value="<?php echo e($status_filter); ?>"><?php endif; ?>
            <input type="search" name="search" placeholder="Search name or ID" value="<?php echo e($search); ?>" aria-label="Search members">
            <select name="type" onchange="this.form.submit()" aria-label="Students or faculty">
                <option value="">Students &amp; faculty</option>
                <option value="student" <?php echo $type_filter === 'student' ? 'selected' : ''; ?>>Students</option>
                <option value="faculty" <?php echo $type_filter === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Search</button>
            <?php if ($search || $status_filter || $type_filter): ?><a href="<?php echo BASE_URL; ?>/dashboard/members.php" class="mm-clear">Clear</a><?php endif; ?>
        </form>
    </div>

    <?php if ($members->num_rows > 0): ?>
        <?php if ($counts['pending'] > 0 && ($status_filter === 'pending' || $status_filter === '')): ?>
        <form method="POST" id="bulk-form" class="no-print" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap; margin:0 0 10px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="bulk_approve">
            <label style="display:flex; gap:8px; align-items:center; margin:0;"><input type="checkbox" id="pick-all-pending"> Select all pending</label>
            <button type="submit" class="btn btn-primary btn-sm">Approve selected</button>
        </form>
        <script>
        document.getElementById('pick-all-pending').addEventListener('change', function () {
            document.querySelectorAll('input[name="ids[]"][form="bulk-form"]').forEach(function (c) { c.checked = this.checked; }, this);
        });
        </script>
        <?php endif; ?>
        <div class="mm-head" aria-hidden="true"><span>Member</span><span>Role</span><span>Position</span><span>Status</span><span></span></div>
        <ul class="mm-list">
        <?php while ($m = $members->fetch_assoc()):
            $mid = (int) $m['user_id'];
            $is_fac = (($m['user_type'] ?? 'student') === 'faculty');
            $pos = $is_fac ? ($m['faculty_position'] ?? '') : ($m['committee_role'] ?? '');
            $can_role = $is_admin && $mid !== (int) $uid;
            $is_pending = !empty($m['approval_pending']);
        ?>
            <li class="mm-row">
                <div class="mm-who">
                    <?php if ($is_pending): ?><input type="checkbox" name="ids[]" value="<?php echo $mid; ?>" form="bulk-form" aria-label="Select <?php echo e(full_name($m)); ?>" class="no-print"><?php endif; ?>
                    <span class="mm-ava">
                        <?php if (!empty($m['profile_photo'])): ?>
                            <img src="<?php echo asset_v($m['profile_photo']); ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <?php echo e(initials($m['first_name'] ?? '', $m['last_name'] ?? '')); ?>
                        <?php endif; ?>
                    </span>
                    <span class="mm-who-text">
                        <span class="mm-name"><?php echo e(full_name($m)); ?> <?php if ($is_fac): ?><span class="badge">Faculty</span><?php endif; ?> <?php echo verified_badge_html($m['verified_as'] ?? 'none'); ?></span>
                        <span class="mm-sub"><?php echo e($m['student_id']); ?> &middot; <?php echo e($is_fac ? ($m['course'] ?? '') : trim(($m['course'] ?? '') . ' ' . ($m['year_level'] ?? ''))); ?></span>
                        <?php if ($is_pending): ?>
                            <span class="mm-sub"><span class="badge">Pending approval</span>
                            <?php if ($roster_total === 0): ?>No Student ID list uploaded
                            <?php elseif ((int) $m['on_roster'] > 0): ?>On the ID list<?php echo !empty($m['roster_name']) ? ' as ' . e($m['roster_name']) : ''; ?>
                            <?php else: ?>Not on the ID list<?php endif; ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="mm-cell" data-label="Role">
                    <?php if ($can_role): ?>
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="set_role">
                            <input type="hidden" name="user_id" value="<?php echo $mid; ?>">
                            <select name="role" class="mm-select" onchange="this.form.submit()" aria-label="Role for <?php echo e(full_name($m)); ?>">
                                <?php foreach (['member' => 'Member', 'officer' => 'Officer', 'moderator' => 'Moderator'] as $rk => $rl): ?>
                                    <option value="<?php echo $rk; ?>" <?php echo $m['role'] === $rk ? 'selected' : ''; ?>><?php echo $rl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    <?php else: ?>
                        <span class="badge<?php echo $m['role'] === 'member' ? ' badge-outline' : ''; ?>"><?php echo ucfirst($m['role']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="mm-cell mm-pos" data-label="Position"><?php echo $pos !== '' && $pos !== null ? e($pos) : '<span class="mm-none">&mdash;</span>'; ?></div>

                <div class="mm-cell" data-label="Status">
                    <form method="POST">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="user_id" value="<?php echo $mid; ?>">
                        <select name="status" class="mm-select mm-status-<?php echo e($m['status']); ?>" onchange="this.form.submit()" aria-label="Status for <?php echo e(full_name($m)); ?>">
                            <option value="active" <?php echo $m['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $m['status'] !== 'active' ? 'selected' : ''; ?>><?php echo ($is_fac || $is_pending) ? 'Pending' : 'Inactive'; ?></option>
                        </select>
                    </form>
                </div>

                <div class="mm-actions">
                    <?php if ($is_pending): ?>
                        <form method="POST">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="approve_signup">
                            <input type="hidden" name="user_id" value="<?php echo $mid; ?>">
                            <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                        </form>
                        <form method="POST" <?php echo confirm_attr('Decline and remove the sign-up of ' . full_name($m) . '?'); ?>>
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="decline_signup">
                            <input type="hidden" name="user_id" value="<?php echo $mid; ?>">
                            <button type="submit" class="btn btn-outline btn-sm">Decline</button>
                        </form>
                    <?php endif; ?>
                    <a href="<?php echo e(mm_url(['edit' => $mid], $base_q + ($status_filter !== '' ? ['status' => $status_filter] : []))); ?>" class="btn btn-outline btn-sm">Edit</a>
                    <?php if ($is_admin): ?>
                    <details class="mm-menu">
                        <summary aria-label="More actions for <?php echo e(full_name($m)); ?>"><span aria-hidden="true">&#8943;</span></summary>
                        <div class="mm-pop">
                            <form method="POST" class="mm-pop-form">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="set_badge">
                                <input type="hidden" name="user_id" value="<?php echo $mid; ?>">
                                <label for="vb-<?php echo $mid; ?>">Verified badge</label>
                                <select id="vb-<?php echo $mid; ?>" name="verified_as" class="mm-select" onchange="this.form.submit()">
                                    <?php foreach (verified_options($m['user_type'] ?? 'student', $m['role'] ?? 'member') as $vk => $vl): ?>
                                        <option value="<?php echo $vk; ?>" <?php echo ($m['verified_as'] ?? 'none') === $vk ? 'selected' : ''; ?>><?php echo e($vl); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php if ($mid !== (int) $uid): ?>
                            <form method="POST" <?php echo confirm_attr('Delete ' . full_name($m) . '? Their attendance and registrations will be removed. This cannot be undone.'); ?>>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="user_id" value="<?php echo $mid; ?>">
                                <button type="submit" class="mm-delete">Delete member</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>
            </li>
        <?php endwhile; ?>
        </ul>
    <?php else: ?>
        <div class="empty-state"><h3>No members found</h3><p>Try a different search or clear the filters.</p></div>
    <?php endif; ?>
</div>

<script>
// Close the "more" menu when clicking elsewhere or pressing Escape
document.addEventListener('click', function (e) {
    document.querySelectorAll('.mm-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.mm-menu[open]').forEach(function (d) { d.removeAttribute('open'); });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
