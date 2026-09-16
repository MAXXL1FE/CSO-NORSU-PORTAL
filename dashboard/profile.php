<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_login();

$uid = $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $first_name = clean($_POST['first_name']);
    $last_name  = clean($_POST['last_name']);
    $email      = clean($_POST['email']);
    $course     = clean($_POST['course']);
    $year_level = clean($_POST['year_level']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($first_name) || empty($last_name) || empty($email) || empty($course) || empty($year_level)) {
        $errors[] = 'All profile fields are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!empty($new_password)) {
        if (strlen($new_password) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
        if ($new_password !== $confirm_password) {
            $errors[] = 'New password and confirmation do not match.';
        }
    }

    $email_check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
    $email_check->bind_param('si', $email, $uid);
    $email_check->execute();
    if ($email_check->get_result()->num_rows > 0) {
        $errors[] = 'That email is already in use by another account.';
    }

    $photo_path = null;
    if (empty($errors)) {
        $upload = handle_image_upload('profile_photo', 'profiles');
        if (!$upload['ok']) {
            $errors[] = $upload['error'];
        } else {
            $photo_path = $upload['path'];
        }
    }

    if (empty($errors)) {
        if ($photo_path) {
            // Replacing an existing photo — remove the old file so uploads don't pile up
            $old = $conn->prepare("SELECT profile_photo FROM users WHERE user_id = ?");
            $old->bind_param('i', $uid);
            $old->execute();
            $old_photo = $old->get_result()->fetch_assoc()['profile_photo'] ?? null;
            if ($old_photo) delete_uploaded_image($old_photo);
        }

        if (!empty($new_password) && $photo_path) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, course=?, year_level=?, password=?, profile_photo=? WHERE user_id=?");
            $stmt->bind_param('sssssssi', $first_name, $last_name, $email, $course, $year_level, $hashed, $photo_path, $uid);
        } elseif (!empty($new_password)) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, course=?, year_level=?, password=? WHERE user_id=?");
            $stmt->bind_param('ssssssi', $first_name, $last_name, $email, $course, $year_level, $hashed, $uid);
        } elseif ($photo_path) {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, course=?, year_level=?, profile_photo=? WHERE user_id=?");
            $stmt->bind_param('ssssssi', $first_name, $last_name, $email, $course, $year_level, $photo_path, $uid);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, course=?, year_level=? WHERE user_id=?");
            $stmt->bind_param('sssssi', $first_name, $last_name, $email, $course, $year_level, $uid);
        }
        $stmt->execute();

        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name'] = $last_name;
        if ($photo_path) $_SESSION['profile_photo'] = $photo_path;
        set_flash('success', 'Profile updated.');
        header('Location: ' . BASE_URL . '/dashboard/profile.php');
        exit;
    }
}

$s = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
$s->bind_param('i', $uid);
$s->execute();
$me = $s->get_result()->fetch_assoc();

$qr_img = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' . urlencode($me['student_id']);

$page_title = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>My Profile</h1>
        <p class="muted">Keep your details current so officers can reach you.</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <div style="display:flex; align-items:center; gap:14px;">
            <button type="button" onclick="openQrModal(event)" style="padding:0; border:0; background:none; cursor:pointer; flex-shrink:0;" title="Click to enlarge — scan this at check-in">
                <img src="<?php echo htmlspecialchars($qr_img); ?>" alt="QR code for student ID <?php echo htmlspecialchars($me['student_id']); ?>" style="width:60px; height:60px; background:#fff; border:1px solid var(--line); border-radius:8px; padding:4px; display:block;">
            </button>
            <h2 style="margin:0;"><?php echo htmlspecialchars($me['student_id']); ?></h2>
        </div>
        <span class="badge<?php echo $me['role'] === 'member' ? ' badge-outline' : ''; ?>"><?php echo ucfirst($me['role']); ?></span>
        <?php if (!empty($me['committee_role'])): ?>
            <span class="badge badge-outline"><?php echo htmlspecialchars($me['committee_role']); ?></span>
        <?php endif; ?>
    </div>
    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>

        <div class="form-row" style="display:flex; align-items:center; gap:16px;">
            <?php if (!empty($me['profile_photo'])): ?>
                <img src="<?php echo asset_v($me['profile_photo']); ?>" alt="Your profile photo" style="width:72px; height:72px; border-radius:50%; object-fit:cover; border:1px solid var(--line);">
            <?php else: ?>
                <span class="avatar-chip" style="width:72px; height:72px; font-size:1.4rem;"><?php echo htmlspecialchars(initials($me['first_name'], $me['last_name'])); ?></span>
            <?php endif; ?>
            <div style="flex:1;">
                <label>Profile Photo</label>
                <input type="file" name="profile_photo" accept="image/png,image/jpeg,image/webp,image/gif">
                <p class="muted" style="font-size:0.78rem; margin:6px 0 0;">Shown in the officer directory and around the dashboard. JPG, PNG, WEBP, or GIF — up to 3MB.</p>
            </div>
        </div>

        <div class="form-row form-row-split">
            <div><label>First Name</label><input type="text" name="first_name" value="<?php echo htmlspecialchars($me['first_name']); ?>" required></div>
            <div><label>Last Name</label><input type="text" name="last_name" value="<?php echo htmlspecialchars($me['last_name']); ?>" required></div>
        </div>
        <div class="form-row"><label>Email</label><input type="email" name="email" value="<?php echo htmlspecialchars($me['email']); ?>" required></div>
        <div class="form-row form-row-split">
            <div><label>Course</label><input type="text" name="course" value="<?php echo htmlspecialchars($me['course']); ?>" required></div>
            <div>
                <label>Year Level</label>
                <select name="year_level" required>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo $me['year_level'] === $yr ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <hr style="border:none; border-top:1px solid var(--line); margin:20px 0;">
        <p class="muted" style="font-size:0.85rem; margin-bottom:14px;">Leave the password fields blank to keep your current password.</p>
        <div class="form-row form-row-split">
            <div><label>New Password</label><input type="password" name="new_password" minlength="8"></div>
            <div><label>Confirm New Password</label><input type="password" name="confirm_password" minlength="8"></div>
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
