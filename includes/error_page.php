<?php
/**
 * Renders a clean, on-brand error page without depending on the database
 * (since this is often called precisely when the database is unreachable).
 */
function render_error_page($title, $message, $detail = null) {
    $base = defined('BASE_URL') ? BASE_URL : '';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title><?php echo htmlspecialchars($title); ?> — CSO-NORSU Portal</title>
        <script>
        (function () { try { var s = localStorage, p = s.getItem('cso-theme') || 'system';
            var d = p === 'dark' || (p === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.setAttribute('data-theme', d ? 'dark' : 'light'); } catch (e) {} })();
        </script>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap">
        <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/style.css">
        <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/professional.css">
    </head>
    <body>
        <div class="error-screen">
            <div class="error-card">
                <div class="error-icon">!</div>
                <h1><?php echo htmlspecialchars($title); ?></h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                <?php if ($detail && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)): ?>
                    <details class="error-detail">
                        <summary>Technical detail</summary>
                        <code><?php echo htmlspecialchars($detail); ?></code>
                    </details>
                <?php endif; ?>
                <a href="<?php echo $base; ?>/index.php" class="btn btn-primary">Back to Home</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
