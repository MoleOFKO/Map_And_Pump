<?php
session_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../activity_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feedback_manage.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0 || strtolower((string)($_SESSION['role_name'] ?? '')) !== 'staff') {
    header('Location: feedback_manage.php');
    exit;
}

$staffCsrfToken = (string)($_SESSION['staff_csrf_token'] ?? '');
if ($staffCsrfToken === '' || !hash_equals($staffCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid request token. Reload the page and try again.');
}

$importance = strtolower(trim((string)($_POST['importance'] ?? 'normal')));
$allowed = ['low','normal','high'];
if (!in_array($importance, $allowed, true)) $importance = 'normal';

$short = trim((string)($_POST['short_summary'] ?? 'Staff report'));
if ($short === '') $short = 'Staff report';
if (strlen($short) > 255) $short = substr($short, 0, 255);

$why = trim((string)($_POST['why_happened'] ?? ''));
$attachId = (int)($_POST['attach_feedback_id'] ?? 0);

// If an attached feedback id was provided, try to fetch its text and include it.
$attachedText = '';
if ($attachId > 0 && isset($conn)) {
    $s = $conn->prepare('SELECT feedback FROM feedback WHERE feedback_id = ? LIMIT 1');
    if ($s) {
        $s->bind_param('i', $attachId);
        $s->execute();
        $res = $s->get_result();
        $row = $res?->fetch_assoc();
        if ($row) $attachedText = (string)$row['feedback'];
        $s->close();
    }
}

// Build a short message payload (keep under 255 to match schema)
$messageParts = [];
if ($attachedText !== '') $messageParts[] = 'Recent problem: ' . $attachedText;
if ($why !== '') $messageParts[] = 'Why: ' . $why;
$message = implode(' | ', $messageParts);
if (strlen($message) > 255) $message = substr($message, 0, 252) . '...';

// Use current user's role if available; fallback to Staff role id (5)
$roleId = isset($_SESSION['role_id']) ? (int)$_SESSION['role_id'] : 5;

// Insert into report table
$insert = $conn->prepare('INSERT INTO report (user_id, role_id, importance, short_summery, message, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
if (!$insert) {
    header('Location: feedback_manage.php');
    exit;
}
$insert->bind_param('iisss', $userId, $roleId, $importance, $short, $message);
$ok = $insert->execute();
$insert->close();

if ($ok) {
    map_pump_log_action($pdo, $userId, 'Submitted staff report: ' . $short, 5);
    header('Location: feedback_manage.php?report_created=1');
    exit;
}

header('Location: feedback_manage.php');
exit;
