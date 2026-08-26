<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../feedback_schema.php';
require_once __DIR__ . '/../usage_history_schema.php';

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
                uh.filled_liters, uh.status, uh.filled_at, s.station_name, o.oil_name
         FROM usage_history uh
         LEFT JOIN stations s ON s.station_id = uh.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
         WHERE uh.usage_id = :usage_id AND uh.user_id = :user_id
         LIMIT 1'
    );
    $usageStmt->execute([
        ':usage_id' => $usageId,
        ':user_id' => $userId,
    ]);
    $usage = $usageStmt->fetch(PDO::FETCH_ASSOC);

    if (!$usage || strtolower((string)($usage['status'] ?? '')) !== 'filled') {
        customer_feedback_redirect('feedback_error=not_filled');
    }

    $context = sprintf(
        '[Usage #%d | Booking %s | Station %s | Fuel %s | Liters %s | Filled %s%s]',
        (int)$usage['usage_id'],
        (string)($usage['qr_refrence_code'] ?? 'N/A'),
        (string)($usage['station_name'] ?? ('Station #' . (int)$usage['station_id'])),
        (string)($usage['oil_name'] ?? 'Fuel'),
        rtrim(rtrim(number_format((float)($usage['filled_liters'] ?? 0), 1, '.', ''), '0'), '.'),
        (string)($usage['filled_at'] ?? 'N/A'),
        $rating > 0 ? (' | Rating ' . $rating . '/5') : ''
    );
    $feedbackText = $message !== '' ? ($context . "\n" . $message) : ($context . "\nCustomer submitted a rating without a written comment.");

    $insert = $pdo->prepare(
        'INSERT INTO feedback (user_id, usage_id, station_id, rating, feedback, created_at)
         VALUES (:user_id, :usage_id, :station_id, :rating, :feedback, NOW())'
    );
    $insert->execute([
        ':user_id' => $userId,
        ':usage_id' => $usageId,
        ':station_id' => !empty($usage['station_id']) ? (int)$usage['station_id'] : null,
        ':rating' => $rating > 0 ? $rating : null,
        ':feedback' => $feedbackText,
    ]);

    customer_feedback_redirect('feedback_sent=1');
} catch (Throwable $e) {
    error_log('Failed to submit customer usage feedback: ' . $e->getMessage());
    customer_feedback_redirect('feedback_error=save');
}
