<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/otp.php';

if (is_logged_in()) redirect_home();

$page_title = 'Forgot password';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $identifier = clean($_POST['identifier'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!otp_email_available()) {
        $notice = 'Emailed codes are not turned on yet. Ask a CSO officer to issue you a reset code in person.';
    } elseif ($identifier !== '') {
        $stmt = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, email, role, status
                                FROM users WHERE student_id = ? OR email = ? LIMIT 1");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Admin accounts are never reset from this form. Only active accounts with an email get a code.
        if ($u && $u['status'] === 'active' && $u['role'] !== 'admin' && !otp_too_many($conn, $u['user_id'], $ip)) {
            $code = otp_issue($conn, $u['user_id'], 'email', $ip);
            otp_send_email($u['email'], full_name($u), $code);   // result is not shown, so no account is revealed
        }
    }

    if ($notice !== '') goto render;   // email codes are off: show the officer instructions

    // Same answer whether or not the account exists.
    $_SESSION['reset_identifier'] = $identifier;
    set_flash('success', 'If an account matches, a 6-digit code was sent to its email. The code expires in ' . OTP_LIFETIME_MIN . ' minutes.');
    header('Location: ' . BASE_URL . '/verify_code.php');
    exit;
}

render:
require_once __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
    <div class="container narrow">
        <h1>Forgot your password?</h1>
        <p class="muted">Enter your Student ID or email. If an account matches, we will send a one-time code to its email.</p>

        <?php if ($notice): ?>
            <div class="alert alert-warning"><?php echo e($notice); ?></div>
        <?php endif; ?>

        <form method="POST" class="form" autocomplete="off">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <label for="identifier">Student ID or email</label>
                <input type="text" id="identifier" name="identifier" required maxlength="120" autocomplete="username">
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Send code</button>
        </form>

        <p style="margin-top:18px;"><a href="<?php echo BASE_URL; ?>/login.php">Back to log in</a></p>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
