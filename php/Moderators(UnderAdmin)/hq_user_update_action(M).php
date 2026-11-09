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
    hq_flash('danger', 'Please provide a valid account name and email address.');
    hq_redirect('manage_hq(M).php');
}

if (mb_strlen($userName) > 225 || mb_strlen($email) > 255) {
    hq_flash('danger', 'The account name or email address is longer than the database allows.');
    hq_redirect('manage_hq(M).php');
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('manage_hq(M).php');
}

global $conn;
$targetQuery = $conn->prepare(
        'SELECT u.user_id, u.user_name, u.email AS user_email, bas.headquarter_id, h.company_name,
            h.headquarters_owner_id AS owner_user_id
     FROM users u
         INNER JOIN business_account_scope bas ON bas.user_id = u.user_id
     INNER JOIN roles r ON r.role_id = u.role_id
         INNER JOIN headquarter h ON h.headquarter_id = bas.headquarter_id
         WHERE u.user_id = ? AND bas.headquarter_id = ?
       AND (u.role_id = 3 OR u.business_role_id = 3)
     LIMIT 1'
);
if (!$targetQuery) {
    hq_flash('danger', 'The selected company account could not be loaded.');
    hq_redirect('manage_hq(M).php');
}
$targetQuery->bind_param('ii', $userId, $headquarterId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();
if (!$target) {
    hq_flash('danger', 'That account is not linked to the selected company.');
    hq_redirect('manage_hq(M).php');
}

if ((int) $target['owner_user_id'] === (int) $userId && mb_strlen($email) > 225) {
    hq_flash('danger', 'The company owner email must be 225 characters or fewer because it is also stored on the company record.');
    hq_redirect('manage_hq(M).php');
}

$duplicateQuery = $conn->prepare(
    'SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ?
     UNION ALL
     SELECT headquarter_id FROM headquarter WHERE LOWER(headquarter_email) = LOWER(?) AND headquarter_id <> ?
     LIMIT 1'
);

if (!$duplicateQuery) {
    hq_flash('danger', 'The account email could not be checked.');
    hq_redirect('manage_hq(M).php');
}

$duplicateQuery->bind_param('sisi', $email, $userId, $email, $headquarterId);
$duplicateQuery->execute();
$duplicate = $duplicateQuery->get_result()?->fetch_assoc();
$duplicateQuery->close();

if ($duplicate) {
    hq_flash('danger', 'That email address is already registered to another account or company.');
    hq_redirect('manage_hq(M).php');
}

try {
    $conn->begin_transaction();

    $userUpdate = $conn->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ? AND EXISTS (SELECT 1 FROM business_account_scope bas WHERE bas.user_id = users.user_id AND bas.headquarter_id = ?)');
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
            throw new RuntimeException('Could not prepare the company email synchronization.');
        }
        $hqEmailUpdate->bind_param('si', $email, $headquarterId);
        if (!$hqEmailUpdate->execute()) {
            throw new RuntimeException('Could not synchronize the company email.');
        }
        $hqEmailUpdate->close();

    }

    admin_record_log((int) $moderatorUser['user_id'], 'Updated company account #' . $userId . ' in Company #' . $headquarterId . ' (' . $target['company_name'] . ').');
    $conn->commit();

    $oldEmail = (string) ($target['user_email'] ?? '');
    $emailChanged = strcasecmp($oldEmail, $email) !== 0;
    $profileChanged = trim((string) ($target['user_name'] ?? '')) !== $userName;
    $notice = null;
    if ($emailChanged || $profileChanged) {
        $notice = mail_send_account_notice($oldEmail, [
            'kind' => 'security',
            'action' => $emailChanged ? 'email_changed' : 'profile_changed',
        ]);
    }
    $noticeSuffix = $notice === null ? '' : (!empty($notice['sent'])
        ? ' A notice was sent to the previous account email.'
        : ' The profile was saved, but an email notice could not be delivered to the previous address.');
    hq_flash('success', 'The company account was updated and synchronized with the company record.' . $noticeSuffix);
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Moderator HQ user] Update failed: ' . $exception->getMessage());
    hq_flash('danger', 'The company account could not be updated. No partial changes were saved.');
}

hq_redirect('manage_hq(M).php');
