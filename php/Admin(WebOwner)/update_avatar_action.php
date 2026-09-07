<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect('edit_profile.php');
}

$user = admin_current_user(true);
$upload = $_FILES['profile_avatar'] ?? null;
if (!$user || !is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    admin_flash('danger', 'Please choose a valid image before uploading.');
    admin_redirect('edit_profile.php');
}

if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
    admin_flash('danger', 'Profile images must be 2 MB or smaller.');
    admin_redirect('edit_profile.php');
}

$temporaryPath = (string) ($upload['tmp_name'] ?? '');
$imageInfo = @getimagesize($temporaryPath);
$mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];
if (!isset($extensions[$mime])) {
    admin_flash('danger', 'Only JPG, PNG, GIF, and WebP profile images are supported.');
    admin_redirect('edit_profile.php');
}

$directory = __DIR__ . '/uploads';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    admin_flash('danger', 'The profile image directory could not be created.');
    admin_redirect('edit_profile.php');
}

$fileName = 'admin-' . (int) $user['user_id'] . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
$destination = $directory . DIRECTORY_SEPARATOR . $fileName;
if (!move_uploaded_file($temporaryPath, $destination)) {
    admin_flash('danger', 'The profile image could not be saved.');
    admin_redirect('edit_profile.php');
}

global $conn;
global $pdo;
$avatarUrl = '/php/Admin(WebOwner)/uploads/' . $fileName;
if (!hq_db_available() && $pdo instanceof PDO) {
    $statement = $pdo->prepare('UPDATE users SET oauth_avatar_url = ?, updated_at = NOW() WHERE user_id = ?');
    if (!$statement->execute([$avatarUrl, (int) $user['user_id']])) {
        @unlink($destination);
        admin_flash('danger', 'The profile image could not be updated.');
        admin_redirect('edit_profile.php');
    }

    $oldAvatar = (string) ($user['oauth_avatar_url'] ?? '');
    $oldPrefixes = [
        '/php/Admin(WebOwner)/uploads/avatars/' => __DIR__ . '/uploads/avatars',
        '/php/Admin(WebOwner)/uploads/' => $directory,
    ];
    foreach ($oldPrefixes as $prefix => $oldDirectory) {
        if (!str_starts_with($oldAvatar, $prefix)) {
            continue;
        }

        $oldFileName = basename(substr($oldAvatar, strlen($prefix)));
        $oldPath = $oldDirectory . DIRECTORY_SEPARATOR . $oldFileName;
        if ($oldFileName !== '' && is_file($oldPath)) {
            @unlink($oldPath);
        }
        break;
    }

    admin_record_log((int) $user['user_id'], 'Updated admin profile avatar.');
    admin_flash('success', 'Profile image updated successfully.');
    admin_redirect('edit_profile.php');
}

$statement = $conn->prepare('UPDATE users SET oauth_avatar_url = ?, updated_at = NOW() WHERE user_id = ?');
if (!$statement) {
    @unlink($destination);
    admin_flash('danger', 'The profile image update could not be prepared.');
    admin_redirect('edit_profile.php');
}
$userId = (int) $user['user_id'];
$statement->bind_param('si', $avatarUrl, $userId);
if (!$statement->execute()) {
    @unlink($destination);
    $statement->close();
    admin_flash('danger', 'The profile image could not be updated.');
    admin_redirect('edit_profile.php');
}
$statement->close();

$oldAvatar = (string) ($user['oauth_avatar_url'] ?? '');
$oldPrefixes = [
    '/php/Admin(WebOwner)/uploads/avatars/' => __DIR__ . '/uploads/avatars',
    '/php/Admin(WebOwner)/uploads/' => $directory,
];
foreach ($oldPrefixes as $prefix => $oldDirectory) {
    if (!str_starts_with($oldAvatar, $prefix)) {
        continue;
    }

    $oldFileName = basename(substr($oldAvatar, strlen($prefix)));
    $oldPath = $oldDirectory . DIRECTORY_SEPARATOR . $oldFileName;
    if ($oldFileName !== '' && is_file($oldPath)) {
        @unlink($oldPath);
    }
    break;
}

admin_record_log($userId, 'Updated admin profile avatar.');
admin_flash('success', 'Profile image updated successfully.');
admin_redirect('edit_profile.php');
