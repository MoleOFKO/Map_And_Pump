<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$actingUser = admin_require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('view_users.php');
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$actionType = strtolower(trim((string) ($_POST['action_type'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));
$redirect = trim((string) ($_POST['return_to'] ?? 'view_users.php'));
if ($redirect === '') {
    $redirect = 'view_users.php';
}

if (!$userId || !in_array($actionType, ['suspend', 'remove'], true) || $reason === '') {
    hq_flash('danger', 'Choose suspend or remove and provide a reason before submitting.');
    hq_redirect($redirect);
}

if (mb_strlen($reason) > 2000) {
    hq_flash('danger', 'The reason must be 2,000 characters or fewer.');
    hq_redirect($redirect);
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect($redirect);
}

$historyReady = user_action_table_ready();

global $conn;

// Match the same effective role the list uses (current → business → base).
$targetQuery = $conn->prepare(
    "SELECT u.user_id, u.user_name, u.email, u.status, u.role_id, u.business_role_id, u.current_role_id,
            COALESCE(cr.role_name, br.role_name, r.role_name) AS role_name
     FROM users u
     LEFT JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN roles br ON br.role_id = u.business_role_id
     LEFT JOIN roles cr ON cr.role_id = u.current_role_id
     WHERE u.user_id = ?
       AND u.role_id = 6
       AND LOWER(TRIM(r.role_name)) = 'customer'
       AND (u.business_role_id IS NULL OR u.business_role_id = 6)
       AND (u.current_role_id IS NULL OR u.current_role_id = 6)
     LIMIT 1"
);

if (!$targetQuery) {
    hq_flash('danger', 'The selected account could not be loaded.');
    hq_redirect($redirect);
}

$targetQuery->bind_param('i', $userId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();

if (!$target) {
    hq_flash('danger', 'The selected account could not be found.');
    hq_redirect($redirect);
}

$newStatus = $actionType === 'remove' ? 'removed' : 'suspended';
$notification = hq_send_notification(
    (string) $target['email'],
    'Map & Pump account ' . ucfirst($actionType),
    sprintf(
        "Your Map & Pump account has been %s.\n\nReason:\n%s",
        $actionType === 'remove' ? 'removed' : 'suspended',
        $reason
    )
);

try {
    $conn->begin_transaction();

    // Direct update by user_id; role guard already applied above.
    $statusUpdate = $conn->prepare(
        'UPDATE users
         SET status = ?, updated_at = NOW()
         WHERE user_id = ?'
    );
    if (!$statusUpdate) {
        throw new RuntimeException('Could not prepare the status update: ' . $conn->error);
    }
    $statusUpdate->bind_param('si', $newStatus, $userId);
    if (!$statusUpdate->execute()) {
        throw new RuntimeException('Could not update the account status: ' . $statusUpdate->error);
    }
    if ($statusUpdate->affected_rows < 0) {
        throw new RuntimeException('Status update reported a failure.');
    }
    $statusUpdate->close();

    if ($historyReady) {
        $actionInsert = $conn->prepare(
            'INSERT INTO user_account_actions (user_id, action_type, reason, notification_status, notification_note)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$actionInsert) {
            throw new RuntimeException('Could not prepare the action history insert: ' . $conn->error);
        }
        $notificationStatus = (string) ($notification['status'] ?? 'not_configured');
        $notificationNote = (string) ($notification['note'] ?? 'Email delivery is not configured.');
        $actionInsert->bind_param('issss', $userId, $actionType, $reason, $notificationStatus, $notificationNote);
        if (!$actionInsert->execute()) {
            throw new RuntimeException('Could not record the action history: ' . $actionInsert->error);
        }
        $actionInsert->close();
    }

    $conn->commit();

    // Logging is best-effort and must not undo a successful status change.
    try {
        admin_record_log(
            (int) $actingUser['user_id'],
            'Marked user #' . $userId . ' as ' . $newStatus . '.'
        );
    } catch (Throwable $logError) {
        error_log('[Customer registry] Action log skipped: ' . $logError->getMessage());
    }

    $message = 'The account was marked as ' . $newStatus . '.';
    if (!$historyReady) {
        $message .= ' Action history table was unavailable, so only the status was updated.';
    }
    hq_flash(
        'success',
        $message . ' Email delivery status: ' . ($notification['status'] ?? 'unknown') . '.'
    );
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Customer registry] Account action failed: ' . $exception->getMessage());
    hq_flash('danger', 'The account action could not be completed. The account status was not changed.');
}

hq_redirect($redirect);
