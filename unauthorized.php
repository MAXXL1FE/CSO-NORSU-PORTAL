<?php
require_once __DIR__ . '/includes/functions.php';
$page_title = 'Unauthorized';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
    <div class="container narrow" style="text-align:center;">
        <h2>Access Denied</h2>
        <p>You don't have permission to view that page.</p>
        <?php if (is_logged_in()): ?>
            <a href="<?php echo BASE_URL; ?>/dashboard/<?php echo $_SESSION['role']; ?>.php" class="btn btn-primary">Back to Dashboard</a>
        <?php else: ?>
            <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary">Log in</a>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
