<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

if (is_logged_in()) {
    redirect_to_dashboard();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $identifier = clean($_POST['identifier']); // student_id or email
    $password   = $_POST['password'];

    $stmt = $conn->prepare("SELECT user_id, first_name, last_name, password, role, status, profile_photo FROM users WHERE student_id = ? OR email = ?");
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if ($user['role'] !== 'admin') {
            // Deliberately vague — don't reveal whether the account exists
            // or what role it has, this login is admin-only.
            $error = 'Invalid admin credentials.';
        } elseif ($user['status'] !== 'active') {
            $error = 'This admin account is inactive. Please contact the system owner.';
        } elseif (password_verify($password, $user['password'])) {
            $_SESSION['user_id']       = $user['user_id'];
            $_SESSION['first_name']    = $user['first_name'];
            $_SESSION['last_name']     = $user['last_name'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['profile_photo'] = $user['profile_photo'];

            if (!empty($_SESSION['redirect_after_login'])) {
                $redirect = $_SESSION['redirect_after_login'];
                unset($_SESSION['redirect_after_login']);
                header('Location: ' . BASE_URL . $redirect);
                exit;
            }

            redirect_to_dashboard();
        } else {
            $error = 'Invalid admin credentials.';
        }
    } else {
        $error = 'Invalid admin credentials.';
    }
    $stmt->close();
}

$page_title = 'Admin Login';
require_once __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container narrow">
        <h2>Admin Login</h2>
        <p class="muted" style="margin-top:-8px;">Restricted access — for CSO NORSU administrators only.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo BASE_URL; ?>/admin_login.php" class="form">
            <?php echo csrf_field(); ?>
            <div class="form-row">
                <label for="identifier">Admin Student ID or Email</label>
                <input type="text" id="identifier" name="identifier" required autofocus>
            </div>
            <div class="form-row">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Log In as Admin</button>
            <p class="form-footnote"><a href="<?php echo BASE_URL; ?>/login.php">← Not an admin? Member login</a></p>
        </form>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
