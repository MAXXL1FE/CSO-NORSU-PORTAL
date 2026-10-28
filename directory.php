<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

/* Public Faculty & Staff Directory: approved faculty, with search and filters. */

$q    = trim($_GET['q'] ?? '');
$unit = trim($_GET['unit'] ?? '');
$type = $_GET['type'] ?? '';

$employee_types = ['adviser' => 'Adviser', 'instructor' => 'Instructor', 'professor' => 'Professor', 'other' => 'Other'];
if (!isset($employee_types[$type])) $type = '';

// Unit options come from the departments that approved faculty have entered.
$units = [];
$res = $conn->query("SELECT DISTINCT course FROM users WHERE user_type = 'faculty' AND status = 'active' AND course <> '' ORDER BY course");
while ($r = $res->fetch_assoc()) $units[] = $r['course'];
if ($unit !== '' && !in_array($unit, $units, true)) $unit = '';

$where  = ["user_type = 'faculty'", "status = 'active'"];
$params = [];
$bind   = '';

if ($q !== '') {
    $where[] = "(first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR faculty_position LIKE ? OR course LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $bind .= 'sssss';
}
if ($unit !== '') {
    $where[] = 'course = ?';
    $params[] = $unit;
    $bind .= 's';
}
$type_sql = [
    'adviser'    => "faculty_position LIKE '%Adviser%'",
    'instructor' => "faculty_position LIKE '%Instructor%'",
    'professor'  => "faculty_position LIKE '%Professor%'",
    'other'      => "(faculty_position IS NULL OR faculty_position = '' OR (faculty_position NOT LIKE '%Adviser%' AND faculty_position NOT LIKE '%Instructor%' AND faculty_position NOT LIKE '%Professor%'))",
];
if ($type !== '') $where[] = $type_sql[$type];

$sql = "SELECT user_id, first_name, middle_name, last_name, suffix, course, faculty_position, profile_photo, verified_as
        FROM users WHERE " . implode(' AND ', $where) . " ORDER BY last_name, first_name";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($bind, ...$params);
$stmt->execute();
$people = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$page_title = 'Faculty & Staff Directory';
require_once __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Faculty &amp; Staff Directory</h1>
        <p class="muted">Advisers, instructors and professors who work with CSO.</p>
    </div>
</div>

<form method="GET" class="panel dir-filters" role="search">
    <div class="dir-filter-grid">
        <div>
            <label for="dir-q">Search by keyword</label>
            <input type="search" id="dir-q" name="q" value="<?php echo e($q); ?>" placeholder="Name, position or department">
        </div>
        <div>
            <label for="dir-unit">Unit</label>
            <select id="dir-unit" name="unit">
                <option value="">Any</option>
                <?php foreach ($units as $u): ?>
                    <option value="<?php echo e($u); ?>" <?php echo $unit === $u ? 'selected' : ''; ?>><?php echo e($u); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="dir-type">Employee type</label>
            <select id="dir-type" name="type">
                <option value="">Any</option>
                <?php foreach ($employee_types as $k => $label): ?>
                    <option value="<?php echo $k; ?>" <?php echo $type === $k ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="dir-filter-actions">
        <button type="submit" class="btn btn-primary">Apply filters</button>
        <a href="<?php echo BASE_URL; ?>/directory.php" class="btn btn-outline">Reset</a>
    </div>
</form>

<p class="muted dir-count" aria-live="polite"><?php echo count($people); ?> <?php echo count($people) === 1 ? 'person' : 'people'; ?></p>

<?php if (!$people): ?>
    <div class="card dir-empty">
        <p>No one matches these filters. <a href="<?php echo BASE_URL; ?>/directory.php">Show everyone</a>.</p>
    </div>
<?php else: ?>
    <ul class="dir-grid">
        <?php foreach ($people as $p): ?>
            <li class="dir-card">
                <div class="dir-avatar" aria-hidden="true">
                    <span class="dir-initials"><?php echo e(initials($p['first_name'], $p['last_name'])); ?></span>
                    <?php if (!empty($p['profile_photo'])): ?>
                        <img src="<?php echo asset_v($p['profile_photo']); ?>" alt="" loading="lazy" onerror="this.remove()">
                    <?php endif; ?>
                </div>
                <div class="dir-info">
                    <h2 class="dir-name"><?php echo e(full_name($p)); ?></h2>
                    <?php echo verified_badge_html($p['verified_as'] ?? 'none'); ?>
                    <?php if (!empty($p['faculty_position'])): ?>
                        <p class="dir-position"><em><?php echo e($p['faculty_position']); ?></em></p>
                    <?php endif; ?>
                    <?php if (!empty($p['course'])): ?>
                        <p class="dir-unit"><?php echo e($p['course']); ?></p>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
