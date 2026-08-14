<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('manage_users(M).php');
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userName = trim((string) ($_POST['user_name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));

if (!$userId || $userName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    hq_flash('danger', 'Please provide a valid Customer user name and email address.');
    hq_redirect('manage_users(M).php');
}

if (mb_strlen($userName) > 225 || mb_strlen($email) > 255) {
    hq_flash('danger', 'The Customer user name or email address is longer than the database allows.');
    hq_redirect('manage_users(M).php');
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('manage_users(M).php');
}

global $conn;
$targetQuery = $conn->prepare(
    "SELECT u.user_id, u.user_name, u.email, r.role_id, r.role_name
     FROM users u
     INNER JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = ? AND r.role_id = 6 AND LOWER(TRIM(r.role_name)) = 'customer'
     LIMIT 1"
);
if (!$targetQuery) {
    hq_flash('danger', 'The selected Customer account could not be loaded.');
    hq_redirect('manage_users(M).php');
}
$targetQuery->bind_param('i', $userId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();

if (!$target) {
    hq_flash('danger', 'That user is not a role 6 Customer account.');
    hq_redirect('manage_users(M).php');
}

$duplicateQuery = $conn->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ? LIMIT 1');
if (!$duplicateQuery) {
    hq_flash('danger', 'The Customer email could not be checked.');
    hq_redirect('manage_users(M).php');
}
$duplicateQuery->bind_param('si', $email, $userId);
$duplicateQuery->execute();
$duplicate = $duplicateQuery->get_result()?->fetch_assoc();
$duplicateQuery->close();

if ($duplicate) {
    hq_flash('danger', 'That email address is already registered to another account.');
    hq_redirect('manage_users(M).php');
}

try {
    $conn->begin_transaction();
    $userUpdate = $conn->prepare("UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 6");
    if (!$userUpdate) {
        throw new RuntimeException('Could not prepare the Customer account update.');
    }
    $userUpdate->bind_param('ssi', $userName, $email, $userId);
    if (!$userUpdate->execute()) {
        throw new RuntimeException('Could not update the Customer account.');
    }
    $userUpdate->close();
    admin_record_log((int) $moderatorUser['user_id'], 'Updated Customer user #' . $userId . ' (' . $userName . ').');
    $conn->commit();
    hq_flash('success', 'The Customer account was updated in the database.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Moderator Customer] Update failed: ' . $exception->getMessage());
    hq_flash('danger', 'The Customer account could not be updated. No partial changes were saved.');
}

hq_redirect('manage_users(M).php');
