<?php
/**
 * ONE-TIME SETUP SCRIPT
 * Run this once in your browser (e.g. http://localhost/CSO-MIS/reset_admin_password.php)
 * after importing cso_mis.sql, to set a real password for the default admin account.
 * DELETE THIS FILE after running it once.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$new_password = 'admin123'; // change this if you want a different default
$hashed = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password = ? WHERE student_id = 'ADMIN-001'");
$stmt->bind_param('s', $hashed);
$ok = $stmt->execute();
$page_title = 'One-Time Setup';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
    <div class="container narrow">
        <?php if ($ok): ?>
            <div class="alert alert-success">Admin password has been set successfully.</div>
            <div class="checkin-card">
                <div class="checkin-row"><span class="checkin-label">Student ID</span><span class="checkin-value">ADMIN-001</span></div>
                <div class="checkin-row"><span class="checkin-label">Password</span><span class="checkin-value"><?php echo htmlspecialchars($new_password); ?></span></div>
            </div>
            <div class="alert alert-warning" style="margin-top:20px;">Delete <code>reset_admin_password.php</code> now — leaving it on the server lets anyone reset the admin password.</div>
            <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-primary" style="margin-top:8px;">Go to Login</a>
        <?php else: ?>
            <div class="alert alert-error">Something went wrong: <?php echo htmlspecialchars($conn->error); ?></div>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
