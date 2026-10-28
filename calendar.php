<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

// Month shown: ?m=2026-10 (defaults to this month)
$m = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $m, $mm) || (int) $mm[2] < 1 || (int) $mm[2] > 12) $m = date('Y-m');
$year  = (int) substr($m, 0, 4);
$month = (int) substr($m, 5, 2);
$first = sprintf('%04d-%02d-01', $year, $month);
$days_in_month = (int) date('t', strtotime($first));
$last  = sprintf('%04d-%02d-%02d', $year, $month, $days_in_month);
$start_dow = (int) date('w', strtotime($first));   // 0 = Sunday
$prev_m = date('Y-m', strtotime($first . ' -1 month'));
$next_m = date('Y-m', strtotime($first . ' +1 month'));

$stmt = $conn->prepare("SELECT event_id, title, event_date, event_end_date, event_time, venue
                        FROM events WHERE event_date <= ? AND COALESCE(event_end_date, event_date) >= ?
                        ORDER BY event_date, event_time");
$stmt->bind_param('ss', $last, $first);
$stmt->execute();
$events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$by_day = [];
foreach ($events as $ev) {
    $s = max($ev['event_date'], $first);
    $e = min($ev['event_end_date'] ?: $ev['event_date'], $last);
    for ($d = strtotime($s); $d <= strtotime($e); $d += 86400) {
        $by_day[(int) date('j', $d)][] = $ev;
    }
}
$today = date('Y-m-d');

$page_title = 'Event Calendar';
require_once __DIR__ . '/includes/header.php';
?>
<nav class="crumbs" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/home.php" aria-label="Home"><svg class="icon" aria-hidden="true"><use href="#i-home"/></svg></a>
    <span aria-hidden="true">&rsaquo;</span>
    <a href="<?php echo BASE_URL; ?>/events.php">Events</a>
    <span aria-hidden="true">&rsaquo;</span>
    <span aria-current="page">Calendar</span>
</nav>

<section class="section" style="padding-top:12px;">
    <div class="container">
        <h1 class="page-title">Event Calendar</h1>
        <div class="cal-nav">
            <a class="btn btn-outline btn-sm" href="?m=<?php echo e($prev_m); ?>" aria-label="Previous month">&larr; <?php echo e(date('M', strtotime($prev_m . '-01'))); ?></a>
            <h2 class="cal-title"><?php echo e(date('F Y', strtotime($first))); ?></h2>
            <a class="btn btn-outline btn-sm" href="?m=<?php echo e($next_m); ?>" aria-label="Next month"><?php echo e(date('M', strtotime($next_m . '-01'))); ?> &rarr;</a>
            <?php if ($m !== date('Y-m')): ?><a class="btn btn-outline btn-sm" href="?m=<?php echo e(date('Y-m')); ?>">Today</a><?php endif; ?>
            <a class="btn btn-outline btn-sm" href="<?php echo BASE_URL; ?>/events.php">List view</a>
        </div>

        <div class="cal-grid" role="grid" aria-label="<?php echo e(date('F Y', strtotime($first))); ?>">
            <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dn): ?><div class="cal-dow" role="columnheader"><?php echo $dn; ?></div><?php endforeach; ?>
            <?php for ($i = 0; $i < $start_dow; $i++): ?><div class="cal-cell cal-empty" aria-hidden="true"></div><?php endfor; ?>
            <?php for ($d = 1; $d <= $days_in_month; $d++):
                $iso = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $list = $by_day[$d] ?? [];
            ?>
                <div class="cal-cell<?php echo $iso === $today ? ' is-today' : ''; ?>" role="gridcell">
                    <span class="cal-day"><?php echo $d; ?></span>
                    <?php foreach (array_slice($list, 0, 3) as $ev): ?>
                        <a class="cal-ev" href="<?php echo BASE_URL; ?>/event.php?id=<?php echo (int) $ev['event_id']; ?>" title="<?php echo e($ev['title']); ?>"><?php echo e($ev['title']); ?></a>
                    <?php endforeach; ?>
                    <?php if (count($list) > 3): ?><span class="cal-more">+<?php echo count($list) - 3; ?> more</span><?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>

        <h2 class="cal-agenda-title">This month</h2>
        <?php if ($events): ?>
            <ul class="cal-agenda">
                <?php foreach ($events as $ev): ?>
                    <li>
                        <a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo (int) $ev['event_id']; ?>">
                            <strong><?php echo e($ev['title']); ?></strong>
                            <span class="muted"><?php echo e(date('M j', strtotime($ev['event_date']))); ?><?php echo ($ev['event_end_date'] && $ev['event_end_date'] !== $ev['event_date']) ? ' – ' . e(date('M j', strtotime($ev['event_end_date']))) : ''; ?>
                                <?php echo $ev['event_time'] ? '&middot; ' . e(date('g:i A', strtotime($ev['event_time']))) : ''; ?> <?php echo $ev['venue'] !== '' ? '&middot; ' . e($ev['venue']) : ''; ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="empty-state">
                <svg class="icon empty-icon" aria-hidden="true"><use href="#i-calendar"/></svg>
                <h3>No events this month</h3>
                <p>Try another month with the arrows above.</p>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
