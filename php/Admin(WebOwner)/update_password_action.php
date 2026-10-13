<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect('edit_profile.php');
}

$user = admin_require_admin();

$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if (!password_verify($currentPassword, (string) ($user['hashed_password'] ?? ''))) {
    admin_flash('danger', 'The current password is incorrect.');
    admin_redirect('edit_profile.php');
}
if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
    admin_flash('danger', 'The new password must contain between 8 and 72 characters.');
    admin_redirect('edit_profile.php');
}
if (!preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/\d/', $newPassword) || !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
    admin_flash('danger', 'The new password must contain upper- and lowercase letters, a number, and a symbol.');
    admin_redirect('edit_profile.php');
}
if (!hash_equals($newPassword, $confirmPassword)) {
    admin_flash('danger', 'The new password and confirmation do not match.');
    admin_redirect('edit_profile.php');
}

global $conn;
global $pdo;
$hash = password_hash($newPassword, PASSWORD_DEFAULT);
if (!hq_db_available() && $pdo instanceof PDO) {
    $statement = $pdo->prepare('UPDATE users SET hashed_password = ?, updated_at = NOW() WHERE user_id = ?');
    if (!$statement->execute([$hash, (int) $user['user_id']])) {
        admin_flash('danger', 'The password could not be updated.');
        admin_redirect('edit_profile.php');
    }

    admin_record_log((int) $user['user_id'], 'Updated admin password.');
    $notice = mail_send_account_notice((string) ($user['email'] ?? ''), [
        'kind' => 'security',
        'action' => 'password_changed',
    ]);
    admin_flash('success', 'Password updated successfully.' . (!empty($notice['sent'])
        ? ' A security notice was sent to the account email.'
        : ' The password was saved, but a security email could not be delivered.'));
    admin_redirect('edit_profile.php');
}

$statement = $conn->prepare('UPDATE users SET hashed_password = ?, updated_at = NOW() WHERE user_id = ?');
if (!$statement) {
    admin_flash('danger', 'The password update could not be prepared.');
    admin_redirect('edit_profile.php');
}
$userId = (int) $user['user_id'];
$statement->bind_param('si', $hash, $userId);
if (!$statement->execute()) {
    $error = $statement->error;
    $statement->close();
    error_log('[Admin profile] Password update failed: ' . $error);
    admin_flash('danger', 'The password could not be updated.');
    admin_redirect('edit_profile.php');
}
$statement->close();

admin_record_log($userId, 'Updated admin password.');
$notice = mail_send_account_notice((string) ($user['email'] ?? ''), [
    'kind' => 'security',
    'action' => 'password_changed',
]);
admin_flash('success', 'Password updated successfully.' . (!empty($notice['sent'])
    ? ' A security notice was sent to the account email.'
    : ' The password was saved, but a security email could not be delivered.'));
admin_redirect('edit_profile.php');
