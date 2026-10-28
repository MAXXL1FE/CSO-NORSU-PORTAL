<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_login();   // members only: names of registered students are not shown to visitors

$q = trim($_GET['q'] ?? '');
$where = "user_type = 'student' AND status = 'active' AND show_in_members = 1";
$params = []; $bind = '';
if ($q !== '') {
    $where .= " AND (first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ?)";
    $like = '%' . $q . '%';
    $params = [$like, $like, $like]; $bind = 'sss';
}
// Only name + photos are selected. Student IDs and status notes are never loaded for this page.
$stmt = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, verified_as, profile_photo, cover_photo FROM users WHERE $where ORDER BY last_name, first_name");
if ($params) $stmt->bind_param($bind, ...$params);
$stmt->execute();
$people = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$me_visible = true;
$mv = $conn->prepare("SELECT show_in_members FROM users WHERE user_id = ?");
$mv_uid = (int) $_SESSION['user_id'];
$mv->bind_param('i', $mv_uid);
$mv->execute();
$mv_row = $mv->get_result()->fetch_assoc();
$mv->close();
$me_visible = !empty($mv_row['show_in_members']);

$n_listed = count($people);
$cnt = function ($sql) use ($conn) { $r = $conn->query($sql); return $r ? (int) $r->fetch_assoc()['c'] : 0; };
$n_members  = $cnt("SELECT COUNT(*) c FROM users WHERE role <> 'admin' AND user_type = 'student' AND status = 'active'");
$n_officers = $cnt("SELECT COUNT(*) c FROM users WHERE role = 'officer' AND user_type = 'student' AND status = 'active'");

$page_title = 'Members';
require_once __DIR__ . '/includes/header.php';
?>

<div class="home-page people-page">

<section class="home-hero-wrap" aria-label="Members">
    <div class="home-hero page-hero">
        <img src="<?php echo asset_v('/assets/images/members-banner.jpg'); ?>" alt="CSO-NORSU members group photo" class="home-hero-img" width="2048" height="1079" fetchpriority="high">
        <div class="home-hero-content">
            <h1 class="home-hero-title">Members</h1>
            <p class="home-hero-sub">The students of the Computer Science Organization who chose to be listed.</p>
            <div class="home-hero-actions">
                <a href="#find" class="btn btn-primary btn-lg">Find a member</a>
                <a href="<?php echo BASE_URL; ?>/officers.php" class="btn btn-outline btn-lg">Meet the officers</a>
            </div>
        </div>
    </div>
</section>

<section class="home-stats-wrap" aria-label="Members at a glance">
    <div class="home-stats">
        <div class="home-stat"><strong><?php echo $n_listed; ?></strong><span>Listed here</span></div>
        <div class="home-stat"><strong><?php echo $n_members; ?></strong><span>Student members</span></div>
        <div class="home-stat"><strong><?php echo $n_officers; ?></strong><span>Officers</span></div>
    </div>
</section>

<section id="find" class="section">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Directory</span><h2>Find a member</h2><p>Search by first, middle or last name.</p></div></div>

        <form method="GET" class="members-search" role="search">
            <label for="mem-q" class="sr-only">Search members by name</label>
            <input type="search" id="mem-q" name="q" value="<?php echo e($q); ?>" placeholder="Search by name">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($q !== ''): ?><a href="<?php echo BASE_URL; ?>/members.php" class="btn btn-outline">Clear</a><?php endif; ?>
        </form>

        <p class="muted members-count" aria-live="polite"><?php echo count($people); ?> <?php echo count($people) === 1 ? 'member' : 'members'; ?></p>

<?php if (!$me_visible): ?>
    <p class="muted members-hidden-note">You are hidden from this list. Turn on <strong>Show me in Members</strong> in the menu's Settings to appear here.</p>
<?php endif; ?>

<?php if (!$people): ?>
    <div class="card"><p><?php echo $q !== '' ? 'No members match "' . e($q) . '".' : 'No members have chosen to be listed yet.'; ?></p></div>
<?php else: ?>
    <ul class="mem-grid">
        <?php foreach ($people as $p):
            $nm = full_name($p);
        ?>
            <li class="mem-card">
                <div class="mem-cover<?php echo empty($p['cover_photo']) ? ' mem-cover-default' : ''; ?>">
                    <?php if (!empty($p['cover_photo'])): ?>
                        <img src="<?php echo asset_v($p['cover_photo']); ?>" alt="" loading="lazy" onerror="this.remove(); this.parentElement.classList.add('mem-cover-default')">
                    <?php else: ?>
                        <img src="<?php echo asset_v('/assets/images/member-cover-default.webp'); ?>" alt="" loading="lazy" onerror="this.remove(); this.parentElement.classList.add('mem-cover-default')">
                    <?php endif; ?>
                </div>
                <div class="mem-avatar">
                    <svg class="icon" aria-hidden="true"><use href="#i-user"/></svg>
                    <?php if (!empty($p['profile_photo'])): ?>
                        <img src="<?php echo asset_v($p['profile_photo']); ?>" alt="" loading="lazy" onerror="this.remove()">
                    <?php endif; ?>
                </div>
                <p class="mem-name"><?php echo e($nm); ?> <?php echo verified_badge_html($p['verified_as'] ?? 'none'); ?></p>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
    </div>
</section>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
