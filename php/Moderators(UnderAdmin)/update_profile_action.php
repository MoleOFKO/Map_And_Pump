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
$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));

if ($fullName === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['flash_message'] = 'Please provide a valid name and email.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

try {
    $checkStmt = $pdo->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id != ? LIMIT 1');
    $checkStmt->execute([$email, $userId]);
    if ($checkStmt->fetch()) {
        $_SESSION['flash_message'] = 'That email is already in use by another account.';
        $_SESSION['flash_type'] = 'danger';
        header('Location: edit_profile(M).php');
        exit;
    }

    $stmt = $pdo->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?');
    $stmt->execute([$fullName, $email, $userId]);

    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_email'] = $email;
    $_SESSION['flash_message'] = 'Profile updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: edit_profile(M).php');
    exit;
} catch (Throwable $e) {
    error_log('Moderator profile update failed: ' . $e->getMessage());
    $_SESSION['flash_message'] = 'Unable to update your profile right now.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}
