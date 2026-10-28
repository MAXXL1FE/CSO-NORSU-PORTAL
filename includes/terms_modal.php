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
                <?php require __DIR__ . '/terms_content.php'; ?>
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
