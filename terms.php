<?php
require_once __DIR__ . '/includes/functions.php';
$page_title = 'Terms and Conditions';
require_once __DIR__ . '/includes/header.php';
?>

<section class="section">
    <div class="container narrow">
        <h2>Terms and Conditions</h2>
        <p style="color:var(--muted); font-size:0.85rem;">Last updated: <?php echo date('F Y'); ?></p>

        <div class="terms-body">
            <?php require __DIR__ . '/includes/terms_content.php'; ?>
        </div>

        <a href="<?php echo BASE_URL; ?>/login.php" class="btn btn-outline" style="margin-top:20px;">Back to Login</a>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
