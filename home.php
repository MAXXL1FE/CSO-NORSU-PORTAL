<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';

$page_title = 'Home';

// ---- About CSO (editable by the admin in Dashboard > About CSO Section) ----
$info_result = $conn->query("SELECT * FROM org_info WHERE id = 1");
$info = $info_result ? $info_result->fetch_assoc() : null;
if (!$info) {
    $info = ['title' => 'About CSO', 'content' => 'The Computer Science Organization (CSO) brings together BSCS students for academic growth, technical skill-building, and community.', 'image' => null];
}

// ---- Faculty / instructors / professors who joined CSO (approved only) ----
// Adviser-type positions first, then everyone else alphabetically.
$faculty = $conn->query("
    SELECT user_id, first_name, middle_name, last_name, suffix, course, profile_photo, faculty_position
    FROM users
    WHERE user_type = 'faculty' AND status = 'active'
    ORDER BY CASE
        WHEN faculty_position LIKE '%Dean%'       THEN 0
        WHEN faculty_position LIKE '%Head%'       THEN 1
        WHEN faculty_position LIKE '%Co-Adviser%' THEN 3
        WHEN faculty_position LIKE '%Adviser%'    THEN 2
        ELSE 4
    END, last_name ASC
");

// ---- Officers ----
$officers = $conn->query("SELECT user_id, first_name, middle_name, last_name, suffix, course, year_level, profile_photo, committee_role FROM users WHERE role = 'officer' AND status = 'active' AND user_type = 'student' ORDER BY last_name ASC");

// ---- CSO at a glance ----
$count = function ($sql) use ($conn) {
    $r = $conn->query($sql);
    return $r ? (int) $r->fetch_assoc()['c'] : 0;
};
$n_members  = $count("SELECT COUNT(*) c FROM users WHERE role <> 'admin' AND user_type = 'student' AND status = 'active'");
$n_officers = $count("SELECT COUNT(*) c FROM users WHERE role = 'officer' AND user_type = 'student' AND status = 'active'");
$n_faculty  = $count("SELECT COUNT(*) c FROM users WHERE user_type = 'faculty' AND status = 'active'");
$n_events   = $count("SELECT COUNT(*) c FROM events");

// ---- Upcoming events + latest announcements ----
$marquee_on = home_marquee_enabled($conn);   // admin switch: false = no moving strips
$upcoming = $conn->query("
    SELECT e.* FROM events e
    WHERE e.event_date IS NULL OR COALESCE(e.event_end_date, e.event_date) >= CURDATE()
    ORDER BY e.event_date IS NULL, e.event_date ASC, e.event_time ASC
    LIMIT 12
");
$latest = $conn->query("SELECT a.* FROM announcements a ORDER BY a.created_at DESC LIMIT 3");

// ---- Logged-in member: profile progress (Zeigarnik effect: show unfinished steps) ----
$me_progress = null;
if (is_logged_in()) {
    $me_stmt = $conn->prepare("SELECT profile_photo, cover_photo, status_note FROM users WHERE user_id = ?");
    $uid = (int) $_SESSION['user_id'];
    $me_stmt->bind_param('i', $uid);
    $me_stmt->execute();
    $me = $me_stmt->get_result()->fetch_assoc();
    if ($me) {
        $checks = [
            'Add a profile photo'  => !empty($me['profile_photo']),
            'Add a cover photo'    => !empty($me['cover_photo']),
            'Write a status note'  => !empty($me['status_note']),
        ];
        $done  = count(array_filter($checks));
        $todo  = array_keys(array_filter($checks, fn($ok) => !$ok));
        $me_progress = ['done' => $done, 'total' => count($checks), 'todo' => $todo];
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/cards.php';
?>

<div class="home-page">

<section class="home-hero-wrap" aria-label="Welcome">
    <div class="home-hero home-hero-editorial">
        <figure class="home-hero-photo">
            <img src="<?php echo asset_v('/assets/images/home-hero.jpg'); ?>" alt="CSO-NORSU students gathered for a group photo" width="1920" height="1036" fetchpriority="high">
            <div class="home-hero-content">
                <span class="home-hero-eyebrow">NORSU <span aria-hidden="true">·</span> Dumaguete</span>
                <div class="home-hero-heading-row">
                    <h1 class="home-hero-title">Computer Science<br>Organization</h1>
                    <div class="home-hero-intro">
                        <p class="home-hero-sub">A student community for learning, sharing ideas, and building things together.</p>
                        <div class="home-hero-actions">
                            <?php if (!is_logged_in()): ?>
                                <a href="<?php echo BASE_URL; ?>/register.php" class="btn btn-primary btn-lg">Join CSO</a>
                                <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-outline btn-lg">Member login</a>
                            <?php else: ?>
                                <a href="<?php echo BASE_URL; ?>/events.php" class="btn btn-primary btn-lg">See upcoming events</a>
                                <a href="<?php echo BASE_URL; ?>/announcements.php" class="btn btn-outline btn-lg">Announcements</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <figcaption>Computer Science Organization &mdash; NORSU</figcaption>
        </figure>
    </div>
</section>

<section class="home-stats-wrap" aria-label="CSO at a glance">
    <div class="home-stats">
        <div class="home-stat"><strong><?php echo $n_members; ?></strong><span>Student members</span></div>
        <div class="home-stat"><strong><?php echo $n_officers; ?></strong><span>Officers</span></div>
        <?php if ($n_faculty > 0): ?>
        <div class="home-stat"><strong><?php echo $n_faculty; ?></strong><span>Faculty &amp; advisers</span></div>
        <?php endif; ?>
        <div class="home-stat"><strong><?php echo $n_events; ?></strong><span>Events organized</span></div>
    </div>
</section>

<?php if ($me_progress): ?>
<section class="section" style="padding-top:24px;padding-bottom:0;">
    <div class="container">
        <div class="card" style="padding:18px 20px;display:flex;flex-wrap:wrap;gap:16px 28px;align-items:center;justify-content:space-between;">
            <div style="min-width:220px;flex:1;">
                <p class="muted" style="margin:0 0 4px;font-size:0.85rem;">Your profile</p>
                <p style="margin:0 0 10px;font-weight:500;"><?php echo $me_progress['done']; ?> of <?php echo $me_progress['total']; ?> steps done<?php if ($me_progress['done'] === $me_progress['total']): ?> &middot; all set<?php endif; ?></p>
                <div style="height:8px;border-radius:4px;background:var(--surface-1);overflow:hidden;">
                    <div style="height:100%;width:<?php echo round($me_progress['done'] / $me_progress['total'] * 100); ?>%;background:var(--text-accent);border-radius:4px;"></div>
                </div>
            </div>
            <?php if (!empty($me_progress['todo'])): ?>
            <div style="flex:1;min-width:220px;">
                <p class="muted" style="margin:0 0 6px;font-size:0.85rem;">Next step</p>
                <p style="margin:0 0 10px;"><?php echo htmlspecialchars($me_progress['todo'][0]); ?></p>
                <a href="<?php echo BASE_URL; ?>/dashboard/profile.php" class="btn btn-outline">Finish profile</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section id="about" class="section">
    <div class="container">
        <div class="about-block<?php echo !empty($info['image']) ? '' : ' about-block-noimg'; ?>">
            <?php if (!empty($info['image'])): ?>
                <img src="<?php echo asset_v($info['image']); ?>" alt="" class="about-block-img">
            <?php endif; ?>
            <div class="about-block-text">
                <h2><?php echo htmlspecialchars($info['title']); ?></h2>
                <p><?php echo nl2br(htmlspecialchars($info['content'])); ?></p>
            </div>
        </div>
    </div>
</section>

<section id="mission" class="section alt">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">About us</span><h2>Who we are</h2></div></div>
        <figure class="cso-photo">
            <img src="<?php echo asset_v('/assets/images/members-banner.jpg'); ?>" alt="CSO-NORSU members gathered together at an org activity" width="2048" height="1079" loading="lazy" decoding="async">
            <figcaption>CSO members at an org gathering</figcaption>
        </figure>
        <div class="cso-duo">
            <div class="cso-item">
                <h3>Our mission</h3>
                <p>To help Computer Science students grow in their studies, sharpen their technical skills, and build a supportive community through events, projects, and peer learning.</p>
            </div>
            <div class="cso-item">
                <h3>Our vision</h3>
                <p>A connected, skilled, and service-minded group of future computing professionals who learn together and contribute to the university and the wider community.</p>
            </div>
        </div>
    </div>
</section>

<section id="values" class="section">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">What guides us</span><h2>Our core values</h2></div><img src="<?php echo asset_v('/assets/images/logo.png'); ?>" alt="CSO-NORSU logo" class="cso-logo" width="200" height="200" loading="lazy"></div>
        <div class="cso-pillars">
            <div class="cso-item">
                <h3>Learn</h3>
                <p>We keep learning beyond the syllabus and share what we know with one another.</p>
            </div>
            <div class="cso-item">
                <h3>Build</h3>
                <p>We turn ideas into working projects, from small tools to full systems.</p>
            </div>
            <div class="cso-item">
                <h3>Connect</h3>
                <p>We look out for each other across year levels, batches and faculty.</p>
            </div>
            <div class="cso-item">
                <h3>Serve</h3>
                <p>We use what we know to help the university and the communities around us.</p>
            </div>
        </div>
        <div class="cso-goals">
            <h3>What we aim for this school year</h3>
            <ul>
                <li>Hold regular workshops, tech talks and coding sessions open to every BSCS student.</li>
                <li>Support every year level with study groups, peer tutoring and the curriculum guide.</li>
                <li>Keep members informed with timely announcements, event reminders and a shared calendar.</li>
                <li>Recognize active members through recorded attendance and certificates.</li>
                <li>Carry out outreach and service projects that put computing skills to use.</li>
            </ul>
        </div>
    </div>
</section>

<section id="what-we-do" class="section alt">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Activities</span><h2>What CSO does</h2></div></div>
        <div class="cso-pillars">
            <div class="cso-item">
                <h3>Tech &amp; skills</h3>
                <p>Workshops, tech talks, coding sessions and hackathons that go beyond the classroom.</p>
            </div>
            <div class="cso-item">
                <h3>Academic support</h3>
                <p>Study groups, peer tutoring and the BSCS curriculum guide to help you plan your years.</p>
            </div>
            <div class="cso-item">
                <h3>Community</h3>
                <p>Meet batchmates, officers, and faculty advisers through socials and org activities.</p>
            </div>
            <div class="cso-item">
                <h3>Outreach &amp; service</h3>
                <p>Programs that put your computing skills to work for the campus and nearby communities.</p>
            </div>
        </div>
    </div>
</section>

<section id="who-is-in-cso" class="section">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Our people</span><h2>Who is in CSO</h2></div></div>
        <figure class="cso-photo">
            <img src="<?php echo asset_v('/assets/images/officers-banner.jpg'); ?>" alt="CSO-NORSU officers and members posing together" width="1962" height="1074" loading="lazy" decoding="async">
            <figcaption>CSO officers and members</figcaption>
        </figure>
        <div class="cso-pillars">
            <div class="cso-item">
                <h3>Members</h3>
                <p>BSCS students who join events, attend activities, and take part in study groups and projects.</p>
            </div>
            <div class="cso-item">
                <h3>Officers</h3>
                <p>Student leaders who plan events, post announcements, check in attendees and approve new sign-ups.</p>
            </div>
            <div class="cso-item">
                <h3>Faculty &amp; advisers</h3>
                <p>Instructors and professors who guide the organization and are listed on this page with their positions.</p>
            </div>
            <div class="cso-item">
                <h3>Admin</h3>
                <p>Looks after accounts, roles and the information shown on this site so everything stays accurate and secure.</p>
            </div>
        </div>
    </div>
</section>

<section id="benefits" class="section alt">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Membership</span><h2>What you get as a member</h2></div></div>
        <div class="cso-pillars">
            <div class="cso-item">
                <h3>Easy event check-in</h3>
                <p>Register for events and check in with a QR code. Your attendance is recorded for you.</p>
            </div>
            <div class="cso-item">
                <h3>Stay updated</h3>
                <p>Get announcements and notifications about events, deadlines and org news in one place.</p>
            </div>
            <div class="cso-item">
                <h3>Your own profile</h3>
                <p>Add a photo, cover and status note, and show up in the members directory.</p>
            </div>
            <div class="cso-item">
                <h3>Campus map</h3>
                <p>Find the rooms and venues used for CSO events with the built-in campus map.</p>
            </div>
        </div>
    </div>
</section>

<section id="tools" class="section">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Explore</span><h2>Everything in one place</h2></div></div>
        <a class="cso-photo cso-photo-map" href="<?php echo BASE_URL; ?>/map.php" aria-label="Open the interactive campus map">
            <img src="<?php echo asset_v('/assets/images/campus-map-clean.svg'); ?>" alt="Color-coded map of the NORSU Main Campus 1 showing colleges, offices, sports areas, facilities and gates" width="1108" height="1085" loading="lazy" decoding="async">
            <span class="cso-photo-cap"><span class="cso-map-hint">Swipe to see the whole map. </span>Open the interactive campus map &rarr;</span>
        </a>
        <div class="cso-links">
            <a class="cso-link" href="<?php echo BASE_URL; ?>/events.php"><span><strong>Events</strong><small>Browse, register and check in to CSO events.</small></span><span class="cso-arrow" aria-hidden="true">&rarr;</span></a>
            <a class="cso-link" href="<?php echo BASE_URL; ?>/announcements.php"><span><strong>Announcements</strong><small>Org news, deadlines and reminders.</small></span><span class="cso-arrow" aria-hidden="true">&rarr;</span></a>
            <a class="cso-link" href="<?php echo BASE_URL; ?>/curriculum.php"><span><strong>BSCS curriculum</strong><small>Plan your subjects year by year.</small></span><span class="cso-arrow" aria-hidden="true">&rarr;</span></a>
            <a class="cso-link" href="<?php echo BASE_URL; ?>/map.php"><span><strong>Campus map</strong><small>Find rooms and venues, with walking routes.</small></span><span class="cso-arrow" aria-hidden="true">&rarr;</span></a>
            <a class="cso-link" href="<?php echo BASE_URL; ?>/officers.php"><span><strong>Officers</strong><small>Meet the people leading CSO.</small></span><span class="cso-arrow" aria-hidden="true">&rarr;</span></a>
            <a class="cso-link" href="<?php echo BASE_URL; ?>/calendar.php"><span><strong>Calendar</strong><small>See what is coming up at a glance.</small></span><span class="cso-arrow" aria-hidden="true">&rarr;</span></a>
        </div>
    </div>
</section>

<section id="events" class="section">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">What's next</span><h2>Upcoming CSO Events</h2></div><a href="<?php echo BASE_URL; ?>/events.php">All events →</a></div>
    </div>
    <?php
    $ev_cards = [];
    if ($upcoming && $upcoming->num_rows > 0) { while ($row = $upcoming->fetch_assoc()) $ev_cards[] = card_html('render_event_card', $row); }
    if ($ev_cards) render_marquee($ev_cards, ['label' => 'Upcoming events (moving)', 'enabled' => $marquee_on, 'static_max' => 6, 'min' => 7, 'sec' => 6]);
    else echo '<div class="container"><div class="card-grid"><p>No upcoming events right now &mdash; check back soon.</p></div></div>';
    ?>
</section>

<section id="announcements" class="section alt">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">News</span><h2>Latest Announcements</h2></div><a href="<?php echo BASE_URL; ?>/announcements.php">All announcements →</a></div>
        <div class="card-grid">
            <?php if ($latest && $latest->num_rows > 0): ?>
                <?php while ($row = $latest->fetch_assoc()) render_announcement_card($row); ?>
            <?php else: ?>
                <p>No announcements yet.</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<section id="officers" class="section">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Leadership</span><h2>Officers</h2></div><a href="<?php echo BASE_URL; ?>/officers.php">Full directory →</a></div>
    </div>
    <?php
    $off_cards = [];
    if ($officers && $officers->num_rows > 0) { while ($row = $officers->fetch_assoc()) $off_cards[] = card_html('render_officer_card', $row); }
    if ($off_cards) render_marquee($off_cards, ['label' => 'CSO officers (moving)', 'people' => true, 'enabled' => $marquee_on, 'min' => 9, 'sec' => 4]);
    else echo '<div class="container"><div class="card-grid"><p>Officer profiles will appear here once the admin promotes members to officer roles.</p></div></div>';
    ?>
</section>

<?php if ($faculty && $faculty->num_rows > 0): ?>
<section id="faculty" class="section alt">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Guidance</span><h2>Faculty &amp; Staff</h2></div><a href="<?php echo BASE_URL; ?>/directory.php">See all faculty &amp; staff →</a></div>
    </div>
    <?php
    $fac_cards = []; $fac_i = 0;
    while ($row = $faculty->fetch_assoc()) {
        // Still (non-moving) mode keeps the old phone layout: only the first six show.
        $fac_cards[] = card_html('render_faculty_card', $row, (!$marquee_on && $fac_i >= 6) ? 'fac-extra' : '');
        $fac_i++;
    }
    render_marquee($fac_cards, ['label' => 'Faculty and staff (moving)', 'people' => true, 'reverse' => true, 'enabled' => $marquee_on, 'min' => 9, 'sec' => 4]);
    ?>
</section>
<?php endif; ?>

<section id="how-to-join" class="section<?php echo ($faculty && $faculty->num_rows > 0) ? '' : ' alt'; ?>">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Get started</span><h2>How to join</h2></div></div>
        <ol class="cso-steps">
            <li class="cso-step"><span class="cso-step-n">1</span><div><h3>Create an account</h3><p>Use your Student ID (or Employee ID for faculty) and your email.</p></div></li>
            <?php if (member_approval_required($conn)): ?>
            <li class="cso-step"><span class="cso-step-n">2</span><div><h3>Get approved</h3><p>A CSO officer checks every new sign-up before the account goes live. Faculty accounts are reviewed by a CSO admin.</p></div></li>
            <?php else: ?>
            <li class="cso-step"><span class="cso-step-n">2</span><div><h3>Log in</h3><p>Student accounts are ready right away, so you can log in as soon as you sign up. Faculty accounts are reviewed by a CSO admin.</p></div></li>
            <?php endif; ?>
            <li class="cso-step"><span class="cso-step-n">3</span><div><h3>Take part</h3><p>Join events, check in, read announcements, and complete your profile.</p></div></li>
        </ol>
    </div>
</section>

<section id="faq" class="section<?php echo ($faculty && $faculty->num_rows > 0) ? ' alt' : ''; ?>">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Help</span><h2>Frequently asked questions</h2></div></div>
        <div class="cso-faq">
            <details class="cso-q"><summary>Who can join CSO?</summary><p>BSCS students can join as members. Faculty, advisers, instructors and professors can also create an account and will be shown on this page once approved.</p></details>
            <details class="cso-q"><summary>Is there a fee to create an account?</summary><p>Creating an account on this site is free. For any org fees or dues, please ask an officer.</p></details>
            <details class="cso-q"><summary>How do I check in to an event?</summary><p>Open the event, register, and scan the QR code at the venue or use the check-in page. Your attendance shows in your dashboard.</p></details>
            <details class="cso-q"><summary>I forgot my password. What do I do?</summary><p>Use &ldquo;Forgot password?&rdquo; on the login page to get a code by email and set a new password.</p></details>
            <details class="cso-q"><summary>How do I become an officer?</summary><p>Officers are chosen by CSO and promoted by an admin. Stay active in events and talk to the current officers.</p></details>
        </div>
    </div>
</section>

<?php if (defined('CSO_CONTACT_EMAIL') && (CSO_CONTACT_EMAIL !== '' || CSO_FACEBOOK_URL !== '')): ?>
<section id="contact" class="section alt">
    <div class="container">
        <div class="section-head"><div><span class="eyebrow">Contact</span><h2>Get in touch</h2></div></div>
        <div class="card cso-contact">
            <p class="muted" style="margin:0 0 12px;">Questions about CSO, events or joining? Reach out to us.</p>
            <div class="hero-buttons">
                <?php if (CSO_CONTACT_EMAIL !== ''): ?><a class="btn btn-outline" href="mailto:<?php echo htmlspecialchars(CSO_CONTACT_EMAIL); ?>"><?php echo htmlspecialchars(CSO_CONTACT_EMAIL); ?></a><?php endif; ?>
                <?php if (CSO_FACEBOOK_URL !== ''): ?><a class="btn btn-outline" href="<?php echo htmlspecialchars(CSO_FACEBOOK_URL); ?>" target="_blank" rel="noopener">Facebook page</a><?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!is_logged_in()): ?>
<section class="section cso-join">
    <div class="container">
        <h2>Be part of CSO</h2>
        <p class="muted">Students and faculty are welcome. Joining takes about a minute.</p>
        <div class="hero-buttons" style="margin-top:16px;">
            <a href="<?php echo BASE_URL; ?>/register.php" class="btn btn-primary btn-lg">Join CSO</a>
        </div>
    </div>
</section>
<?php endif; ?>

</div><!-- /.home-page -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
