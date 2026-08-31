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

if (!$userId || !in_array($actionType, ['suspend', 'remove'], true) || $reason === '') {
    hq_flash('danger', 'Choose suspend or remove and provide a reason before submitting.');
    hq_redirect($redirect);
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
    "SELECT u.user_id, u.user_name, u.email, u.status, r.role_id, r.role_name
     FROM users u
     INNER JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = ? AND LOWER(TRIM(r.role_name)) NOT IN ('admin', 'moderator', 'hq', 'headquarter', 'headquarters')
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
    hq_flash('danger', 'The selected account is not a manageable non-HQ user account.');
    hq_redirect($redirect);
}

$newStatus = $actionType === 'remove' ? 'removed' : 'suspended';
$notification = hq_send_notification(
    (string) $target['email'],
    'Map & Pump Customer account ' . ucfirst($actionType),
    sprintf("Your Map & Pump Customer account has been %s.\n\nReason:\n%s", $actionType === 'remove' ? 'removed' : 'suspended', $reason)
);

try {
    $conn->begin_transaction();

    $statusUpdate = $conn->prepare(
        "UPDATE users
         SET status = ?, updated_at = NOW()
         WHERE user_id = ?
           AND role_id IN (
               SELECT role_id FROM roles
               WHERE LOWER(TRIM(role_name)) NOT IN ('admin', 'moderator', 'hq', 'headquarter', 'headquarters')
           )"
    );
    if (!$statusUpdate) {
        throw new RuntimeException('Could not prepare the Customer status update.');
    }
    $statusUpdate->bind_param('si', $newStatus, $userId);
    if (!$statusUpdate->execute()) {
        throw new RuntimeException('Could not update the Customer account status.');
    }
    $statusUpdate->close();

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



