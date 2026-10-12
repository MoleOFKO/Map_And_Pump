<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$actingUser = admin_require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('view_hq_and_user_account.php');
}

$headquarterId = filter_var($_POST['headquarter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$actionType = strtolower(trim((string) ($_POST['action_type'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));

if (!$headquarterId || !in_array($actionType, ['suspend', 'remove', 'restore'], true) || $reason === '') {
    hq_flash('danger', 'Choose suspend, remove, or restore and provide a reason before submitting.');
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
$hqRoleId = hq_hq_role_id();
if ($hqRoleId <= 0) {
    hq_flash('danger', 'The HQ role could not be identified. The account status was not changed.');
    hq_redirect('view_hq_and_user_account.php');
}

// Match HQ accounts on any role slot (base / business / current). Older rows
// sometimes only populate current_role_id after role switching.
$targetQuery = $conn->prepare(
    "SELECT h.company_name, owner.user_id, owner.email
     FROM headquarter h
     LEFT JOIN (
         SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id
         FROM users u
         WHERE u.role_id = ? OR u.business_role_id = ? OR u.current_role_id = ?
         GROUP BY u.headquarter_id
     ) owner_link ON owner_link.headquarter_id = h.headquarter_id
     LEFT JOIN users owner ON owner.user_id = owner_link.owner_user_id
     WHERE h.headquarter_id = ?
     LIMIT 1"
);

if (!$targetQuery) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('view_hq_and_user_account.php');
}

$targetQuery->bind_param('iiii', $hqRoleId, $hqRoleId, $hqRoleId, $headquarterId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();

if (!$target) {
    hq_flash('danger', 'That HQ account could not be found.');
    hq_redirect('view_hq_and_user_account.php');
}

$statusMap = [
    'remove' => 'removed',
    'suspend' => 'suspended',
    'restore' => 'approved',
];
$newStatus = $statusMap[$actionType] ?? 'suspended';
$actionVerb = match ($actionType) {
    'remove' => 'removed',
    'restore' => 'restored (approved)',
    default => 'suspended',
};
try {
    $conn->begin_transaction();

    // Update HQ-role accounts under this HQ across all role slots used by the app.
    $statusUpdate = $conn->prepare(
        'UPDATE users
         SET status = ?, updated_at = NOW()
         WHERE headquarter_id = ?
           AND (role_id = ? OR business_role_id = ? OR current_role_id = ?)'
    );
    if (!$statusUpdate) {
        throw new RuntimeException('Could not prepare the HQ status update: ' . $conn->error);
    }

    $statusUpdate->bind_param('siiii', $newStatus, $headquarterId, $hqRoleId, $hqRoleId, $hqRoleId);
    if (!$statusUpdate->execute()) {
        throw new RuntimeException('Could not update the HQ account status: ' . $statusUpdate->error);
    }
    $affected = $statusUpdate->affected_rows;
    $statusUpdate->close();

    // No matching HQ-role users means the UI status badge cannot change.
    if ($affected <= 0 && empty($target['user_id'])) {
        throw new RuntimeException(
            'No HQ-role user is linked to this headquarters, so the account status could not be changed.'
        );
    }

    $actionId = 0;
    if ($historyReady) {
        // Live schema (db.sql): hq_account_actions has no user_id column.
        $notificationStatus = 'pending';
        $notificationNote = 'Account action committed; email delivery is pending.';
        $actionInsert = $conn->prepare(
            'INSERT INTO hq_account_actions
                (headquarter_id, action_type, reason, notification_status, notification_note)
             VALUES (?, ?, ?, ?, ?)'
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
        if (!$actionInsert->execute()) {
            throw new RuntimeException('Could not record the HQ action history: ' . $actionInsert->error);
        }
        $actionId = (int) $conn->insert_id;
        $actionInsert->close();
    }

    $conn->commit();

    try {
        $notification = !empty($target['email'])
            ? hq_send_notification((string) $target['email'], '', '', [
                'kind' => 'hq',
                'action' => $actionType,
                'account_label' => (string) ($target['company_name'] ?? ''),
                'reason' => $reason,
            ])
            : [
                'sent' => false,
                'status' => 'skipped',
                'note' => 'No HQ email address is available; the reason was recorded in the HQ action history.',
            ];
    } catch (Throwable $mailError) {
        error_log('[HQ registry] Account notice failed: ' . $mailError->getMessage());
        $notification = [
            'status' => 'failed',
            'note' => 'Email delivery failed. The account action was completed.',
        ];
    }

    if ($historyReady && $actionId > 0) {
        try {
            $historyUpdate = $conn->prepare(
                'UPDATE hq_account_actions SET notification_status = ?, notification_note = ? WHERE action_id = ?'
            );
            if ($historyUpdate) {
                $notificationStatus = (string) ($notification['status'] ?? 'unknown');
                $notificationNote = substr((string) ($notification['note'] ?? ''), 0, 255);
                $historyUpdate->bind_param('ssi', $notificationStatus, $notificationNote, $actionId);
                $historyUpdate->execute();
                $historyUpdate->close();
            }
        } catch (Throwable $historyError) {
            error_log('[HQ registry] Email status history update failed: ' . $historyError->getMessage());
        }
    }

    admin_record_log(
        (int) $actingUser['user_id'],
        ucfirst($actionType) . ' HQ #' . $headquarterId . ' (' . $target['company_name'] . ').'
    );

    $message = match ($actionType) {
        'remove' => 'The HQ account was marked as removed.',
        'restore' => 'The HQ account was restored to approved status.',
        default => 'The HQ account was suspended.',
    };
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
