<?php
/**
 * Entry point of the app.
 * Logged-in users go to the Home page.
 * Everyone else is sent to the login page first — the public homepage
 * (with events/officers previews) lives at home.php, reachable from
 * the login page and the "Home" link in the nav.
 */
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect_home();
} else {
    header('Location: ' . BASE_URL . '/login.php');
    exit;
}
