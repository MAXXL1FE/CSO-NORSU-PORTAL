<?php
/**
 * Saves the "Show me in Members" switch from the Settings menu.
 * POST: csrf_token, value (1 or 0). Replies with JSON.
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

$value = !empty($_POST['value']) && $_POST['value'] !== '0' ? 1 : 0;
$uid   = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE users SET show_in_members = ? WHERE user_id = ?");
$stmt->bind_param('ii', $value, $uid);
$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save. Try again.']);
    exit;
}
echo json_encode(['ok' => true, 'value' => $value]);
