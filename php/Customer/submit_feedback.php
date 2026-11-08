<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../feedback_schema.php';
require_once __DIR__ . '/../usage_history_schema.php';
require_once __DIR__ . '/license_expiry.php';

function customer_feedback_redirect(string $query = ''): void
{
    $suffix = $query !== '' ? ('?' . $query) : '';
    header('Location: usage_history.php' . $suffix);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    customer_feedback_redirect();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: ../Login/login.php');
    exit;
}

$licenseExpiryState = customer_sync_license_expiry($pdo, $userId);
if (($licenseExpiryState['driver']['status'] ?? '') === 'expired') {
    customer_feedback_redirect();
}

$usageId = (int)($_POST['usage_id'] ?? 0);
$rating = (int)($_POST['rating'] ?? 0);
$message = trim((string)($_POST['feedback'] ?? ''));

if ($usageId <= 0) {
    customer_feedback_redirect('feedback_error=invalid');
}

if ($rating < 0 || $rating > 5) {
    $rating = 0;
}

if ($rating === 0 && $message === '') {
    customer_feedback_redirect('feedback_error=empty');
}

if (!map_pump_usage_history_schema_ready($pdo) || !map_pump_feedback_schema_ready($pdo)) {
    customer_feedback_redirect('feedback_error=unavailable');
}

try {
    $usageStmt = $pdo->prepare(
        'SELECT uh.usage_id, uh.booking_id, uh.user_id, uh.station_id, uh.oil_type_id, uh.qr_refrence_code,
                uh.filled_liters, uh.status, uh.filled_at, s.branch_name AS station_name, o.oil_name
                 FROM usage_history uh
         LEFT JOIN branches s ON s.branch_id = uh.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
                 WHERE uh.usage_id = :usage_id
                     AND uh.user_id = :user_id
                     AND LOWER(uh.status) = \'filled\'
                     AND uh.staff_user_id IS NOT NULL
         LIMIT 1'
    );
    $usageStmt->execute([
        ':usage_id' => $usageId,
        ':user_id' => $userId,
    ]);
    $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);

    if (!$usage) {
        customer_feedback_redirect('feedback_error=not_filled');
    }

    $insert = $pdo->prepare(
        'INSERT INTO feedback (user_id, usage_id, station_id, rating, feedback, created_at)
         VALUES (:user_id, :usage_id, :station_id, :rating, :feedback, NOW())'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':usage_id' => $usageId,
        ':station_id' => !empty($usage['station_id']) ? (int)$usage['station_id'] : null,
        ':rating' => $rating > 0 ? $rating : null,
        ':feedback' => $message,
    ]);

    customer_feedback_redirect('feedback_sent=1');
} catch (Throwable $e) {
    error_log('Failed to submit customer usage feedback: ' . $e->getMessage());
    customer_feedback_redirect('feedback_error=save');
}
