<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_login();

$uid = $_SESSION['user_id'];
$role = $_SESSION['role'];
$can_see_all = in_array($role, ['admin', 'officer'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $subject = clean($_POST['subject']);
        $message = clean($_POST['message']);
        $rating = isset($_POST['rating']) && $_POST['rating'] !== '' ? (int) $_POST['rating'] : null;
        if ($rating !== null) $rating = max(1, min(5, $rating));

        if (empty($subject) || empty($message)) {
            set_flash('error', 'Please fill in a subject and message.');
        } else {
            $stmt = $conn->prepare("INSERT INTO feedback (user_id, subject, message, rating) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('issi', $uid, $subject, $message, $rating);
            $stmt->execute();
            set_flash('success', 'Thanks — your feedback was submitted.');
        }
    }

    if ($action === 'delete' && $can_see_all) {
        $fid = (int) $_POST['feedback_id'];
        $stmt = $conn->prepare("DELETE FROM feedback WHERE feedback_id = ?");
        $stmt->bind_param('i', $fid);
        $stmt->execute();
        set_flash('success', 'Feedback entry deleted.');
    }

    header('Location: ' . BASE_URL . '/dashboard/feedback.php');
    exit;
}

if ($can_see_all) {
    $rows = $conn->query("
        SELECT f.*, u.first_name, u.middle_name, u.last_name, u.suffix, u.student_id
        FROM feedback f JOIN users u ON u.user_id = f.user_id
        ORDER BY f.created_at DESC
    ");
} else {
    $stmt = $conn->prepare("SELECT * FROM feedback WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = $stmt->get_result();
}

function render_stars($rating) {
    if ($rating === null) return '';
    $html = '<span class="star-rating">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<span class="' . ($i <= $rating ? 'filled' : '') . '">&#9733;</span>';
    }
    return $html . '</span>';
}

// Sender details shown in the "From" line of the compose window
$me_email = '';
try {
    $mq = $conn->prepare("SELECT email FROM users WHERE user_id = ?");
    if ($mq) { $mq->bind_param('i', $uid); $mq->execute(); $me_email = (string) ($mq->get_result()->fetch_assoc()['email'] ?? ''); $mq->close(); }
} catch (Throwable $e) { /* no email column: leave blank */ }
$me_name = full_name($_SESSION);
$rows_total = $rows ? $rows->num_rows : 0;

$page_title = 'Feedback';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="fbx">
    <header class="fbx-head">
        <h1>Feedback</h1>
        <p><?php echo $can_see_all ? 'Read what members are telling CSO, and send your own message to the officers.' : 'Write to the CSO officers. Tell us what is working and what could be better.'; ?></p>
    </header>

    <div class="fbx-grid">
        <!-- ===== Compose window ===== -->
        <section class="fbx-compose" aria-labelledby="fbx-compose-title">
            <div class="fbx-compose-bar">
                <span class="fbx-compose-title" id="fbx-compose-title"><svg class="icon" aria-hidden="true"><use href="#i-message"/></svg>New message</span>
                <span class="fbx-dots" aria-hidden="true"><i></i><i></i><i></i></span>
            </div>

            <form method="POST" id="fbx-form" class="fbx-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="create">

                <div class="fbx-row">
                    <span class="fbx-label">From</span>
                    <span class="fbx-chip fbx-chip-me"><span class="fbx-ava"><?php echo htmlspecialchars(initials($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '')); ?></span><?php echo htmlspecialchars($me_name); ?><?php if ($me_email !== ''): ?> <small>&lt;<?php echo htmlspecialchars($me_email); ?>&gt;</small><?php endif; ?></span>
                </div>
                <div class="fbx-row">
                    <span class="fbx-label">To</span>
                    <span class="fbx-chip"><span class="fbx-ava fbx-ava-org"><svg class="icon" aria-hidden="true"><use href="#i-users"/></svg></span>CSO Officers <small>&lt;csonorsu&gt;</small></span>
                </div>
                <div class="fbx-row fbx-row-input">
                    <label class="fbx-label" for="fbx-subject">Subject</label>
                    <input id="fbx-subject" type="text" name="subject" maxlength="150" required placeholder="What is this about?" autocomplete="off">
                </div>

                <label class="sr-only" for="fbx-message">Message</label>
                <textarea id="fbx-message" name="message" required maxlength="2000" placeholder="Write your message here&hellip;"></textarea>

                <div class="fbx-rate">
                    <span class="fbx-rate-label" id="fbx-rate-label">How would you rate your experience? <em>(optional)</em></span>
                    <div class="fbx-stars" role="radiogroup" aria-labelledby="fbx-rate-label">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <input type="radio" name="rating" id="fbx-star-<?php echo $i; ?>" value="<?php echo $i; ?>">
                            <label for="fbx-star-<?php echo $i; ?>" title="<?php echo [1 => 'Poor', 2 => 'Needs work', 3 => 'Okay', 4 => 'Good', 5 => 'Excellent'][$i]; ?>"><span class="sr-only"><?php echo $i; ?> star<?php echo $i > 1 ? 's' : ''; ?></span>&#9733;</label>
                        <?php endfor; ?>
                    </div>
                    <button type="button" class="fbx-link" id="fbx-rate-clear" hidden>Clear</button>
                </div>

                <div class="fbx-foot">
                    <div class="fbx-actions">
                        <button type="submit" class="btn btn-primary fbx-send">
                            <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"/></svg>Send
                        </button>
                        <button type="button" class="fbx-discard" id="fbx-discard" aria-label="Discard draft" title="Discard draft"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>
                    </div>
                    <span class="fbx-count" id="fbx-count" aria-live="polite">0 / 2000</span>
                </div>
            </form>
        </section>

        <!-- ===== Mailbox ===== -->
        <section class="fbx-mailbox" aria-labelledby="fbx-box-title">
            <div class="fbx-box-head">
                <h2 id="fbx-box-title"><?php echo $can_see_all ? 'Inbox' : 'Sent'; ?></h2>
                <span class="fbx-badge"><?php echo (int) $rows_total; ?></span>
            </div>

            <?php if ($rows_total > 0): ?>
                <ul class="fbx-list">
                <?php while ($f = $rows->fetch_assoc()):
                    $who = $can_see_all ? full_name($f) : 'To: CSO Officers';
                    $ini = $can_see_all ? initials($f['first_name'] ?? '', $f['last_name'] ?? '') : 'CSO';
                    $snippet = mb_strimwidth(preg_replace('/\s+/', ' ', (string) $f['message']), 0, 110, '…');
                    $ts = strtotime($f['created_at']);
                    $when = date('Y-m-d', $ts) === date('Y-m-d') ? date('g:i A', $ts) : date('M j, Y', $ts);
                ?>
                    <li>
                        <details class="fbx-item">
                            <summary>
                                <span class="fbx-ava"><?php echo htmlspecialchars($ini); ?></span>
                                <span class="fbx-item-main">
                                    <span class="fbx-item-top"><strong><?php echo htmlspecialchars($who); ?></strong><time><?php echo htmlspecialchars($when); ?></time></span>
                                    <span class="fbx-item-subject"><?php echo htmlspecialchars($f['subject']); ?></span>
                                    <span class="fbx-item-snippet"><?php echo htmlspecialchars($snippet); ?></span>
                                </span>
                                <?php if ($f['rating'] !== null): ?><span class="fbx-item-rating"><?php echo render_stars((int) $f['rating']); ?></span><?php endif; ?>
                            </summary>
                            <div class="fbx-open">
                                <dl class="fbx-meta">
                                    <?php if ($can_see_all): ?><div><dt>From</dt><dd><?php echo htmlspecialchars(full_name($f)); ?> <small><?php echo htmlspecialchars($f['student_id']); ?></small></dd></div><?php endif; ?>
                                    <div><dt>Date</dt><dd><?php echo date('F j, Y \a\t g:i A', $ts); ?></dd></div>
                                </dl>
                                <div class="fbx-body"><?php echo nl2br(htmlspecialchars($f['message'])); ?></div>
                                <?php if ($can_see_all): ?>
                                    <form method="POST" class="fbx-del" <?php echo confirm_attr('Delete this feedback entry? This cannot be undone.'); ?>>
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="feedback_id" value="<?php echo (int) $f['feedback_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </details>
                    </li>
                <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <div class="fbx-empty">
                    <span class="fbx-empty-ico"><svg class="icon" aria-hidden="true"><use href="#i-message"/></svg></span>
                    <h3><?php echo $can_see_all ? 'Your inbox is empty' : 'Nothing sent yet'; ?></h3>
                    <p><?php echo $can_see_all ? 'Feedback from members will show up here.' : 'Messages you send to the officers will be listed here.'; ?></p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('fbx-form');
    if (!form) return;
    var msg = document.getElementById('fbx-message'), count = document.getElementById('fbx-count');
    var subj = document.getElementById('fbx-subject');
    var clear = document.getElementById('fbx-rate-clear');
    function upd() { count.textContent = msg.value.length + ' / ' + msg.maxLength; }
    function grow() { msg.style.height = 'auto'; msg.style.height = Math.max(180, msg.scrollHeight) + 'px'; }
    msg.addEventListener('input', function () { upd(); grow(); });
    form.addEventListener('change', function (e) { if (e.target.name === 'rating') clear.hidden = false; });
    clear.addEventListener('click', function () {
        Array.prototype.forEach.call(form.querySelectorAll('input[name="rating"]'), function (r) { r.checked = false; });
        clear.hidden = true;
    });
    document.getElementById('fbx-discard').addEventListener('click', function () {
        if ((subj.value || msg.value) && !window.confirm('Discard this message?')) return;
        form.reset(); clear.hidden = true; upd(); grow();
    });
    form.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); if (form.reportValidity()) form.submit(); }
    });
    upd();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
