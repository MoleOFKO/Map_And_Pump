<?php
session_start();
define('MAP_PUMP_JSON_RESPONSE', true);
require __DIR__ . '/../conn.php';
require_once __DIR__ . '/license_expiry.php';

header('Content-Type: application/json');

function booking_cancellation_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS booking_cancellation (
                cancellation_id int NOT NULL AUTO_INCREMENT,
                booking_id int NOT NULL,
                user_id int NOT NULL,
                reason_tags text NOT NULL,
                details varchar(1000) DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (cancellation_id),
                UNIQUE KEY uq_booking_cancellation_booking (booking_id),
                KEY idx_booking_cancellation_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        return true;
    } catch (Throwable $e) {
        error_log('Could not prepare booking_cancellation table: ' . $e->getMessage());
        return false;
    }
}

function booking_cancellation_response(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    booking_cancellation_response(405, 'Method not allowed.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    booking_cancellation_response(401, 'Please sign in again to cancel this booking.');
}

$licenseExpiryState = customer_sync_license_expiry($pdo, $userId);
if (($licenseExpiryState['driver']['status'] ?? '') === 'expired') {
    booking_cancellation_response(403, 'Your driver license has expired. Active bookings have been cancelled.');
}

$sessionToken = (string) ($_SESSION['booking_csrf_token'] ?? '');
$requestToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
    booking_cancellation_response(403, 'Your session has expired. Refresh the page and try again.');
}

$bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
$reasons = $_POST['reasons'] ?? [];
$details = trim((string) ($_POST['details'] ?? ''));
$allowedReasons = [
    'Change of plans',
    'Found a closer station',
    'Long wait or queue',
    'Station closed or unavailable',
    'Fuel type or stock unavailable',
    'Booking details were incorrect',
    'Vehicle problem',
    'Payment or budget issue',
    'Booked by mistake',
    'Emergency',
    'Other',
];

if (!$bookingId || !is_array($reasons)) {
    booking_cancellation_response(422, 'Invalid cancellation request.');
}
$reasons = array_values(array_unique(array_filter($reasons, 'is_string')));
if (count($reasons) < 1 || count($reasons) > 3 || array_diff($reasons, $allowedReasons)) {
    booking_cancellation_response(422, 'Select between 1 and 3 valid cancellation reasons.');
}
if (in_array('Other', $reasons, true) && $details === '') {
    booking_cancellation_response(422, 'Please describe the problem when selecting Other.');
}
if (strlen($details) > 1000) {
    booking_cancellation_response(422, 'The cancellation details are too long.');
}

if (!booking_cancellation_schema_ready($pdo)) {
    booking_cancellation_response(500, 'Cancellation service is temporarily unavailable.');
}

try {
    $pdo->beginTransaction();
    $bookingStmt = $pdo->prepare(
        'SELECT booking_id, status, expire_at, (expire_at > NOW()) AS is_not_expired
         FROM booking
         WHERE booking_id = :booking_id AND user_id = :user_id
         LIMIT 1 FOR UPDATE'
    );
    $bookingStmt->execute([':booking_id' => $bookingId, ':user_id' => $userId]);
    $booking = $bookingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$booking) {
        $pdo->rollBack();
        booking_cancellation_response(404, 'Booking not found.');
    }

    if (!in_array(strtolower((string) $booking['status']), ['notfilled', 'serving'], true)
        || (int) $booking['is_not_expired'] !== 1) {
        $pdo->rollBack();
        booking_cancellation_response(409, 'This booking is no longer active and cannot be cancelled.');
    }

    $saveReason = $pdo->prepare(
        'INSERT INTO booking_cancellation (booking_id, user_id, reason_tags, details)
         VALUES (:booking_id, :user_id, :reason_tags, :details)'
    );
    $saveReason->execute([
        ':booking_id' => $bookingId,
        ':user_id' => $userId,
        ':reason_tags' => json_encode($reasons, JSON_UNESCAPED_UNICODE),
        ':details' => $details !== '' ? $details : null,
    ]);

    $cancel = $pdo->prepare(
        "UPDATE booking SET status = 'cancelled'
         WHERE booking_id = :booking_id AND user_id = :user_id AND status IN ('notfilled', 'serving')"
    );
    $cancel->execute([':booking_id' => $bookingId, ':user_id' => $userId]);
    if ($cancel->rowCount() !== 1) {
        throw new RuntimeException('Booking status changed while cancellation was being processed.');
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Booking cancelled.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Customer booking cancellation failed: ' . $e->getMessage());
    booking_cancellation_response(500, 'Unable to cancel this booking. Please try again.');
}
