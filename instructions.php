<?php
require_once __DIR__ . '/includes/functions.php';
require_login();

$role = $_SESSION['role'];
$page_title = 'Instructions';
require_once __DIR__ . '/includes/header.php';
?>

<section class="section">
    <div class="container narrow">
        <h2>Instructions & Guidelines</h2>
        <p class="muted">A quick directory of how things work in CSO-NORSU Portal, grouped by topic.</p>

        <div class="terms-body">
            <h3>Getting Started</h3>
            <p>Register with your student ID, name, email, program, and year level. Once approved, log in from the Login page using your student ID and password.</p>

            <h3>Your Profile</h3>
            <p>Go to <strong>My Profile</strong> to update your details, upload a profile photo, and change your password. Your profile photo also appears on your member QR code.</p>

            <h3>Attendance & QR Check-In</h3>
            <ul>
                <li>Your member QR code is on your profile — show it to an officer/moderator/admin to be scanned in at events.</li>
                <li>Scanning requires an active internet connection on the scanning device.</li>
                <li>Check-in only works during the event's actual date(s) — an old printed QR won't work for a past or future event.</li>
                <li>Scanning again after you've checked in records your time-out.</li>
            </ul>

            <h3>Events</h3>
            <p>Browse upcoming events from the homepage or <strong>Browse Events</strong> in your dashboard. Events show a date (or date range for multi-day events), time, and venue.</p>

            <h3>Announcements</h3>
            <p>Check the homepage or your dashboard for the latest announcements. Some announcements include a deadline — pay attention to these for anything requiring action by a specific date.</p>

            <?php if (in_array($role, ['admin', 'officer', 'moderator'], true)): ?>
            <h3>Managing Members, Events & Attendance</h3>
            <ul>
                <li><strong>Members:</strong> add or edit basic member info. Only admins can change a member's role or delete an account.</li>
                <li><strong>Events:</strong> create or edit events, including start/end date, start/end time, venue, and a cover image. Only Admins can create new events — Officers and Moderators can edit existing ones.</li>
                <li><strong>Attendance:</strong> scan members in/out during an event, or use the per-row <strong>Edit</strong> control on the attendance roster to correct a status or time manually.</li>
                <li><strong>Announcements:</strong> only Admins can post new announcements. Officers and Moderators can edit announcements they previously created.</li>
            </ul>
            <?php endif; ?>

            <?php if ($role === 'admin'): ?>
            <h3>Admin-Only: Roles & Moderators</h3>
            <p>From <strong>Members</strong>, edit a member's role to promote them to Officer, Moderator, or Admin. Moderators have the same management access as Officers (Events, Attendance, Announcements, Members) but — like officers — cannot change roles or delete accounts; only Admins can do that.</p>
            <?php endif; ?>

            <h3>Need Help?</h3>
            <p>If something isn't working as expected, reach out to a CSO officer, moderator, or administrator.</p>
        </div>

        <a href="<?php echo BASE_URL; ?>/dashboard/<?php echo $role; ?>.php" class="btn btn-outline" style="margin-top:20px;">Back to Dashboard</a>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
