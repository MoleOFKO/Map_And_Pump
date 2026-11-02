<?php
session_start();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../activity_log.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_message'] = 'Invalid password update request.';
    $_SESSION['flash_type'] = 'error';
    header('Location: staff_account.php');
    exit;
}

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    $_SESSION['login_error'] = 'Please sign in to continue.';
    header('Location: ../Login/login.php');
    exit;
}

if (strtolower((string)($_SESSION['role_name'] ?? '')) !== 'staff') {
    http_response_code(403);
    exit('This action is only available to staff accounts.');
}

$staffCsrfToken = (string)($_SESSION['staff_csrf_token'] ?? '');
if ($staffCsrfToken === '' || !hash_equals($staffCsrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid request token. Reload the page and try again.');
}

$userId = (int) $_SESSION['user_id'];
$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    $_SESSION['flash_message'] = 'Please fill in every password field.';
    $_SESSION['flash_type'] = 'error';
    header('Location: staff_account.php');
    exit;
}

if (strlen($newPassword) < 6) {
    $_SESSION['flash_message'] = 'Your new password must be at least 6 characters long.';
    $_SESSION['flash_type'] = 'error';
    header('Location: staff_account.php');
    exit;
}

if ($newPassword !== $confirmPassword) {
    $_SESSION['flash_message'] = 'The new password and confirmation do not match.';
    $_SESSION['flash_type'] = 'error';
    header('Location: staff_account.php');
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT hashed_password FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account || !isset($account['hashed_password'])) {
        $_SESSION['flash_message'] = 'Your account could not be found.';
        $_SESSION['flash_type'] = 'error';
        header('Location: staff_account.php');
        exit;
    }

    if (!password_verify($currentPassword, $account['hashed_password'])) {
        $_SESSION['flash_message'] = 'The current password is incorrect.';
        $_SESSION['flash_type'] = 'error';
        header('Location: staff_account.php');
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare('UPDATE users SET hashed_password = ?, updated_at = NOW() WHERE user_id = ?');
    $update->execute([$newHash, $userId]);
    map_pump_log_action($pdo, $userId, 'Updated staff account password', 5);

    $_SESSION['flash_message'] = 'Your password was updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: staff_account.php');
    exit;
} catch (Throwable $e) {
    error_log('Staff password update failed: ' . $e->getMessage());
    $_SESSION['flash_message'] = 'Unable to update your password right now.';
    $_SESSION['flash_type'] = 'error';
    header('Location: staff_account.php');
    exit;
}
