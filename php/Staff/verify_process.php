<?php
require __DIR__ . '/../conn.php';

$bookingCode = '';
$ajax = false;

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $ajax = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookingCode = isset($_POST['booking_code']) ? trim($_POST['booking_code']) : '';
    if (isset($_POST['qr_payload'])) {
        $qrPayload = trim($_POST['qr_payload']);
        if (strpos($qrPayload, '|') !== false) {
            $bookingCode = explode('|', $qrPayload)[0];
        } else {
            $bookingCode = $qrPayload;
        }
    }
}

function respond(array $response, bool $ajax, int $status = 200): void
{
    http_response_code($status);
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['verify_flash'] = $response;
    header('Location: verify_qr.php');
    exit;
}

if ($bookingCode === '') {
    respond(['success' => false, 'message' => 'No booking code was supplied.'], $ajax, 422);
}

$normalized = isset($_POST['qr_payload']) ? trim($_POST['qr_payload']) : '';

$stmt = $pdo->prepare(
    'SELECT b.booking_id, b.user_id, b.station_id, b.oil_type_id, b.qr_code, b.qr_refrence_code,
            b.status, b.filled, b.time_of_filled, b.expire_at, b.created_at,
            s.station_name, o.oil_name
     FROM booking b
     LEFT JOIN stations s ON s.station_id = b.station_id
     LEFT JOIN oil_types o ON o.oil_type_id = b.oil_type_id
     WHERE b.qr_refrence_code = :code_exact
        OR b.qr_refrence_code LIKE :code_prefix
        OR b.qr_refrence_code = :payload_a
        OR b.qr_code = :code_b
        OR b.qr_code = :payload_b
     LIMIT 1'
);
$stmt->execute([
    ':code_exact' => $bookingCode,
    ':code_prefix' => $bookingCode . '|%',
    ':payload_a' => $normalized,
    ':code_b' => $bookingCode,
    ':payload_b' => $normalized,
]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    respond(['success' => false, 'message' => 'Booking code not found in the database.'], $ajax, 404);
}

$closedStatuses = ['filled', 'completed', 'cancelled', 'expired'];
if ((int)$row['filled'] === 1 || in_array(strtolower((string)$row['status']), $closedStatuses, true)) {
    respond([
        'success' => false,
        'message' => 'This booking has already been used or is no longer valid.',
        'booking' => $row,
    ], $ajax);
}

if (!empty($row['expire_at']) && strtotime((string)$row['expire_at']) < time()) {
    $expireUpdate = $pdo->prepare('UPDATE booking SET status = :status, expire_at = expire_at, created_at = created_at WHERE booking_id = :booking_id');
    $expireUpdate->execute([':status' => 'expired', ':booking_id' => $row['booking_id']]);
    $row['status'] = 'expired';
    respond([
        'success' => false,
        'message' => 'This booking pass has expired.',
        'booking' => $row,
    ], $ajax);
}

$update = $pdo->prepare('UPDATE booking SET status = :status, filled = :filled, time_of_filled = NOW(), expire_at = expire_at, created_at = created_at WHERE booking_id = :booking_id');
$update->execute([
    ':status' => 'filled',
    ':filled' => 1,
    ':booking_id' => $row['booking_id'],
]);

$row['status'] = 'filled';
$row['filled'] = 1;
$row['time_of_filled'] = date('Y-m-d H:i:s');

respond([
    'success' => true,
    'message' => 'Booking confirmed and checked in.',
    'booking' => $row,
], $ajax);
