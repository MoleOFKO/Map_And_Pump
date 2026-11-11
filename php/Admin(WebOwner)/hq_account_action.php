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

if (!$headquarterId || !in_array($actionType, ['activate', 'suspend', 'remove', 'restore'], true) || $reason === '') {
    hq_flash('danger', 'Choose activate, suspend, remove, or restore and provide a reason before submitting.');
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
    hq_flash('danger', 'The company role could not be identified. The account status was not changed.');
    hq_redirect('view_hq_and_user_account.php');
}

// Match HQ accounts on any role slot (base / business / current). Older rows
// sometimes only populate current_role_id after role switching.
$targetQuery = $conn->prepare(
          "SELECT h.company_name, h.status AS hq_status, owner.user_id, owner.email
     FROM headquarter h
     LEFT JOIN (
         SELECT bas.headquarter_id, MIN(u.user_id) AS owner_user_id
         FROM business_account_scope bas
         INNER JOIN users u ON u.user_id = bas.user_id
         WHERE u.role_id = ? OR u.business_role_id = ? OR u.current_role_id = ?
         GROUP BY bas.headquarter_id
     ) owner_link ON owner_link.headquarter_id = h.headquarter_id
     LEFT JOIN users owner ON owner.user_id = COALESCE(h.headquarters_owner_id, owner_link.owner_user_id)
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
    hq_flash('danger', 'That company could not be found.');
    hq_redirect('view_hq_and_user_account.php');
}
if ($actionType === 'activate' && strtolower(trim((string) $target['hq_status'])) !== 'pending') {
    hq_flash('danger', 'Only a pending company can be activated.');
    hq_redirect('view_hq_and_user_account.php');
}

$statusMap = [
    'activate' => 'approved',
    'remove' => 'removed',
    'suspend' => 'suspended',
    'restore' => 'approved',
];
$hqStatusMap = [
    'activate' => 'active',
    'remove' => 'removed',
    'suspend' => 'suspended',
    'restore' => 'active',
];
$newStatus = $statusMap[$actionType] ?? 'suspended';
$actionVerb = match ($actionType) {
    'activate' => 'activated',
    'remove' => 'removed',
    'restore' => 'restored (approved)',
    default => 'suspended',
};
try {
    $conn->begin_transaction();

    // HQ ownership is stored in business_account_scope / headquarters_owner_id.
    $statusUpdate = $conn->prepare(
        'UPDATE users AS u
         SET status = ?, updated_at = NOW()
         WHERE (
             EXISTS (
                 SELECT 1 FROM business_account_scope bas
                 WHERE bas.user_id = u.user_id AND bas.headquarter_id = ?
             )
             OR EXISTS (
                 SELECT 1 FROM headquarter h
                 WHERE h.headquarters_owner_id = u.user_id AND h.headquarter_id = ?
             )
         )
           AND (u.role_id = ? OR u.business_role_id = ? OR u.current_role_id = ?)'
    );
    if (!$statusUpdate) {
        throw new RuntimeException('Could not prepare the company status update: ' . $conn->error);
    }

    $statusUpdate->bind_param('siiiii', $newStatus, $headquarterId, $headquarterId, $hqRoleId, $hqRoleId, $hqRoleId);
    if (!$statusUpdate->execute()) {
        throw new RuntimeException('Could not update the company account status: ' . $statusUpdate->error);
    }
    $affected = $statusUpdate->affected_rows;
    $statusUpdate->close();

    // No matching HQ-role users means the UI status badge cannot change.
    if ($affected <= 0 && empty($target['user_id'])) {
        throw new RuntimeException(
            'No company-role account is linked to this company, so its status could not be changed.'
        );
    }

    $hqStatusUpdate = $conn->prepare('UPDATE headquarter SET status = ? WHERE headquarter_id = ?');
    if (!$hqStatusUpdate) {
        throw new RuntimeException('Could not prepare the company status update: ' . $conn->error);
    }
    $newHqStatus = $hqStatusMap[$actionType] ?? 'suspended';
    $hqStatusUpdate->bind_param('si', $newHqStatus, $headquarterId);
    if (!$hqStatusUpdate->execute()) {
        throw new RuntimeException('Could not update the company status: ' . $hqStatusUpdate->error);
    }
    $hqStatusUpdate->close();

    $scopeUpdate = $conn->prepare(
        'UPDATE business_account_scope SET status = ? WHERE headquarter_id = ? AND business_role_id = ?'
    );
    if (!$scopeUpdate) {
        throw new RuntimeException('Could not prepare the company business-scope update: ' . $conn->error);
    }
    $scopeUpdate->bind_param('sii', $newStatus, $headquarterId, $hqRoleId);
    if (!$scopeUpdate->execute()) {
        throw new RuntimeException('Could not update the company business scope: ' . $scopeUpdate->error);
    }
    $scopeUpdate->close();

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
            throw new RuntimeException('Could not prepare the company action history insert: ' . $conn->error);
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
            throw new RuntimeException('Could not record the company action history: ' . $actionInsert->error);
        }
        $actionId = (int) $conn->insert_id;
        $actionInsert->close();
    }

    if (!$conn->commit()) {
        throw new RuntimeException('The company action transaction could not be committed.');
    }

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
                'note' => 'No company email address is available; the reason was recorded in the company action history.',
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
        ucfirst($actionType) . ' Company #' . $headquarterId . ' (' . $target['company_name'] . ').'
    );

    $message = match ($actionType) {
        'activate' => 'The company account was activated.',
        'remove' => 'The company account was marked as removed.',
        'restore' => 'The company account was restored to approved status.',
        default => 'The company account was suspended.',
    };
    if ($affected <= 0) {
        $message .= ' (No linked company-role account rows matched; history may still be recorded.)';
    }
    if (!$historyReady) {
        $message .= ' Action history table was unavailable, so only the status was updated.';
    }
    hq_flash('success', $message . ' Email delivery status: ' . ($notification['status'] ?? 'unknown') . '.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[HQ registry] Account action failed: ' . $exception->getMessage());
    hq_flash('danger', 'The company action could not be completed: ' . $exception->getMessage());
}

hq_redirect('view_hq_and_user_account.php');
