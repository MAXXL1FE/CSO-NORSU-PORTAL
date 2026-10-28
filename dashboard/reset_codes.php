<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/otp.php';
require_role(['admin', 'officer']);

$me   = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];
$ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

$issued = null;   // one-time display of a new code
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $identifier = clean($_POST['identifier'] ?? '');
    $stmt = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, student_id, role, status
                            FROM users WHERE student_id = ? OR email = ? LIMIT 1");
    $stmt->bind_param('ss', $identifier, $identifier);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Codes are not issued for admin accounts, or for your own account.
    $allowed = $target
        && $target['status'] === 'active'
        && (int) $target['user_id'] !== $me
        && $target['role'] !== 'admin';

    if (!$target) {
        set_flash('error', 'No active account matches that Student ID or email.');
    } elseif (!$allowed) {
        set_flash('error', 'You are not allowed to issue a code for this account.');
    } else {
        $code = otp_issue($conn, (int) $target['user_id'], 'officer', $ip, $me);
        $_SESSION['issued_code'] = [
            'code'  => $code,
            'name'  => full_name($target),
            'sid'   => $target['student_id'],
            'at'    => time(),
        ];
    }
    header('Location: ' . BASE_URL . '/dashboard/reset_codes.php');
    exit;
}

// Show the code once, then forget it.
if (!empty($_SESSION['issued_code']) && time() - $_SESSION['issued_code']['at'] < 600) {
    $issued = $_SESSION['issued_code'];
}
unset($_SESSION['issued_code']);

$page_title = 'Reset Codes';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Reset Codes</h1>
        <p class="muted">Use this when a member cannot receive the emailed code. Confirm who they are first, then give them the code in person.</p>
    </div>
</div>

<?php if ($issued): ?>
<div class="panel" role="status">
    <div class="panel-head"><h2>Code for <?php echo e($issued['name']); ?></h2></div>
    <p class="muted">Student ID <?php echo e($issued['sid']); ?>. Share this once. It expires in <?php echo OTP_LIFETIME_MIN; ?> minutes, works one time, and is not shown again.</p>
    <p style="font-size:2rem;font-weight:800;letter-spacing:0.3em;margin:12px 0;font-family:var(--font-mono);"><?php echo e($issued['code']); ?></p>
    <p class="muted" style="font-size:0.9rem;">Tell them to open the Forgot password page, choose Student ID or email, and enter this code.</p>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head"><h2>Issue a code</h2></div>
    <form method="POST" class="form" autocomplete="off">
        <?php echo csrf_field(); ?>
        <div class="form-row">
            <label for="rc-id">Student ID or email</label>
            <input type="text" id="rc-id" name="identifier" required maxlength="120">
        </div>
        <button type="submit" class="btn btn-primary">Generate code</button>
    </form>
    <p class="muted" style="font-size:0.85rem;margin-top:14px;">Admin accounts can only be reset through the database by the system owner.</p>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
