<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$ann_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = $conn->prepare("
    SELECT a.*, u.user_id AS author_id, u.first_name AS author_first, u.last_name AS author_last,
           u.role AS author_role, u.committee_role AS author_committee
    FROM announcements a
    LEFT JOIN users u ON u.user_id = a.created_by
    WHERE a.announcement_id = ?
");
$stmt->bind_param('i', $ann_id);
$stmt->execute();
$ann = $stmt->get_result()->fetch_assoc();

$more = null;
if ($ann) {
    $m = $conn->prepare("SELECT * FROM announcements WHERE announcement_id <> ? ORDER BY created_at DESC LIMIT 3");
    $m->bind_param('i', $ann_id);
    $m->execute();
    $more = $m->get_result();
}

$page_title = $ann ? $ann['title'] : 'Announcement Not Found';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/cards.php';
?>

<section class="section detail-section">
    <div class="container detail-wrap">
        <?php if (!$ann): ?>
            <div class="empty-state">
                <h2>Announcement not found</h2>
                <p>This announcement may have been removed.</p>
                <a href="<?php echo BASE_URL; ?>/announcements.php" class="btn btn-primary">All announcements</a>
            </div>
        <?php else: ?>
            <?php
            $deadline_ts = !empty($ann['deadline']) ? strtotime($ann['deadline']) : null;
            $is_open     = $deadline_ts && $deadline_ts > time();
            $author_name = trim(($ann['author_first'] ?? '') . ' ' . ($ann['author_last'] ?? ''));
            ?>
            <a href="<?php echo BASE_URL; ?>/announcements.php" class="back-link">← All announcements</a>

            <div class="detail-layout">
                <article class="detail-main">
                    <?php $post_imgs = post_images_for($conn, 'announcement', (int) $ann['announcement_id'], $ann['image'] ?? null); ?>
                    <?php if ($post_imgs): ?>
                        <div class="detail-gallery"><?php render_post_gallery($post_imgs, $ann['title']); ?></div>
                    <?php endif; ?>

                    <div class="detail-chips">
                        <span class="chip">Announcement</span>
                        <?php if ($deadline_ts): ?>
                            <span class="chip <?php echo $is_open ? 'chip-warn' : 'chip-muted'; ?>"><?php echo e($is_open ? time_left_text($deadline_ts) : 'Deadline passed'); ?></span>
                        <?php endif; ?>
                    </div>
                    <h1 class="detail-title"><?php echo e($ann['title']); ?></h1>
                    <p class="detail-meta">Posted <?php echo date('F j, Y \a\t g:i A', strtotime($ann['created_at'])); ?><?php echo $author_name ? ' by ' . e($author_name) : ''; ?></p>

                    <div class="prose"><p><?php echo nl2br(e($ann['content'])); ?></p></div>
                </article>

                <aside class="detail-aside">
                    <div class="side-card">
                        <h3>Important info</h3>
                        <ul class="info-list">
                            <li>
                                <span class="info-icon"><svg class="icon" aria-hidden="true"><use href="#i-calendar"/></svg></span>
                                <span><span class="info-label">Posted on</span><span class="info-value"><?php echo date('F j, Y', strtotime($ann['created_at'])); ?></span><small class="muted"><?php echo date('g:i A', strtotime($ann['created_at'])); ?></small></span>
                            </li>
                            <li>
                                <span class="info-icon"><svg class="icon" aria-hidden="true"><use href="#i-clock"/></svg></span>
                                <span>
                                    <span class="info-label">Deadline</span>
                                    <?php if ($deadline_ts): ?>
                                        <span class="info-value"><?php echo date('F j, Y', $deadline_ts); ?></span>
                                        <small class="muted"><?php echo date('g:i A', $deadline_ts); ?> · <?php echo e($is_open ? time_left_text($deadline_ts) : 'passed'); ?></small>
                                    <?php else: ?>
                                        <span class="info-value">No deadline</span>
                                    <?php endif; ?>
                                </span>
                            </li>
                            <li>
                                <span class="info-icon"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg></span>
                                <span>
                                    <span class="info-label">Posted by</span>
                                    <span class="info-value">
                                        <?php if ($author_name && ($ann['author_role'] ?? '') === 'officer'): ?>
                                            <a href="<?php echo BASE_URL; ?>/officer.php?id=<?php echo (int) $ann['author_id']; ?>"><?php echo e($author_name); ?></a>
                                        <?php else: ?>
                                            <?php echo e($author_name ?: 'CSO'); ?>
                                        <?php endif; ?>
                                    </span>
                                    <?php if (!empty($ann['author_committee'])): ?><small class="muted"><?php echo e($ann['author_committee']); ?></small><?php endif; ?>
                                </span>
                            </li>
                        </ul>
                        <div class="side-actions">
                            <button type="button" class="btn btn-outline btn-block" data-copy-link>Copy link</button>
                            <a href="<?php echo BASE_URL; ?>/announcements.php" class="btn btn-outline btn-block">All announcements</a>
                        </div>
                    </div>
                </aside>
            </div>

            <?php if ($more && $more->num_rows > 0): ?>
                <div class="section-head" style="margin-top:56px;"><h2>More announcements</h2><a href="<?php echo BASE_URL; ?>/announcements.php">View all →</a></div>
                <div class="card-grid">
                    <?php while ($m = $more->fetch_assoc()) render_announcement_card($m); ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
