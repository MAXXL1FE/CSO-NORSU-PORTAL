<?php
/**
 * Shared helpers for: email alerts, event limits + waitlist, event reminders,
 * and committee permissions.
 */
require_once __DIR__ . '/otp.php';

/** True when emails can actually be sent (mail API key set and cURL present). */
function alerts_available() { return otp_email_available(); }

/**
 * Emails every active member who has alerts turned on. One mail API call per 50 people
 * (recipients are in BCC, so nobody sees anyone else's address).
 * Returns the number of people the mail was sent to.
 */
function alert_members($conn, $subject, $text) {
    if (!alerts_available()) return 0;
    $res = $conn->query("SELECT email FROM users WHERE status = 'active' AND approval_pending = 0 AND email_alerts = 1 AND email <> ''");
    if (!$res) return 0;
    $emails = [];
    while ($r = $res->fetch_assoc()) if (filter_var($r['email'], FILTER_VALIDATE_EMAIL)) $emails[] = $r['email'];
    $sent = 0;
    foreach (array_chunk($emails, 50) as $batch) {
        if (cso_send_bulk($batch, $subject, $text)) $sent += count($batch);
    }
    return $sent;
}

function cso_send_bulk(array $bcc, $subject, $text) {
    if (OTP_MAIL_API_KEY === '' || !function_exists('curl_init') || !$bcc) return false;
    // One message addressed to the sender, with everyone else on BCC, so members
    // never see each other's addresses. Resend caps a message at 50 recipients,
    // which is why alert_members() sends in chunks of 50.
    $from = OTP_MAIL_FROM_NAME !== '' ? sprintf('%s <%s>', OTP_MAIL_FROM_NAME, OTP_MAIL_FROM) : OTP_MAIL_FROM;
    $payload = json_encode([
        'from'    => $from,
        'to'      => [OTP_MAIL_FROM],
        'bcc'     => array_values($bcc),
        'subject' => $subject,
        'text'    => $text,
    ]);
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . OTP_MAIL_API_KEY, 'Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $resp !== false && $status >= 200 && $status < 300;
}

/** Absolute link to a page on this site (for emails). */
function site_url($path) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . $path;
}

/** A short personal notice shown on the person's Notifications page. */
function add_notice($conn, $user_id, $message) {
    $stmt = $conn->prepare("INSERT INTO user_notices (user_id, message) VALUES (?, ?)");
    $user_id = (int) $user_id;
    $message = mb_substr($message, 0, 255);
    $stmt->bind_param('is', $user_id, $message);
    $stmt->execute();
    $stmt->close();
}

// ------------------------------------------------------------------
// Event limits and waitlist
// ------------------------------------------------------------------

/** How many confirmed (not waitlisted) people are registered. */
function event_confirmed_count($conn, $event_id) {
    $event_id = (int) $event_id;
    $r = $conn->query("SELECT COUNT(*) c FROM event_registrations WHERE event_id = $event_id AND waitlisted = 0");
    return $r ? (int) $r->fetch_assoc()['c'] : 0;
}

/** Registers a person, or puts them on the waitlist if the event is full. Returns 'registered', 'waitlisted' or 'already'. */
function event_register($conn, $event_id, $user_id) {
    $event_id = (int) $event_id; $user_id = (int) $user_id;
    $ev = $conn->query("SELECT max_attendees FROM events WHERE event_id = $event_id")->fetch_assoc();
    if (!$ev) return 'already';
    $exists = $conn->query("SELECT 1 FROM event_registrations WHERE event_id = $event_id AND user_id = $user_id")->num_rows;
    if ($exists) return 'already';
    $full = $ev['max_attendees'] !== null && event_confirmed_count($conn, $event_id) >= (int) $ev['max_attendees'];
    $wl = $full ? 1 : 0;
    $stmt = $conn->prepare("INSERT IGNORE INTO event_registrations (event_id, user_id, waitlisted) VALUES (?, ?, ?)");
    $stmt->bind_param('iii', $event_id, $user_id, $wl);
    $stmt->execute();
    $stmt->close();
    return $full ? 'waitlisted' : 'registered';
}

/** Moves people from the waitlist into free spots (oldest first) and tells them. */
function event_promote_waitlist($conn, $event_id) {
    $event_id = (int) $event_id;
    $ev = $conn->query("SELECT title, max_attendees FROM events WHERE event_id = $event_id")->fetch_assoc();
    if (!$ev) return;
    $free = $ev['max_attendees'] === null ? PHP_INT_MAX : (int) $ev['max_attendees'] - event_confirmed_count($conn, $event_id);
    if ($free <= 0) return;
    $limit = $free === PHP_INT_MAX ? 1000 : $free;
    $res = $conn->query("SELECT r.registration_id, r.user_id, u.email, u.first_name, u.middle_name, u.last_name, u.suffix
                         FROM event_registrations r JOIN users u ON u.user_id = r.user_id
                         WHERE r.event_id = $event_id AND r.waitlisted = 1 ORDER BY r.registered_at, r.registration_id LIMIT $limit");
    while ($row = $res->fetch_assoc()) {
        $conn->query("UPDATE event_registrations SET waitlisted = 0 WHERE registration_id = " . (int) $row['registration_id']);
        add_notice($conn, $row['user_id'], 'A spot opened up: you are now registered for "' . $ev['title'] . '".');
        cso_send_mail($row['email'], full_name($row), 'A spot opened up: ' . $ev['title'],
            "Hello " . full_name($row) . ",\n\nA spot opened up and you are now registered for \"" . $ev['title'] . "\".\n" . site_url('/event.php?id=' . $event_id));
    }
}

// ------------------------------------------------------------------
// Reminders (run at most once an hour, triggered by normal page visits)
// ------------------------------------------------------------------
function run_due_reminders($conn) {
    $last = $conn->query("SELECT v FROM app_state WHERE k = 'reminders_ran'");
    $last = ($last && ($r = $last->fetch_assoc())) ? (int) $r['v'] : 0;
    if (time() - $last < 3600) return;
    $conn->query("INSERT INTO app_state (k, v) VALUES ('reminders_ran', '" . time() . "') ON DUPLICATE KEY UPDATE v = '" . time() . "'");

    // Events that start tomorrow (or are already a day away) and have not sent their reminder yet.
    $evs = $conn->query("SELECT event_id, title, event_date, event_time, venue FROM events
                         WHERE reminder_sent = 0 AND event_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
    if (!$evs) return;
    while ($ev = $evs->fetch_assoc()) {
        $eid = (int) $ev['event_id'];
        $conn->query("UPDATE events SET reminder_sent = 1 WHERE event_id = $eid");
        $regs = $conn->query("SELECT u.user_id, u.email, u.first_name, u.middle_name, u.last_name, u.suffix, u.email_alerts
                              FROM event_registrations r JOIN users u ON u.user_id = r.user_id
                              WHERE r.event_id = $eid AND r.waitlisted = 0 AND u.status = 'active'");
        $when = fmt_when($ev['event_date'], $ev['event_time'], 'F j, Y', ' at ');
        while ($u = $regs->fetch_assoc()) {
            add_notice($conn, $u['user_id'], 'Reminder: "' . $ev['title'] . '" is tomorrow, ' . $when . ', at ' . $ev['venue'] . '.');
            if (!empty($u['email_alerts'])) {
                cso_send_mail($u['email'], full_name($u), 'Reminder: ' . $ev['title'] . ' is tomorrow',
                    "Hello " . full_name($u) . ",\n\nA reminder that \"" . $ev['title'] . "\" is tomorrow, $when, at " . $ev['venue'] . ".\n" . site_url('/event.php?id=' . $eid));
            }
        }
    }
}

// ------------------------------------------------------------------
// Committees: a staff member whose "committee / position" matches an event's committee can manage it.
// ------------------------------------------------------------------
function same_committee($user_committee, $event_committee) {
    $a = mb_strtolower(trim((string) $user_committee));
    $b = mb_strtolower(trim((string) $event_committee));
    return $a !== '' && $a === $b;
}

function session_committee($conn) {
    static $c = null;
    if ($c === null) {
        $c = '';
        if (is_logged_in()) {
            $r = $conn->query("SELECT committee_role FROM users WHERE user_id = " . (int) $_SESSION['user_id'])->fetch_assoc();
            $c = trim((string) ($r['committee_role'] ?? ''));
        }
    }
    return $c;
}
