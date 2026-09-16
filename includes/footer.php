<?php $is_dashboard = strpos($_SERVER['SCRIPT_NAME'], '/dashboard/') !== false; ?>
<?php if ($is_dashboard): ?>
        </div>
    </main>
</div>
<?php else: ?>
<footer class="site-footer">
    <div class="container footer-inner">
        <div>
            <strong>CSO NORSU</strong>
            <p class="muted">Computer Science Organization — Management &amp; Information System</p>
        </div>
        <div class="footer-links">
            <a href="<?php echo BASE_URL; ?>/home.php">Home</a>
            <a href="<?php echo BASE_URL; ?>/officers.php">Officers</a>
            <a href="<?php echo BASE_URL; ?>/terms.php" onclick="openTermsModal(event)">Terms</a>
        </div>
    </div>
    <div class="container">
        <p class="muted footer-copy">&copy; <?php echo date('Y'); ?> CSO NORSU. All rights reserved.</p>
    </div>
</footer>
<?php require_once __DIR__ . '/terms_modal.php'; ?>
<?php endif; ?>
</body>
</html>
