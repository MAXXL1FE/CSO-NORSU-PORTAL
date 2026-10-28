<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer', 'moderator']);

$is_admin = $_SESSION['role'] === 'admin';
$uid = $_SESSION['user_id'];
$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
$quick_message = null;
$quick_status = null;
$last_mode = 'in';
$reopen_scanner = false;

// ---------------------------------------------------------
// Quick check-in / check-out (manual / barcode-scanner / camera QR entry)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_checkin') {
    csrf_check();
    $event_id = (int) $_POST['event_id'];
    $student_id = clean($_POST['student_id']);
    $mode = ($_POST['mode'] ?? 'in') === 'out' ? 'out' : 'in';
    $last_mode = $mode;
    $reopen_scanner = !empty($_POST['reopen_scanner']);

    $u = $conn->prepare("SELECT user_id, first_name, middle_name, last_name, suffix, course, year_level FROM users WHERE student_id = ?");
    $u->bind_param('s', $student_id);
    $u->execute();
    $student = $u->get_result()->fetch_assoc();

    if (!$student) {
        $quick_status = 'error';
        $quick_message = "No student found with ID \"$student_id\".";
    } elseif ($mode === 'in') {
        $ins = $conn->prepare("
            INSERT INTO attendance (event_id, user_id, status, recorded_by, checked_in_at)
            VALUES (?, ?, 'present', ?, NOW())
            ON DUPLICATE KEY UPDATE status = 'present', recorded_by = VALUES(recorded_by), checked_in_at = NOW()
        ");
        $ins->bind_param('iii', $event_id, $student['user_id'], $uid);
        $ins->execute();
        $quick_status = 'success';
        $quick_message = full_name($student) . ' timed in at ' . date('g:i A') . '.';
    } else {
        $chk = $conn->prepare("SELECT status, time_out FROM attendance WHERE event_id = ? AND user_id = ?");
        $chk->bind_param('ii', $event_id, $student['user_id']);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if (!$existing || $existing['status'] !== 'present') {
            $quick_status = 'error';
            $quick_message = full_name($student) . ' hasn\'t timed in yet — use Time In first.';
        } else {
            $out = $conn->prepare("UPDATE attendance SET time_out = NOW(), recorded_by = ? WHERE event_id = ? AND user_id = ?");
            $out->bind_param('iii', $uid, $event_id, $student['user_id']);
            $out->execute();
            $quick_status = 'success';
            $quick_message = full_name($student) . ' timed out at ' . date('g:i A') . '.';
        }
    }
}

// ---------------------------------------------------------
// Manual time-out from the roster (per row, when scanning out isn't practical)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_timeout') {
    csrf_check();
    $event_id = (int) $_POST['event_id'];
    $target_user_id = (int) $_POST['user_id'];
    $stmt = $conn->prepare("UPDATE attendance SET time_out = NOW(), recorded_by = ? WHERE event_id = ? AND user_id = ? AND status = 'present'");
    $stmt->bind_param('iii', $uid, $event_id, $target_user_id);
    $stmt->execute();
    set_flash('success', 'Time out recorded.');
    header('Location: ' . BASE_URL . '/dashboard/attendance.php?event_id=' . $event_id);
    exit;
}

// ---------------------------------------------------------
// Manually edit a member's attendance record — lets admins/officers/
// moderators correct a wrong scan time or status directly.
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_record') {
    csrf_check();
    $event_id       = (int) $_POST['event_id'];
    $target_user_id = (int) $_POST['user_id'];
    $edit_status    = $_POST['edit_status'] === 'absent' ? 'absent' : 'present';
    $edit_time_in   = !empty($_POST['edit_time_in']) ? str_replace('T', ' ', $_POST['edit_time_in']) . ':00' : date('Y-m-d H:i:s');
    $edit_time_out  = !empty($_POST['edit_time_out']) ? str_replace('T', ' ', $_POST['edit_time_out']) . ':00' : null;

    $stmt = $conn->prepare("
        INSERT INTO attendance (event_id, user_id, status, recorded_by, checked_in_at, time_out)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), recorded_by = VALUES(recorded_by), checked_in_at = VALUES(checked_in_at), time_out = VALUES(time_out)
    ");
    $stmt->bind_param('iisiss', $event_id, $target_user_id, $edit_status, $uid, $edit_time_in, $edit_time_out);
    $stmt->execute();
    set_flash('success', 'Attendance record updated.');
    header('Location: ' . BASE_URL . '/dashboard/attendance.php?event_id=' . $event_id);
    exit;
}

// ---------------------------------------------------------
// Roster checkbox save
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_roster') {
    csrf_check();
    $event_id = (int) $_POST['event_id'];
    $present_ids = isset($_POST['present']) ? array_map('intval', $_POST['present']) : [];

    // Mark selected as present
    if (!empty($present_ids)) {
        $stmt = $conn->prepare("
            INSERT INTO attendance (event_id, user_id, status, recorded_by)
            VALUES (?, ?, 'present', ?)
            ON DUPLICATE KEY UPDATE status = 'present', recorded_by = VALUES(recorded_by)
        ");
        foreach ($present_ids as $pid) {
            $stmt->bind_param('iii', $event_id, $pid, $uid);
            $stmt->execute();
        }
    }
    // Mark unselected (but previously recorded) as absent
    $stmt2 = $conn->prepare("UPDATE attendance SET status = 'absent', recorded_by = ? WHERE event_id = ?" . (!empty($present_ids) ? " AND user_id NOT IN (" . implode(',', $present_ids) . ")" : ""));
    $stmt2->bind_param('ii', $uid, $event_id);
    $stmt2->execute();

    set_flash('success', 'Attendance roster saved.');
    header('Location: ' . BASE_URL . '/dashboard/attendance.php?event_id=' . $event_id);
    exit;
}

// ---------------------------------------------------------
// Attendance reminders (post / delete)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_reminder') {
    csrf_check();
    $reminder_event_id = !empty($_POST['reminder_event_id']) ? (int) $_POST['reminder_event_id'] : null;
    $message = clean($_POST['message']);

    if (empty($message)) {
        set_flash('error', 'Reminder message can\'t be empty.');
    } else {
        $stmt = $conn->prepare("INSERT INTO attendance_reminders (event_id, message, created_by) VALUES (?, ?, ?)");
        $stmt->bind_param('isi', $reminder_event_id, $message, $uid);
        $stmt->execute();
        set_flash('success', 'Reminder posted — members and officers will see it on their dashboard.');
    }
    header('Location: ' . BASE_URL . '/dashboard/attendance.php' . ($event_id ? '?event_id=' . $event_id : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_reminder') {
    csrf_check();
    $reminder_id = (int) $_POST['reminder_id'];
    $chk = $conn->prepare("SELECT created_by FROM attendance_reminders WHERE reminder_id = ?");
    $chk->bind_param('i', $reminder_id);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    if ($existing && ($is_admin || (int) $existing['created_by'] === $uid)) {
        $del = $conn->prepare("DELETE FROM attendance_reminders WHERE reminder_id = ?");
        $del->bind_param('i', $reminder_id);
        $del->execute();
        set_flash('success', 'Reminder removed.');
    }
    header('Location: ' . BASE_URL . '/dashboard/attendance.php' . ($event_id ? '?event_id=' . $event_id : ''));
    exit;
}

$events = $conn->query("SELECT event_id, title, event_date FROM events ORDER BY event_date DESC");
$events_for_reminder = $conn->query("SELECT event_id, title, event_date FROM events ORDER BY event_date DESC");

$reminders = $conn->query("
    SELECT r.reminder_id, r.message, r.created_at, r.event_id, e.title AS event_title, u.first_name, u.middle_name, u.last_name, u.suffix
    FROM attendance_reminders r
    LEFT JOIN events e ON e.event_id = r.event_id
    LEFT JOIN users u ON u.user_id = r.created_by
    ORDER BY r.created_at DESC
    LIMIT 10
");

$event = null;
$roster = null;
$registrants = null;
$present_count = 0;
$total_count = 0;

if ($event_id > 0) {
    $es = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
    $es->bind_param('i', $event_id);
    $es->execute();
    $event = $es->get_result()->fetch_assoc();

    if ($event) {
        $roster = $conn->prepare("
            SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.suffix, u.student_id, u.course, u.year_level,
                   (a.status = 'present') AS is_present, a.checked_in_at, a.time_out
            FROM users u
            LEFT JOIN attendance a ON a.event_id = ? AND a.user_id = u.user_id
            WHERE u.role IN ('member','officer') AND u.status = 'active'
            ORDER BY u.last_name ASC
        ");
        $roster->bind_param('i', $event_id);
        $roster->execute();
        $roster = $roster->get_result();

        $pc = $conn->prepare("SELECT COUNT(*) c FROM attendance WHERE event_id = ? AND status = 'present'");
        $pc->bind_param('i', $event_id);
        $pc->execute();
        $present_count = (int) $pc->get_result()->fetch_assoc()['c'];

        $tc = $conn->prepare("SELECT COUNT(*) c FROM users WHERE role IN ('member','officer') AND status = 'active'");
        $tc->execute();
        $total_count = (int) $tc->get_result()->fetch_assoc()['c'];

        $rg = $conn->prepare("
            SELECT u.first_name, u.middle_name, u.last_name, u.suffix, u.student_id
            FROM event_registrations r JOIN users u ON u.user_id = r.user_id
            WHERE r.event_id = ? ORDER BY u.last_name ASC
        ");
        $rg->bind_param('i', $event_id);
        $rg->execute();
        $registrants = $rg->get_result();
    }
}

$page_title = 'Attendance';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Attendance</h1>
        <p class="muted">Pick an event to run time in/out, view the roster, export a CSV, or post a reminder.</p>
    </div>
    <button type="button" class="btn btn-outline btn-sm print-btn no-print" onclick="window.print()"><svg class="icon" aria-hidden="true"><use href="#i-printer"/></svg>Print</button>
</div>

<div class="panel">
    <div class="panel-head"><h2>Attendance Reminders</h2></div>
    <p class="muted" style="margin-top:-8px;">Post a short reminder — it shows up on every member's and officer's dashboard. Tie it to an event, or leave it general.</p>
    <form method="POST" style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="post_reminder">
        <div style="flex:2; min-width:220px;">
            <label for="fld-1">Message</label>
            <input id="fld-1" type="text" name="message" placeholder="e.g. Don't forget to time in at the GA on Friday!" maxlength="255" required>
        </div>
        <div style="flex:1; min-width:180px;">
            <label for="fld-2">Event (optional)</label>
            <select id="fld-2" name="reminder_event_id">
                <option value="">General reminder</option>
                <?php while ($ev = $events_for_reminder->fetch_assoc()): ?>
                    <option value="<?php echo $ev['event_id']; ?>" <?php echo $event_id === (int) $ev['event_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ev['title']) . ' — ' . fmt_date($ev['event_date']); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Post Reminder</button>
    </form>

    <?php if ($reminders->num_rows > 0): ?>
        <div class="table-wrap" style="margin-top:18px;">
            <table class="data-table">
                <thead><tr><th scope="col">Message</th><th scope="col">Event</th><th scope="col">Posted</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php while ($r = $reminders->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['message']); ?></td>
                        <td><?php echo $r['event_title'] ? htmlspecialchars($r['event_title']) : '<span class="muted">General</span>'; ?></td>
                        <td class="muted"><?php echo date('M j, g:i A', strtotime($r['created_at'])); ?></td>
                        <td>
                            <form method="POST" <?php echo confirm_attr('Delete this reminder? This cannot be undone.'); ?>>
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_reminder">
                                <input type="hidden" name="reminder_id" value="<?php echo $r['reminder_id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state" style="padding:20px;"><p>No reminders posted yet.</p></div>
    <?php endif; ?>
</div>

<div class="panel">
    <form method="GET" class="toolbar">
        <select name="event_id" onchange="this.form.submit()" style="max-width:340px;">
            <option value="">Select an event…</option>
            <?php while ($ev = $events->fetch_assoc()): ?>
                <option value="<?php echo $ev['event_id']; ?>" <?php echo $ev['event_id'] == $event_id ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ev['title']) . ' — ' . fmt_date($ev['event_date']); ?>
                </option>
            <?php endwhile; ?>
        </select>
    </form>
</div>

<?php if ($event): ?>

    <div class="scan-box">
        <label for="quick-id">Quick Time In / Time Out — scan a QR code, use a barcode scanner, or type the Student ID and press Enter</label>
        <form method="POST" id="quick-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="quick_checkin">
            <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
            <input type="hidden" name="mode" id="quick-mode" value="<?php echo $last_mode; ?>">
            <input type="hidden" name="reopen_scanner" id="reopen_scanner" value="0">
            <div class="scan-mode-toggle" style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                <button type="button" id="mode-in-btn" class="btn btn-sm <?php echo $last_mode === 'in' ? 'btn-primary' : 'btn-outline'; ?>" onclick="setScanMode('in')">Time In</button>
                <button type="button" id="mode-out-btn" class="btn btn-sm <?php echo $last_mode === 'out' ? 'btn-primary' : 'btn-outline'; ?>" onclick="setScanMode('out')">Time Out</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="openQrScanner()" style="margin-left:auto;"><svg class="icon" aria-hidden="true" style="width:16px;height:16px;"><use href="#i-camera"/></svg> Scan QR with Camera</button>
            </div>
            <input type="text" id="quick-id" name="student_id" autocomplete="off" autofocus placeholder="Student ID">
        </form>
        <?php if ($quick_message): ?>
            <div class="alert alert-<?php echo $quick_status === 'success' ? 'success' : 'error'; ?>" style="margin-top:14px; text-align:left;"><?php echo htmlspecialchars($quick_message); ?></div>
        <?php endif; ?>
        <p class="scan-counter"><strong><?php echo $present_count; ?></strong> / <?php echo $total_count; ?> checked in</p>
    </div>

    <script>
        function setScanMode(mode) {
            document.getElementById('quick-mode').value = mode;
            var isIn = mode === 'in';
            setModeBtnState('mode-in-btn', isIn);
            setModeBtnState('mode-out-btn', !isIn);
            setModeBtnState('scan-mode-in-btn', isIn);
            setModeBtnState('scan-mode-out-btn', !isIn);
            document.getElementById('quick-id').focus();
            var label = document.getElementById('scan-mode-label');
            if (label) label.textContent = isIn ? 'Time In' : 'Time Out';
        }
        function setModeBtnState(id, active) {
            var el = document.getElementById(id);
            if (el) el.className = 'btn btn-sm ' + (active ? 'btn-primary' : 'btn-outline');
        }
        // Barcode scanners type fast then send Enter — this submits automatically
        // and refocuses the field so the next scan is ready immediately.
        document.getElementById('quick-id').focus();
    </script>

    <!-- ---------------------------------------------------------
         Scan result confirmation — shown right after a camera scan
         is recorded, with a button to continue to the next scan.
         --------------------------------------------------------- -->
    <div class="modal-overlay" id="scanResultOverlay" onclick="if (event.target === this) closeScanResultModal();">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="scanResultTitle" style="max-width:380px; text-align:center;">
            <div class="modal-body" style="padding-top:30px; padding-bottom:6px;">
                <div id="scan-result-icon" class="scan-result-icon is-success" style="margin-bottom:10px;"><svg class="icon icon-xl" aria-hidden="true"><use href="#i-check-circle"/></svg></div>
                <h3 id="scanResultTitle" style="margin:0 0 8px;">Successful Scan!</h3>
                <p id="scan-result-message" class="muted" style="margin:0;">Attendance recorded — thank you for your patience.</p>
            </div>
            <div class="modal-footer" style="justify-content:center; flex-wrap:wrap;">
                <button type="button" class="btn btn-primary btn-sm" onclick="closeScanResultModal(); openQrScanner();"><svg class="icon" aria-hidden="true" style="width:16px;height:16px;"><use href="#i-camera"/></svg> Scan Next</button>
                <button type="button" class="btn btn-outline btn-sm" onclick="closeScanResultModal();">Done Scanning</button>
            </div>
        </div>
    </div>

    <!-- ---------------------------------------------------------
         Camera QR scanner — reads a member's profile QR code
         (which encodes their raw Student ID) and feeds it into the
         same quick check-in flow as typing/barcode scanning.
         --------------------------------------------------------- -->
    <div class="modal-overlay" id="scanQrOverlay" onclick="if (event.target === this) closeQrScanner();">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="scanQrTitle" style="max-width:420px;">
            <div class="modal-header">
                <h3 id="scanQrTitle">Scan Member QR Code</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeQrScanner()">&times;</button>
            </div>
            <div class="modal-body" style="text-align:center;">
                <div style="display:flex; gap:8px; justify-content:center; margin-bottom:12px;">
                    <button type="button" id="scan-mode-in-btn" class="btn btn-sm <?php echo $last_mode === 'in' ? 'btn-primary' : 'btn-outline'; ?>" onclick="setScanMode('in')">Time In</button>
                    <button type="button" id="scan-mode-out-btn" class="btn btn-sm <?php echo $last_mode === 'out' ? 'btn-primary' : 'btn-outline'; ?>" onclick="setScanMode('out')">Time Out</button>
                </div>
                <p class="muted" style="font-size:0.85rem; margin-top:0;">Mode: <strong id="scan-mode-label"><?php echo $last_mode === 'out' ? 'Time Out' : 'Time In'; ?></strong> — point the camera at the member's QR code from their profile.</p>
                <p class="muted" style="font-size:0.78rem; margin-top:-4px;"> Requires an internet connection to scan and record attendance — make sure your device is online.</p>
                <div id="qr-reader" style="width:100%; border-radius:var(--radius-sm); overflow:hidden;"></div>
                <p id="scan-status" class="muted" style="margin-top:10px; font-size:0.82rem;">Starting camera…</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline btn-sm" onclick="closeQrScanner()">Cancel</button>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <script>
        var html5QrScanner = null;
        var scanLocked = false;

        function openQrScanner() {
            var overlay = document.getElementById('scanQrOverlay');
            overlay.classList.add('open');
            document.body.style.overflow = 'hidden';
            scanLocked = false;
            var currentMode = document.getElementById('quick-mode').value === 'out' ? 'out' : 'in';
            setModeBtnState('scan-mode-in-btn', currentMode === 'in');
            setModeBtnState('scan-mode-out-btn', currentMode === 'out');
            document.getElementById('scan-mode-label').textContent = currentMode === 'out' ? 'Time Out' : 'Time In';
            document.getElementById('scan-status').textContent = 'Starting camera…';

            if (typeof Html5Qrcode === 'undefined') {
                document.getElementById('scan-status').textContent = 'Camera scanner failed to load — check your internet connection, then try again.';
                return;
            }

            html5QrScanner = new Html5Qrcode('qr-reader');
            html5QrScanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 220, height: 220 } },
                function onScanSuccess(decodedText) {
                    if (scanLocked) return;
                    scanLocked = true;
                    document.getElementById('scan-status').textContent = 'Scanned ' + decodedText + ' — recording…';
                    document.getElementById('quick-id').value = decodedText.trim();
                    document.getElementById('reopen_scanner').value = '1';
                    html5QrScanner.stop().catch(function () {}).finally(function () {
                        document.getElementById('quick-form').submit();
                    });
                },
                function onScanFailure() { /* fires every frame with no QR in view — ignore */ }
            ).catch(function (err) {
                document.getElementById('scan-status').textContent = 'Could not access the camera: ' + err + '. Check camera permissions and that the site is loaded over HTTPS.';
            });
        }

        function closeQrScanner() {
            document.getElementById('scanQrOverlay').classList.remove('open');
            document.body.style.overflow = '';
            if (html5QrScanner) {
                html5QrScanner.stop().catch(function () {});
            }
        }

        function openScanResultModal() {
            document.getElementById('scanResultOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function closeScanResultModal() {
            document.getElementById('scanResultOverlay').classList.remove('open');
            document.body.style.overflow = '';
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            closeQrScanner();
            closeScanResultModal();
        });

        <?php if ($reopen_scanner && $quick_message): ?>
        // A camera scan was just submitted — show the result (success or
        // issue) with a "Scan Next" button instead of silently reopening
        // the camera, so the officer gets clear confirmation each time.
        var scanIcon = document.getElementById('scan-result-icon');
        scanIcon.className = 'scan-result-icon <?php echo $quick_status === 'success' ? 'is-success' : 'is-warning'; ?>';
        scanIcon.innerHTML = '<svg class="icon icon-xl" aria-hidden="true"><use href="#i-<?php echo $quick_status === 'success' ? 'check-circle' : 'alert'; ?>"/></svg>';
        document.getElementById('scanResultTitle').textContent = <?php echo json_encode($quick_status === 'success' ? 'Successful Scan!' : 'Scan Issue'); ?>;
        document.getElementById('scan-result-message').textContent = <?php echo json_encode($quick_status === 'success' ? $quick_message . ' Thank you for your patience.' : $quick_message); ?>;
        openScanResultModal();
        <?php endif; ?>
    </script>

    <div class="panel">
        <div class="panel-head">
            <h2><?php echo htmlspecialchars($event['title']); ?> — Roster</h2>
            <div class="table-actions">
                <a href="<?php echo BASE_URL; ?>/dashboard/gallery.php?event_id=<?php echo $event_id; ?>" class="btn btn-outline btn-sm">Event Photos</a>
                <a href="<?php echo BASE_URL; ?>/dashboard/attendance_export.php?event_id=<?php echo $event_id; ?>" class="btn btn-outline btn-sm">Download CSV</a>
            </div>
        </div>
        <form method="POST">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="save_roster">
            <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th scope="col" style="width:40px;"></th><th scope="col">Name</th><th scope="col">Student ID</th><th scope="col">Program / Year</th><th scope="col">Time In</th><th scope="col">Time Out</th><th scope="col"></th></tr></thead>
                    <tbody>
                    <?php while ($r = $roster->fetch_assoc()): ?>
                        <tr>
                            <td><input type="checkbox" name="present[]" value="<?php echo $r['user_id']; ?>" <?php echo $r['is_present'] ? 'checked' : ''; ?>></td>
                            <td><?php echo htmlspecialchars(full_name($r)); ?></td>
                            <td><?php echo htmlspecialchars($r['student_id']); ?></td>
                            <td><?php echo htmlspecialchars($r['course'] . ' — ' . $r['year_level']); ?></td>
                            <td class="muted"><?php echo $r['checked_in_at'] ? date('g:i A', strtotime($r['checked_in_at'])) : '—'; ?></td>
                            <td class="muted"><?php echo $r['time_out'] ? date('g:i A', strtotime($r['time_out'])) : '—'; ?></td>
                            <td>
                                <?php if ($r['is_present'] && !$r['time_out']): ?>
                                <form method="POST" style="display:inline;">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="set_timeout">
                                    <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                                    <input type="hidden" name="user_id" value="<?php echo $r['user_id']; ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Time Out Now</button>
                                </form>
                                <?php endif; ?>
                                <button type="button" class="btn btn-outline btn-sm" style="margin-left:6px;"
                                    onclick="openEditAttendanceModal(<?php echo json_encode($r['user_id']); ?>, <?php echo json_encode(full_name($r)); ?>, <?php echo json_encode($r['is_present'] ? 'present' : 'absent'); ?>, <?php echo json_encode($r['checked_in_at'] ? date('Y-m-d\TH:i', strtotime($r['checked_in_at'])) : ''); ?>, <?php echo json_encode($r['time_out'] ? date('Y-m-d\TH:i', strtotime($r['time_out'])) : ''); ?>)">Edit</button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:16px;">Save Roster</button>
        </form>
    </div>

    <div class="modal-overlay" id="editAttendanceModalOverlay" onclick="if (event.target === this) closeEditAttendanceModal();">
        <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="editAttendanceModalTitle" style="max-width:400px;">
            <div class="modal-header">
                <h3 id="editAttendanceModalTitle">Edit Attendance</h3>
                <button type="button" class="modal-close" aria-label="Close" onclick="closeEditAttendanceModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form method="POST" id="editAttendanceForm" style="display:flex; flex-direction:column; gap:10px;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="edit_record">
                    <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                    <input type="hidden" name="user_id" id="editAttUserId">
                    <p id="editAttName" style="font-weight:600; margin:0;"></p>
                    <div class="form-row">
                        <label for="editAttStatus">Status</label>
                        <select name="edit_status" id="editAttStatus">
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="editAttTimeIn">Time In</label>
                        <input type="datetime-local" name="edit_time_in" id="editAttTimeIn">
                    </div>
                    <div class="form-row">
                        <label for="editAttTimeOut">Time Out</label>
                        <input type="datetime-local" name="edit_time_out" id="editAttTimeOut">
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
    <script>
    function openEditAttendanceModal(userId, name, status, timeIn, timeOut) {
        document.getElementById('editAttUserId').value = userId;
        document.getElementById('editAttName').textContent = name;
        document.getElementById('editAttStatus').value = status;
        document.getElementById('editAttTimeIn').value = timeIn;
        document.getElementById('editAttTimeOut').value = timeOut;
        document.getElementById('editAttendanceModalOverlay').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeEditAttendanceModal() {
        document.getElementById('editAttendanceModalOverlay').classList.remove('open');
        document.body.style.overflow = '';
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeEditAttendanceModal(); });
    </script>

    <div class="panel">
        <div class="panel-head"><h2>Pre-Registered Members</h2></div>
        <?php if ($registrants->num_rows > 0): ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th scope="col">Name</th><th scope="col">Student ID</th></tr></thead>
                    <tbody>
                    <?php while ($r = $registrants->fetch_assoc()): ?>
                        <tr><td><?php echo htmlspecialchars(full_name($r)); ?></td><td><?php echo htmlspecialchars($r['student_id']); ?></td></tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state"><h3>No one pre-registered for this event</h3></div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="empty-state"><h3>Choose an event above</h3><p>Attendance tools appear once you select one.</p></div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
