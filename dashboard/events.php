<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/features.php';
require_once __DIR__ . '/../includes/fb_preview.php';
require_role(['admin', 'officer', 'moderator']);

$is_admin = $_SESSION['role'] === 'admin';
$uid = $_SESSION['user_id'];
$my_committee = session_committee($conn);   // e.g. "Events Committee Head"; empty if none
// Admins and moderators may post any event; an officer with a committee may post for that committee.
$can_post = in_array($_SESSION['role'], ['admin', 'moderator'], true) || ($_SESSION['role'] === 'officer' && $my_committee !== '');

function can_manage_event($event, $is_admin, $uid) {
    global $my_committee;
    return $is_admin || (int) $event['created_by'] === (int) $uid || same_committee($my_committee, $event['committee'] ?? '');
}

/** Committee text for a new/edited event: officers can only use their own committee. */
function posted_committee($is_admin_or_mod) {
    global $my_committee;
    $c = clean($_POST['committee'] ?? '');
    if (!$is_admin_or_mod) return $my_committee !== '' ? $my_committee : null;
    return $c !== '' ? mb_substr($c, 0, 100) : null;
}
function posted_capacity() {
    $v = trim((string) ($_POST['max_attendees'] ?? ''));
    return ($v !== '' && ctype_digit($v) && (int) $v > 0) ? (int) $v : null;
}

// ---------------------------------------------------------
// Handle create / update / delete
// ---------------------------------------------------------
/** Facebook link from the form: null = left blank, false = invalid, string = ok. */
function posted_facebook_url() {
    $u = trim((string) ($_POST['facebook_url'] ?? ''));
    if ($u === '') return null;
    if (!preg_match('~^https://([a-z0-9-]+\.)?(facebook\.com|fb\.com|fb\.me|fb\.watch)(/|$)~i', $u) || strlen($u) > 500) return false;
    return $u;
}

/** Reads the Facebook link and fills in any date/time left blank. Returns the preview (or null). */
function apply_fb_preview($facebook_url, &$event_date, &$event_time, &$event_end_date, &$event_end_time) {
    if (!is_string($facebook_url)) return null;
    $fb = fb_preview($facebook_url);
    if (!$fb) return null;
    if ($event_date === '' && !empty($fb['date'])) {
        $event_date = $fb['date'];
        if ($event_time === '' && !empty($fb['time'])) $event_time = $fb['time'];
        if ($event_end_date === null && !empty($fb['end_date'])) { $event_end_date = $fb['end_date']; if ($event_end_time === null) $event_end_time = $fb['end_time']; }
    }
    return $fb;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Photos bigger than the server's post_max_size arrive as an empty POST — say so instead of a confusing security error.
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        set_flash('error', 'That upload was too large for the server. Try fewer or smaller photos.');
        header('Location: ' . BASE_URL . '/dashboard/events.php');
        exit;
    }
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if (!$can_post) {
            header('Location: ' . BASE_URL . '/unauthorized.php');
            exit;
        }
        $title          = clean($_POST['title']);
        $description    = clean($_POST['description']);
        $event_date     = clean($_POST['event_date']);
        $event_time     = clean($_POST['event_time']);
        $event_end_date = !empty($_POST['event_end_date']) ? clean($_POST['event_end_date']) : null;
        $event_end_time = !empty($_POST['event_end_time']) ? clean($_POST['event_end_time']) : null;
        $venue          = clean($_POST['venue']);
        $facebook_url   = posted_facebook_url();
        $qr_token       = bin2hex(random_bytes(16));
        $committee      = posted_committee(in_array($_SESSION['role'], ['admin', 'moderator'], true));
        $capacity       = posted_capacity();
        $fb = apply_fb_preview($facebook_url, $event_date, $event_time, $event_end_date, $event_end_time);

        // Date and time are optional: blank means "to be announced". A time without a date makes no sense, so it is dropped.
        if ($event_date === '') { $event_date = null; $event_time = null; $event_end_date = null; $event_end_time = null; }
        elseif ($event_time === '') { $event_time = null; }

        if (empty($title) || (empty($venue) && $facebook_url === null)) {
            set_flash('error', 'Please fill in the title and venue (venue can be skipped if you add a Facebook link). Date and time are optional.');
        } elseif ($facebook_url === false) {
            set_flash('error', 'That Facebook link does not look right. It must start with https:// and be a facebook.com or fb.me address.');
        } else {
            $stmt = $conn->prepare("INSERT INTO events (title, description, event_date, event_end_date, event_time, event_end_time, venue, qr_token, created_by, committee, max_attendees, facebook_url, fb_image, fb_description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $fb_image = $fb ? fb_save_image($fb['image_url'] ?? null) : null;
            $fb_desc  = $fb['description'] ?? null;
            $stmt->bind_param('ssssssssisisss', $title, $description, $event_date, $event_end_date, $event_time, $event_end_time, $venue, $qr_token, $uid, $committee, $capacity, $facebook_url, $fb_image, $fb_desc);
            $stmt->execute();
            $new_id = (int) $conn->insert_id;
            if (!empty($_POST['email_members'])) {
                $sent = alert_members($conn, 'New event: ' . $title,
                    "A new CSO event was posted:\n\n$title\n" . fmt_when($event_date, $event_time, 'F j, Y', ' at ', 'Date to be announced') . "\nVenue: $venue\n\n" . site_url('/event.php?id=' . $new_id)
                    . "\n\nYou can turn these emails off on your Notifications page.");
                $mail_note = $sent > 0 ? " Emailed $sent member" . ($sent === 1 ? '' : 's') . '.' : '';
            }
            $r = save_post_images($conn, 'event', $new_id, 'events');
            if ($r['error']) {
                set_flash('error', 'Event created, but: ' . $r['error']);
            } else {
                set_flash('success', 'Event created.' . ($mail_note ?? ''));
            }
        }
    }

    if ($action === 'update') {
        $event_id = (int) $_POST['event_id'];
        $chk = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
        $chk->bind_param('i', $event_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if (!$existing) {
            set_flash('error', 'Event not found.');
        } elseif (!can_manage_event($existing, $is_admin, $uid)) {
            header('Location: ' . BASE_URL . '/unauthorized.php');
            exit;
        } else {
            $title          = clean($_POST['title']);
            $description    = clean($_POST['description']);
            $event_date     = clean($_POST['event_date']);
            $event_time     = clean($_POST['event_time']);
            $event_end_date = !empty($_POST['event_end_date']) ? clean($_POST['event_end_date']) : null;
            $event_end_time = !empty($_POST['event_end_time']) ? clean($_POST['event_end_time']) : null;
            $venue          = clean($_POST['venue']);
            $facebook_url   = posted_facebook_url();
            $capacity       = posted_capacity();
            $committee      = in_array($_SESSION['role'], ['admin', 'moderator'], true) ? posted_committee(true) : ($existing['committee'] ?? null);
            $fb = apply_fb_preview($facebook_url, $event_date, $event_time, $event_end_date, $event_end_time);

            if ($event_date === '') { $event_date = null; $event_time = null; $event_end_date = null; $event_end_time = null; }
            elseif ($event_time === '') { $event_time = null; }

            if ($title === '' || ($venue === '' && $facebook_url === null)) {
                set_flash('error', 'Please fill in the title and venue (venue can be skipped if you add a Facebook link). Date and time are optional.');
            } elseif ($facebook_url === false) {
                set_flash('error', 'That Facebook link does not look right. It must start with https:// and be a facebook.com or fb.me address.');
            } else {
                $stmt = $conn->prepare("UPDATE events SET title=?, description=?, event_date=?, event_end_date=?, event_time=?, event_end_time=?, venue=?, max_attendees=?, committee=?, facebook_url=?, fb_image=?, fb_description=? WHERE event_id=?");
                // Keep the previously fetched image/description unless the link changed (or nothing was fetched yet)
                $fb_image = $existing['fb_image'] ?? null;
                $fb_desc  = $existing['fb_description'] ?? null;
                if ($facebook_url === null) { $fb_image = null; $fb_desc = null; }
                elseif ($fb) {
                    if (($existing['facebook_url'] ?? '') !== $facebook_url || !$fb_image) {
                        if (!empty($existing['fb_image'])) delete_uploaded_image($existing['fb_image']);
                        $fb_image = fb_save_image($fb['image_url'] ?? null);
                    }
                    $fb_desc = $fb['description'] ?? $fb_desc;
                }
                $stmt->bind_param('sssssssissssi', $title, $description, $event_date, $event_end_date, $event_time, $event_end_time, $venue, $capacity, $committee, $facebook_url, $fb_image, $fb_desc, $event_id);
                $stmt->execute();
                event_promote_waitlist($conn, $event_id);   // a higher limit frees spots for the waitlist
                if (!empty($_POST['remove_images']) && is_array($_POST['remove_images'])) {
                    remove_post_images($conn, 'event', $event_id, $_POST['remove_images']);
                }
                $r = save_post_images($conn, 'event', $event_id, 'events');
                if ($r['error']) {
                    set_flash('error', 'Event updated, but: ' . $r['error']);
                } else {
                    set_flash('success', 'Event updated.');
                }
            }
        }
    }

    if ($action === 'delete') {
        $event_id = (int) $_POST['event_id'];
        $chk = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
        $chk->bind_param('i', $event_id);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();

        if ($existing && can_manage_event($existing, $is_admin, $uid)) {
            delete_post_images_all($conn, 'event', $event_id);
            if (!empty($existing['cover_image'])) delete_uploaded_image($existing['cover_image']);
            $gp = $conn->prepare("SELECT image_path FROM event_photos WHERE event_id = ?");
            $gp->bind_param('i', $event_id);
            $gp->execute();
            $gp_res = $gp->get_result();
            while ($g = $gp_res->fetch_assoc()) delete_uploaded_image($g['image_path']);

            $stmt = $conn->prepare("DELETE FROM events WHERE event_id = ?");
            $stmt->bind_param('i', $event_id);
            $stmt->execute();
            set_flash('success', 'Event deleted.');
        } elseif ($existing) {
            header('Location: ' . BASE_URL . '/unauthorized.php');
            exit;
        }
    }

    header('Location: ' . BASE_URL . '/dashboard/events.php');
    exit;
}

// ---------------------------------------------------------
// List + single-event views
// ---------------------------------------------------------
$edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$qr_id   = isset($_GET['qr']) ? (int) $_GET['qr'] : 0;

$events = $conn->query("
    SELECT e.*, u.first_name, u.middle_name, u.last_name, u.suffix,
           (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.event_id) AS reg_count,
           (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.event_id AND a.status = 'present') AS present_count
    FROM events e
    LEFT JOIN users u ON u.user_id = e.created_by
    ORDER BY e.event_date DESC
");

$edit_row = null;
if ($edit_id > 0) {
    $es = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
    $es->bind_param('i', $edit_id);
    $es->execute();
    $edit_row = $es->get_result()->fetch_assoc();
    if ($edit_row && !can_manage_event($edit_row, $is_admin, $uid)) {
        header('Location: ' . BASE_URL . '/unauthorized.php');
        exit;
    }
}

$edit_images = [];
if ($edit_row) $edit_images = post_images_for($conn, 'event', (int) $edit_row['event_id'], $edit_row['cover_image'] ?? null);

$qr_row = null;
if ($qr_id > 0) {
    $qs = $conn->prepare("SELECT * FROM events WHERE event_id = ?");
    $qs->bind_param('i', $qr_id);
    $qs->execute();
    $qr_row = $qs->get_result()->fetch_assoc();
}

$page_title = 'Events';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Events</h1>
        <p class="muted">Create events, generate check-in QR codes, and manage the ones you're responsible for.</p>
    </div>
</div>

<?php if ($qr_row):
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $checkin_url = $scheme . '://' . $_SERVER['HTTP_HOST'] . BASE_URL . '/checkin.php?event_id=' . $qr_row['event_id'] . '&token=' . $qr_row['qr_token'];
    $qr_img = $checkin_url;   // drawn in the browser, so the secret token is never sent to another site
?>
<div class="panel" style="text-align:center; max-width:420px; margin-left:auto; margin-right:auto;">
    <h2><?php echo htmlspecialchars($qr_row['title']); ?> — Check-In QR</h2>
    <p class="muted">Members scan this while logged in <?php echo !empty($qr_row['event_date']) ? 'on ' . e(date('F j, Y', strtotime($qr_row['event_date']))) : 'once the event has a date'; ?> to mark themselves present.</p>
    <img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEAAAAALAAAAAABAAEAAAIBRAA7" data-qr="<?php echo htmlspecialchars($qr_img); ?>" width="260" height="260" alt="Check-in QR code" style="margin:16px auto; border:1px solid var(--line); border-radius:var(--radius); padding:12px; background:#fff;">
    <p class="muted" style="font-size:0.78rem; word-break:break-all;"><?php echo htmlspecialchars($checkin_url); ?></p>
    <a href="<?php echo BASE_URL; ?>/dashboard/events.php" class="btn btn-outline btn-sm">Close</a>
</div>
<?php endif; ?>

<?php
$first_name = $_SESSION['first_name'] ?? '';
// the always-visible required fields, and the optional "end" section
$ev_details = function ($v, $p) {
    return '<div class="composer-grid">'
        . '<div><label for="' . $p . '-date">Start date <span class="muted">(optional)</span></label><input type="date" id="' . $p . '-date" name="event_date" value="' . e($v['event_date'] ?? '') . '"></div>'
        . '<div><label for="' . $p . '-time">Start time <span class="muted">(optional)</span></label><input type="time" id="' . $p . '-time" name="event_time" value="' . e(isset($v['event_time']) ? substr($v['event_time'], 0, 5) : '') . '"></div>'
        . '</div>'
        . '<div><label for="' . $p . '-venue">Venue</label><input type="text" id="' . $p . '-venue" name="venue" maxlength="150" placeholder="Where is it happening? (optional with a Facebook link)" value="' . e($v['venue'] ?? '') . '"></div>'
        . '<div><label for="' . $p . '-fb">Facebook link <span class="muted">(optional)</span></label><input type="url" id="' . $p . '-fb" name="facebook_url" maxlength="500" placeholder="https://www.facebook.com/events/..." value="' . e($v['facebook_url'] ?? '') . '"><small class="muted">With a link, the site reads the image, description, date and time from Facebook (public posts only). Date and time can be left empty and will show as to be announced.</small></div>';
};
$ev_opts = function ($v, $p, $is_new) use ($my_committee) {
    $is_am = in_array($_SESSION['role'], ['admin', 'moderator'], true);
    $h = '<div class="composer-grid">'
       . '<div><label for="' . $p . '-cap">Registration limit</label><input type="number" min="1" id="' . $p . '-cap" name="max_attendees" value="' . e($v['max_attendees'] ?? '') . '" placeholder="No limit"></div>';
    if ($is_am) {
        $h .= '<div><label for="' . $p . '-com">Committee</label><input type="text" id="' . $p . '-com" name="committee" maxlength="100" value="' . e($v['committee'] ?? '') . '" placeholder="e.g. Events Committee Head"></div>';
    } else {
        $h .= '<div><label>Committee</label><p class="muted" style="margin:8px 0 0;">' . e($my_committee) . '</p></div>';
    }
    $h .= '</div><p class="muted" style="font-size:.82rem; margin:6px 0 0;">When the limit is reached, extra sign-ups join a waitlist and move up automatically when someone cancels. A committee head can manage events that carry their committee name.</p>';
    if ($is_new) {
        $h .= '<label class="check-row" style="display:flex; gap:8px; align-items:center; margin-top:10px;"><input type="checkbox" name="email_members" value="1"' . (alerts_available() ? ' checked' : ' disabled') . '> Email members about this event' . (alerts_available() ? '' : ' (email is not set up yet)') . '</label>';
    }
    return $h;
};
$ev_end = function ($v, $p) {
    return '<div class="composer-grid">'
        . '<div><label for="' . $p . '-edate">End date</label><input type="date" id="' . $p . '-edate" name="event_end_date" value="' . e($v['event_end_date'] ?? '') . '"></div>'
        . '<div><label for="' . $p . '-etime">End time</label><input type="time" id="' . $p . '-etime" name="event_end_time" value="' . e(!empty($v['event_end_time']) ? substr($v['event_end_time'], 0, 5) : '') . '"></div>'
        . '</div>';
};
?>

<?php if ($can_post): ?>
<div class="feed" style="margin:0 auto 22px;">
    <div class="post-launcher">
        <?php render_post_avatar($_SESSION['profile_photo'] ?? null, $first_name, $_SESSION['last_name'] ?? ''); ?>
        <button type="button" class="post-launcher-input" data-composer-open="eventComposer">Post a new event, <?php echo e($first_name !== '' ? $first_name : 'there'); ?>?</button>
        <button type="button" class="post-launcher-photo" data-composer-open="eventComposer" data-composer-photos aria-label="Add photos"><svg class="icon" aria-hidden="true"><use href="#i-photos"/></svg><span>Photo</span></button>
    </div>
</div>
<?php
render_composer([
    'id'                => 'eventComposer',
    'heading'           => 'Create event',
    'button'            => 'Post event',
    'hidden'            => ['action' => 'create'],
    'title_placeholder' => 'Event name',
    'body_name'         => 'description',
    'body_value'        => '',
    'body_placeholder'  => 'Tell members about the event…',
    'details_title'     => 'When & where',
    'details_html'      => $ev_details([], 'ev-new'),
    'tools'             => [[
        'key' => 'end', 'label' => 'Add end date / time', 'icon' => 'i-clock', 'open' => false,
        'html' => $ev_end([], 'ev-new'),
    ], [
        'key' => 'opts', 'label' => 'Limit, committee, email', 'icon' => 'i-users', 'open' => false,
        'html' => $ev_opts([], 'ev-new', true),
    ]],
]);
endif; ?>

<?php if ($edit_row):
    $has_end = !empty($edit_row['event_end_date']) || !empty($edit_row['event_end_time']);
    render_composer([
        'id'                => 'editEventComposer',
        'open'              => true,
        'close_url'         => BASE_URL . '/dashboard/events.php',
        'heading'           => 'Edit event',
        'button'            => 'Save',
        'hidden'            => ['action' => 'update', 'event_id' => (int) $edit_row['event_id']],
        'title_placeholder' => 'Event name',
        'title_value'       => $edit_row['title'],
        'body_name'         => 'description',
        'body_value'        => $edit_row['description'] ?? '',
        'body_placeholder'  => 'Tell members about the event…',
        'details_title'     => 'When & where',
        'details_html'      => $ev_details($edit_row, 'ev-edit'),
        'images'            => $edit_images,
        'tools'             => [[
            'key' => 'end', 'label' => 'Add end date / time', 'icon' => 'i-clock', 'open' => $has_end,
            'html' => $ev_end($edit_row, 'ev-edit'),
        ], [
            'key' => 'opts', 'label' => 'Limit, committee', 'icon' => 'i-users', 'open' => $edit_row['max_attendees'] !== null || !empty($edit_row['committee']),
            'html' => $ev_opts($edit_row, 'ev-edit', false),
        ]],
    ]);
endif; ?>

<script src="<?php echo e(asset_v('/assets/js/composer.js')); ?>" defer></script>

<div class="panel">
    <div class="panel-head"><h2>All Events</h2></div>
    <?php if ($events->num_rows > 0): ?>
        <div class="table-wrap">
            <table class="data-table ev-table">
                <thead><tr><th scope="col"></th><th scope="col">Event</th><th scope="col">Date</th><th scope="col">Venue</th><th scope="col">Registered</th><th scope="col">Present</th><th scope="col">Organizer</th><th scope="col"></th></tr></thead>
                <tbody>
                <?php while ($e = $events->fetch_assoc()): $mine = can_manage_event($e, $is_admin, $uid); ?>
                    <tr>
                        <td class="ev-cover">
                            <?php if (!empty($e['cover_image'])): ?>
                                <img src="<?php echo asset_v($e['cover_image']); ?>" alt="" style="width:40px; height:40px; border-radius:6px; object-fit:cover;">
                            <?php else: ?>
                                <span style="display:inline-flex; width:40px; height:40px; border-radius:6px; background:var(--line-soft);"></span>
                            <?php endif; ?>
                        </td>
                        <td class="ev-title"><a href="<?php echo BASE_URL; ?>/event.php?id=<?php echo $e['event_id']; ?>"><?php echo htmlspecialchars($e['title']); ?></a></td>
                        <td class="ev-meta" data-label="Date"><?php echo e(fmt_date($e['event_date'])); ?></td>
                        <td class="ev-meta" data-label="Venue"><?php echo htmlspecialchars($e['venue']); ?></td>
                        <td class="ev-meta ev-num" data-label="Registered"><?php echo (int) $e['reg_count']; ?></td>
                        <td class="ev-meta ev-num" data-label="Present"><?php echo (int) $e['present_count']; ?></td>
                        <td class="ev-meta" data-label="By"><?php echo htmlspecialchars(full_name($e) ?: '—'); ?></td>
                        <td class="ev-actions">
                            <div class="table-actions">
                                <a href="<?php echo BASE_URL; ?>/dashboard/events.php?qr=<?php echo $e['event_id']; ?>" class="btn btn-outline btn-sm">QR</a>
                                <a href="<?php echo BASE_URL; ?>/dashboard/attendance.php?event_id=<?php echo $e['event_id']; ?>" class="btn btn-outline btn-sm">Attendance</a>
                                <a href="<?php echo BASE_URL; ?>/dashboard/gallery.php?event_id=<?php echo $e['event_id']; ?>" class="btn btn-outline btn-sm">Photos</a>
                                <?php if ($mine): ?>
                                    <a href="<?php echo BASE_URL; ?>/dashboard/events.php?edit=<?php echo $e['event_id']; ?>" class="btn btn-outline btn-sm">Edit</a>
                                    <form method="POST" <?php echo confirm_attr('Delete "' . $e['title'] . '"? Registrations and attendance records go with it. This cannot be undone.'); ?>>
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="event_id" value="<?php echo $e['event_id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-state"><h3>No events yet</h3><p>Create your first event above.</p></div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
