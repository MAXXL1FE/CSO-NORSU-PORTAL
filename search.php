<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/cards.php';

$q = trim($_GET['q'] ?? '');
$q = mb_substr($q, 0, 80);
$events = $anns = $people = [];
if (mb_strlen($q) >= 2) {
    $like = '%' . $q . '%';
    $s = $conn->prepare("SELECT event_id, title, event_date, event_end_date, venue FROM events
                         WHERE title LIKE ? OR venue LIKE ? OR description LIKE ? ORDER BY event_date DESC LIMIT 10");
    $s->bind_param('sss', $like, $like, $like);
    $s->execute();
    $events = $s->get_result()->fetch_all(MYSQLI_ASSOC);

    $s = $conn->prepare("SELECT announcement_id, title, created_at FROM announcements
                         WHERE title LIKE ? OR content LIKE ? ORDER BY created_at DESC LIMIT 10");
    $s->bind_param('ss', $like, $like);
    $s->execute();
    $anns = $s->get_result()->fetch_all(MYSQLI_ASSOC);

    // Member names are only searchable by people who are logged in.
    if (is_logged_in()) {
        $s = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, course, year_level, committee_role
                             FROM users WHERE status = 'active' AND approval_pending = 0 AND role IN ('member','officer')
                             AND (first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?) ORDER BY last_name LIMIT 10");
        $s->bind_param('sss', $like, $like, $like);
        $s->execute();
        $people = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
$total = count($events) + count($anns) + count($people);

$page_title = 'Search';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section" style="padding-top:12px;">
    <div class="container">
        <h1 class="page-title">Search</h1>
        <form method="GET" class="inline-form" role="search" style="margin-bottom:18px;">
            <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search events, announcements<?php echo is_logged_in() ? ', members' : ''; ?>" aria-label="Search the site" autofocus>
            <button type="submit" class="btn btn-primary">Search</button>
        </form>

        <?php if ($q !== '' && mb_strlen($q) < 2): ?>
            <p class="muted">Type at least 2 letters.</p>
        <?php elseif ($q !== '' && $total === 0): ?>
            <div class="empty-state"><svg class="icon empty-icon" aria-hidden="true"><use href="#i-search"/></svg><h3>No results for &ldquo;<?php echo e($q); ?>&rdquo;</h3><p>Try a different word.</p></div>
        <?php elseif ($q !== ''): ?>
            <?php if ($events): ?>
                <h2 class="cal-agenda-title">Events</h2>
                <ul class="cal-agenda">
                <?php foreach ($events as $r): ?>
                    <li><a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo (int) $r['event_id']; ?>"><strong><?php echo e($r['title']); ?></strong>
                        <span class="muted"><?php echo e(event_range_text($r)); ?> &middot; <?php echo e($r['venue']); ?></span></a></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($anns): ?>
                <h2 class="cal-agenda-title">Announcements</h2>
                <ul class="cal-agenda">
                <?php foreach ($anns as $r): ?>
                    <li><a href="<?php echo BASE_URL; ?>/announcement.php?id=<?php echo (int) $r['announcement_id']; ?>"><strong><?php echo e($r['title']); ?></strong>
                        <span class="muted"><?php echo e(date('F j, Y', strtotime($r['created_at']))); ?></span></a></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($people): ?>
                <h2 class="cal-agenda-title">Members</h2>
                <ul class="cal-agenda">
                <?php foreach ($people as $r): ?>
                    <li><a href="<?php echo BASE_URL; ?>/members.php?q=<?php echo urlencode($r['last_name']); ?>"><strong><?php echo e(full_name($r)); ?></strong>
                        <span class="muted"><?php echo e(trim($r['course'] . ' ' . $r['year_level'])); ?><?php echo !empty($r['committee_role']) ? ' &middot; ' . e($r['committee_role']) : ''; ?></span></a></li>
                <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php else: ?>
            <p class="muted">Search events, announcements<?php echo is_logged_in() ? ' and members' : ''; ?>. <?php echo is_logged_in() ? '' : 'Log in to search members too.'; ?></p>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
