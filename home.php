<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$page_title = 'Home';

// Pull the editable About CSO content (title, body, optional image)
$info_result = $conn->query("SELECT * FROM org_info WHERE id = 1");
$info = $info_result ? $info_result->fetch_assoc() : null;
if (!$info) {
    $info = ['title' => 'About CSO', 'content' => 'The Computer Science Organization (CSO) brings together BSCS students for academic growth, technical skill-building, and community.', 'image' => null];
}

// Pull officers for public listing
$officers = $conn->query("SELECT first_name, last_name, course, year_level, profile_photo FROM users WHERE role = 'officer' AND status = 'active' ORDER BY last_name ASC");

// Pull upcoming events (public preview)
$events = $conn->query("SELECT event_id, title, event_date, event_time, venue, cover_image FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT 3");

// Pull recent announcements (public preview)
$announcements = $conn->query("SELECT title, content, image, created_at FROM announcements ORDER BY created_at DESC LIMIT 3");

require_once __DIR__ . '/includes/header.php';
?>

<section class="hero-banner">
    <img src="<?php echo asset_v('/assets/images/banner.png'); ?>" alt="Computer Science Organization — NORSU" class="hero-banner-img">
</section>

<?php if (!is_logged_in()): ?>
<section class="hero-cta">
    <div class="container">
        <p class="hero-tagline">Management and Information System</p>
        <div class="hero-buttons">
            <a href="<?php echo BASE_URL; ?>/register.php" class="btn btn-primary">Join CSO</a>
            <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-outline">Member Login</a>
        </div>
    </div>
</section>
<?php endif; ?>

<section id="about" class="section">
    <div class="container">
        <div class="about-block<?php echo !empty($info['image']) ? '' : ' about-block-noimg'; ?>">
            <?php if (!empty($info['image'])): ?>
                <img src="<?php echo asset_v($info['image']); ?>" alt="" class="about-block-img">
            <?php endif; ?>
            <div class="about-block-text">
                <h2><?php echo htmlspecialchars($info['title']); ?></h2>
                <p><?php echo nl2br(htmlspecialchars($info['content'])); ?></p>
            </div>
        </div>
    </div>
</section>

<section id="officers" class="section alt">
    <div class="container">
        <h2>Officers</h2>
        <div class="card-grid">
            <?php if ($officers && $officers->num_rows > 0): ?>
                <?php while ($row = $officers->fetch_assoc()): ?>
                    <div class="card officer-card">
                        <?php if (!empty($row['profile_photo'])): ?>
                            <img src="<?php echo asset_v($row['profile_photo']); ?>" alt="" class="officer-photo">
                        <?php else: ?>
                            <span class="officer-photo officer-photo-fallback"><?php echo htmlspecialchars(initials($row['first_name'], $row['last_name'])); ?></span>
                        <?php endif; ?>
                        <h3><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></h3>
                        <p><?php echo htmlspecialchars($row['course'] . ' — ' . $row['year_level']); ?></p>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>Officer profiles will appear here once the admin promotes members to officer roles.</p>
            <?php endif; ?>
        </div>
        <p style="margin-top:16px;"><a href="<?php echo BASE_URL; ?>/officers.php">View full officer directory →</a></p>
    </div>
</section>

<section id="events" class="section">
    <div class="container">
        <h2>Upcoming Events</h2>
        <div class="card-grid">
            <?php if ($events && $events->num_rows > 0): ?>
                <?php while ($row = $events->fetch_assoc()): ?>
                    <a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $row['event_id']; ?>" class="card card-link post-card">
                        <?php if (!empty($row['cover_image'])): ?>
                            <img src="<?php echo asset_v($row['cover_image']); ?>" alt="" class="post-card-img">
                        <?php endif; ?>
                        <h3><?php echo htmlspecialchars($row['title']); ?></h3>
                        <p><?php echo date('F j, Y', strtotime($row['event_date'])); ?> &middot; <?php echo date('g:i A', strtotime($row['event_time'])); ?></p>
                        <p><?php echo htmlspecialchars($row['venue']); ?></p>
                        <span class="card-cta">View details →</span>
                    </a>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No upcoming events yet.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section alt">
    <div class="container">
        <h2>Announcements</h2>
        <div class="card-grid">
            <?php if ($announcements && $announcements->num_rows > 0): ?>
                <?php while ($row = $announcements->fetch_assoc()): ?>
                    <div class="card post-card">
                        <?php if (!empty($row['image'])): ?>
                            <img src="<?php echo asset_v($row['image']); ?>" alt="" class="post-card-img">
                        <?php endif; ?>
                        <h3><?php echo htmlspecialchars($row['title']); ?></h3>
                        <p><?php echo htmlspecialchars(substr($row['content'], 0, 120)); ?>...</p>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No announcements yet.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
