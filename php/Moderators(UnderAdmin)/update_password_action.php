<?php
session_start();

require_once __DIR__ . '/../conn.php';

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../Login/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    $_SESSION['flash_message'] = 'Please fill in all password fields.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

if (strlen($newPassword) < 8) {
    $_SESSION['flash_message'] = 'New password must be at least 8 characters long.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['flash_message'] = 'New password and confirmation do not match.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT hashed_password FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !isset($user['hashed_password']) || !password_verify($currentPassword, $user['hashed_password'])) {
        $_SESSION['flash_message'] = 'Current password is incorrect.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: edit_profile(M).php');
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $updateStmt = $pdo->prepare('UPDATE users SET hashed_password = ?, updated_at = NOW() WHERE user_id = ?');
    $updateStmt->execute([$newHash, $userId]);

    $_SESSION['flash_message'] = 'Password updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: edit_profile(M).php');
    exit;
} catch (Throwable $e) {
    error_log('Moderator password update failed: ' . $e->getMessage());
    $_SESSION['flash_message'] = 'Unable to update your password right now.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}
