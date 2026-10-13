<?php
session_start();

require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash_message'] = 'Invalid request.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

$userId = (int) $moderatorUser['user_id'];
$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email = trim((string) ($_POST['email'] ?? ''));
$oldEmail = trim((string) ($moderatorUser['email'] ?? ''));
$emailChanged = strcasecmp($oldEmail, $email) !== 0;
$profileChanged = trim((string) ($moderatorUser['user_name'] ?? '')) !== $fullName;

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
    $notice = null;
    if ($emailChanged || $profileChanged) {
        $notice = mail_send_account_notice($oldEmail, [
            'kind' => 'security',
            'action' => $emailChanged ? 'email_changed' : 'profile_changed',
        ]);
    }
    $_SESSION['flash_message'] = 'Profile updated successfully.' . ($notice === null ? '' : (!empty($notice['sent'])
        ? ' A notice was sent to the previous account email.'
        : ' The profile was saved, but an email notice could not be delivered to the previous address.'));
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
