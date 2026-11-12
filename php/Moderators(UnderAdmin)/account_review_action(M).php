<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    request_redirect('view_requests(M).php');
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason = '';

if (!$userId || !in_array($action, ['approve', 'reject'], true)) {
    request_flash('danger', 'The account review action was invalid.');
    request_redirect('view_requests(M).php');
}

if ($action === 'reject') {
    $resolvedReason = request_resolve_rejection_reason($_POST);
    if (empty($resolvedReason['ok'])) {
        request_flash('danger', (string) ($resolvedReason['error'] ?? 'A rejection reason is required.'));
        request_redirect('view_requests(M).php');
    }
    $reason = (string) $resolvedReason['reason'];
}

if (!hq_db_available() || !user_account_review_schema_ready()) {
    request_flash('danger', hq_db_error_message());
    request_redirect('view_requests(M).php');
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

    if (!empty($target['license_id']) && user_account_column_exists('user_license', 'status')) {
        $licenseStatus = $action === 'approve' ? 'approved' : 'rejected';
        $licenseUpdate = $conn->prepare('UPDATE user_license SET status = ?, updated_at = NOW() WHERE license_id = ?');
        if (!$licenseUpdate) {
            throw new RuntimeException('Could not prepare the license status update.');
        }
        $licenseId = (int) $target['license_id'];
        $licenseUpdate->bind_param('si', $licenseStatus, $licenseId);
        if (!$licenseUpdate->execute()) {
            throw new RuntimeException('Could not update the driver license status.');
        }
        $licenseUpdate->close();
    }

    $notification = request_send_notification((string) $target['email'], '', '', [
        'kind' => 'verification',
        'action' => $action === 'approve' ? 'approve' : 'reject',
        'account_label' => (string) ($target['user_name'] ?? ''),
        'reason' => $action === 'approve' ? '' : $reason,
        'effect' => $action === 'approve'
            ? 'Your account verification was approved. You may use the account under the project Terms of Service.'
            : 'Your account verification was not approved. You may correct the submission and try again if the project allows it.',
    ]);
    $notificationSubject = (string) ($notification['subject'] ?? ('Map & Pump account verification: ' . ucfirst($newStatus)));
    $notificationMessage = $action === 'approve'
        ? 'Your Map & Pump account verification has been approved. You can now use the account.'
        : "Your Map & Pump account verification was rejected.\n\nReason:\n" . $reason;

    // Account verification does not originate in system_requests, so request_id stays NULL.
    $notificationInsert = $conn->prepare(
        'INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$notificationInsert) {
        throw new RuntimeException('Could not prepare the account notification record.');
    }
    $accountRequestId = null;
    $notificationType = 'account_' . $newStatus;
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
    admin_record_log((int) $moderatorUser['user_id'], $logVerb . ' pending account #' . $userId . ' (' . $target['user_name'] . ').');
    $conn->commit();

    request_flash('success', 'Driver license #' . $licenseId . ' was ' . $newStatus . '. The user account was not changed.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Moderator account review] Review failed: ' . $exception->getMessage());
    request_flash('danger', 'The account could not be reviewed. No partial changes were saved.');
}

request_redirect('view_requests(M).php');
