<?php
session_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../feedback_schema.php';
require_once __DIR__ . '/../activity_log.php';
require_once __DIR__ . '/staff_access_guard.php';

map_pump_require_active_staff_session($pdo, true);

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

    $staffRow = $pdo->prepare(
        'SELECT staff_id, branch_id
         FROM staff
         WHERE user_id = :user_id AND is_active = 1
         LIMIT 1'
    );
    $staffRow->execute([':user_id' => $userId]);
    $staffRecord = $staffRow->fetch(PDO::FETCH_ASSOC);
    if (!$staffRecord) {
        throw new RuntimeException('Staff record not found for the current user.');
    }
    $staffId = (int)($staffRecord['staff_id'] ?? 0);
    $stationId = (int)($staffRecord['branch_id'] ?? 0);

    $feedbackStmt = $pdo->prepare(
        'SELECT feedback_id
         FROM feedback
         WHERE feedback_id = :feedback_id
           AND station_id = :station_id
         LIMIT 1'
    );
    $feedbackStmt->execute([':feedback_id' => $feedbackId, ':station_id' => $stationId]);
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
    $existsStmt = $pdo->prepare(
        'SELECT 1
         FROM feedback_replies
         WHERE feedback_id = :feedback_id
           AND staff_id = :staff_id
           AND reply_text = :reply_text
         LIMIT 1'
    );
    $existsStmt->execute([
        ':feedback_id' => $feedbackId,
        ':staff_id' => $staffId,
        ':reply_text' => $replyMsg,
    ]);
    if ($existsStmt->fetchColumn()) {
        $pdo->commit();
        header('Location: feedback_manage.php?tab=replies&reply_sent=1');
        exit;
    }

    $replyStmt = $pdo->prepare(
        'INSERT INTO feedback_replies (feedback_id, staff_id, reply_text, customer_read_at, created_at)
         VALUES (:feedback_id, :staff_id, :reply_text, NULL, NOW())'
    );
    $replyStmt->execute([
        ':feedback_id' => $feedbackId,
        ':staff_id' => $staffId,
        ':reply_text' => $replyMsg,
    ]);
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
