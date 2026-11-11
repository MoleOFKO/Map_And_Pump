<?php
require_once __DIR__ . '/../Login/session_helper.php';
map_pump_configure_session();
session_start();

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/profile_schema.php';

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (empty($_SESSION['is_logged_in'])) {
    http_response_code(401);
    exit;
}

$isHqAuth = !empty($_SESSION['hq_auth']);
$accountId = $isHqAuth
    ? (int) ($_SESSION['headquarter_id'] ?? 0)
    : (int) ($_SESSION['user_id'] ?? 0);
$table = $isHqAuth ? 'headquarter' : 'users';
$idColumn = $isHqAuth ? 'headquarter_id' : 'user_id';

if ($accountId <= 0) {
    http_response_code(404);
    exit;
}

try {
    hq_profile_ensure_avatar_schema($pdo, $isHqAuth);
    $stmt = $pdo->prepare(
        "SELECT profile_avatar_data, profile_avatar_mime FROM {$table} WHERE {$idColumn} = ? LIMIT 1"
    );
    $stmt->execute([$accountId]);
    $avatar = $stmt->fetch(PDO::FETCH_ASSOC);
    $imageData = $avatar['profile_avatar_data'] ?? null;
    if (is_resource($imageData)) {
        $imageData = stream_get_contents($imageData);
    }
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!$avatar || !is_string($imageData) || $imageData === ''
        || !in_array((string) $avatar['profile_avatar_mime'], $allowedMimes, true)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $avatar['profile_avatar_mime']);
    header('Content-Length: ' . strlen($imageData));
    echo $imageData;
} catch (Throwable $exception) {
    error_log('HQ profile image could not be loaded: ' . $exception->getMessage());
    http_response_code(500);
}
