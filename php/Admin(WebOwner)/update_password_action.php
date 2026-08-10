<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect('edit_profile.php');
}

$user = admin_current_user(true);
if (!$user) {
    admin_flash('danger', 'No admin or moderator account is available for this password update.');
    admin_redirect('edit_profile.php');
}

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
$hash = password_hash($newPassword, PASSWORD_DEFAULT);
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
admin_flash('success', 'Password updated successfully.');
admin_redirect('edit_profile.php');
