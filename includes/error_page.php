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
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?> — CSO NORSU Portal</title>
        <link rel="stylesheet" href="<?php echo $base; ?>/assets/css/style.css">
    </head>
    <body>
        <div class="error-screen">
            <div class="error-card">
                <div class="error-icon">!</div>
                <h1><?php echo htmlspecialchars($title); ?></h1>
                <p><?php echo htmlspecialchars($message); ?></p>
                <?php if ($detail && ini_get('display_errors')): ?>
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
