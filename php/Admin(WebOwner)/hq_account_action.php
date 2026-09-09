<?php
require_once __DIR__ . '/hq_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('view_hq_and_user_account.php');
}

$headquarterId = filter_var($_POST['headquarter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$actionType = strtolower(trim((string) ($_POST['action_type'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));

if (!$headquarterId || !in_array($actionType, ['suspend', 'remove'], true) || $reason === '') {
    hq_flash('danger', 'Choose suspend or remove and provide a reason before submitting.');
    hq_redirect('view_hq_and_user_account.php');
}

if (mb_strlen($reason) > 2000) {
    hq_flash('danger', 'The reason must be 2,000 characters or fewer.');
    hq_redirect('view_hq_and_user_account.php');
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('view_hq_and_user_account.php');
}

// Action history is preferred but must not block the status change if DDL is restricted.
$historyReady = hq_action_table_ready();

global $conn;
$targetQuery = $conn->prepare(
    "SELECT h.company_name, u.user_id, u.email
     FROM headquarter h
     LEFT JOIN users u ON u.user_id = (
         SELECT MIN(u2.user_id)
         FROM users u2
         WHERE u2.headquarter_id = h.headquarter_id
           AND (u2.role_id = 3 OR u2.business_role_id = 3)
     )
     WHERE h.headquarter_id = ?
     LIMIT 1"
);

if (!$targetQuery) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('view_hq_and_user_account.php');
}

$targetQuery->bind_param('i', $headquarterId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();

if (!$target) {
    hq_flash('danger', 'That HQ account could not be found.');
    hq_redirect('view_hq_and_user_account.php');
}

$newStatus = $actionType === 'remove' ? 'removed' : 'suspended';
$notificationSubject = sprintf('Map & Pump HQ account %s', ucfirst($actionType));
$notificationBody = sprintf(
    "Your Map & Pump HQ account (%s) has been %s.\n\nReason:\n%s",
    $target['company_name'],
    $actionType === 'remove' ? 'removed' : 'suspended',
    $reason
);
$notification = !empty($target['email'])
    ? hq_send_notification((string) $target['email'], $notificationSubject, $notificationBody)
    : [
        'sent' => false,
        'status' => 'not_configured',
        'note' => 'No HQ email address is available; the reason was recorded in the HQ action history.',
    ];

try {
    $conn->begin_transaction();

    // Update all HQ-role accounts under this headquarter (role_id or business_role_id).
    $statusUpdate = $conn->prepare(
        'UPDATE users
         SET status = ?, updated_at = NOW()
         WHERE headquarter_id = ?
           AND (role_id = 3 OR business_role_id = 3)'
    );
    if (!$statusUpdate) {
        throw new RuntimeException('Could not prepare the HQ status update: ' . $conn->error);
    }

    $statusUpdate->bind_param('si', $newStatus, $headquarterId);
    if (!$statusUpdate->execute()) {
        throw new RuntimeException('Could not update the HQ account status: ' . $statusUpdate->error);
    }
    $affected = $statusUpdate->affected_rows;
    $statusUpdate->close();

    if ($historyReady) {
        $targetUserId = isset($target['user_id']) && $target['user_id'] !== null
            ? (int) $target['user_id']
            : null;
        $notificationStatus = (string) ($notification['status'] ?? 'not_configured');
        $notificationNote = (string) ($notification['note'] ?? '');

        if ($targetUserId !== null && $targetUserId > 0) {
            $actionInsert = $conn->prepare(
                'INSERT INTO hq_account_actions
                    (headquarter_id, user_id, action_type, reason, notification_status, notification_note)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$actionInsert) {
                throw new RuntimeException('Could not prepare the HQ action history insert: ' . $conn->error);
            }
            $actionInsert->bind_param(
                'iissss',
                $headquarterId,
                $targetUserId,
                $actionType,
                $reason,
                $notificationStatus,
                $notificationNote
            );
        } else {
            $actionInsert = $conn->prepare(
                'INSERT INTO hq_account_actions
                    (headquarter_id, user_id, action_type, reason, notification_status, notification_note)
                 VALUES (?, NULL, ?, ?, ?, ?)'
            );
            if (!$actionInsert) {
                throw new RuntimeException('Could not prepare the HQ action history insert: ' . $conn->error);
            }
            $actionInsert->bind_param(
                'issss',
                $headquarterId,
                $actionType,
                $reason,
                $notificationStatus,
                $notificationNote
            );
        }

        if (!$actionInsert->execute()) {
            throw new RuntimeException('Could not record the HQ action history: ' . $actionInsert->error);
        }
        $actionInsert->close();
    }

    $conn->commit();

    $message = $actionType === 'remove'
        ? 'The HQ account was marked as removed.'
        : 'The HQ account was suspended.';
    if ($affected <= 0) {
        $message .= ' (No linked HQ-role user rows matched; history may still be recorded.)';
    }
    if (!$historyReady) {
        $message .= ' Action history table was unavailable, so only the status was updated.';
    }
    hq_flash('success', $message . ' Email delivery status: ' . ($notification['status'] ?? 'unknown') . '.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[HQ registry] Account action failed: ' . $exception->getMessage());
    hq_flash('danger', 'The HQ action could not be completed. The account status was not changed.');
}

hq_redirect('view_hq_and_user_account.php');
