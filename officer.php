<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$officer_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Public profile: only officers, and only fields that are already public
// in the directory (no email, student ID or password data).
$stmt = $conn->prepare("
    SELECT user_id, first_name, middle_name, last_name, suffix, course, year_level,
           status, profile_photo, cover_photo, status_note, role, committee_role, created_at, verified_as
    FROM users
    WHERE user_id = ? AND role = 'officer'
");
$stmt->bind_param('i', $officer_id);
$stmt->execute();
$officer = $stmt->get_result()->fetch_assoc();

$events_res = $ann_res = $others = null;
$event_count = $ann_count = 0;
if ($officer) {
    $es = $conn->prepare("SELECT event_id, title, event_date, event_end_date, event_time, event_end_time, venue FROM events WHERE created_by = ? ORDER BY event_date DESC LIMIT 6");
    $es->bind_param('i', $officer_id);
    $es->execute();
    $events_res = $es->get_result();

    $ec = $conn->prepare("SELECT COUNT(*) c FROM events WHERE created_by = ?");
    $ec->bind_param('i', $officer_id);
    $ec->execute();
    $event_count = (int) $ec->get_result()->fetch_assoc()['c'];

    $as = $conn->prepare("SELECT announcement_id, title, created_at FROM announcements WHERE created_by = ? ORDER BY created_at DESC LIMIT 6");
    $as->bind_param('i', $officer_id);
    $as->execute();
    $ann_res = $as->get_result();

    $ac = $conn->prepare("SELECT COUNT(*) c FROM announcements WHERE created_by = ?");
    $ac->bind_param('i', $officer_id);
    $ac->execute();
    $ann_count = (int) $ac->get_result()->fetch_assoc()['c'];

    $os = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, course, year_level, status, profile_photo, committee_role FROM users WHERE role = 'officer' AND user_id <> ? AND status = 'active' ORDER BY last_name ASC LIMIT 3");
    $os->bind_param('i', $officer_id);
    $os->execute();
    $others = $os->get_result();
}

$page_title = $officer ? full_name($officer, false) : 'Officer Not Found';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/cards.php';
require_once __DIR__ . '/includes/profile_header.php';
?>

<section class="section detail-section">
    <div class="container detail-wrap">
        <?php if (!$officer): ?>
            <div class="empty-state">
                <h2>Officer not found</h2>
                <p>This profile may no longer be listed.</p>
                <a href="<?php echo BASE_URL; ?>/officers.php" class="btn btn-primary">Officer directory</a>
            </div>
        <?php else: ?>
            <a href="<?php echo BASE_URL; ?>/officers.php" class="back-link">← All officers</a>

            <?php
            $meta_line = e($officer['committee_role'] ?: 'CSO Officer') . ' &middot; ' . e($officer['course']) . ' &middot; ' . e($officer['year_level'])
                       . ' &middot; <span class="status status-' . e($officer['status']) . '">' . e(ucfirst($officer['status'])) . '</span>';
            render_profile_header($officer, ['meta' => $meta_line]);
            ?>

            <div class="tiles">
                <div class="tile"><span class="info-label">Program</span><span class="tile-value"><?php echo e($officer['course']); ?></span></div>
                <div class="tile"><span class="info-label">Year level</span><span class="tile-value"><?php echo e($officer['year_level']); ?></span></div>
                <div class="tile"><span class="info-label">Position</span><span class="tile-value"><?php echo e($officer['committee_role'] ?: 'Officer'); ?></span></div>
                <div class="tile"><span class="info-label">With CSO since</span><span class="tile-value"><?php echo date('F Y', strtotime($officer['created_at'])); ?></span></div>
                <div class="tile"><span class="info-label">Events organized</span><span class="tile-value"><?php echo $event_count; ?></span></div>
                <div class="tile"><span class="info-label">Announcements posted</span><span class="tile-value"><?php echo $ann_count; ?></span></div>
            </div>

            <div class="detail-columns">
                <div>
                    <h2 class="detail-h2">Events organized</h2>
                    <?php if ($events_res->num_rows > 0): ?>
                        <div class="mini-list">
                            <?php while ($ev = $events_res->fetch_assoc()): ?>
                                <a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo (int) $ev['event_id']; ?>" class="mini-item">
                                    <span class="mini-date"><?php if (!empty($ev['event_date'])): ?><b><?php echo date('d', strtotime($ev['event_date'])); ?></b><?php echo date('M', strtotime($ev['event_date'])); ?><?php else: ?><b>TBA</b><?php endif; ?></span>
                                    <span class="mini-text"><strong><?php echo e($ev['title']); ?></strong><small><?php echo e(implode(' · ', array_filter([event_time_text($ev), $ev['venue']], function ($x) { return $x !== '' && $x !== null; }))); ?></small></span>
                                    <?php echo event_state_chip($ev); ?>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="muted">No events organized yet.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <h2 class="detail-h2">Announcements posted</h2>
                    <?php if ($ann_res->num_rows > 0): ?>
                        <div class="mini-list">
                            <?php while ($an = $ann_res->fetch_assoc()): ?>
                                <a href="<?php echo BASE_URL; ?>/announcement.php?id=<?php echo (int) $an['announcement_id']; ?>" class="mini-item">
                                    <span class="mini-date"><b><?php echo date('d', strtotime($an['created_at'])); ?></b><?php echo date('M', strtotime($an['created_at'])); ?></span>
                                    <span class="mini-text"><strong><?php echo e($an['title']); ?></strong><small>Posted <?php echo date('M j, Y', strtotime($an['created_at'])); ?></small></span>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <p class="muted">No announcements posted yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($others && $others->num_rows > 0): ?>
                <div class="section-head" style="margin-top:56px;"><h2>Other officers</h2><a href="<?php echo BASE_URL; ?>/officers.php">View all →</a></div>
                <div class="card-grid">
                    <?php while ($o = $others->fetch_assoc()) render_officer_card($o); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
