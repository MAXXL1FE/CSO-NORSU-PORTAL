<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/features.php';

$event_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$message = '';

// Handle register / unregister (members only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && is_logged_in()) {
    csrf_check();
    $user_id = $_SESSION['user_id'];
    if ($_POST['action'] === 'register') {
        $outcome = event_register($conn, $event_id, $user_id);
        $message = $outcome === 'waitlisted'
            ? "This event is full, so you were added to the waitlist. We will tell you if a spot opens up."
            : "You're registered for this event.";
    } elseif ($_POST['action'] === 'unregister') {
        $stmt = $conn->prepare("DELETE FROM event_registrations WHERE event_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $event_id, $user_id);
        $stmt->execute();
        event_promote_waitlist($conn, $event_id);
        $message = 'Registration cancelled.';
    }
}

$stmt = $conn->prepare("
    SELECT e.*, u.user_id AS organizer_id, u.first_name AS organizer_first, u.last_name AS organizer_last,
           u.role AS organizer_role, u.committee_role AS organizer_committee,
           (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id AND r.waitlisted = 0) AS registration_count,
           (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id AND r.waitlisted = 1) AS waitlist_count
    FROM events e
    LEFT JOIN users u ON u.user_id = e.created_by
    WHERE e.event_id = ?
");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();

$is_registered = false;
$on_waitlist = false;
if ($event && is_logged_in()) {
    $chk = $conn->prepare("SELECT registration_id, waitlisted FROM event_registrations WHERE event_id = ? AND user_id = ?");
    $chk->bind_param('ii', $event_id, $_SESSION['user_id']);
    $chk->execute();
    $my_reg = $chk->get_result()->fetch_assoc();
    $is_registered = (bool) $my_reg;
    $on_waitlist = $my_reg && (int) $my_reg['waitlisted'] === 1;
}

$photos_res = null;
$more_events = null;
if ($event) {
    $photos = $conn->prepare("SELECT image_path, caption FROM event_photos WHERE event_id = ? ORDER BY uploaded_at DESC");
    $photos->bind_param('i', $event_id);
    $photos->execute();
    $photos_res = $photos->get_result();

    $more = $conn->prepare("SELECT event_id, title, description, event_date, event_end_date, event_time, event_end_time, venue, cover_image, facebook_url, fb_image, fb_description FROM events WHERE event_id <> ? ORDER BY ABS(DATEDIFF(event_date, CURDATE())) ASC LIMIT 3");
    $more->bind_param('i', $event_id);
    $more->execute();
    $more_events = $more->get_result();
}

$page_title = $event ? $event['title'] : 'Event Not Found';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/cards.php';
?>

<section class="section detail-section">
    <div class="container detail-wrap">
        <?php if (!$event): ?>
            <div class="empty-state">
                <h2>Event not found</h2>
                <p>This event may have been removed.</p>
                <a href="<?php echo BASE_URL; ?>/events.php" class="btn btn-primary">Browse all events</a>
            </div>
        <?php else: ?>
            <?php
            list($start_ts, $end_ts) = event_timestamps($event);
            $state = event_state($event);
            $organizer_name = trim(($event['organizer_first'] ?? '') . ' ' . ($event['organizer_last'] ?? ''));
            $has_date = !empty($event['event_date']);
            $cal_url = !$has_date ? '' : 'https://calendar.google.com/calendar/render?action=TEMPLATE'
                . '&text=' . urlencode($event['title'])
                . '&dates=' . date('Ymd\THis', $start_ts) . '/' . date('Ymd\THis', $end_ts)
                . '&location=' . urlencode($event['venue'])
                . '&details=' . urlencode((string) ($event['description'] ?? ''));
            ?>
            <a href="<?php echo BASE_URL; ?>/events.php" class="back-link">← All events</a>
            <?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>

            <div class="detail-layout">
                <article class="detail-main">
                    <?php $post_imgs = post_images_for($conn, 'event', (int) $event['event_id'], $event['cover_image'] ?? null);
                          if (!$post_imgs && !empty($event['fb_image'])) $post_imgs = [['id' => 0, 'path' => $event['fb_image']]]; ?>
                    <?php if ($post_imgs): ?>
                        <div class="detail-gallery"><?php render_post_gallery($post_imgs, $event['title']); ?></div>
                    <?php else: ?>
                        <div class="detail-cover detail-cover-empty"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg></div>
                    <?php endif; ?>

                    <div class="detail-chips">
                        <?php echo event_state_chip($event); ?>
                        <?php if ($state === 'upcoming'): ?><span class="chip chip-muted"><?php echo e(event_relative_text($event)); ?></span><?php endif; ?>
                        <?php if ($is_registered): ?><span class="chip"><?php echo $on_waitlist ? "You're on the waitlist" : "You're registered"; ?></span><?php endif; ?>
                    </div>
                    <h1 class="detail-title"><?php echo e($event['title']); ?></h1>
                    <p class="detail-meta">
                        <?php echo e(implode(' · ', array_filter([event_range_text($event), event_time_text($event), $event['venue']], function ($x) { return $x !== '' && $x !== null; }))); ?>
                    </p>

                    <h2 class="detail-h2">About this event</h2>
                    <div class="prose">
                        <?php $about = trim((string) $event['description']) !== '' ? $event['description'] : (string) ($event['fb_description'] ?? ''); ?>
                        <?php if (trim($about) !== ''): ?>
                            <p><?php echo nl2br(e($about)); ?></p>
                        <?php else: ?>
                            <p class="muted">The organizers haven't added a description for this event yet.</p>
                        <?php endif; ?>
                    </div>

                    <?php if ($photos_res && $photos_res->num_rows > 0): ?>
                        <h2 class="detail-h2">Event photos <span class="muted" style="font-size:.9rem; font-weight:500;">(<?php echo $photos_res->num_rows; ?>)</span></h2>
                        <div class="photo-grid">
                            <?php while ($p = $photos_res->fetch_assoc()): ?>
                                <a href="<?php echo asset_v($p['image_path']); ?>" target="_blank" rel="noopener" class="photo-grid-item" title="<?php echo e($p['caption'] ?? ''); ?>">
                                    <img src="<?php echo asset_v($p['image_path']); ?>" alt="<?php echo e($p['caption'] ?? ''); ?>" loading="lazy">
                                </a>
                            <?php endwhile; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <aside class="detail-aside">
                    <div class="side-card">
                        <h3>Event details</h3>
                        <ul class="info-list">
                            <li>
                                <span class="info-icon"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg></span>
                                <span><span class="info-label">Date</span><span class="info-value"><?php echo e(event_range_text($event)); ?></span></span>
                            </li>
                            <?php if (event_time_text($event) !== ''): ?>
                            <li>
                                <span class="info-icon"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg></span>
                                <span><span class="info-label">Time</span><span class="info-value"><?php echo e(event_time_text($event)); ?></span></span>
                            </li>
                            <?php endif; ?>
                            <?php if ($event['venue'] !== ''): ?>
                            <li>
                                <span class="info-icon"><svg class="icon" aria-hidden="true"><use href="#i-pin"/></svg></span>
                                <span><span class="info-label">Venue</span><span class="info-value"><?php echo e($event['venue']); ?></span></span>
                            </li>
                            <?php endif; ?>
                            <li>
                                <span class="info-icon"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg></span>
                                <span>
                                    <span class="info-label">Organized by</span>
                                    <span class="info-value">
                                        <?php if ($organizer_name && ($event['organizer_role'] ?? '') === 'officer'): ?>
                                            <a href="<?php echo BASE_URL; ?>/officer.php?id=<?php echo (int) $event['organizer_id']; ?>"><?php echo e($organizer_name); ?></a>
                                        <?php else: ?>
                                            <?php echo e($organizer_name ?: 'CSO'); ?>
                                        <?php endif; ?>
                                        <?php if (!empty($event['organizer_committee'])): ?><small class="muted" style="display:block; font-weight:500;"><?php echo e($event['organizer_committee']); ?></small><?php endif; ?>
                                    </span>
                                </span>
                            </li>
                            <li>
                                <span class="info-icon"><svg class="icon" aria-hidden="true"><use href="#i-users"/></svg></span>
                                <span><span class="info-label">Registered</span><span class="info-value"><?php echo (int) $event['registration_count']; ?> member<?php echo (int) $event['registration_count'] === 1 ? '' : 's'; ?><?php
                                    if ($event['max_attendees'] !== null) {
                                        $left = max(0, (int) $event['max_attendees'] - (int) $event['registration_count']);
                                        echo ' &middot; ' . ($left > 0 ? $left . ' spot' . ($left === 1 ? '' : 's') . ' left' : 'full');
                                        if ((int) $event['waitlist_count'] > 0) echo ' &middot; ' . (int) $event['waitlist_count'] . ' on waitlist';
                                    } ?></span></span>
                            </li>
                        </ul>

                        <div class="side-actions">
                            <?php if (!is_logged_in()): ?>
                                <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary btn-block">Log in to register</a>
                            <?php elseif ($_SESSION['role'] === 'member'): ?>
                                <?php if ($state === 'ended'): ?>
                                    <button type="button" class="btn btn-outline btn-block" disabled>This event has ended</button>
                                <?php else: ?>
                                    <form method="POST">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="event_id" value="<?php echo (int) $event['event_id']; ?>">
                                        <?php if ($is_registered): ?>
                                            <input type="hidden" name="action" value="unregister">
                                            <button type="submit" class="btn btn-outline btn-block"><?php echo $on_waitlist ? 'Leave the waitlist' : 'Cancel my registration'; ?></button>
                                        <?php else: ?>
                                            <input type="hidden" name="action" value="register">
                                            <?php $is_full = $event['max_attendees'] !== null && (int) $event['registration_count'] >= (int) $event['max_attendees']; ?>
                                            <button type="submit" class="btn btn-primary btn-block"><?php echo $is_full ? 'Join the waitlist' : 'Register for this event'; ?></button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="btn btn-outline btn-block">Manage this event</a>
                            <?php endif; ?>
                            <?php if (!empty($event['facebook_url'])): ?>
                                <a href="<?php echo e($event['facebook_url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline btn-block">View on Facebook</a>
                            <?php endif; ?>
                            <?php if ($state !== 'ended' && $has_date): ?>
                                <a href="<?php echo e($cal_url); ?>" target="_blank" rel="noopener" class="btn btn-outline btn-block">Add to Google Calendar</a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline btn-block" data-copy-link>Copy link</button>
                        </div>
                    </div>
                </aside>
            </div>

            <?php if ($more_events && $more_events->num_rows > 0): ?>
                <div class="section-head" style="margin-top:56px;"><h2>More events</h2><a href="<?php echo BASE_URL; ?>/events.php">View all →</a></div>
                <div class="card-grid">
                    <?php while ($m = $more_events->fetch_assoc()) render_event_card($m); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
