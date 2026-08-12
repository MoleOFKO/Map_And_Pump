<?php
require_once __DIR__ . '/request_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    request_redirect();
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));

if (!$requestId || !in_array($action, ['approve', 'reject'], true)) {
    request_flash('danger', 'The request action was invalid.');
    request_redirect();
}

if ($action === 'reject' && $reason === '') {
    request_flash('danger', 'A rejection reason is required.');
    request_redirect();
}

if (mb_strlen($reason) > 2000) {
    request_flash('danger', 'The rejection reason must be 2,000 characters or fewer.');
    request_redirect();
}

if (!hq_db_available() || !request_schema_ready()) {
    request_flash('danger', 'The request database is temporarily unavailable.');
    request_redirect();
}

global $conn;
$reviewer = admin_current_user(false);
if (!$reviewer) {
    request_flash('danger', 'A signed-in administrator or moderator account is required to review requests.');
    request_redirect();
}
$reviewerId = (int) $reviewer['user_id'];

$targetQuery = $conn->prepare(
    'SELECT r.request_id, r.reference_code, r.sender_entity,
            r.sender_email, r.status, u.user_id, u.email AS user_email
     FROM system_requests r
     LEFT JOIN users u ON u.user_id = r.submitter_user_id
     WHERE r.request_id = ?
     LIMIT 1'
);

if (!$targetQuery) {
    request_flash('danger', 'The request could not be loaded.');
    request_redirect();
}

$targetQuery->bind_param('i', $requestId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();

if (!$target) {
    request_flash('danger', 'That request could not be found.');
    request_redirect();
}

if (strtolower((string) $target['status']) !== 'pending') {
    request_flash('danger', 'Only pending requests can be approved or rejected.');
    request_redirect();
}

$newStatus = $action === 'approve' ? 'approved' : 'rejected';
$userStatus = $newStatus;
$reviewReason = $action === 'approve' ? 'Approved by administrator.' : $reason;
$recipient = trim((string) ($target['user_email'] ?: $target['sender_email']));
$subject = sprintf('Map & Pump request %s: %s', $target['reference_code'], ucfirst($newStatus));
$message = $action === 'approve'
    ? sprintf('Your request %s for %s has been approved.', $target['reference_code'], $target['sender_entity'])
    : sprintf("Your request %s for %s was rejected.\n\nReason:\n%s", $target['reference_code'], $target['sender_entity'], $reason);
$notification = request_send_notification($recipient, $subject, $message);

try {
    $conn->begin_transaction();

    $requestUpdate = $conn->prepare(
        'UPDATE system_requests
         SET status = ?, review_reason = ?, reviewed_at = NOW(), reviewed_by = ?, updated_at = NOW()
         WHERE request_id = ? AND status = \'pending\''
    );
    if (!$requestUpdate) {
        throw new RuntimeException('Could not prepare the request update.');
    }

    $requestUpdate->bind_param('ssii', $newStatus, $reviewReason, $reviewerId, $requestId);
    if (!$requestUpdate->execute() || $requestUpdate->affected_rows !== 1) {
        throw new RuntimeException('The request was already changed or could not be updated.');
    }
    $requestUpdate->close();

    $userId = $target['user_id'] !== null ? (int) $target['user_id'] : null;
    if ($userId !== null) {
        $userUpdate = $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?');
        if (!$userUpdate) {
            throw new RuntimeException('Could not prepare the account status update.');
        }

        $userUpdate->bind_param('si', $userStatus, $userId);
        if (!$userUpdate->execute()) {
            throw new RuntimeException('Could not update the account status.');
        }
        $userUpdate->close();
    }

    $notificationInsert = $conn->prepare(
        'INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$notificationInsert) {
        throw new RuntimeException('Could not prepare the notification record.');
    }

    $notificationType = 'request_' . $newStatus;
    $deliveryStatus = $notification['status'];
    $deliveryNote = $notification['note'];
    $notificationInsert->bind_param(
        'iissssss',
        $requestId,
        $userId,
        $recipient,
        $notificationType,
        $subject,
        $message,
        $deliveryStatus,
        $deliveryNote
    );
    if (!$notificationInsert->execute()) {
        throw new RuntimeException('Could not record the notification.');
    }
    $notificationInsert->close();

    $conn->commit();
    request_flash('success', sprintf('Request %s was %s. The notification was recorded for delivery.', $target['reference_code'], $newStatus));
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Request registry] Action failed: ' . $exception->getMessage());
    request_flash('danger', 'The request action could not be completed. No partial change was saved.');
}

request_redirect();
