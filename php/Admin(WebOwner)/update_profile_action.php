<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect('edit_profile.php');
}

$user = admin_require_admin();

$userName = trim(preg_replace('/\s+/', ' ', (string) ($_POST['user_name'] ?? '')) ?? '');
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$userId = (int) $user['user_id'];
$oldEmail = trim((string) ($user['email'] ?? ''));
$emailChanged = strcasecmp($oldEmail, $email) !== 0;
$profileChanged = trim((string) ($user['user_name'] ?? '')) !== $userName;

if ($userName === '' || strlen($userName) > 225) {
    admin_flash('danger', 'Please provide a username/display name no longer than 225 characters.');
    admin_redirect('edit_profile.php');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
    admin_flash('danger', 'Please provide a valid email address.');
    admin_redirect('edit_profile.php');
}

global $conn;
global $pdo;
if (!hq_db_available() && $pdo instanceof PDO) {
    $duplicate = $pdo->prepare('SELECT user_id FROM users WHERE (user_name = ? OR email = ?) AND user_id <> ? LIMIT 1');
    $duplicate->execute([$userName, $email, $userId]);
    if ($duplicate->fetch(PDO::FETCH_ASSOC)) {
        admin_flash('danger', 'That username or email address is already in use.');
        admin_redirect('edit_profile.php');
    }

    $statement = $pdo->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?');
    if (!$statement->execute([$userName, $email, $userId])) {
        admin_flash('danger', 'The profile could not be updated.');
        admin_redirect('edit_profile.php');
    }

    $_SESSION['user_name'] = $userName;
    $_SESSION['user_email'] = $email;
    admin_record_log($userId, 'Updated admin profile details.');
    $notice = null;
    if ($emailChanged || $profileChanged) {
        $notice = mail_send_account_notice($oldEmail, [
            'kind' => 'security',
            'action' => $emailChanged ? 'email_changed' : 'profile_changed',
        ]);
    }
    $noticeSuffix = $notice === null ? '' : (!empty($notice['sent'])
        ? ' A notice was sent to the previous account email.'
        : ' Profile details were saved, but an email notice could not be delivered to the previous address.');
    admin_flash('success', 'Profile details were updated successfully.' . $noticeSuffix);
    admin_redirect('edit_profile.php');
}

$duplicate = $conn->prepare('SELECT user_id FROM users WHERE (user_name = ? OR email = ?) AND user_id <> ? LIMIT 1');
if (!$duplicate) {
    admin_flash('danger', 'The profile could not be checked against the database.');
    admin_redirect('edit_profile.php');
}
$duplicate->bind_param('ssi', $userName, $email, $userId);
$duplicate->execute();
$exists = (bool) ($duplicate->get_result()?->fetch_assoc());
$duplicate->close();

if ($exists) {
    admin_flash('danger', 'That username or email address is already in use.');
    admin_redirect('edit_profile.php');
}

$statement = $conn->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?');
if (!$statement) {
    admin_flash('danger', 'The profile update could not be prepared.');
    admin_redirect('edit_profile.php');
}
$statement->bind_param('ssi', $userName, $email, $userId);
if (!$statement->execute()) {
    $error = $statement->error;
    $statement->close();
    error_log('[Admin profile] Profile update failed: ' . $error);
    admin_flash('danger', 'The profile could not be updated.');
    admin_redirect('edit_profile.php');
}
$statement->close();

$_SESSION['user_name'] = $userName;
$_SESSION['user_email'] = $email;
admin_record_log($userId, 'Updated admin profile details.');
$notice = null;
if ($emailChanged || $profileChanged) {
    $notice = mail_send_account_notice($oldEmail, [
        'kind' => 'security',
        'action' => $emailChanged ? 'email_changed' : 'profile_changed',
    ]);
}
$noticeSuffix = $notice === null ? '' : (!empty($notice['sent'])
    ? ' A notice was sent to the previous account email.'
    : ' Profile details were saved, but an email notice could not be delivered to the previous address.');
admin_flash('success', 'Profile details were updated successfully.' . $noticeSuffix);
admin_redirect('edit_profile.php');
