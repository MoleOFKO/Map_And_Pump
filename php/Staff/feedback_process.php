<?php
session_start();
require_once __DIR__ . '/../conn.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: feedback_manage.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
    header('Location: feedback_manage.php');
    exit;
}

$feedbackId = isset($_POST['feedback_id']) ? (int)$_POST['feedback_id'] : 0;
$staffId = isset($_POST['staff_id']) ? (int)$_POST['staff_id'] : $userId;
$replyMsg = trim((string)($_POST['response_msg'] ?? ''));

if ($feedbackId <= 0 || $replyMsg === '') {
    header('Location: feedback_manage.php');
    exit;
}

// Ensure staff id matches session (prevent spoofing)
if ($staffId !== $userId) $staffId = $userId;

// Ensure replies table exists (safe create)
if (isset($conn)) {
    $createSql = "CREATE TABLE IF NOT EXISTS feedback_replies (
        reply_id INT AUTO_INCREMENT PRIMARY KEY,
        feedback_id INT NOT NULL,
        staff_id INT NOT NULL,
        reply_text TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    @$conn->query($createSql);

    $ins = $conn->prepare('INSERT INTO feedback_replies (feedback_id, staff_id, reply_text, created_at) VALUES (?, ?, ?, NOW())');
    if ($ins) {
        $ins->bind_param('iis', $feedbackId, $staffId, $replyMsg);
        $ok = $ins->execute();
        $ins->close();
        if ($ok) {
            header('Location: feedback_manage.php?reply_sent=1');
            exit;
        }
    }
}

// Fallback: redirect back
header('Location: feedback_manage.php');
exit;

?>
