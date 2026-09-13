<?php
session_start();

require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['profile_avatar']['name'])) {
    $_SESSION['flash_message'] = 'No image was selected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

$userId = (int) $moderatorUser['user_id'];
$targetDir = __DIR__ . '/uploads/';
$file = $_FILES['profile_avatar'];
$maxBytes = 2 * 1024 * 1024;
if ((int) ($file['size'] ?? 0) > $maxBytes) {
    $_SESSION['flash_message'] = 'Profile images must be 2 MB or smaller.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    $_SESSION['flash_message'] = 'The image upload failed.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

$imageInfo = @getimagesize((string) $file['tmp_name']);
$mime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
$extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($extensions[$mime])) {
    $_SESSION['flash_message'] = 'Only valid JPG, PNG, and WebP profile images are supported.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
    $_SESSION['flash_message'] = 'The profile image directory could not be created.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

$filename = 'moderator_' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
$destination = $targetDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    $_SESSION['flash_message'] = 'Unable to save the uploaded image.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

$relativePath = '/php/Moderators(UnderAdmin)/uploads/' . $filename;

try {
    $stmt = $pdo->prepare('UPDATE users SET oauth_avatar_url = ?, updated_at = NOW() WHERE user_id = ?');
    $stmt->execute([$relativePath, $userId]);

    $_SESSION['flash_message'] = 'Profile picture updated successfully.';
    $_SESSION['flash_type'] = 'success';
    header('Location: edit_profile(M).php');
    exit;
} catch (Throwable $e) {
    error_log('Moderator avatar update failed: ' . $e->getMessage());
    $_SESSION['flash_message'] = 'Unable to update the profile picture right now.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}
