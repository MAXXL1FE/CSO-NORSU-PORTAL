<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer']);

$is_admin = $_SESSION['role'] === 'admin';
$uid = $_SESSION['user_id'];

// ---------------------------------------------------------
// Handle create / update / delete
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $student_id = clean($_POST['student_id']);
        $first_name = clean($_POST['first_name']);
        $last_name  = clean($_POST['last_name']);
        $email      = clean($_POST['email']);
        $course     = clean($_POST['course']);
        $year_level = clean($_POST['year_level']);
        $password   = $_POST['password'];

        if (empty($student_id) || empty($first_name) || empty($last_name) || empty($email) || empty($course) || empty($year_level) || strlen($password) < 8) {
            set_flash('error', 'Please fill in all fields — password must be at least 8 characters.');
        } else {
            $check = $conn->prepare("SELECT user_id FROM users WHERE student_id = ? OR email = ?");
            $check->bind_param('ss', $student_id, $email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                set_flash('error', 'A member with this Student ID or email already exists.');
            } else {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (student_id, first_name, last_name, email, password, course, year_level, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'member', 'active')");
                $stmt->bind_param('sssssss', $student_id, $first_name, $last_name, $email, $hashed, $course, $year_level);
                $stmt->execute();
                set_flash('success', 'Member added successfully.');
            }
        }
    }

    if ($action === 'update') {
        $edit_id    = (int) $_POST['user_id'];
        $first_name = clean($_POST['first_name']);
        $last_name  = clean($_POST['last_name']);
        $email      = clean($_POST['email']);
        $course     = clean($_POST['course']);
        $year_level = clean($_POST['year_level']);
        $status     = $_POST['status'] === 'inactive' ? 'inactive' : 'active';

        // Only admins may change role or committee/position; officers editing keep the existing values
        if ($is_admin && in_array($_POST['role'] ?? '', ['member', 'officer'], true)) {
            $role = $_POST['role'];
            $committee_role = clean($_POST['committee_role'] ?? '');
            $committee_role = $committee_role !== '' ? $committee_role : null;
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, course=?, year_level=?, status=?, role=?, committee_role=? WHERE user_id=?");
            $stmt->bind_param('ssssssssi', $first_name, $last_name, $email, $course, $year_level, $status, $role, $committee_role, $edit_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, course=?, year_level=?, status=? WHERE user_id=? AND role != 'admin'");
            $stmt->bind_param('ssssssi', $first_name, $last_name, $email, $course, $year_level, $status, $edit_id);
        }
        $stmt->execute();
        set_flash('success', 'Member details updated.');
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

    header('Location: ' . BASE_URL . '/dashboard/members.php' . (!empty($_GET['search']) ? '?search=' . urlencode($_GET['search']) : ''));
    exit;
}

// ---------------------------------------------------------
// List with search + status filter
// ---------------------------------------------------------
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) && in_array($_GET['status'], ['active', 'inactive'], true) ? $_GET['status'] : '';
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$where = "role IN ('member','officer')";
$params = [];
$types = '';
if ($search !== '') {
    $where .= " AND (first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
    $types .= 'sss';
}
if ($status_filter !== '') {
    $where .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

$sql = "SELECT * FROM users WHERE $where ORDER BY role ASC, last_name ASC";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$members = $stmt->get_result();

$edit_row = null;
if ($edit_id > 0) {
    $es = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND role IN ('member','officer')");
    $es->bind_param('i', $edit_id);
    $es->execute();
    $edit_row = $es->get_result()->fetch_assoc();
}

$page_title = 'Members';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Members</h1>
        <p class="muted">Manage CSO membership records<?php echo $is_admin ? '' : ' — you can add and edit, admins handle deletions and role changes'; ?>.</p>
    </div>
</div>

<?php if ($edit_row): ?>
<div class="edit-panel">
    <h3>Edit <?php echo htmlspecialchars($edit_row['first_name'] . ' ' . $edit_row['last_name']); ?></h3>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" value="<?php echo $edit_row['user_id']; ?>">
        <div class="form-row form-row-split">
            <div><label>First Name</label><input type="text" name="first_name" value="<?php echo htmlspecialchars($edit_row['first_name']); ?>" required></div>
            <div><label>Last Name</label><input type="text" name="last_name" value="<?php echo htmlspecialchars($edit_row['last_name']); ?>" required></div>
        </div>
        <div class="form-row"><label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($edit_row['email']); ?>" required></div>
        <div class="form-row form-row-split">
            <div><label>Course</label><input type="text" name="course" value="<?php echo htmlspecialchars($edit_row['course']); ?>" required></div>
            <div>
                <label>Year Level</label>
                <select name="year_level" required>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo $edit_row['year_level'] === $yr ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row form-row-split">
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="active" <?php echo $edit_row['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $edit_row['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <?php if ($is_admin): ?>
            <div>
                <label>Role</label>
                <select name="role">
                    <option value="member" <?php echo $edit_row['role'] === 'member' ? 'selected' : ''; ?>>Member</option>
                    <option value="officer" <?php echo $edit_row['role'] === 'officer' ? 'selected' : ''; ?>>Officer</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($is_admin): ?>
        <div class="form-row">
            <label>Committee / Position</label>
            <input type="text" name="committee_role" value="<?php echo htmlspecialchars($edit_row['committee_role'] ?? ''); ?>" placeholder="e.g. Vice President, Events Committee Head — leave blank if none">
            <p class="muted" style="font-size:0.78rem; margin:6px 0 0;">Shown in the Officer Directory and on their dashboard. Type any committee or position name you like.</p>
        </div>
        <?php endif; ?>
        <div class="table-actions">
            <button type="submit" class="btn btn-primary">Save Changes</button>
            <a href="<?php echo BASE_URL; ?>/dashboard/members.php" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2>Add Member</h2></div>
    <form method="POST">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="create">
        <div class="form-row form-row-split">
            <div><label>Student ID</label><input type="text" name="student_id" placeholder="e.g. 2026-001" required></div>
            <div><label>Email</label><input type="email" name="email" required></div>
        </div>
        <div class="form-row form-row-split">
            <div><label>First Name</label><input type="text" name="first_name" required></div>
            <div><label>Last Name</label><input type="text" name="last_name" required></div>
        </div>
        <div class="form-row form-row-split">
            <div><label>Course</label><input type="text" name="course" placeholder="e.g. BSCS" required></div>
            <div>
                <label>Year Level</label>
                <select name="year_level" required>
                    <option value="">Select</option>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yr): ?>
                        <option value="<?php echo $yr; ?>"><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row"><label>Temporary Password</label><input type="password" name="password" minlength="8" required></div>
        <button type="submit" class="btn btn-primary">Add Member</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head"><h2>All Members</h2></div>
    <form method="GET" class="toolbar">
        <input type="text" name="search" placeholder="Search by name or student ID" value="<?php echo htmlspecialchars($search); ?>">
        <select name="status" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">Search</button>
        <?php if ($search || $status_filter): ?><a href="<?php echo BASE_URL; ?>/dashboard/members.php" class="btn btn-ghost btn-sm" style="color:var(--muted); border-color:var(--line);">Clear</a><?php endif; ?>
    </form>

    <?php if ($members->num_rows > 0): ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Name</th><th>Student ID</th><th>Course / Year</th><th>Role</th><th>Committee / Position</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php while ($m = $members->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></td>
                        <td><?php echo htmlspecialchars($m['student_id']); ?></td>
                        <td><?php echo htmlspecialchars($m['course'] . ' — ' . $m['year_level']); ?></td>
                        <td><span class="badge<?php echo $m['role'] === 'member' ? ' badge-outline' : ''; ?>"><?php echo ucfirst($m['role']); ?></span></td>
                        <td class="muted"><?php echo !empty($m['committee_role']) ? htmlspecialchars($m['committee_role']) : '—'; ?></td>
                        <td><span class="status status-<?php echo $m['status']; ?>"><?php echo ucfirst($m['status']); ?></span></td>
                        <td>
                            <div class="table-actions">
                                <a href="<?php echo BASE_URL; ?>/dashboard/members.php?edit=<?php echo $m['user_id']; ?>" class="btn btn-outline btn-sm">Edit</a>
                                <?php if ($is_admin && $m['user_id'] !== $uid): ?>
                                <form method="POST" onsubmit="return confirm('Remove this member? This cannot be undone.');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo $m['user_id']; ?>">
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
        <div class="empty-state"><h3>No members found</h3><p>Try a different search or clear the filters.</p></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
