<?php
/**
 * ONE-TIME ADMIN SETUP
 *
 * Sets the password for the default ADMIN-001 account. You choose the password
 * here; it is never shown on screen or written in any file.
 *
 * The script locks itself as soon as the admin has a password, so it cannot be
 * used to reset an existing admin. Still, delete this file from the server after
 * setup.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$page_title = 'One-Time Setup';

// Find the admin row and check whether a password is already set.
$admin = null;
$res = $conn->query("SELECT user_id, password FROM users WHERE student_id = 'ADMIN-001' LIMIT 1");
if ($res) $admin = $res->fetch_assoc();

$state  = 'form';     // form | locked | missing | done
$errors = [];

if (!$admin) {
    $state = 'missing';
} elseif ($admin['password'] !== '') {
    $state = 'locked';
}

if ($state === 'form' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw      = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($pw) < 10)      $errors[] = 'Use at least 10 characters.';
    if ($pw !== $confirm)      $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        $hashed = password_hash($pw, PASSWORD_DEFAULT);
        // Only update while the password is still empty, so a second submit cannot overwrite it.
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE student_id = 'ADMIN-001' AND password = ''");
        $stmt->bind_param('s', $hashed);
        $stmt->execute();
        $changed = $stmt->affected_rows;
        $stmt->close();
        $state = $changed === 1 ? 'done' : 'locked';
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
    <div class="container narrow">
        <?php if ($state === 'done'): ?>
            <div class="alert alert-success">The admin password is set. Log in with ADMIN-001 and your new password.</div>
            <div class="alert alert-warning" style="margin-top:20px;">Delete <code>reset_admin_password.php</code> from the server now.</div>
            <a href="<?php echo BASE_URL; ?>/admin_login.php" class="btn btn-primary" style="margin-top:8px;">Go to Admin Login</a>

        <?php elseif ($state === 'locked'): ?>
            <div class="alert alert-error">The admin password is already set, so this setup is closed. Delete <code>reset_admin_password.php</code> from the server.</div>

        <?php elseif ($state === 'missing'): ?>
            <div class="alert alert-error">No ADMIN-001 account was found. Import <code>database/cso_mis_full.sql</code> first, then reload this page.</div>

        <?php else: ?>
            <h1>Set the admin password</h1>
            <p class="muted">This sets the password for ADMIN-001. It can only be done once.</p>
            <?php foreach ($errors as $e): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>
            <form method="post" class="form" autocomplete="off">
                <?php echo csrf_field(); ?>
                <div class="form-row">
                    <label for="new_password">New password</label>
                    <input type="password" id="new_password" name="new_password" minlength="10" required autocomplete="new-password">
                    <p class="muted" style="font-size:0.82rem;margin:6px 0 0;">At least 10 characters.</p>
                </div>
                <div class="form-row">
                    <label for="confirm_password">Confirm password</label>
                    <input type="password" id="confirm_password" name="confirm_password" minlength="10" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg">Set admin password</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
