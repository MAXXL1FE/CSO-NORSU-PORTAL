<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/otp.php';

if (is_logged_in()) redirect_home();

$state = $_SESSION['pw_reset'] ?? null;
if (!$state || !otp_reset_valid($conn, $state['user_id'], $state['reset_id'])) {
    unset($_SESSION['pw_reset']);
    set_flash('error', 'That reset session has expired. Start again.');
    header('Location: ' . BASE_URL . '/forgot_password.php');
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pw      = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $conn->prepare("SELECT student_id, email FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $state['user_id']);
    $stmt->execute();
    $me = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (strlen($pw) < 10)                    $errors[] = 'Use at least 10 characters.';
    if (strlen($pw) > 128)                   $errors[] = 'Use 128 characters or fewer.';
    if ($pw !== $confirm)                    $errors[] = 'The two passwords do not match.';
    if ($me && stripos($pw, $me['student_id']) !== false) $errors[] = 'Do not include your Student ID in the password.';
    if ($me && stripos($pw, strstr($me['email'], '@', true)) !== false && strlen($me['email']) > 3) $errors[] = 'Do not include your email name in the password.';

    if (!$errors) {
        $hash = password_hash($pw, PASSWORD_DEFAULT);
        // token_version + 1 signs out every other device and browser.
        $stmt = $conn->prepare("UPDATE users SET password = ?, token_version = token_version + 1 WHERE user_id = ?");
        $stmt->bind_param('si', $hash, $state['user_id']);
        $stmt->execute();
        $stmt->close();

        otp_close_all($conn, $state['user_id']);
        unset($_SESSION['pw_reset'], $_SESSION['reset_identifier']);
        session_regenerate_id(true);
        header('Location: ' . BASE_URL . '/login.php?reset=1');
        exit;
    }
}

$page_title = 'Set a new password';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
    <div class="container narrow">
        <h1>Set a new password</h1>
        <p class="muted">Choose a password you do not use anywhere else. It must be at least 10 characters.</p>

        <?php foreach ($errors as $err): ?><div class="alert alert-error" role="alert"><?php echo e($err); ?></div><?php endforeach; ?>

        <form method="POST" class="form" autocomplete="off">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <label for="new_password">New password</label>
                <input type="password" id="new_password" name="new_password" minlength="10" maxlength="128" required autocomplete="new-password" data-pw-new data-pw-confirm="confirm_password" data-pw-rules="pw-rules-rst" aria-describedby="pw-rules-rst">
            </div>
            <div class="form-row">
                <label for="confirm_password">Confirm new password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="10" maxlength="128" required autocomplete="new-password">
                <ul class="pw-rules" id="pw-rules-rst" aria-live="polite">
                            <li data-rule="len">At least 10 characters</li>
                            <li data-rule="match">Both passwords match</li>
                        </ul>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Save new password</button>
        </form>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
