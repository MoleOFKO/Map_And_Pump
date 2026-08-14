<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('manage_hq(M).php');
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$headquarterId = filter_var($_POST['headquarter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userName = trim((string) ($_POST['user_name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));

if (!$userId || !$headquarterId || $userName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    hq_flash('danger', 'Please provide a valid user name and email address.');
    hq_redirect('manage_hq(M).php');
}

if (mb_strlen($userName) > 225 || mb_strlen($email) > 255) {
    hq_flash('danger', 'The user name or email address is longer than the database allows.');
    hq_redirect('manage_hq(M).php');
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('manage_hq(M).php');
}

global $conn;
$targetQuery = $conn->prepare(
    'SELECT u.user_id, u.headquarter_id, h.company_name,
            (SELECT MIN(u2.user_id)
             FROM users u2
             INNER JOIN roles r2 ON r2.role_id = u2.role_id
             WHERE u2.headquarter_id = u.headquarter_id
               AND r2.role_id = 3 AND LOWER(TRIM(r2.role_name)) = \'hq\') AS owner_user_id
     FROM users u
     INNER JOIN roles r ON r.role_id = u.role_id
     INNER JOIN headquarter h ON h.headquarter_id = u.headquarter_id
     WHERE u.user_id = ? AND u.headquarter_id = ?
       AND r.role_id = 3 AND LOWER(TRIM(r.role_name)) = \'hq\'
     LIMIT 1'
);

if (!$targetQuery) {
    hq_flash('danger', 'The selected HQ user could not be loaded.');
    hq_redirect('manage_hq(M).php');
}

$targetQuery->bind_param('ii', $userId, $headquarterId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();

if (!$target) {
    hq_flash('danger', 'That user does not belong to the selected HQ.');
    hq_redirect('manage_hq(M).php');
}

if ((int) $target['owner_user_id'] === (int) $userId && mb_strlen($email) > 225) {
    hq_flash('danger', 'The HQ owner email must be 225 characters or fewer because it is also stored on the HQ record.');
    hq_redirect('manage_hq(M).php');
}

$duplicateQuery = $conn->prepare(
    'SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ?
     UNION ALL
     SELECT headquarter_id FROM headquarter WHERE LOWER(headquarter_email) = LOWER(?) AND headquarter_id <> ?
     LIMIT 1'
);

if (!$duplicateQuery) {
    hq_flash('danger', 'The user email could not be checked.');
    hq_redirect('manage_hq(M).php');
}

$duplicateQuery->bind_param('sisi', $email, $userId, $email, $headquarterId);
$duplicateQuery->execute();
$duplicate = $duplicateQuery->get_result()?->fetch_assoc();
$duplicateQuery->close();

if ($duplicate) {
    hq_flash('danger', 'That email address is already registered to another account or HQ.');
    hq_redirect('manage_hq(M).php');
}

try {
    $conn->begin_transaction();

    $userUpdate = $conn->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ? AND headquarter_id = ?');
    if (!$userUpdate) {
        throw new RuntimeException('Could not prepare the user account update.');
    }
    $userUpdate->bind_param('ssii', $userName, $email, $userId, $headquarterId);
    if (!$userUpdate->execute()) {
        throw new RuntimeException('Could not update the user account.');
    }
    $userUpdate->close();

    if ((int) $target['owner_user_id'] === (int) $userId) {
        $hqEmailUpdate = $conn->prepare('UPDATE headquarter SET headquarter_email = ? WHERE headquarter_id = ?');
        if (!$hqEmailUpdate) {
            throw new RuntimeException('Could not prepare the HQ email synchronization.');
        }
        $hqEmailUpdate->bind_param('si', $email, $headquarterId);
        if (!$hqEmailUpdate->execute()) {
            throw new RuntimeException('Could not synchronize the HQ email.');
        }
        $hqEmailUpdate->close();

        $requestUpdate = $conn->prepare(
            'UPDATE system_requests
             SET sender_name = ?, sender_email = ?, updated_at = NOW()
             WHERE headquarter_id = ? AND request_type IN (\'hq_registration\', \'headquarter_registration\') AND status = \'pending\''
        );
        if ($requestUpdate) {
            $requestUpdate->bind_param('ssi', $userName, $email, $headquarterId);
            $requestUpdate->execute();
            $requestUpdate->close();
        }
    }

    admin_record_log((int) $moderatorUser['user_id'], 'Updated user #' . $userId . ' in HQ #' . $headquarterId . ' (' . $target['company_name'] . ').');
    $conn->commit();
    hq_flash('success', 'The user account was updated and synchronized with the HQ record.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Moderator HQ user] Update failed: ' . $exception->getMessage());
    hq_flash('danger', 'The user account could not be updated. No partial changes were saved.');
}

hq_redirect('manage_hq(M).php');
