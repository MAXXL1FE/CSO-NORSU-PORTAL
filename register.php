<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/otp.php';

if (is_logged_in()) {
    redirect_home();
}

$errors = [];
$success = false;
$success_faculty = false;
$auto_approved = false;   // true when an admin has turned off member approval

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $is_faculty  = (($_POST['account_type'] ?? 'student') === 'faculty');
    $student_id = clean($_POST['student_id']);
    $first_name  = clean($_POST['first_name']);
    $middle_name = clean($_POST['middle_name'] ?? '');
    $last_name   = clean($_POST['last_name']);
    $suffix      = clean($_POST['suffix'] ?? '');
    $email      = strtolower(clean($_POST['email']));   // stored lower-case: addresses are not case-sensitive, and the mail API matches exactly
    $faculty_position = clean($_POST['faculty_position'] ?? '');
    if ($is_faculty) {
        // Faculty: the course column holds their department, year level is fixed.
        $course     = clean($_POST['department'] ?? '');
        $year_level = 'Faculty';
    } else {
        $course     = 'BSCS';   // BSCS (Computer Science) is the only course offered
        $year_level = clean($_POST['year_level'] ?? '');
    }
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];

    if (empty($student_id) || empty($first_name) || empty($last_name) || empty($email) || empty($course) || empty($year_level) || empty($password)) {
        $errors[] = 'All fields are required.';
    }
    if (empty($_POST['agree_terms'])) {
        $errors[] = 'Please read and agree to the Terms and Conditions to create an account.';
    }
    if ($is_faculty && $faculty_position === '') {
        $errors[] = 'Please enter your position (e.g. Adviser, Instructor, Professor).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 10) {
        $errors[] = 'Password must be at least 10 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Students must be on the official Student ID list (when the list has been uploaded).
    if (empty($errors) && !$is_faculty) {
        $rc = $conn->query("SELECT COUNT(*) AS c FROM student_roster");
        if ($rc && (int) $rc->fetch_assoc()['c'] > 0) {
            $rs = $conn->prepare("SELECT 1 FROM student_roster WHERE student_id = ?");
            $rs->bind_param('s', $student_id);
            $rs->execute();
            $rs->store_result();
            if ($rs->num_rows === 0) {
                $errors[] = 'This Student ID is not on the CSO member list. Check the number, or ask a CSO officer for help.';
            }
            $rs->close();
        }
    }

    if (empty($errors)) {
        $check = $conn->prepare("SELECT user_id FROM users WHERE student_id = ? OR email = ?");
        $check->bind_param('ss', $student_id, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $errors[] = $is_faculty ? 'An account with this Employee ID or email already exists.' : 'An account with this Student ID or email already exists.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            // While email verification is switched on, every new account is held
            // inactive and unverified until the emailed code is entered
            // (verify_email.php). Only then do the usual approval rules apply.
            $verify_by_email = otp_email_available();

            if ($is_faculty) {
                // Faculty start as 'inactive' (pending). Staff approve them in Dashboard > Members,
                // and only approved faculty appear on the Home page.
                $new_verified = $verify_by_email ? 0 : 1;
                $stmt = $conn->prepare("INSERT INTO users (student_id, first_name, middle_name, last_name, suffix, email, password, course, year_level, role, user_type, faculty_position, status, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'member', 'faculty', ?, 'inactive', ?)");
                $stmt->bind_param('ssssssssssi', $student_id, $first_name, $middle_name, $last_name, $suffix, $email, $hashed, $course, $year_level, $faculty_position, $new_verified);
            } else {
                // Admin setting (Admin dashboard > Sign-up settings): when approval is off, new
                // student accounts are active straight away. Faculty always need admin approval.
                $needs_approval = member_approval_required($conn);
                if ($verify_by_email) {
                    $new_status    = 'inactive';
                    $new_pending   = 0;          // officers only see it once the email is verified
                    $new_verified  = 0;
                    $auto_approved = false;
                } else {
                    $new_status    = $needs_approval ? 'inactive' : 'active';
                    $new_pending   = $needs_approval ? 1 : 0;
                    $new_verified  = 1;
                    $auto_approved = !$needs_approval;
                }
                $stmt = $conn->prepare("INSERT INTO users (student_id, first_name, middle_name, last_name, suffix, email, password, course, year_level, role, status, approval_pending, email_verified) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'member', ?, ?, ?)");
                $stmt->bind_param('ssssssssssii', $student_id, $first_name, $middle_name, $last_name, $suffix, $email, $hashed, $course, $year_level, $new_status, $new_pending, $new_verified);
            }

            if ($stmt->execute()) {
                $new_user_id = (int) $conn->insert_id;
                $stmt->close();

                if ($verify_by_email) {
                    // Send the 6-digit code and hand the visitor over to verify_email.php.
                    $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    $code   = ev_issue($conn, $new_user_id, $ip);
                    $mailed = ev_send_email($email, trim($first_name . ' ' . $last_name), $code);
                    $_SESSION['verify_user'] = $new_user_id;
                    set_flash($mailed ? 'success' : 'error', $mailed
                        ? 'We sent a 6-digit code to ' . $email . '. It expires in ' . OTP_LIFETIME_MIN . ' minutes.'
                        : 'We could not send the code to ' . $email . ' just now. Try "Send a new code" below, or ask a CSO officer for help.');
                    header('Location: ' . BASE_URL . '/verify_email.php');
                    exit;
                }

                $success = true;
                $success_faculty = $is_faculty;
            } else {
                $errors[] = 'Registration failed. Please try again.';
                $stmt->close();
            }
        }
        $check->close();
    }
}

$page_title = 'Register';
$auth_layout = true;
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/auth_shell.php';
?>

<?php auth_stage_start('register'); ?>
            <h1 class="signin-title">Create your account</h1>
            <p class="signin-sub">Join CSO-NORSU in about a minute.</p>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php if ($success_faculty): ?>
                    Thank you! Your faculty request was sent. A CSO admin will review it &mdash; once approved you can <a href="<?php echo BASE_URL; ?>/login.php">log in</a> and you will be listed on the Home page with your position.
                <?php elseif ($auto_approved): ?>
                    Welcome to CSO! Your account is ready &mdash; you can <a href="<?php echo BASE_URL; ?>/login.php">log in</a> now.
                <?php else: ?>
                    Thank you! Your sign-up was received. A CSO officer will check it, and you can <a href="<?php echo BASE_URL; ?>/login.php">log in</a> once it is approved.<?php echo otp_email_available() ? ' We will also email you when it is reviewed.' : ' Check back later, or ask a CSO officer.'; ?>
                <?php endif; ?>
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
                <?php $acct = (($_POST['account_type'] ?? 'student') === 'faculty') ? 'faculty' : 'student'; ?>
                <div class="form-row">
                    <label>I am joining as</label>
                    <div class="seg acct-seg" id="acctSeg" role="radiogroup" aria-label="Account type" style="grid-template-columns:repeat(2,1fr);">
                        <label class="seg-btn<?php echo $acct === 'student' ? ' is-on' : ''; ?>" style="cursor:pointer;"><input type="radio" name="account_type" value="student" <?php echo $acct === 'student' ? 'checked' : ''; ?> style="position:absolute;opacity:0;pointer-events:none;">Student</label>
                        <label class="seg-btn<?php echo $acct === 'faculty' ? ' is-on' : ''; ?>" style="cursor:pointer;"><input type="radio" name="account_type" value="faculty" <?php echo $acct === 'faculty' ? 'checked' : ''; ?> style="position:absolute;opacity:0;pointer-events:none;">Faculty / Instructor</label>
                    </div>
                </div>
                <div class="form-row">
                    <label for="student_id" id="idLabel"><?php echo $acct === 'faculty' ? 'Employee ID' : 'Student ID'; ?></label>
                    <input type="text" id="student_id" name="student_id" placeholder="<?php echo $acct === 'faculty' ? 'e.g. EMP1024' : 'e.g. 2026001'; ?>" value="<?php echo isset($_POST['student_id']) ? htmlspecialchars($_POST['student_id']) : ''; ?>" required>
                </div>
                <div class="form-row form-row-split">
                    <div>
                        <label for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" placeholder="e.g. Juan" value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>" required>
                    </div>
                    <div>
                        <label for="middle_name">Middle Name <span class="muted" style="font-weight:400;">(optional)</span></label>
                        <input type="text" id="middle_name" name="middle_name" placeholder="e.g. Santos" value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>">
                    </div>
                    <div>
                        <label for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" placeholder="e.g. Dela Cruz" value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>" required>
                    </div>
                    <div>
                        <label for="suffix">Suffix <span class="muted" style="font-weight:400;">(optional, e.g. Jr., Sr., II, III)</span></label>
                        <input type="text" id="suffix" name="suffix" placeholder="e.g. Jr." value="<?php echo isset($_POST['suffix']) ? htmlspecialchars($_POST['suffix']) : ''; ?>">
                    </div>
                </div>
                <div class="form-row">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" placeholder="e.g. juandelacruz@gmail.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                </div>
                <div class="form-row form-row-split" data-for="student"<?php echo $acct === 'faculty' ? ' hidden' : ''; ?>>
                    <div>
                        <label for="course">Program</label>
                        <input type="text" id="course" value="BSCS — Computer Science" disabled>
                    </div>
                    <div>
                        <label for="year_level">Year Level</label>
                        <select id="year_level" name="year_level" <?php echo $acct === 'faculty' ? '' : 'required'; ?>>
                            <option value="">Select</option>
                            <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yr): ?>
                                <option value="<?php echo $yr; ?>" <?php echo (isset($_POST['year_level']) && $_POST['year_level'] === $yr) ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row form-row-split" data-for="faculty"<?php echo $acct === 'faculty' ? '' : ' hidden'; ?>>
                    <div>
                        <label for="faculty_position">Position</label>
                        <input type="text" id="faculty_position" name="faculty_position" list="positionList" placeholder="e.g. Adviser, Instructor" value="<?php echo isset($_POST['faculty_position']) ? htmlspecialchars($_POST['faculty_position']) : ''; ?>" <?php echo $acct === 'faculty' ? 'required' : ''; ?>>
                        <datalist id="positionList">
                            <option value="Adviser"><option value="Co-Adviser"><option value="Instructor"><option value="Professor"><option value="Department Head"><option value="Dean">
                        </datalist>
                    </div>
                    <div>
                        <label for="department">Department / College</label>
                        <input type="text" id="department" name="department" placeholder="e.g. College of Computer Science" value="<?php echo isset($_POST['department']) ? htmlspecialchars($_POST['department']) : ''; ?>" <?php echo $acct === 'faculty' ? 'required' : ''; ?>>
                    </div>
                </div>
                <p class="muted" data-for="faculty" style="font-size:0.82rem; margin:-4px 0 14px;"<?php echo $acct === 'faculty' ? '' : ' hidden'; ?>>Faculty accounts are reviewed by a CSO admin before they go live. Once approved, you will be shown on the Home page with your position.</p>
                <div class="form-row form-row-split">
                    <div>
                        <label for="password">Password</label>
                        <div class="pw-wrap">
                            <input type="password" id="password" name="password" placeholder="At least 10 characters" minlength="10" required data-pw-new data-pw-confirm="confirm_password" data-pw-rules="pw-rules-reg" aria-describedby="pw-rules-reg">
                            <button type="button" class="pw-toggle" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
                        </div>
                    </div>
                    <div>
                        <label for="confirm_password">Confirm Password</label>
                        <div class="pw-wrap">
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Re-enter your password" minlength="10" required>
                            <button type="button" class="pw-toggle" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
                        </div>
                    
                        <ul class="pw-rules" id="pw-rules-reg" aria-live="polite">
                            <li data-rule="len">At least 10 characters</li>
                            <li data-rule="match">Both passwords match</li>
                        </ul>
                    </div>
                </div>
                <label class="signin-check">
                    <input type="checkbox" name="agree_terms" id="agree_terms" value="1" required <?php echo !empty($_POST['agree_terms']) ? 'checked' : ''; ?>>
                    <span>I have read and agree to the <a href="<?php echo BASE_URL; ?>/terms.php" onclick="openTermsModal(event)">Terms and Conditions</a> and Privacy Policy.</span>
                </label>
                <button type="submit" class="btn btn-primary btn-block btn-lg">Create account</button>
                <p class="form-footnote">Already have an account? <a href="<?php echo BASE_URL; ?>/login.php" data-switch="login">Log in</a></p>
            </form>
        <?php endif; ?>
<?php auth_stage_end(); ?>

<script>
/* Student / Faculty switch: shows the matching fields and relabels the ID box. */
(function () {
    var radios = document.querySelectorAll('input[name="account_type"]');
    if (!radios.length) return;
    function apply() {
        var type = document.querySelector('input[name="account_type"]:checked').value;
        var fac = type === 'faculty';
        document.querySelectorAll('[data-for]').forEach(function (el) { el.hidden = el.getAttribute('data-for') !== type; });
        document.getElementById('idLabel').textContent = fac ? 'Employee ID' : 'Student ID';
        document.getElementById('student_id').placeholder = fac ? 'e.g. EMP1024' : 'e.g. 2026001';
        document.getElementById('year_level').required = !fac;
        document.getElementById('faculty_position').required = fac;
        document.getElementById('department').required = fac;
        document.querySelectorAll('#acctSeg .seg-btn').forEach(function (l) {
            l.classList.toggle('is-on', l.querySelector('input').checked);
        });
    }
    radios.forEach(function (r) { r.addEventListener('change', apply); });
    apply();
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
