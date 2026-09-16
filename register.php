<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

if (is_logged_in()) {
    redirect_to_dashboard();
}

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $student_id = clean($_POST['student_id']);
    $first_name = clean($_POST['first_name']);
    $last_name  = clean($_POST['last_name']);
    $email      = clean($_POST['email']);
    $course     = clean($_POST['course']);
    $year_level = clean($_POST['year_level']);
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];

    if (empty($student_id) || empty($first_name) || empty($last_name) || empty($email) || empty($course) || empty($year_level) || empty($password)) {
        $errors[] = 'All fields are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        $check = $conn->prepare("SELECT user_id FROM users WHERE student_id = ? OR email = ?");
        $check->bind_param('ss', $student_id, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $errors[] = 'An account with this Student ID or email already exists.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (student_id, first_name, last_name, email, password, course, year_level, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'member', 'active')");
            $stmt->bind_param('sssssss', $student_id, $first_name, $last_name, $email, $hashed, $course, $year_level);

            if ($stmt->execute()) {
                $success = true;
            } else {
                $errors[] = 'Registration failed. Please try again.';
            }
            $stmt->close();
        }
        $check->close();
    }
}

$page_title = 'Register';
require_once __DIR__ . '/includes/header.php';
?>

<section class="section auth-section">
    <div class="container narrow">
        <h2>Create Your CSO Account</h2>

        <?php if ($success): ?>
            <div class="alert alert-success">
                Registration successful! You can now <a href="<?php echo BASE_URL; ?>/login.php">log in</a>.
            </div>
        <?php else: ?>
            <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?php echo BASE_URL; ?>/register.php" class="form">
                <?php echo csrf_field(); ?>
                <div class="form-row">
                    <label for="student_id">Student ID</label>
                    <input type="text" id="student_id" name="student_id" placeholder="e.g. 2026-001" value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>" required>
                </div>
                <div class="form-row form-row-split">
                    <div>
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
                    </div>
                    <div>
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
                    </div>
                </div>
                <div class="form-row">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                </div>
                <div class="form-row form-row-split">
                    <div>
                        <label for="course">Course</label>
                        <input type="text" id="course" name="course" placeholder="e.g. BSCS" value="<?php echo isset($_POST['course']) ? htmlspecialchars($_POST['course']) : ''; ?>" required>
                    </div>
                    <div>
                        <label for="year_level">Year Level</label>
                        <select id="year_level" name="year_level" required>
                            <option value="">Select</option>
                            <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yr): ?>
                                <option value="<?php echo $yr; ?>" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] === $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row form-row-split">
                    <div>
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" minlength="8" required>
                    </div>
                    <div>
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Register</button>
                <p class="form-footnote">Already have an account? <a href="<?php echo BASE_URL; ?>/login.php">Log in</a></p>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
