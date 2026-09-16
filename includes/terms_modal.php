<?php
/**
 * Shared "Terms and Conditions" modal.
 * Include this once near the end of a public-facing page (see footer.php).
 * Any link that should open it in-place instead of navigating away can call
 * openTermsModal(event) from an onclick handler; the href still points at
 * terms.php so the link keeps working if JavaScript is unavailable.
 */
?>
<div class="modal-overlay" id="termsModalOverlay" onclick="if (event.target === this) closeTermsModal();">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="termsModalTitle">
        <div class="modal-header">
            <h3 id="termsModalTitle">Terms and Conditions</h3>
            <button type="button" class="modal-close" aria-label="Close" onclick="closeTermsModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="muted" style="font-size:0.85rem; margin-bottom:0;">Last updated: <?php echo date('F Y'); ?></p>
            <div class="terms-body">
                <h3>1. Purpose of this System</h3>
                <p>CSO NORSU Portal (Computer Science Organization — Management and Information System) is provided for members, officers, and administrators of the Computer Science Organization to manage membership records, events, announcements, and attendance.</p>

                <h3>2. Account Responsibility</h3>
                <p>You are responsible for keeping your password confidential and for all activity that happens under your account. Notify an officer or admin immediately if you suspect unauthorized access to your account.</p>

                <h3>3. Accurate Information</h3>
                <p>You agree to provide accurate registration details (student ID, name, course, year level, email) and to keep your profile up to date. Impersonating another student or submitting false information may result in account suspension.</p>

                <h3>4. Data We Collect</h3>
                <p>The system stores your student ID, name, email, course, year level, and your event registration and attendance history. This data is used only for CSO membership management, event coordination, and attendance tracking — it is not sold or shared with third parties outside the organization.</p>

                <h3>5. QR Code and Quick Check-In</h3>
                <p>Attendance features (QR scan check-in and staff-assisted quick check-in) record your presence at CSO events using your account or student ID. Please only check yourself in — checking in on behalf of another student is a violation of these terms.</p>

                <h3>6. Acceptable Use</h3>
                <p>You agree not to attempt to access accounts, data, or admin functions you are not authorized to use, and not to use the system to harass, impersonate, or misrepresent yourself to other members.</p>

                <h3>7. Changes</h3>
                <p>These terms may be updated from time to time as the system evolves. Continued use of CSO NORSU Portal after changes means you accept the updated terms.</p>

                <h3>8. Contact</h3>
                <p>Questions about these terms or your data can be directed to a CSO officer or administrator.</p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-primary btn-sm" onclick="closeTermsModal()">Got it</button>
        </div>
    </div>
</div>
<script>
function openTermsModal(e) {
    if (e && e.preventDefault) e.preventDefault();
    var overlay = document.getElementById('termsModalOverlay');
    if (!overlay) return;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeTermsModal() {
    var overlay = document.getElementById('termsModalOverlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeTermsModal();
});
</script>
