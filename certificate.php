<?php
/* Certificate of Attendance: one per student per event they were marked present at. */
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/db.php';
require_login();

$event_id = (int) ($_GET['event_id'] ?? 0);
$user_id  = (int) ($_GET['user_id'] ?? $_SESSION['user_id']);
$is_staff = in_array($_SESSION['role'], ['admin', 'officer', 'moderator'], true);
if ($user_id !== (int) $_SESSION['user_id'] && !$is_staff) {
    header('Location: ' . BASE_URL . '/unauthorized.php');
    exit;
}

$stmt = $conn->prepare("SELECT e.title, e.event_date, e.event_end_date, e.venue, a.status,
                               u.first_name, u.middle_name, u.last_name, u.suffix
                        FROM attendance a
                        JOIN events e ON e.event_id = a.event_id
                        JOIN users u  ON u.user_id = a.user_id
                        WHERE a.event_id = ? AND a.user_id = ? AND a.status = 'present'");
$stmt->bind_param('ii', $event_id, $user_id);
$stmt->execute();
$c = $stmt->get_result()->fetch_assoc();

if (!$c || $c['event_date'] > date('Y-m-d')) {
    $page_title = 'Certificate';
    require_once __DIR__ . '/includes/header.php';
    echo '<section class="section"><div class="container"><div class="empty-state"><h3>No certificate available</h3><p>A certificate is available once you are marked present at an event that has started.</p>'
       . '<a class="btn btn-outline btn-sm" href="' . BASE_URL . '/dashboard/my_attendance.php">Back to my attendance</a></div></div></section>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
$name = full_name($c);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Certificate of Attendance — <?php echo e($name); ?></title>
<style>
  @page { size: A4 landscape; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e9ede9; font-family: Georgia, "Times New Roman", serif; color: #1b2a20; }
  .bar { text-align: center; padding: 14px; font-family: system-ui, sans-serif; }
  .bar button, .bar a { font: inherit; padding: 10px 18px; border-radius: 8px; border: 1px solid #375534; background: #375534; color: #fff; text-decoration: none; cursor: pointer; margin: 0 4px; }
  .bar a { background: #fff; color: #375534; }
  .cert { width: min(1000px, 96vw); aspect-ratio: 297 / 210; margin: 10px auto 30px; background: #fff; border: 14px double #375534; padding: 5% 7%; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 1.6%; }
  .cert img { width: 84px; height: 84px; object-fit: contain; }
  .org { letter-spacing: .18em; text-transform: uppercase; font-size: clamp(.7rem, 1.4vw, 1rem); color: #375534; font-family: system-ui, sans-serif; font-weight: 700; }
  h1 { margin: 0; font-size: clamp(1.6rem, 4.6vw, 3rem); color: #375534; }
  .small { font-size: clamp(.8rem, 1.5vw, 1.1rem); }
  .name { font-size: clamp(1.5rem, 4vw, 2.6rem); font-style: italic; border-bottom: 2px solid #375534; padding: 0 2em 4px; }
  .ev { font-size: clamp(1.1rem, 2.4vw, 1.7rem); font-weight: 700; }
  .sig { display: flex; gap: 14%; margin-top: 3%; font-family: system-ui, sans-serif; font-size: clamp(.65rem, 1.2vw, .85rem); }
  .sig div { border-top: 1px solid #1b2a20; padding-top: 6px; min-width: 170px; }
  .bar button, .bar a { min-height: 44px; border-radius: 4px; }
  .cert :is(.name, .ev, .small) { max-width: 100%; overflow-wrap: anywhere; }
  .name { padding-right: .5em; padding-left: .5em; }
  @media (max-width: 640px) {
    .bar { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; padding: 12px; }
    .bar button, .bar a { margin: 0; }
    .cert { width: calc(100% - 24px); min-height: 540px; aspect-ratio: auto; margin: 0 12px 18px; padding: 24px 18px; gap: 12px; border-width: 8px; }
    .cert img { width: 64px; height: 64px; }
    .org { max-width: 100%; font-size: .7rem; line-height: 1.45; letter-spacing: .1em; }
    h1 { font-size: clamp(1.5rem, 7vw, 2rem); }
    .small { font-size: .84rem; line-height: 1.45; }
    .name { font-size: clamp(1.4rem, 7vw, 2rem); }
    .ev { font-size: clamp(1.05rem, 5.5vw, 1.45rem); }
    .sig { width: 100%; justify-content: center; gap: 8%; margin-top: 12px; }
    .sig div { min-width: 0; max-width: 180px; flex: 1; }
  }
  @media (max-width: 380px) { .cert { padding-right: 13px; padding-left: 13px; border-width: 6px; } }
  @media print { body { background: #fff; } .bar { display: none; } .cert { width: 100vw; height: 100vh; min-height: 0; margin: 0; border-width: 18px; aspect-ratio: auto; } }
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Print / Save as PDF</button><a href="<?php echo BASE_URL; ?>/dashboard/my_attendance.php">Back</a></div>
<div class="cert">
  <img src="<?php echo asset_v('/assets/images/logo.png'); ?>" alt="CSO-NORSU logo">
  <div class="org">Computer Science Organization &middot; NORSU</div>
  <h1>Certificate of Attendance</h1>
  <div class="small">This is to certify that</div>
  <div class="name"><?php echo e($name); ?></div>
  <div class="small">attended</div>
  <div class="ev"><?php echo e($c['title']); ?></div>
  <div class="small">held on <?php echo e(event_range_text($c)); ?> at <?php echo e($c['venue']); ?>.</div>
  <div class="sig"><div>CSO President</div><div>Adviser</div></div>
</div>
</body>
</html>
