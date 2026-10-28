<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer', 'moderator']);

$q = trim($_GET['q'] ?? '');
$uid = (int) ($_GET['user_id'] ?? 0);

$matches = null;
if ($q !== '') {
    // Matches a name (first, middle, last, or the full name typed in any order of words), the Student/Employee ID,
    // or the numeric account number. Pending sign-ups and moderators are included and labelled in the list.
    $like = '%' . $q . '%';
    $words = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
    $sql = "SELECT user_id, student_id, first_name, middle_name, last_name, suffix, course, year_level, role, approval_pending, status
            FROM users WHERE role IN ('member','officer','moderator')
            AND (student_id LIKE ? OR user_id = ?";
    $types = 'si'; $uidq = ctype_digit($q) ? (int) $q : 0; $params = [$like, $uidq];
    // every typed word must appear somewhere in the full name
    $nameSql = [];
    foreach ($words as $w) { $nameSql[] = "CONCAT_WS(' ', first_name, middle_name, last_name, suffix) LIKE ?"; $types .= 's'; $params[] = '%' . $w . '%'; }
    if ($nameSql) $sql .= ' OR (' . implode(' AND ', $nameSql) . ')';
    $sql .= ") ORDER BY approval_pending ASC, last_name, first_name LIMIT 25";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$student = null; $rows = []; $held = 0; $present = 0;
if ($uid > 0) {
    $s = $conn->prepare("SELECT * FROM users WHERE user_id = ? AND role IN ('member','officer','moderator')");
    $s->bind_param('i', $uid);
    $s->execute();
    $student = $s->get_result()->fetch_assoc();
    if ($student) {
        $s = $conn->prepare("SELECT e.event_id, e.title, e.event_date, e.venue, a.status, a.checked_in_at, a.time_out
                             FROM attendance a JOIN events e ON e.event_id = a.event_id WHERE a.user_id = ? ORDER BY e.event_date DESC");
        $s->bind_param('i', $uid);
        $s->execute();
        $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) if ($r['status'] === 'present') $present++;
        // Events held since this person joined (and already started)
        $s = $conn->prepare("SELECT COUNT(*) c FROM events WHERE event_date <= CURDATE() AND event_date >= DATE(?)");
        $s->bind_param('s', $student['created_at']);
        $s->execute();
        $held = (int) $s->get_result()->fetch_assoc()['c'];
    }
}
$rate = $held > 0 ? min(100, round($present / $held * 100)) : 0;

$page_title = 'Student Attendance Report';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Student Attendance Report</h1>
        <p class="muted">Find a student, see every event they attended, and print the report or a certificate.</p>
    </div>
    <?php if ($student): ?><button type="button" class="btn btn-outline btn-sm print-btn no-print" onclick="window.print()"><svg class="icon" aria-hidden="true"><use href="#i-printer"/></svg>Print</button><?php endif; ?>
</div>

<div class="panel no-print">
    <form method="GET" class="inline-form" role="search">
        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search name or Student ID" aria-label="Search name or Student ID">
        <button type="submit" class="btn btn-primary">Find</button>
    </form>
    <?php if ($matches !== null): ?>
        <?php if ($matches): ?>
            <ul class="cal-agenda" style="margin-top:12px;">
                <?php foreach ($matches as $m): ?>
                    <li><a href="?user_id=<?php echo (int) $m['user_id']; ?>"><strong><?php echo e(full_name($m)); ?></strong>
                        <span class="muted"><?php echo e($m['student_id']); ?> &middot; <?php echo e(trim($m['course'] . ' ' . $m['year_level'])); ?><?php
                            if (!empty($m['approval_pending'])) echo ' &middot; <em>sign-up still waiting for approval</em>';
                            elseif ($m['status'] === 'inactive') echo ' &middot; inactive';
                            elseif ($m['role'] !== 'member') echo ' &middot; ' . e(ucfirst($m['role'])); ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?><p class="muted" style="margin-top:12px;">No student found. Search by name, Student ID or account number. Admins are not listed.</p><?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($student): ?>
<div class="panel">
    <div class="panel-head"><h2><?php echo e(full_name($student)); ?></h2></div>
    <p class="muted"><?php echo e($student['student_id']); ?> &middot; <?php echo e(trim($student['course'] . ' ' . $student['year_level'])); ?> &middot; Report date <?php echo date('F j, Y'); ?></p>
    <p><strong><?php echo $present; ?></strong> event<?php echo $present === 1 ? '' : 's'; ?> attended
       <?php if ($held > 0): ?> out of <strong><?php echo $held; ?></strong> held since joining (<strong><?php echo $rate; ?>%</strong>)<?php endif; ?>.</p>
    <?php if ($rows): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th scope="col">Event</th><th scope="col">Date</th><th scope="col">Venue</th><th scope="col">Time in</th><th scope="col">Time out</th><th scope="col">Status</th><th scope="col" class="no-print"><span class="sr-only">Certificate</span></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['title']); ?></td>
                    <td><?php echo e(fmt_date($r['event_date'])); ?></td>
                    <td><?php echo e($r['venue']); ?></td>
                    <td><?php echo e(date('g:i A', strtotime($r['checked_in_at']))); ?></td>
                    <td><?php echo $r['time_out'] ? e(date('g:i A', strtotime($r['time_out']))) : '&mdash;'; ?></td>
                    <td><?php echo $r['status'] === 'present' ? 'Present' : 'Absent'; ?></td>
                    <td class="no-print"><?php if ($r['status'] === 'present' && $r['event_date'] <= date('Y-m-d')): ?>
                        <a class="btn btn-outline btn-sm" target="_blank" rel="noopener" href="<?php echo BASE_URL; ?>/certificate.php?event_id=<?php echo (int) $r['event_id']; ?>&user_id=<?php echo (int) $student['user_id']; ?>">Certificate</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?><p class="muted">No attendance records yet.</p><?php endif; ?>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
