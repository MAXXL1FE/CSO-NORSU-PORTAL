<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/db.php';
require_role(['admin', 'officer', 'moderator']);

$event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;

$es = $conn->prepare("SELECT title, event_date FROM events WHERE event_id = ?");
$es->bind_param('i', $event_id);
$es->execute();
$event = $es->get_result()->fetch_assoc();

if (!$event) {
    header('Location: ' . BASE_URL . '/dashboard/attendance.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT u.student_id, u.first_name, u.last_name, u.course, u.year_level, a.status, a.checked_in_at, a.time_out
    FROM attendance a JOIN users u ON u.user_id = a.user_id
    WHERE a.event_id = ? ORDER BY u.last_name ASC
");
$stmt->bind_param('i', $event_id);
$stmt->execute();
$rows = $stmt->get_result();

$filename = 'attendance_' . preg_replace('/[^a-z0-9]+/i', '_', $event['title']) . '_' . $event['event_date'] . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Spreadsheet programs run text that starts with = + - @ as a formula. A leading
// apostrophe makes them show it as plain text instead.
function csv_safe($v) {
    $v = (string) $v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
}

$out = fopen('php://output', 'w');
fputcsv($out, ['Student ID', 'Last Name', 'First Name', 'Program', 'Year Level', 'Status', 'Time In', 'Time Out']);
while ($r = $rows->fetch_assoc()) {
    fputcsv($out, array_map('csv_safe', [
        $r['student_id'], $r['last_name'], $r['first_name'], $r['course'], $r['year_level'],
        ucfirst($r['status']), $r['checked_in_at'], $r['time_out'] ?: '',
    ]));
}
fclose($out);
exit;
