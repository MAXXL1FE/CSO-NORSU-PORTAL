<?php
/**
 * Saves the "Moving strips on the Home page" switch from the admin Settings menu.
 * POST: csrf_token, value (1 = officers/events/faculty glide sideways on Home, 0 = still grid).
 * Admins only. Replies with JSON.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Please log in again.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}
$token = $_POST['csrf_token'] ?? '';
if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Refresh the page.']);
    exit;
}

// Admins only. The role is read from the database, not just the session.
$uid  = (int) $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role FROM users WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param('i', $uid);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$me || $me['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Only admins can change this.']);
    exit;
}

$value = (!empty($_POST['value']) && $_POST['value'] !== '0') ? '1' : '0';
if (!set_app_setting($conn, 'home_marquee', $value)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save. Try again.']);
    exit;
}
echo json_encode(['ok' => true, 'value' => (int) $value]);
