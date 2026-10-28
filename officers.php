<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$like = '%' . $search . '%';

$stmt = $conn->prepare("
    SELECT user_id, first_name, middle_name, last_name, suffix, course, year_level, status, profile_photo, committee_role
    FROM users
    WHERE role = 'officer' AND (first_name LIKE ? OR last_name LIKE ? OR course LIKE ?)
    ORDER BY status ASC, last_name ASC
");
$stmt->bind_param('sss', $like, $like, $like);
$stmt->execute();
$officers = $stmt->get_result();

$cnt = function ($sql) use ($conn) { $r = $conn->query($sql); return $r ? (int) $r->fetch_assoc()['c'] : 0; };
$n_officers = $cnt("SELECT COUNT(*) c FROM users WHERE role = 'officer' AND user_type = 'student' AND status = 'active'");
$n_members  = $cnt("SELECT COUNT(*) c FROM users WHERE role <> 'admin' AND user_type = 'student' AND status = 'active'");
$n_events   = $cnt("SELECT COUNT(*) c FROM events");

$page_title = 'Officers';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/cards.php';
?>

<div class="home-page people-page">

<section class="home-hero-wrap" aria-label="Officers">
    <div class="home-hero page-hero">
        <img src="<?php echo asset_v('/assets/images/officers-banner.jpg'); ?>" alt="CSO-NORSU officers and members group photo" class="home-hero-img" width="1962" height="1074" fetchpriority="high">
        <div class="home-hero-content">
            <h1 class="home-hero-title">Officers</h1>
            <p class="home-hero-sub">The students who run CSO events, announcements and attendance.</p>
            <div class="home-hero-actions">
                <a href="#directory" class="btn btn-primary btn-lg">Browse officers</a>
                <a href="<?php echo BASE_URL; ?>/events.php" class="btn btn-outline btn-lg">Upcoming events</a>
            </div>
        </div>
    </div>
</section>

<section class="home-stats-wrap" aria-label="Officers at a glance">
    <div class="home-stats">
        <div class="home-stat"><strong><?php echo $n_officers; ?></strong><span>Active officers</span></div>
        <div class="home-stat"><strong><?php echo $n_members; ?></strong><span>Student members</span></div>
        <div class="home-stat"><strong><?php echo $n_events; ?></strong><span>Events organized</span></div>
    </div>
</section>

<section id="directory" class="section">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Directory</span><h2>Officer Directory</h2><p>Select an officer to see their profile.</p></div></div>

        <form method="GET" class="inline-form" role="search">
            <label for="search-box" class="sr-only">Search officers by name or program</label>
            <input type="text" id="search-box" name="search" placeholder="Search officers by name or program" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search !== ''): ?><a href="<?php echo BASE_URL; ?>/officers.php" class="btn btn-outline">Clear</a><?php endif; ?>
        </form>

        <div class="card-grid">
            <?php if ($officers->num_rows > 0): ?>
                <?php while ($row = $officers->fetch_assoc()) render_officer_card($row); ?>
            <?php else: ?>
                <p>No officers found<?php echo $search ? ' matching "' . htmlspecialchars($search) . '"' : ''; ?>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
