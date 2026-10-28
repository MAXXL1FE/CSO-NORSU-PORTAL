<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$like   = '%' . $search . '%';

function ann_clean_date($v) {
    $v = trim((string) $v);
    return (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) ? $v : '';
}
$from  = ann_clean_date($_GET['from'] ?? '');
$to    = ann_clean_date($_GET['to'] ?? '');
$order = (($_GET['sort'] ?? '') === 'oldest') ? 'ASC' : 'DESC';
$bind_types = 'ss';
$bind_vals  = [$like, $like];
$date_sql = '';
if ($from) { $date_sql .= ' AND DATE(a.created_at) >= ?'; $bind_types .= 's'; $bind_vals[] = $from; }
if ($to)   { $date_sql .= ' AND DATE(a.created_at) <= ?'; $bind_types .= 's'; $bind_vals[] = $to; }

$stmt = $conn->prepare("
    SELECT a.*, " . post_author_select() . "
    FROM announcements a
    LEFT JOIN users u ON u.user_id = a.created_by
    WHERE (a.title LIKE ? OR a.content LIKE ?)$date_sql
    ORDER BY a.created_at $order
");
$stmt->bind_param($bind_types, ...$bind_vals);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$img_map = post_images_map($conn, 'announcement', array_column($rows, 'announcement_id'));

$page_title = 'Announcements';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/cards.php';
?>

<section class="section">
    <div class="container">
        <div class="section-head"><h1 style="margin:0;">Announcements</h1></div>
        <p class="muted">The latest news from the CSO — open a post to read it in full, see every photo and check the deadline.</p>

        <form method="GET" class="inline-form">
            <label for="search-box" class="sr-only" for="search-box">Search announcements</label>
            <input type="text" id="search-box" name="search" placeholder="Search announcements" value="<?php echo e($search); ?>">
            <label class="filter-date"><span>From</span><input type="date" name="from" value="<?php echo e($from); ?>"></label>
            <label class="filter-date"><span>To</span><input type="date" name="to" value="<?php echo e($to); ?>"></label>
            <label class="filter-date"><span class="sr-only">Sort</span>
                <select name="sort" aria-label="Sort announcements">
                    <option value="newest" <?php echo $order === 'DESC' ? 'selected' : ''; ?>>Newest first</option>
                    <option value="oldest" <?php echo $order === 'ASC' ? 'selected' : ''; ?>>Oldest first</option>
                </select></label>
            <button type="submit" class="btn btn-outline">Filter</button>
            <?php if ($search !== '' || $from || $to || $order === 'ASC'): ?><a href="<?php echo BASE_URL; ?>/announcements.php" class="btn btn-outline">Clear</a><?php endif; ?>
        </form>

        <div class="feed">
            <?php if ($rows): ?>
                <?php foreach ($rows as $row) render_fb_post($row, 'announcement', $img_map[(int) $row['announcement_id']] ?? []); ?>
            <?php else: ?>
                <div class="empty-state">
                    <svg class="icon empty-icon" aria-hidden="true"><use href="#i-megaphone"/></svg>
                    <h3>No announcements found</h3>
                    <p><?php echo ($search !== '' || $from || $to) ? 'Try a different word or date range.' : 'Nothing has been posted yet.'; ?></p>
                    <?php if ($search !== '' || $from || $to): ?><a href="<?php echo BASE_URL; ?>/announcements.php" class="btn btn-outline btn-sm">Clear filters</a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
