<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$show   = $_GET['show'] ?? 'upcoming';
if (!in_array($show, ['upcoming', 'past', 'all'], true)) $show = 'upcoming';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$like   = '%' . $search . '%';

/* Optional date range: events that overlap the days between "from" and "to". */
function events_clean_date($v) {
    $v = trim((string) $v);
    return (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) ? $v : '';
}
$from = events_clean_date($_GET['from'] ?? '');
$to   = events_clean_date($_GET['to'] ?? '');
$date_note = ($from && $to && $to < $from) ? 'The end date is before the start date, so no events can match.' : '';

$where = '(e.title LIKE ? OR e.venue LIKE ? OR e.description LIKE ?)';
$bind_types = 'sss';
$bind_vals  = [$like, $like, $like];
if ($from) { $where .= ' AND COALESCE(e.event_end_date, e.event_date) >= ?'; $bind_types .= 's'; $bind_vals[] = $from; }
if ($to)   { $where .= ' AND e.event_date <= ?';                            $bind_types .= 's'; $bind_vals[] = $to; }
if ($show === 'upcoming') {
    $where .= ' AND (e.event_date IS NULL OR COALESCE(e.event_end_date, e.event_date) >= CURDATE())';
    $order = 'e.event_date IS NULL, e.event_date ASC, e.event_time ASC';
} elseif ($show === 'past') {
    $where .= ' AND COALESCE(e.event_end_date, e.event_date) < CURDATE()';
    $order = 'e.event_date DESC, e.event_time DESC';
} else {
    $order = 'e.event_date IS NULL DESC, e.event_date DESC, e.event_time DESC';
}

$stmt = $conn->prepare("
    SELECT e.*, " . post_author_select() . ",
           (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id) AS reg_count
    FROM events e
    LEFT JOIN users u ON u.user_id = e.created_by
    WHERE $where
    ORDER BY $order
");
$stmt->bind_param($bind_types, ...$bind_vals);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$img_map = post_images_map($conn, 'event', array_column($rows, 'event_id'));

$page_title = 'Events';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/cards.php';

function events_tab_url($show, $search) {
    global $from, $to;
    return BASE_URL . '/events.php?show=' . $show . ($search !== '' ? '&search=' . urlencode($search) : '')
         . ($from ? '&from=' . urlencode($from) : '') . ($to ? '&to=' . urlencode($to) : '');
}
?>

<?php
/* One tile per event, like the "Major Events & Series" grid. */
function events_tile($row) {
    $fb = !empty($row['facebook_url']);
    $desc = trim((string) ($row['description'] ?? '')) ?: ($fb ? trim((string) ($row['fb_description'] ?? '')) : '');
    $excerpt = mb_strlen($desc) > 150 ? mb_substr($desc, 0, 150) . '…' : $desc;
    $tile_img = !empty($row['cover_image']) ? $row['cover_image'] : ($fb ? ($row['fb_image'] ?? '') : '');
    ?>
    <a href="<?php echo $fb ? e($row['facebook_url']) : BASE_URL . '/event.php?id=' . (int) $row['event_id']; ?>"<?php echo $fb ? ' target="_blank" rel="noopener noreferrer"' : ''; ?> class="event-tile">
        <?php if (!empty($tile_img)): ?>
            <img src="<?php echo asset_v($tile_img); ?>" alt="" class="event-tile-img" loading="lazy">
        <?php else: ?>
            <span class="event-tile-img event-tile-img-empty" aria-hidden="true"><svg class="icon"><use href="#i-calendar"/></svg></span>
        <?php endif; ?>
        <span class="event-tile-body">
            <span class="event-tile-title"><?php echo e($row['title']); ?></span>
            <span class="event-tile-meta"><?php echo e(event_range_text($row)); ?><?php echo $fb ? (event_time_text($row) !== '' ? ' · ' . e(event_time_text($row)) : '') : ($row['venue'] ? ' · ' . e($row['venue']) : ''); ?></span>
            <?php if ($excerpt !== ''): ?><span class="event-tile-desc"><?php echo e($excerpt); ?></span><?php endif; ?>
        </span>
    </a>
    <?php
}
?>

<nav class="crumbs" aria-label="Breadcrumb">
    <a href="<?php echo BASE_URL; ?>/home.php" aria-label="Home"><svg class="icon" aria-hidden="true"><use href="#i-home"/></svg></a>
    <span aria-hidden="true">&rsaquo;</span>
    <span aria-current="page">Events</span>
</nav>

<section class="section" style="padding-top:12px;">
    <div class="container">
        <h1 class="page-title">Events</h1>

        <h2 class="rule-heading"><span>Major Events &amp; Series</span></h2>

        <div class="list-controls">
            <div class="tabs" role="tablist">
                <?php foreach (['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'] as $key => $label): ?>
                    <a href="<?php echo e(events_tab_url($key, $search)); ?>" class="tab<?php echo $show === $key ? ' active' : ''; ?>" role="tab" aria-selected="<?php echo $show === $key ? 'true' : 'false'; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
            <form method="GET" class="inline-form" style="margin:0;">
                <input type="hidden" name="show" value="<?php echo e($show); ?>">
                <input type="text" name="search" placeholder="Search events" value="<?php echo e($search); ?>" aria-label="Search events">
                <label class="filter-date"><span>From</span><input type="date" name="from" value="<?php echo e($from); ?>"></label>
                <label class="filter-date"><span>To</span><input type="date" name="to" value="<?php echo e($to); ?>"></label>
                <button type="submit" class="btn btn-outline">Filter</button>
                <?php if ($search !== '' || $from || $to): ?><a href="<?php echo BASE_URL; ?>/events.php?show=<?php echo e($show); ?>" class="btn btn-outline">Clear</a><?php endif; ?>
                <a href="<?php echo BASE_URL; ?>/calendar.php" class="btn btn-outline">Calendar</a>
            </form>
        </div>
        <?php if ($date_note): ?><p class="muted" role="status"><?php echo e($date_note); ?></p><?php endif; ?>

        <?php if ($rows): ?>
            <div class="event-grid">
                <?php foreach ($rows as $row) events_tile($row); ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <svg class="icon empty-icon" aria-hidden="true"><use href="#i-calendar"/></svg>
                <h3>No <?php echo $show === 'all' ? '' : e($show) . ' '; ?>events found</h3>
                <p><?php echo ($search !== '' || $from || $to) ? 'Try a different word or date range.' : 'Check back soon for new events.'; ?></p>
                <?php if ($search !== '' || $from || $to): ?><a href="<?php echo BASE_URL; ?>/events.php?show=<?php echo e($show); ?>" class="btn btn-outline btn-sm">Clear filters</a><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
