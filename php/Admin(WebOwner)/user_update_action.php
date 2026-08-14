<?php
require_once __DIR__ . '/user_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('manage_users.php');
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$userName = trim((string) ($_POST['user_name'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$redirect = trim((string) ($_POST['return_to'] ?? 'manage_users.php'));

if (!$userId || $userName === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($userName) > 225 || mb_strlen($email) > 255) {
    hq_flash('danger', 'Please provide valid Customer user details.');
    hq_redirect($redirect);
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect($redirect);
}

global $conn;
$targetQuery = $conn->prepare("SELECT u.user_id FROM users u INNER JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ? AND r.role_id = 6 AND LOWER(TRIM(r.role_name)) = 'customer' LIMIT 1");
if (!$targetQuery) {
    hq_flash('danger', 'The selected Customer account could not be loaded.');
    hq_redirect($redirect);
}
$targetQuery->bind_param('i', $userId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();
if (!$target) {
    hq_flash('danger', 'That user is not a role 6 Customer account.');
    hq_redirect($redirect);
}

$duplicateQuery = $conn->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ? LIMIT 1');
$duplicateQuery->bind_param('si', $email, $userId);
$duplicateQuery->execute();
$duplicate = $duplicateQuery->get_result()?->fetch_assoc();
$duplicateQuery->close();
if ($duplicate) {
    hq_flash('danger', 'That email address is already registered to another account.');
    hq_redirect($redirect);
}

try {
    $conn->begin_transaction();
    $update = $conn->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ? AND role_id = 6');
    $update->bind_param('ssi', $userName, $email, $userId);
    if (!$update->execute()) {
        throw new RuntimeException('Could not update the Customer account.');
    }
    $update->close();
    $reviewer = admin_current_user(false);
    admin_record_log((int) ($reviewer['user_id'] ?? 0), 'Updated Customer user #' . $userId . ' (' . $userName . ').');
    $conn->commit();
    hq_flash('success', 'The Customer account was updated in the database.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Admin Customer] Update failed: ' . $exception->getMessage());
    hq_flash('danger', 'The Customer account could not be updated. No partial changes were saved.');
}

hq_redirect($redirect);
