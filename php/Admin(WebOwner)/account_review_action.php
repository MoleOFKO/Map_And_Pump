<?php
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/user_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    request_redirect('view_requests.php');
}

$adminUser = admin_require_admin();
$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason = '';

if (!$userId || !in_array($action, ['approve', 'reject'], true)) {
    request_flash('danger', 'The account review action was invalid.');
    request_redirect('view_requests.php');
}

if ($action === 'reject') {
    $resolvedReason = request_resolve_rejection_reason($_POST);
    if (empty($resolvedReason['ok'])) {
        request_flash('danger', (string) ($resolvedReason['error'] ?? 'A rejection reason is required.'));
        request_redirect('view_requests.php');
    }
    $reason = (string) $resolvedReason['reason'];
}

if (!hq_db_available() || !user_account_review_schema_ready()) {
    request_flash('danger', hq_db_error_message());
    request_redirect('view_requests.php');
}

global $conn;

try {
    $conn->begin_transaction();

    $targetQuery = $conn->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.license_id, l.status AS license_status
         FROM users u
         LEFT JOIN user_license l ON l.license_id = u.license_id
         WHERE u.user_id = ?
         FOR UPDATE'
    );
    if (!$targetQuery) {
        throw new RuntimeException('Could not prepare the account lookup.');
    }
    $targetQuery->bind_param('i', $userId);
    $targetQuery->execute();
    $target = $targetQuery->get_result()?->fetch_assoc();
    $targetQuery->close();

    if (!$target) {
        throw new RuntimeException('That account could not be found.');
    }
    $licenseId = (int) ($target['license_id'] ?? 0);
    if ($licenseId <= 0 || strtolower(trim((string) ($target['license_status'] ?? ''))) !== 'pending') {
        throw new RuntimeException('Only a pending driver license can be approved or rejected.');
    }
    if (duplicate_license_record_count('driver', $licenseId) !== 0) {
        throw new RuntimeException('This account has a duplicate driver license. Use Compare to record the decision and notify every affected customer.');
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $update = $conn->prepare('UPDATE user_license SET status = ?, updated_at = NOW() WHERE license_id = ? AND status = \'pending\'');
    if (!$update) {
        throw new RuntimeException('Could not prepare the driver-license status update.');
    }
    $update->bind_param('si', $newStatus, $licenseId);
    if (!$update->execute()) {
        throw new RuntimeException('Could not update the driver-license status.');
    }
    if ($update->affected_rows !== 1) {
        $update->close();
        throw new RuntimeException('The driver license is no longer pending review. Refresh and try again.');
    }
    $update->close();

    $notification = request_send_notification((string) $target['email'], '', '', [
        'kind' => 'request',
        'action' => $action === 'approve' ? 'approve' : 'reject',
        'account_label' => (string) ($target['user_name'] ?? ''),
        'reason' => $action === 'approve' ? '' : $reason,
        'effect' => $action === 'approve'
            ? 'Your driver license was approved. Your customer account remains active.'
            : 'Your driver license was rejected. Your customer account remains active; correct the license details and submit it again.',
    ]);
    $notificationSubject = (string) ($notification['subject'] ?? ('Map & Pump driver-license review: ' . ucfirst($newStatus)));
    $notificationMessage = $action === 'approve'
        ? 'Your driver license has been approved. Your customer account remains active.'
        : "Your driver license was rejected. Your customer account remains active.\n\nReason:\n" . $reason;

    // Account verification is not represented by a system_requests row, so request_id is intentionally NULL.
    $notificationInsert = $conn->prepare(
        'INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$notificationInsert) {
        throw new RuntimeException('Could not prepare the account notification record.');
    }
    $accountRequestId = null;
    $notificationType = 'driver_license_' . $newStatus;
    $notificationStatus = (string) ($notification['status'] ?? 'not_configured');
    $notificationNote = (string) ($notification['note'] ?? 'Notification delivery was not configured.');
    $notificationInsert->bind_param(
        'iissssss',
        $accountRequestId,
        $userId,
        $target['email'],
        $notificationType,
        $notificationSubject,
        $notificationMessage,
        $notificationStatus,
        $notificationNote
    );
    if (!$notificationInsert->execute()) {
        throw new RuntimeException('Could not record the account notification.');
    }
    $notificationInsert->close();

    $logVerb = $action === 'approve' ? 'Approved' : 'Rejected';
    admin_record_log((int) $adminUser['user_id'], $logVerb . ' driver license #' . $licenseId . ' for user #' . $userId . ' (' . $target['user_name'] . ').');
    $conn->commit();

    $actionLabel = $action === 'approve' ? 'approval' : 'rejection';
    $_SESSION['account_review_success'] = [
        'action' => $actionLabel,
        'account_id' => $userId,
    ];
    request_flash('success', 'The ' . $actionLabel . ' is executed successfully!');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Admin account review] Review failed: ' . $exception->getMessage());
    request_flash('danger', 'The account could not be reviewed. No partial changes were saved.');
}

request_redirect('view_requests.php');
