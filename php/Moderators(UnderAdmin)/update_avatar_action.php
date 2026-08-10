<?php
session_start();

require_once __DIR__ . '/../conn.php';

if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id'])) {
    header('Location: ../Login/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['profile_avatar']['name'])) {
    $_SESSION['flash_message'] = 'No image was selected.';
    $_SESSION['flash_type'] = 'danger';
    header('Location: edit_profile(M).php');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$targetDir = __DIR__ . '/uploads/';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$file = $_FILES['profile_avatar'];
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (!in_array($extension, $allowedExtensions, true)) {
    $_SESSION['flash_message'] = 'Only image files are allowed.';
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

$filename = 'moderator_' . $userId . '_' . time() . '.' . $extension;
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
