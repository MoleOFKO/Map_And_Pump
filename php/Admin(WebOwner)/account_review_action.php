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
$reason = trim((string) ($_POST['reason'] ?? ''));

if (!$userId || !in_array($action, ['approve', 'reject'], true)) {
    request_flash('danger', 'The account review action was invalid.');
    request_redirect('view_requests.php');
}

if ($action === 'reject' && $reason === '') {
    request_flash('danger', 'A rejection reason is required.');
    request_redirect('view_requests.php');
}

if (mb_strlen($reason) > 2000) {
    request_flash('danger', 'The rejection reason must be 2,000 characters or fewer.');
    request_redirect('view_requests.php');
}

if (!hq_db_available() || !request_schema_ready()) {
    request_flash('danger', hq_db_error_message());
    request_redirect('view_requests.php');
}

global $conn;

try {
    $conn->begin_transaction();

    $targetQuery = $conn->prepare(
        'SELECT u.user_id, u.user_name, u.email, u.status, u.license_id, r.role_name
         FROM users u
         INNER JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
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
    if (strtolower(trim((string) $target['status'])) !== 'pending_verification') {
        throw new RuntimeException('Only pending verification accounts can be approved or rejected.');
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $update = $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?');
    if (!$update) {
        throw new RuntimeException('Could not prepare the account status update.');
    }
    $update->bind_param('si', $newStatus, $userId);
    if (!$update->execute()) {
        throw new RuntimeException('Could not update the account status.');
    }
    $update->close();

    if (!empty($target['license_id'])) {
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
    admin_record_log((int) $adminUser['user_id'], $logVerb . ' pending account #' . $userId . ' (' . $target['user_name'] . ').');
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
