<?php
/**
 * Shared helper functions: session bootstrap, auth guards, CSRF,
 * sanitization, and BASE_URL auto-detection.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------
// BASE_URL auto-detection — works whether the app lives at
// http://localhost/CSO-MIS or at the web root http://localhost/
// ---------------------------------------------------------------
if (!defined('BASE_URL')) {
    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    // Normalize: strip a trailing /dashboard if functions.php was required from there
    $script_dir = preg_replace('#/dashboard$#', '', $script_dir);
    $script_dir = rtrim($script_dir, '/');
    define('BASE_URL', $script_dir);
}

// ---------------------------------------------------------------
// Sanitization
// ---------------------------------------------------------------
function clean($value) {
    if ($value === null) return '';
    return trim(strip_tags($value));
}

// ---------------------------------------------------------------
// Auth helpers
// ---------------------------------------------------------------
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function current_role() {
    return $_SESSION['role'] ?? null;
}

function require_login() {
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function require_role($roles) {
    require_login();
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    if (!in_array($_SESSION['role'], $roles, true)) {
        header('Location: ' . BASE_URL . '/unauthorized.php');
        exit;
    }
}

function redirect_to_dashboard() {
    $role = $_SESSION['role'] ?? 'member';
    header('Location: ' . BASE_URL . '/dashboard/' . $role . '.php');
    exit;
}

// ---------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_check() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('Security check failed. Please go back and try again.');
    }
}

// ---------------------------------------------------------------
// Flash messages (survive one redirect)
// ---------------------------------------------------------------
function set_flash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash() {
    if (empty($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

// ---------------------------------------------------------------
// Image uploads (profile photos, event covers, announcement images,
// event galleries, About-CSO image) — all funnel through here so
// validation, naming, and storage stay consistent everywhere.
// ---------------------------------------------------------------
function handle_image_upload($field_name, $subdir, $max_bytes = 3 * 1024 * 1024) {
    if (!isset($_FILES[$field_name]) || $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null]; // nothing uploaded — not an error
    }
    return handle_image_upload_file($_FILES[$field_name], $subdir, $max_bytes);
}

// Same validation/storage logic, but takes a single-file $_FILES-shaped
// array directly — used for multi-file inputs like the event gallery,
// where each entry has to be pulled out of the PHP multi-file structure
// before this runs.
function handle_image_upload_file($file, $subdir, $max_bytes = 3 * 1024 * 1024) {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'The image failed to upload. Please try again.'];
    }
    if ($file['size'] > $max_bytes) {
        $mb = round($max_bytes / 1024 / 1024, 1);
        return ['ok' => false, 'error' => "Image is too large — please keep it under {$mb}MB."];
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Please upload a JPG, PNG, WEBP, or GIF image.'];
    }

    $ext = $allowed[$mime];
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    $dest_dir = __DIR__ . '/../assets/uploads/' . $subdir . '/';
    if (!is_dir($dest_dir)) {
        mkdir($dest_dir, 0755, true);
    }

    if (!move_uploaded_file($file['tmp_name'], $dest_dir . $filename)) {
        return ['ok' => false, 'error' => 'Could not save the uploaded image.'];
    }

    return ['ok' => true, 'path' => '/assets/uploads/' . $subdir . '/' . $filename];
}

function delete_uploaded_image($relative_path) {
    if (empty($relative_path)) return;
    $abs = __DIR__ . '/..' . $relative_path;
    if (is_file($abs)) {
        @unlink($abs);
    }
}

// ---------------------------------------------------------------
// Small display helpers
// ---------------------------------------------------------------
function initials($first, $last) {
    $a = mb_substr(trim($first), 0, 1);
    $b = mb_substr(trim($last), 0, 1);
    return mb_strtoupper($a . $b);
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
