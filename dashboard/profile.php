<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_login();

$uid = $_SESSION['user_id'];
$errors = [];

// ---- Quick actions from the Facebook-style header: cover, avatar, status note ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['profile_action'])) {
    csrf_check();
    $action = $_POST['profile_action'];

    if ($action === 'cover' || $action === 'avatar') {
        $field  = $action === 'cover' ? 'cover_photo' : 'profile_photo';
        $subdir = $action === 'cover' ? 'covers' : 'profiles';
        $upload = handle_image_upload($field, $subdir, $action === 'cover' ? 5 * 1024 * 1024 : 3 * 1024 * 1024);
        if (!$upload['ok']) {
            set_flash('error', $upload['error']);
        } elseif ($upload['path']) {
            $old = $conn->prepare("SELECT $field FROM users WHERE user_id = ?");
            $old->bind_param('i', $uid);
            $old->execute();
            $old_path = $old->get_result()->fetch_assoc()[$field] ?? null;
            if ($old_path) delete_uploaded_image($old_path);

            $up = $conn->prepare("UPDATE users SET $field = ? WHERE user_id = ?");
            $up->bind_param('si', $upload['path'], $uid);
            $up->execute();
            if ($action === 'avatar') $_SESSION['profile_photo'] = $upload['path'];
            set_flash('success', $action === 'cover' ? 'Cover photo updated.' : 'Profile photo updated.');
        }
    } elseif ($action === 'note') {
        $note = isset($_POST['clear_note']) ? '' : mb_substr(trim((string) ($_POST['status_note'] ?? '')), 0, 60);
        $note = $note === '' ? null : $note;
        $up = $conn->prepare("UPDATE users SET status_note = ? WHERE user_id = ?");
        $up->bind_param('si', $note, $uid);
        $up->execute();
        set_flash('success', $note === null ? 'Note cleared.' : 'Note saved.');
    }
    header('Location: ' . BASE_URL . '/dashboard/profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $first_name  = clean($_POST['first_name']);
    $middle_name = clean($_POST['middle_name'] ?? '');
    $last_name   = clean($_POST['last_name']);
    $suffix      = clean($_POST['suffix'] ?? '');
    $email      = clean($_POST['email']);
    $year_level = clean($_POST['year_level']);
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($first_name) || empty($last_name) || empty($email) || empty($year_level)) {
        $errors[] = 'All profile fields are required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!empty($new_password)) {
        if (strlen($new_password) < 10) {
            $errors[] = 'New password must be at least 10 characters.';
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
            $stmt = $conn->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, email=?, year_level=?, password=?, profile_photo=? WHERE user_id=?");
            $stmt->bind_param('ssssssssi', $first_name, $middle_name, $last_name, $suffix, $email, $year_level, $hashed, $photo_path, $uid);
        } elseif (!empty($new_password)) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, email=?, year_level=?, password=? WHERE user_id=?");
            $stmt->bind_param('sssssssi', $first_name, $middle_name, $last_name, $suffix, $email, $year_level, $hashed, $uid);
        } elseif ($photo_path) {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, email=?, year_level=?, profile_photo=? WHERE user_id=?");
            $stmt->bind_param('sssssssi', $first_name, $middle_name, $last_name, $suffix, $email, $year_level, $photo_path, $uid);
        } else {
            $stmt = $conn->prepare("UPDATE users SET first_name=?, middle_name=?, last_name=?, suffix=?, email=?, year_level=? WHERE user_id=?");
            $stmt->bind_param('ssssssi', $first_name, $middle_name, $last_name, $suffix, $email, $year_level, $uid);
        }
        $stmt->execute();

        if (!empty($new_password)) {
            // New token version: every other browser or device is signed out; this one stays in.
            $conn->query("UPDATE users SET token_version = token_version + 1 WHERE user_id = " . (int) $uid);
            $tv = $conn->query("SELECT token_version FROM users WHERE user_id = " . (int) $uid)->fetch_assoc();
            $_SESSION['tv'] = (int) $tv['token_version'];
            session_regenerate_id(true);
        }

        $_SESSION['first_name'] = $first_name;
        $_SESSION['middle_name'] = $middle_name;
        $_SESSION['last_name'] = $last_name;
        $_SESSION['suffix'] = $suffix;
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

$qr_img = $me['student_id'];   // drawn in the browser (assets/js/qr.js)

$page_title = 'My Profile';
$attended_stmt = $conn->prepare("SELECT COUNT(*) c FROM attendance WHERE user_id = ? AND status = 'present'");
$attended_stmt->bind_param('i', $uid);
$attended_stmt->execute();
$attended_count = (int) $attended_stmt->get_result()->fetch_assoc()['c'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/profile_header.php';

$is_faculty = (($me['user_type'] ?? 'student') === 'faculty');
$meta_line = $attended_count . ' event' . ($attended_count === 1 ? '' : 's') . ' attended'
    . ' &middot; ' . e($me['student_id']) . ' &middot; ' . e($me['course'])
    . ($is_faculty ? '' : ' &middot; ' . e($me['year_level']));
if ($is_faculty && !empty($me['faculty_position'])) $meta_line = e($me['faculty_position']) . ' &middot; ' . $meta_line;
if (!empty($me['committee_role'])) $meta_line = e($me['committee_role']) . ' &middot; ' . $meta_line;

ob_start();
?>
<button type="button" class="btn btn-primary" onclick="openQrModal(event)"><svg class="icon" aria-hidden="true"><use href="#i-qr"/></svg>My QR Code</button>
<a href="#edit-profile" class="btn fb-btn-soft"><svg class="icon" aria-hidden="true"><use href="#i-pencil"/></svg>Edit profile</a>
<div class="fb-menu-wrap">
    <button type="button" class="btn fb-btn-soft fb-btn-icon" data-menu-toggle aria-haspopup="true" aria-expanded="false" aria-label="More options"><svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg></button>
    <div class="fb-menu" hidden>
        <label for="avatarInput" class="fb-menu-item"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg>Change profile photo</label>
        <label for="coverInput" class="fb-menu-item"><svg class="icon" aria-hidden="true"><use href="#i-camera"/></svg>Edit cover photo</label>
        <a href="#password-section" class="fb-menu-item"><svg class="icon" aria-hidden="true"><use href="#i-lock"/></svg>Change password</a>
        <a href="<?php echo BASE_URL; ?>/logout.php" class="fb-menu-item fb-menu-danger"><svg class="icon" aria-hidden="true"><use href="#i-logout"/></svg>Log out</a>
    </div>
</div>
<?php
$profile_actions = ob_get_clean();
render_profile_header($me, ['editable' => true, 'meta' => $meta_line, 'actions' => $profile_actions]);
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-error"><ul><?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="panel" id="edit-profile">
    <div class="panel-head">
        <div class="ab-head">
            <h2>About me</h2>
            <span class="badge<?php echo $me['role'] === 'member' ? ' badge-outline' : ''; ?>"><?php echo ucfirst($me['role']); ?></span>
            <?php if (!empty($me['committee_role'])): ?>
                <span class="badge badge-outline"><?php echo htmlspecialchars($me['committee_role']); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <div class="about-me ab-layout" aria-label="About me">
        <div class="ab-main">
            <section class="ab-group" aria-labelledby="ab-personal">
                <h3 class="ab-group-title" id="ab-personal">Personal</h3>
                <dl class="ab-list">
                    <div class="ab-row"><dt>Full name</dt><dd><?php echo e(full_name($me)); ?></dd></div>
                    <div class="ab-row"><dt>Email</dt><dd><?php echo e($me['email']); ?></dd></div>
                </dl>
            </section>

            <section class="ab-group" aria-labelledby="ab-org">
                <h3 class="ab-group-title" id="ab-org"><?php echo $is_faculty ? 'Work' : 'Academic'; ?></h3>
                <dl class="ab-list">
                    <div class="ab-row"><dt><?php echo $is_faculty ? 'Employee ID' : 'Student ID'; ?></dt><dd><?php echo e($me['student_id']); ?></dd></div>
                    <?php if ($is_faculty): ?>
                    <div class="ab-row"><dt>Department</dt><dd><?php echo e($me['course']); ?></dd></div>
                    <div class="ab-row"><dt>Position</dt><dd><?php echo e($me['faculty_position'] ?: '—'); ?></dd></div>
                    <?php else: ?>
                    <div class="ab-row"><dt>Program</dt><dd><?php echo e($me['course']); ?></dd></div>
                    <div class="ab-row"><dt>Year level</dt><dd><?php echo e($me['year_level']); ?></dd></div>
                    <?php endif; ?>
                </dl>
            </section>

            <section class="ab-group" aria-labelledby="ab-cso">
                <h3 class="ab-group-title" id="ab-cso">CSO membership</h3>
                <dl class="ab-list">
                    <div class="ab-row"><dt>Role</dt><dd><?php echo e(ucfirst($me['role'])); ?></dd></div>
                    <?php if (!empty($me['committee_role'])): ?>
                    <div class="ab-row"><dt>Committee</dt><dd><?php echo e($me['committee_role']); ?></dd></div>
                    <?php endif; ?>
                    <div class="ab-row"><dt>Status</dt><dd><span class="status status-<?php echo e($me['status'] ?? 'active'); ?>"><?php echo e(ucfirst($me['status'] ?? 'active')); ?></span></dd></div>
                    <div class="ab-row"><dt><?php echo $is_faculty ? 'Joined' : 'Member since'; ?></dt><dd><?php echo !empty($me['created_at']) ? e(date('F j, Y', strtotime($me['created_at']))) : '—'; ?></dd></div>
                    <div class="ab-row"><dt>Events attended</dt><dd><?php echo (int) $attended_count; ?></dd></div>
                </dl>
            </section>
        </div>

        <aside class="ab-card" aria-label="Check-in QR code">
            <button type="button" class="ab-qr" onclick="openQrModal(event)" aria-label="Show my QR code" title="Click to enlarge">
                <img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-qr="<?php echo htmlspecialchars($qr_img); ?>" alt="QR code for ID <?php echo htmlspecialchars($me['student_id']); ?>">
            </button>
            <p class="ab-card-title">Check-in QR code</p>
            <p class="ab-card-sub">Show this to an officer at events to record your attendance.</p>
            <button type="button" class="btn btn-outline btn-sm" onclick="openQrModal(event)">Enlarge</button>
        </aside>
    </div>

    <form method="POST" id="editProfileForm"<?php echo empty($errors) ? ' hidden' : ''; ?>>
        <?php echo csrf_field(); ?>

        <div class="form-row form-row-split">
            <div><label for="fld-1">First Name</label><input id="fld-1" type="text" name="first_name" value="<?php echo htmlspecialchars($me['first_name']); ?>" required></div>
            <div><label for="fld-2">Middle Name <span class="muted" style="font-weight:400;">(optional)</span></label><input id="fld-2" type="text" name="middle_name" value="<?php echo htmlspecialchars($me['middle_name'] ?? ''); ?>"></div>
            <div><label for="fld-3">Last Name</label><input id="fld-3" type="text" name="last_name" value="<?php echo htmlspecialchars($me['last_name']); ?>" required></div>
            <div><label for="fld-4">Suffix <span class="muted" style="font-weight:400;">(optional)</span></label><input id="fld-4" type="text" name="suffix" value="<?php echo htmlspecialchars($me['suffix'] ?? ''); ?>"></div>
        </div>
        <div class="form-row"><label for="fld-5">Email</label><input id="fld-5" type="email" name="email" value="<?php echo htmlspecialchars($me['email']); ?>" required></div>
        <?php if ($is_faculty): ?>
        <input type="hidden" name="year_level" value="Faculty">
        <div class="form-row form-row-split">
            <div><label for="fld-6">Department / College</label><input id="fld-6" type="text" value="<?php echo htmlspecialchars($me['course']); ?>" disabled></div>
            <div><label for="fld-7">Position</label><input id="fld-7" type="text" value="<?php echo htmlspecialchars($me['faculty_position'] ?? ''); ?>" disabled><p class="muted" style="font-size:0.78rem; margin-top:4px;">Managed by CSO staff &mdash; contact an officer or admin to update this.</p></div>
        </div>
        <?php else: ?>
        <div class="form-row form-row-split">
            <div><label for="fld-8">Program</label><input id="fld-8" type="text" value="<?php echo htmlspecialchars($me['course']); ?>" disabled><p class="muted" style="font-size:0.78rem; margin-top:4px;">Managed by CSO staff — contact an officer or admin to update this.</p></div>
            <div>
                <label for="fld-9">Year Level</label>
                <select id="fld-9" name="year_level" required>
                    <?php foreach (['1st Year', '2nd Year', '3rd Year', '4th Year'] as $yr): ?>
                        <option value="<?php echo $yr; ?>" <?php echo $me['year_level'] === $yr ? 'selected' : ''; ?>><?php echo $yr; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
        <hr style="border:none; border-top:1px solid var(--line); margin:20px 0;">
        <p class="muted" id="password-section" style="font-size:0.85rem; margin-bottom:14px;">Leave the password fields blank to keep your current password.</p>
        <div class="form-row form-row-split">
            <div><label for="pf_new_password">New Password</label><div class="pw-wrap"><input type="password" id="pf_new_password" name="new_password" minlength="10" autocomplete="new-password" data-pw-new data-pw-confirm="pf_confirm_password" data-pw-rules="pw-rules-pf" aria-describedby="pw-rules-pf"><button type="button" class="pw-toggle" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button></div></div>
            <div><label for="pf_confirm_password">Confirm New Password</label><div class="pw-wrap"><input type="password" id="pf_confirm_password" name="confirm_password" minlength="10" autocomplete="new-password"><button type="button" class="pw-toggle" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button></div></div>
            <ul class="pw-rules" id="pw-rules-pf" aria-live="polite">
            <li data-rule="len">At least 10 characters</li>
            <li data-rule="match">Both passwords match</li>
                        </ul>
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <button type="button" class="btn btn-outline" id="editProfileCancel">Cancel</button>
    </form>
</div>

<script>
/* The edit form stays hidden until "Edit profile" (or "Change password") is used. */
(function () {
    var form = document.getElementById('editProfileForm');
    if (!form) return;
    function openForm(targetId) {
        form.hidden = false;
        var t = document.getElementById(targetId) || form;
        t.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    document.querySelectorAll('a[href="#edit-profile"], a[href="#password-section"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            openForm(a.getAttribute('href').slice(1));
        });
    });
    var cancel = document.getElementById('editProfileCancel');
    if (cancel) cancel.addEventListener('click', function () {
        form.reset();
        form.hidden = true;
        document.getElementById('edit-profile').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    if (location.hash === '#edit-profile' || location.hash === '#password-section') openForm(location.hash.slice(1));
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
