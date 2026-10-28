<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer', 'moderator']);

$year_filter = (int) ($_GET['year'] ?? 0);
$years = [];
$yr = $conn->query("SELECT DISTINCT YEAR(event_date) y FROM events WHERE event_date IS NOT NULL ORDER BY y DESC");
while ($yr && ($r = $yr->fetch_assoc())) $years[] = (int) $r['y'];
if ($year_filter && !in_array($year_filter, $years, true)) $year_filter = 0;
$ycond = $year_filter ? " AND YEAR(e.event_date) = " . $year_filter : '';

// Turnout per event (most recent first)
$turnout = $conn->query("SELECT e.event_id, e.title, e.event_date,
        SUM(a.status = 'present') AS present,
        (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id AND r.waitlisted = 0) AS registered
    FROM events e LEFT JOIN attendance a ON a.event_id = e.event_id
    WHERE e.event_date <= CURDATE() $ycond
    GROUP BY e.event_id ORDER BY e.event_date DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);
$turnout = array_reverse($turnout);   // oldest on the left

// Top attendees
$top = $conn->query("SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.suffix, u.student_id, COUNT(*) AS n
    FROM attendance a JOIN users u ON u.user_id = a.user_id JOIN events e ON e.event_id = a.event_id
    WHERE a.status = 'present' $ycond GROUP BY u.user_id ORDER BY n DESC, u.last_name LIMIT 10")->fetch_all(MYSQLI_ASSOC);

// Attendance by month
$by_month = $conn->query("SELECT DATE_FORMAT(e.event_date, '%Y-%m') ym, COUNT(*) n
    FROM attendance a JOIN events e ON e.event_id = a.event_id
    WHERE a.status = 'present' $ycond GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetch_all(MYSQLI_ASSOC);
$by_month = array_reverse($by_month);

// Totals
$tot = $conn->query("SELECT COUNT(DISTINCT e.event_id) events, COUNT(a.attendance_id) checkins, COUNT(DISTINCT a.user_id) people
    FROM events e LEFT JOIN attendance a ON a.event_id = e.event_id AND a.status = 'present'
    WHERE e.event_date <= CURDATE() $ycond")->fetch_assoc();

$max_t = max(1, ...array_map(fn($t) => max((int) $t['present'], (int) $t['registered']), $turnout ?: [['present' => 1, 'registered' => 0]]));
$max_m = max(1, ...array_map(fn($m) => (int) $m['n'], $by_month ?: [['n' => 1]]));
$max_top = max(1, ...array_map(fn($t) => (int) $t['n'], $top ?: [['n' => 1]]));

$page_title = 'Attendance Charts';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Attendance Charts</h1>
        <p class="muted">Which events had the best turnout, and who attends the most.</p>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <form method="GET" class="no-print" style="margin:0;">
            <label class="sr-only" for="yr">Year</label>
            <select id="yr" name="year" onchange="this.form.submit()">
                <option value="0">All years</option>
                <?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $y === $year_filter ? 'selected' : ''; ?>><?php echo $y; ?></option><?php endforeach; ?>
            </select>
        </form>
        <button type="button" class="btn btn-outline btn-sm print-btn no-print" onclick="window.print()"><svg class="icon" aria-hidden="true"><use href="#i-printer"/></svg>Print</button>
    </div>
</div>

<div class="stat-row">
    <div class="stat-box"><strong><?php echo (int) $tot['events']; ?></strong><span>Events held</span></div>
    <div class="stat-box"><strong><?php echo (int) $tot['checkins']; ?></strong><span>Check-ins</span></div>
    <div class="stat-box"><strong><?php echo (int) $tot['people']; ?></strong><span>Different people</span></div>
    <div class="stat-box"><strong><?php echo $tot['events'] > 0 ? round($tot['checkins'] / $tot['events'], 1) : 0; ?></strong><span>Average per event</span></div>
</div>

<div class="panel">
    <div class="panel-head"><h2>Turnout per event</h2></div>
    <?php if ($turnout): ?>
    <p class="muted" style="font-size:.85rem;">Dark bar = present, light bar = registered.</p>
    <ul class="bar-list">
        <?php foreach ($turnout as $t): ?>
            <li>
                <span class="bar-label"><?php echo e($t['title']); ?><small><?php echo e(date('M j, Y', strtotime($t['event_date']))); ?></small></span>
                <span class="bar-track" aria-hidden="true">
                    <span class="bar-fill light" style="width:<?php echo round($t['registered'] / $max_t * 100); ?>%"></span>
                    <span class="bar-fill" style="width:<?php echo round($t['present'] / $max_t * 100); ?>%"></span>
                </span>
                <span class="bar-num"><?php echo (int) $t['present']; ?> / <?php echo (int) $t['registered']; ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?><div class="empty-state"><h3>No past events yet</h3></div><?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head"><h2>Top attendees</h2></div>
    <?php if ($top): ?>
    <ul class="bar-list">
        <?php foreach ($top as $t): ?>
            <li>
                <span class="bar-label"><a href="<?php echo BASE_URL; ?>/dashboard/student_report.php?user_id=<?php echo (int) $t['user_id']; ?>"><?php echo e(full_name($t)); ?></a><small><?php echo e($t['student_id']); ?></small></span>
                <span class="bar-track" aria-hidden="true"><span class="bar-fill" style="width:<?php echo round($t['n'] / $max_top * 100); ?>%"></span></span>
                <span class="bar-num"><?php echo (int) $t['n']; ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?><div class="empty-state"><h3>No attendance yet</h3></div><?php endif; ?>
</div>

<div class="panel">
    <div class="panel-head"><h2>Check-ins by month</h2></div>
    <?php if ($by_month): ?>
    <ul class="bar-list">
        <?php foreach ($by_month as $m): ?>
            <li>
                <span class="bar-label"><?php echo e(date('F Y', strtotime($m['ym'] . '-01'))); ?></span>
                <span class="bar-track" aria-hidden="true"><span class="bar-fill" style="width:<?php echo round($m['n'] / $max_m * 100); ?>%"></span></span>
                <span class="bar-num"><?php echo (int) $m['n']; ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?><div class="empty-state"><h3>No attendance yet</h3></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
