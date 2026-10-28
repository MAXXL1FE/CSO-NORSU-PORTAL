<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

if (is_logged_in()) {
    redirect_home();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_once __DIR__ . '/includes/auth.php';

    $identifier = clean($_POST['identifier']); // student_id or email
    $password   = $_POST['password'] ?? '';
    $result = attempt_login($conn, $identifier, $password, 'admin');
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
    $error = $result['error'];
}

$page_title = 'Admin Login';
$auth_layout  = true;
require_once __DIR__ . '/includes/header.php';
?>

<?php require_once __DIR__ . '/includes/auth_shell.php'; auth_stage_start('login'); ?>

        <h1 class="signin-title">Admin sign in</h1>
        <p class="signin-sub">For CSO-NORSU administrators only.</p>

        <?php if (!empty($flash)): ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?>"><?php echo htmlspecialchars($flash['message']); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo BASE_URL; ?>/admin_login.php" class="signin-form">
            <?php echo csrf_field(); ?>

            <div class="signin-field">
                <label for="identifier">Admin ID or email</label>
                <input type="text" id="identifier" name="identifier" placeholder="e.g. ADMIN-001" autocomplete="username" required autofocus>
            </div>

            <div class="signin-field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                    <button type="button" class="pw-toggle" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">Log in as admin</button>
        </form>

        <div class="signin-divider"><span>Not an administrator?</span></div>
        <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-outline btn-block">Member log in</a>

        <div class="signin-foot">
            <a href="<?php echo BASE_URL; ?>/home.php">Continue without logging in</a>
        </div>

<?php auth_stage_end(); ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
