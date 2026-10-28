<?php $is_dashboard = strpos($_SERVER['SCRIPT_NAME'], '/dashboard/') !== false; ?>
<?php if ($is_dashboard): ?>
        </div>
    </main>
</div>
<?php elseif (!empty($auth_layout)): ?>
<?php require_once __DIR__ . '/terms_modal.php'; ?>
<?php else: ?>
<?php
require_once __DIR__ . '/../config/site.php';
$has_contact = CSO_CONTACT_EMAIL !== '' || CSO_FACEBOOK_URL !== '' || CSO_LOCATION !== '';
?>
<div class="footer-spacer" aria-hidden="true"></div>
<footer class="site-footer footer-pro">
    <!-- Call-to-action band -->
    <div class="fp-cta">
        <div class="fp-cta-inner">
            <div>
                <h3><?php echo is_logged_in() ? 'Have an idea or found a problem?' : 'Be part of the Computer Science Organization'; ?></h3>
                <p><?php echo is_logged_in() ? 'Tell the officers what we can do better. Every message is read.' : 'Students and faculty are welcome. Creating an account takes about a minute.'; ?></p>
            </div>
            <div class="fp-cta-actions">
                <?php if (is_logged_in()): ?>
                    <a href="<?php echo BASE_URL; ?>/dashboard/feedback.php" class="btn btn-primary">Send feedback</a>
                <?php else: ?>
                    <a href="<?php echo BASE_URL; ?>/register.php" class="btn btn-primary">Join CSO</a>
                    <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-outline">Log in</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="fp-main">
        <div class="fp-brand">
            <div class="footer-logo">
                <img src="<?php echo asset_v('/assets/images/logo.png'); ?>" alt="" onerror="this.style.display='none'">
                <div><strong>CSO-NORSU</strong><span>Computer Science Organization</span></div>
            </div>
            <p>The official Management &amp; Information System of the Computer Science Organization of Negros Oriental State University: membership, events, announcements and attendance, all in one place.</p>
            <div class="fp-social">
                <?php if (CSO_FACEBOOK_URL !== ''): ?>
                    <a href="<?php echo e(CSO_FACEBOOK_URL); ?>" target="_blank" rel="noopener" aria-label="CSO-NORSU on Facebook" title="Facebook">
                        <svg class="icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                    </a>
                <?php endif; ?>
                <?php if (CSO_CONTACT_EMAIL !== ''): ?>
                    <a href="mailto:<?php echo e(CSO_CONTACT_EMAIL); ?>" aria-label="Email CSO-NORSU" title="Email">
                        <svg class="icon" aria-hidden="true"><use href="#i-message"/></svg>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <nav class="fp-col" aria-label="Explore">
            <h4>Explore</h4>
            <ul>
                <li><a href="<?php echo BASE_URL; ?>/home.php">Home</a></li>
                <li><a href="<?php echo BASE_URL; ?>/events.php">Events</a></li>
                <li><a href="<?php echo BASE_URL; ?>/announcements.php">Announcements</a></li>
                <li><a href="<?php echo BASE_URL; ?>/calendar.php">Calendar</a></li>
                <?php if (is_logged_in()): ?><li><a href="<?php echo BASE_URL; ?>/map.php">Campus Map</a></li><?php endif; ?>
            </ul>
        </nav>

        <nav class="fp-col" aria-label="Organization">
            <h4>Organization</h4>
            <ul>
                <li><a href="<?php echo BASE_URL; ?>/officers.php">Officers</a></li>
                <li><a href="<?php echo BASE_URL; ?>/directory.php">Faculty &amp; Staff</a></li>
                <?php if (is_logged_in()): ?><li><a href="<?php echo BASE_URL; ?>/members.php">Members</a></li><?php endif; ?>
                <li><a href="<?php echo BASE_URL; ?>/curriculum.php">Curriculum</a></li>
                <li><a href="<?php echo BASE_URL; ?>/home.php#faq">FAQ</a></li>
            </ul>
        </nav>

        <nav class="fp-col" aria-label="Support">
            <h4>Support</h4>
            <ul>
                <?php if (is_logged_in()): ?>
                    <li><a href="<?php echo BASE_URL; ?>/instructions.php">Instructions</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboard/feedback.php">Feedback</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/dashboard/my_attendance.php">My attendance</a></li>
                <?php else: ?>
                    <li><a href="<?php echo BASE_URL; ?>/login.php">Member login</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/register.php">Create an account</a></li>
                    <li><a href="<?php echo BASE_URL; ?>/forgot_password.php">Forgot password</a></li>
                <?php endif; ?>
                <li><a href="<?php echo BASE_URL; ?>/terms.php" onclick="openTermsModal(event)">Terms &amp; Conditions</a></li>
            </ul>
        </nav>

        <div class="fp-col fp-contact">
            <h4>Contact</h4>
            <ul>
                <?php if (CSO_CONTACT_EMAIL !== ''): ?><li><svg class="icon" aria-hidden="true"><use href="#i-message"/></svg><a href="mailto:<?php echo e(CSO_CONTACT_EMAIL); ?>"><?php echo e(CSO_CONTACT_EMAIL); ?></a></li><?php endif; ?>
                <?php if (CSO_FACEBOOK_URL !== ''): ?><li><svg class="icon" aria-hidden="true"><use href="#i-users"/></svg><a href="<?php echo e(CSO_FACEBOOK_URL); ?>" target="_blank" rel="noopener">Official Facebook page</a></li><?php endif; ?>
                <?php if (CSO_LOCATION !== ''): ?><li><svg class="icon" aria-hidden="true"><use href="#i-pin"/></svg><span><?php echo e(CSO_LOCATION); ?></span></li><?php endif; ?>
                <li><svg class="icon" aria-hidden="true"><use href="#i-award"/></svg><span>Find an officer at any CSO event</span></li>
            </ul>
        </div>
    </div>

    <div class="fp-bottom">
        <p class="footer-copy">&copy; <?php echo date('Y'); ?> CSO-NORSU &middot; Negros Oriental State University. All rights reserved.</p>
        <div class="footer-links">
            <a href="<?php echo BASE_URL; ?>/terms.php" onclick="openTermsModal(event)">Terms &amp; Conditions</a>
            <button type="button" class="footer-top" onclick="window.scrollTo({ top: 0, behavior: 'smooth' })" aria-label="Back to top"><svg class="icon" aria-hidden="true"><use href="#i-chevron"/></svg>Back to top</button>
        </div>
    </div>
</footer>
<?php require_once __DIR__ . '/terms_modal.php'; ?>
<?php endif; ?>
<?php if (empty($auth_layout)): ?>
<?php
/* Phone-only bottom tab bar. Hidden by default in ui.css and only shown by assets/css/mobile.css. */
$mt_logged = function_exists('is_logged_in') && is_logged_in();
$mt_file   = basename($_SERVER['SCRIPT_NAME']);
$mt_dash   = strpos($_SERVER['SCRIPT_NAME'], '/dashboard/') !== false;
$mt_tabs = [
    ['home.php', '/home.php', 'home', 'Home', !$mt_dash && in_array($mt_file, ['home.php', 'index.php'], true)],
    ['events.php', '/events.php', 'calendar', 'Events', !$mt_dash && in_array($mt_file, ['events.php', 'event.php', 'calendar.php'], true)],
];
if ($mt_logged) {
    $mt_tabs[] = ['att', '/dashboard/my_attendance.php', 'checkin', 'Attendance', $mt_file === 'my_attendance.php'];
    $mt_tabs[] = ['notif', '/dashboard/notifications.php', 'bell', 'Alerts', $mt_file === 'notifications.php'];
    $mt_tabs[] = ['me', '/dashboard/profile.php', 'user', 'Profile', $mt_file === 'profile.php'];
} else {
    $mt_tabs[] = ['ann', '/announcements.php', 'megaphone', 'News', !$mt_dash && in_array($mt_file, ['announcements.php', 'announcement.php'], true)];
    $mt_tabs[] = ['login', '/login.php', 'user', 'Log in', $mt_file === 'login.php'];
}
?>
<nav class="mtabbar" aria-label="Main tabs">
    <?php foreach ($mt_tabs as $t): ?>
        <a class="mtab<?php echo $t[4] ? ' is-current' : ''; ?>" href="<?php echo BASE_URL . $t[1]; ?>"<?php echo $t[4] ? ' aria-current="page"' : ''; ?>>
            <span class="mtab-ico"><svg class="icon" aria-hidden="true"><use href="#i-<?php echo $t[2]; ?>"/></svg></span>
            <span><?php echo $t[3]; ?></span>
            <?php if ($t[0] === 'notif' && !empty($notif_count)): ?><span class="mtab-badge" aria-label="<?php echo (int) $notif_count; ?> unread"><?php echo $notif_count > 9 ? '9+' : (int) $notif_count; ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>
<script>
/* Phones only.
   1) Measure the real tab-bar height (--m-tab-h) so the page always ends right above the bar.
   2) Never show a cut-off slice of the footer above the tab bar: if the footer would be partly
      visible on the first screen, it is pushed fully below the fold (you scroll down to see it). */
(function () {
    var bar = document.querySelector('.mtabbar');
    if (!bar) return;
    var root = document.documentElement, mq = window.matchMedia('(max-width: 640px)');
    var footer = document.querySelector('footer.site-footer'), spacer = document.querySelector('.footer-spacer');
    var raf = 0, busy = false;
    function run() {
        raf = 0; if (busy) return; busy = true;
        var h = bar.offsetHeight || 0;
        if (h > 0) {
            var safe = Math.max(0, (parseFloat(getComputedStyle(bar).paddingBottom) || 0) - 6);
            root.style.setProperty('--m-tab-h', (h - safe) + 'px');
        }
        if (footer && spacer) {
            spacer.style.removeProperty('min-height');
            if (mq.matches && h > 0) {
                var viewBottom = window.innerHeight - h;                   /* bottom edge of the visible page area */
                var r = footer.getBoundingClientRect();
                var topDoc = r.top + window.pageYOffset;                   /* footer top in page coordinates */
                var atTop = topDoc < viewBottom - 1 && topDoc + r.height > viewBottom + 1;   /* sliced by the bar */
                if (atTop) spacer.style.setProperty('min-height', Math.ceil(spacer.getBoundingClientRect().height + (viewBottom - topDoc) + 10) + 'px', 'important');
            }
        }
        busy = false;
    }
    function queue() { if (!raf) raf = requestAnimationFrame(run); }
    window.addEventListener('load', queue);
    window.addEventListener('resize', queue);
    window.addEventListener('orientationchange', queue);
    window.addEventListener('pageshow', queue);
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(queue);
    document.addEventListener('load', queue, true);                       /* images finishing */
    if (window.MutationObserver) new MutationObserver(queue).observe(document.body, { childList: true, subtree: true });
    if (window.ResizeObserver) { var ro = new ResizeObserver(queue); ro.observe(bar); var m = document.querySelector('section, main'); if (m) ro.observe(m); }
    queue(); setTimeout(queue, 400); setTimeout(queue, 1200);
})();
</script>
<?php endif; ?>
<script>
document.querySelectorAll('.pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = btn.parentElement.querySelector('input');
        if (input.type === 'password') {
            input.type = 'text';
            btn.querySelector('use').setAttribute('href', '#i-eye-off');
        } else {
            input.type = 'password';
            btn.querySelector('use').setAttribute('href', '#i-eye');
        }
    });
});
</script>
<script>
/* Accessibility safety net: every visible field gets an accessible name. */
(function () {
    document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]), select, textarea').forEach(function (el) {
        if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.id && document.querySelector('label[for="' + el.id + '"]')) return;
        if (el.closest('label')) return;
        var name = el.getAttribute('placeholder') || el.getAttribute('name') || el.type || 'Field';
        el.setAttribute('aria-label', name.replace(/[_\-]+/g, ' '));
    });
})();
</script>
</body>
</html>
