<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/otp.php';

if (is_logged_in()) redirect_home();

$identifier = $_SESSION['reset_identifier'] ?? '';
if ($identifier === '') {
    header('Location: ' . BASE_URL . '/forgot_password.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $code = preg_replace('/\s+/', '', $_POST['code'] ?? '');

    $stmt = $conn->prepare("SELECT user_id, status, role FROM users WHERE student_id = ? OR email = ? LIMIT 1");
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $reset_id = ($u && $u['status'] === 'active' && $u['role'] !== 'admin') ? otp_verify($conn, $u['user_id'], $code) : null;

    if ($reset_id) {
        session_regenerate_id(true);
        $_SESSION['pw_reset'] = ['user_id' => (int) $u['user_id'], 'reset_id' => $reset_id];
        header('Location: ' . BASE_URL . '/reset_password.php');
        exit;
    }
    // Same message for a wrong code, an expired code, and an unknown account.
    $error = 'That code is not valid or has expired. Check the code, or request a new one.';
}

$page_title = 'Enter code';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
    <div class="container narrow">
        <h1>Enter your code</h1>
        <p class="muted">Enter the 6-digit code from your email. It works once and expires in <?php echo OTP_LIFETIME_MIN; ?> minutes.</p>

        <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>

        <form method="POST" class="form" autocomplete="one-time-code">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <label for="code">6-digit code</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required
                       style="letter-spacing:0.3em;font-size:1.25rem;text-align:center;">
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Verify code</button>
        </form>

        <p style="margin-top:18px;">Didn't get it? <a href="<?php echo BASE_URL; ?>/forgot_password.php">Request a new code</a>. Check your spam folder first.</p>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
