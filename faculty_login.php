<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

if (is_logged_in()) {
    redirect_home();
}

$error = '';
$result = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/otp.php';   // otp_email_available() for the unverified-account branch

    $identifier = clean($_POST['identifier']); // employee ID or email
    $password   = $_POST['password'] ?? '';
    $result = attempt_login($conn, $identifier, $password, 'faculty');
    if ($result['ok']) {
        $user = $result['user'];
        $_SESSION['user_id']       = $user['user_id'];
        $_SESSION['tv']            = (int) $user['token_version'];
        $_SESSION['first_name']    = $user['first_name'];
        $_SESSION['middle_name']   = $user['middle_name'] ?? '';
        $_SESSION['last_name']     = $user['last_name'];
        $_SESSION['suffix']        = $user['suffix'] ?? '';
        $_SESSION['role']          = $user['role'];
        $_SESSION['profile_photo'] = $user['profile_photo'];
        redirect_after_login();   // always Home (never the dashboard)
    }
    if (!empty($result['unverified_user_id']) && otp_email_available()) {
        // The password was right, so it is safe to send them back to finish verifying.
        $_SESSION['verify_user'] = (int) $result['unverified_user_id'];
        set_flash('error', $result['error']);
        header('Location: ' . BASE_URL . '/verify_email.php');
        exit;
    }
    $error = $result['error'];
}

$page_title = 'Faculty & Staff Login';
$auth_layout  = true;
require_once __DIR__ . '/includes/header.php';
?>

<?php require_once __DIR__ . '/includes/auth_shell.php'; auth_stage_start('login'); ?>

        <h1 class="signin-title">Faculty &amp; staff sign in</h1>
        <p class="signin-sub">For CSO-NORSU advisers, instructors, professors and staff.</p>

        <?php if (!empty($flash)): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <?php if (!empty($result['admin_hint'])): ?>
                    <a href="<?php echo BASE_URL; ?>/admin_login.php">Go to Admin Login</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo BASE_URL; ?>/faculty_login.php" class="signin-form">
            <?php echo csrf_field(); ?>

            <div class="signin-field">
                <label for="identifier">Employee ID or email</label>
                <input type="text" id="identifier" name="identifier" placeholder="e.g. EMP1024 or name@norsu.edu.ph" autocomplete="username" required autofocus>
            </div>

            <div class="signin-field">
                <div class="signin-label-row">
                    <label for="password">Password</label>
                    <a class="signin-link" href="<?php echo BASE_URL; ?>/forgot_password.php">Forgot password?</a>
                </div>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                    <button type="button" class="pw-toggle" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">Log in</button>
        </form>

        <div class="signin-divider"><span>Not faculty or staff?</span></div>
        <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-outline btn-block">Student log in</a>

        <div class="signin-foot">
            <a href="<?php echo BASE_URL; ?>/register.php">Request a faculty account</a>
            <span aria-hidden="true">&middot;</span>
            <a href="<?php echo BASE_URL; ?>/home.php">Continue without logging in</a>
        </div>

<?php auth_stage_end(); ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
