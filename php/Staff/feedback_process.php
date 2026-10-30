<?php
session_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../feedback_schema.php';
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

$action = (string)($_POST['action'] ?? 'reply');
$feedbackId = isset($_POST['feedback_id']) ? (int)$_POST['feedback_id'] : 0;
$replyMsg = trim((string)($_POST['response_msg'] ?? ''));
$returnTab = (string)($_POST['return_tab'] ?? 'all');
$returnTab = in_array($returnTab, ['all', 'read', 'unread', 'replies'], true) ? $returnTab : 'all';

if ($feedbackId <= 0 || ($action === 'reply' && $replyMsg === '')) {
    header('Location: feedback_manage.php?tab=' . $returnTab);
    exit;
}

try {
    if (!map_pump_feedback_schema_ready($pdo)) {
        throw new RuntimeException('Feedback storage is unavailable.');
    }

    $stationStmt = $pdo->prepare(
         'SELECT COALESCE(st.branch_id, bas.station_id) AS station_id
         FROM users u
         LEFT JOIN staff st ON st.user_id = u.user_id
         LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
         WHERE u.user_id = ?
         LIMIT 1'
    );
    $stationStmt->execute([$userId]);
    $stationId = (int)($stationStmt->fetchColumn() ?: 0);

        $feedbackStmt = $pdo->prepare(
                'SELECT f.feedback_id
                 FROM feedback f
                 INNER JOIN usage_history uh ON uh.usage_id = f.usage_id
                 WHERE f.feedback_id = ?
                     AND f.station_id = ?
                     AND uh.station_id = f.station_id
                 LIMIT 1'
        );
    $feedbackStmt->execute([$feedbackId, $stationId]);
    if (!$feedbackStmt->fetchColumn()) {
        throw new RuntimeException('Feedback is outside this station.');
    }

    if ($action === 'mark_read') {
        $markRead = $pdo->prepare('UPDATE feedback SET staff_read_at = COALESCE(staff_read_at, NOW()) WHERE feedback_id = ?');
        $markRead->execute([$feedbackId]);
        map_pump_log_action($pdo, $userId, 'Marked customer feedback #' . $feedbackId . ' as read', 5);
        header('Location: feedback_manage.php?tab=read');
        exit;
    }

    $pdo->beginTransaction();
    $replyStmt = $pdo->prepare('INSERT INTO feedback_replies (feedback_id, staff_id, reply_text, created_at) VALUES (?, ?, ?, NOW())');
    $replyStmt->execute([$feedbackId, $userId, $replyMsg]);
    $markRead = $pdo->prepare('UPDATE feedback SET staff_read_at = COALESCE(staff_read_at, NOW()) WHERE feedback_id = ?');
    $markRead->execute([$feedbackId]);
    $pdo->commit();
    map_pump_log_action($pdo, $userId, 'Replied to customer feedback #' . $feedbackId, 5);
    header('Location: feedback_manage.php?tab=replies&reply_sent=1');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Unable to process staff feedback reply: ' . $e->getMessage());
}

header('Location: feedback_manage.php?tab=' . $returnTab . '&reply_error=1');
exit;
