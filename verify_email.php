<?php
/**
 * Sign-up email verification.
 *
 * Reached straight after register.php. The account exists but is inactive and
 * unverified until the 6-digit code emailed through Resend is entered here.
 */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/otp.php';

if (is_logged_in()) redirect_home();

$user_id = (int) ($_SESSION['verify_user'] ?? 0);
if ($user_id <= 0) {
    header('Location: ' . BASE_URL . '/register.php');
    exit;
}

$stmt = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, email, user_type, email_verified FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) {
    unset($_SESSION['verify_user']);
    header('Location: ' . BASE_URL . '/register.php');
    exit;
}
if ((int) $u['email_verified'] === 1) {
    unset($_SESSION['verify_user']);
    set_flash('success', 'That email is already verified. You can log in.');
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}

$error  = '';
$notice = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'resend') {
        if (ev_too_many($conn, $user_id, $ip)) {
            $error = 'Too many codes requested. Please wait a few minutes before asking for another.';
        } else {
            $code = ev_issue($conn, $user_id, $ip);
            if (ev_send_email($u['email'], full_name($u), $code)) {
                $notice = 'A new code is on its way to ' . $u['email'] . '. It expires in ' . OTP_LIFETIME_MIN . ' minutes.';
            } else {
                // The account is already known to whoever is on this page, so saying
                // the send failed gives nothing away and saves them waiting.
                $error = 'We could not send the code just now. Please try again in a moment, or ask a CSO officer for help.';
            }
        }
    } else {
        $code = preg_replace('/\s+/', '', $_POST['code'] ?? '');
        if (ev_verify($conn, $user_id, $code)) {
            $state = ev_activate($conn, $user_id);
            unset($_SESSION['verify_user']);
            session_regenerate_id(true);
            set_flash('success', $state === 'active'
                ? 'Your email is verified and your account is ready. Log in to get started.'
                : 'Your email is verified. A CSO officer will review your sign-up, and you can log in once it is approved.');
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
        $error = 'That code is not valid or has expired. Check the code, or send a new one.';
    }
}

$page_title = 'Verify your email';
require_once __DIR__ . '/includes/header.php';
?>
<section class="section auth-section">
    <div class="container narrow">
        <h1>Verify your email</h1>
        <p class="muted">We sent a 6-digit code to <strong><?php echo e($u['email']); ?></strong>. Enter it below to finish creating your account. The code works once and expires in <?php echo OTP_LIFETIME_MIN; ?> minutes.</p>

        <?php if ($error): ?><div class="alert alert-error" role="alert"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="alert alert-success" role="status"><?php echo e($notice); ?></div><?php endif; ?>

        <form method="POST" class="form" autocomplete="one-time-code">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <label for="code">6-digit code</label>
                <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required
                       style="letter-spacing:0.3em;font-size:1.25rem;text-align:center;">
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Verify email</button>
        </form>

        <form method="POST" class="form" style="margin-top:14px;">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn btn-outline btn-block">Send a new code</button>
        </form>

        <p style="margin-top:18px;">Check your spam folder first. Wrong email address? <a href="<?php echo BASE_URL; ?>/register.php">Start over</a>.</p>
    </div>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
