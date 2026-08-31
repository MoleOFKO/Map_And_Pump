<?php
require_once __DIR__ . '/user_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('view_users.php');
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$actionType = strtolower(trim((string) ($_POST['action_type'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));
$redirect = trim((string) ($_POST['return_to'] ?? 'view_users.php'));

if (!$userId || !in_array($actionType, ['approve', 'reject', 'suspend', 'remove'], true)) {
    hq_flash('danger', 'Choose a valid Customer account action before submitting.');
    hq_redirect($redirect);
}

if ($reason === '' && !in_array($actionType, ['approve', 'reject'], true)) {
    hq_flash('danger', 'Please provide a reason before updating the Customer account.');
    hq_redirect($redirect);
}

if ($actionType === 'approve' && $reason === '') {
    $reason = 'Approved after moderator review.';
}
if ($actionType === 'reject' && $reason === '') {
    $reason = 'Rejected after moderator review.';
}

if (mb_strlen($reason) > 2000) {
    hq_flash('danger', 'The reason must be 2,000 characters or fewer.');
    hq_redirect($redirect);
}

if (!hq_db_available() || !hq_registry_schema_ready() || !user_action_table_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect($redirect);
}

global $conn;
$targetQuery = $conn->prepare(
    "SELECT u.user_id, u.user_name, u.email, u.status, u.license_id, r.role_id, r.role_name
     FROM users u
     INNER JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = ? AND r.role_id = 6 AND LOWER(TRIM(r.role_name)) = 'customer'
     LIMIT 1"
);

if (!$targetQuery) {
    hq_flash('danger', 'The selected Customer account could not be loaded.');
    hq_redirect($redirect);
}

$targetQuery->bind_param('i', $userId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();

if (!$target) {
    hq_flash('danger', 'The selected account is not a role 6 Customer account.');
    hq_redirect($redirect);
}

$statusMap = [
    'approve' => 'approved',
    'reject' => 'rejected',
    'suspend' => 'suspended',
    'remove' => 'removed',
];
$newStatus = $statusMap[$actionType];
$notification = hq_send_notification(
    (string) $target['email'],
    'Map & Pump Customer account ' . ucfirst($actionType),
    sprintf("Your Map & Pump Customer account has been %s.\n\nReason:\n%s", $newStatus, $reason)
);

try {
    $conn->begin_transaction();

    $statusUpdate = $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 6');
    if (!$statusUpdate) {
        throw new RuntimeException('Could not prepare the Customer status update.');
    }
    $statusUpdate->bind_param('si', $newStatus, $userId);
    if (!$statusUpdate->execute()) {
        throw new RuntimeException('Could not update the Customer account status.');
    }
    $statusUpdate->close();

    if (!empty($target['license_id'])) {
        $licenseUpdate = $conn->prepare('UPDATE user_license SET status = ?, updated_at = NOW() WHERE license_id = ?');
        if (!$licenseUpdate) {
            throw new RuntimeException('Could not prepare the Customer license status update.');
        }
        $licenseUpdate->bind_param('si', $newStatus, $target['license_id']);
        if (!$licenseUpdate->execute()) {
            throw new RuntimeException('Could not update the Customer license status.');
        }
        $licenseUpdate->close();
    }

    $actionInsert = $conn->prepare(
        'INSERT INTO user_account_actions (user_id, action_type, reason, notification_status, notification_note)
         VALUES (?, ?, ?, ?, ?)'
    );
    if (!$actionInsert) {
        throw new RuntimeException('Could not prepare the Customer action history insert.');
    }
    $notificationStatus = (string) ($notification['status'] ?? 'not_configured');
    $notificationNote = (string) ($notification['note'] ?? 'Email delivery is not configured.');
    $actionInsert->bind_param('issss', $userId, $actionType, $reason, $notificationStatus, $notificationNote);
    if (!$actionInsert->execute()) {
        throw new RuntimeException('Could not record the Customer action history.');
    }
    $actionInsert->close();

    $reviewer = admin_current_user(false);
    admin_record_log((int) ($reviewer['user_id'] ?? 0), 'Marked Customer user #' . $userId . ' as ' . $newStatus . '.');
    $conn->commit();
    hq_flash('success', 'The Customer account was marked as ' . $newStatus . '. The reason was recorded; email delivery status: ' . ($notification['status'] ?? 'unknown') . '.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Customer registry] Account action failed: ' . $exception->getMessage());
    hq_flash('danger', 'The Customer account action could not be completed. The account status was not changed.');
}

hq_redirect($redirect);



