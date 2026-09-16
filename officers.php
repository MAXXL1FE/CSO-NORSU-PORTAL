<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$like = '%' . $search . '%';

$stmt = $conn->prepare("
    SELECT first_name, last_name, course, year_level, status, profile_photo, committee_role
    FROM users
    WHERE role = 'officer' AND (first_name LIKE ? OR last_name LIKE ? OR course LIKE ?)
    ORDER BY status ASC, last_name ASC
");
$stmt->bind_param('sss', $like, $like, $like);
$stmt->execute();
$officers = $stmt->get_result();

$page_title = 'Officers';
require_once __DIR__ . '/includes/header.php';
?>

<section class="section">
    <div class="container">
        <h2>Officer Directory</h2>

        <form method="GET" class="inline-form">
            <input type="text" name="search" placeholder="Search officers by name or course" value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn btn-outline">Search</button>
        </form>

        <div class="card-grid">
            <?php if ($officers->num_rows > 0): ?>
                <?php while ($row = $officers->fetch_assoc()): ?>
                    <div class="card officer-card">
                        <?php if (!empty($row['profile_photo'])): ?>
                            <img src="<?php echo asset_v($row['profile_photo']); ?>" alt="" class="officer-photo">
                        <?php else: ?>
                            <span class="officer-photo officer-photo-fallback"><?php echo htmlspecialchars(initials($row['first_name'], $row['last_name'])); ?></span>
                        <?php endif; ?>
                        <h3><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></h3>
                        <?php if (!empty($row['committee_role'])): ?>
                            <p style="font-weight:600;"><?php echo htmlspecialchars($row['committee_role']); ?></p>
                        <?php endif; ?>
                        <p><?php echo htmlspecialchars($row['course'] . ' — ' . $row['year_level']); ?></p>
                        <span class="status status-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>No officers found<?php echo $search ? ' matching "' . htmlspecialchars($search) . '"' : ''; ?>.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
