<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

if (is_logged_in()) {
    redirect_to_dashboard();
}

$error = '';
$show_admin_link = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (empty($_POST['agree_terms'])) {
        $error = 'You must agree to the Terms and Conditions before logging in.';
    } else {
        $identifier = clean($_POST['identifier']); // student_id or email
        $password   = $_POST['password'];

        $stmt = $conn->prepare("SELECT user_id, first_name, last_name, password, role, status, profile_photo FROM users WHERE student_id = ? OR email = ?");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if ($user['role'] === 'admin') {
                $error = 'Admin accounts cannot log in here. Please use the Admin Login page instead.';
                $show_admin_link = true;
            } elseif ($user['status'] !== 'active') {
                $error = 'Your account is inactive. Please contact an officer or admin.';
            } elseif (password_verify($password, $user['password'])) {
                $_SESSION['user_id']    = $user['user_id'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name']  = $user['last_name'];
                $_SESSION['role']       = $user['role'];
                $_SESSION['profile_photo'] = $user['profile_photo'];

                if (!empty($_SESSION['redirect_after_login'])) {
                    $redirect = $_SESSION['redirect_after_login'];
                    unset($_SESSION['redirect_after_login']);
                    header('Location: ' . BASE_URL . $redirect);
                    exit;
                }

                redirect_to_dashboard();
            } else {
                $error = 'Incorrect password.';
            }
        } else {
            $error = 'No account found with that Student ID or email.';
        }
        $stmt->close();
    }
}

$page_title = 'Login';
require_once __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container narrow">
        <h2>Member Login</h2>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error); ?>
                <?php if ($show_admin_link): ?>
                    <a href="<?php echo BASE_URL; ?>/admin_login.php">Go to Admin Login →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo BASE_URL; ?>/login.php" class="form">
                <?php echo csrf_field(); ?>
            <div class="form-row">
                <label for="identifier">Student ID or Email</label>
                <input type="text" id="identifier" name="identifier" required autofocus>
            </div>
            <div class="form-row">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-row form-row-checkbox">
                <label class="checkbox-label">
                    <input type="checkbox" name="agree_terms" id="agree_terms" required>
                    I have read and agree to the <a href="<?php echo BASE_URL; ?>/terms.php" onclick="openTermsModal(event)">Terms and Conditions</a>
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Log In</button>
            <p class="form-footnote">Don't have an account? <a href="<?php echo BASE_URL; ?>/register.php">Register here</a></p>
            <p class="form-footnote"><a href="<?php echo BASE_URL; ?>/home.php">Browse events &amp; officers without logging in →</a></p>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
