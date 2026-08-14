<?php
require __DIR__ . '/../conn.php';

$bookingCode = '';
$ajax = false;

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $ajax = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? trim($_POST['action']) : 'verify';
    if ($action === 'confirm') {
        $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
        $amountLiters = isset($_POST['amount_liters']) ? trim($_POST['amount_liters']) : '';

        if ($bookingId <= 0 || $amountLiters === '' || !is_numeric($amountLiters) || (float)$amountLiters <= 0) {
            respond(['success' => false, 'message' => 'Please enter a valid amount in liters.'], $ajax, 422, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $stmt = $pdo->prepare(
            'SELECT b.booking_id, b.user_id, b.station_id, b.oil_type_id, b.qr_code, b.qr_refrence_code,
                    b.status, b.filled_gas, b.time_of_filled, b.expire_at, b.created_at,
                    u.car_license_id
             FROM booking b
             LEFT JOIN users u ON u.user_id = b.user_id
             WHERE b.booking_id = :booking_id
             LIMIT 1'
        );
        $stmt->execute([':booking_id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            respond(['success' => false, 'message' => 'Booking not found.'], $ajax, 404, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $closedStatuses = ['filled', 'completed', 'cancelled', 'expired'];
        if (in_array(strtolower((string)$row['status']), $closedStatuses, true)) {
            respond(['success' => false, 'message' => 'This booking has already been used or is no longer valid.', 'booking' => $row], $ajax, 200, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        if (!empty($row['expire_at']) && strtotime((string)$row['expire_at']) < time()) {
            $expireUpdate = $pdo->prepare('UPDATE booking SET status = :status, expire_at = expire_at, created_at = created_at WHERE booking_id = :booking_id');
            $expireUpdate->execute([':status' => 'expired', ':booking_id' => $row['booking_id']]);
            respond(['success' => false, 'message' => 'This booking pass has expired.', 'booking' => $row], $ajax, 200, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $pdo->beginTransaction();
        try {
            $carLicenseId = isset($row['car_license_id']) ? (int)$row['car_license_id'] : 0;
            if ($carLicenseId <= 0) {
                $userId = isset($row['user_id']) ? (int)$row['user_id'] : 0;
                if ($userId > 0) {
                    $insertCarLicense = $pdo->prepare('INSERT INTO car_license (car_plate_number, model_year, vehicle_type, location, filled_gas, created_at, updated_at) VALUES (:plate, :year, :vehicle, :location, :filled_gas, NOW(), NOW())');
                    $plateNumber = 'AUTO-' . $userId . '-' . date('YmdHis');
                    $insertCarLicense->execute([
                        ':plate' => $plateNumber,
                        ':year' => (int)date('Y'),
                        ':vehicle' => 'Unknown',
                        ':location' => 'Unknown',
                        ':filled_gas' => 0,
                    ]);
                    $carLicenseId = (int)$pdo->lastInsertId();

                    $linkCarLicense = $pdo->prepare('UPDATE users SET car_license_id = :car_license_id WHERE user_id = :user_id');
                    $linkCarLicense->execute([
                        ':car_license_id' => $carLicenseId,
                        ':user_id' => $userId,
                    ]);
                }
            }

            if ($carLicenseId > 0) {
                $licenseCheck = $pdo->prepare('SELECT filled_gas, updated_at FROM car_license WHERE car_license_id = :car_license_id LIMIT 1');
                $licenseCheck->execute([':car_license_id' => $carLicenseId]);
                $licenseInfo = $licenseCheck->fetch(PDO::FETCH_ASSOC);
                $currentTotal = 0.0;
                if ($licenseInfo) {
                    $updatedAt = $licenseInfo['updated_at'] ?? null;
                    if ($updatedAt && strtotime((string)$updatedAt) < strtotime('-7 days')) {
                        $resetCar = $pdo->prepare('UPDATE car_license SET filled_gas = 0, updated_at = NOW() WHERE car_license_id = :car_license_id');
                        $resetCar->execute([':car_license_id' => $carLicenseId]);
                        $currentTotal = 0.0;
                    } else {
                        $currentTotal = (float)($licenseInfo['filled_gas'] ?? 0);
                    }
                }

                if (($currentTotal + (float)$amountLiters) > 35.0) {
                    $pdo->rollBack();
                    respond(['success' => false, 'message' => 'This customer has already reached the 35-liter weekly limit. The total will reset next week.'], $ajax, 422, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
                }

                $updateCar = $pdo->prepare('UPDATE car_license SET filled_gas = filled_gas + :amount, updated_at = NOW() WHERE car_license_id = :car_license_id');
                $updateCar->execute([
                    ':amount' => (float)$amountLiters,
                    ':car_license_id' => $carLicenseId,
                ]);
            }

            $updateBooking = $pdo->prepare('UPDATE booking SET status = :status, filled_gas = :filled_gas, time_of_filled = NOW(), expire_at = expire_at, created_at = created_at WHERE booking_id = :booking_id');
            $updateBooking->execute([
                ':status' => 'filled',
                ':filled_gas' => (float)$amountLiters,
                ':booking_id' => $row['booking_id'],
            ]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            respond(['success' => false, 'message' => 'Failed to save fill amount. Please try again.'], $ajax, 500, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        respond([
            'success' => true,
            'message' => 'Booking confirmed and the customer’s filled gas was updated.',
            'booking' => $row,
        ], $ajax, 200, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
    }

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

function respond(array $response, bool $ajax, int $status = 200, string $redirect = 'verify_qr.php'): void
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
    header('Location: ' . $redirect);
    exit;
}

if ($bookingCode === '') {
    respond(['success' => false, 'message' => 'No booking code was supplied.'], $ajax, 422);
}

$normalized = isset($_POST['qr_payload']) ? trim($_POST['qr_payload']) : '';

try {
    $stmt = $pdo->prepare(
        'SELECT b.booking_id, b.user_id, b.station_id, b.oil_type_id, b.qr_code, b.qr_refrence_code,
                b.status, b.filled_gas, b.time_of_filled, b.expire_at, b.created_at,
                s.station_name, o.oil_name
         FROM booking b
         LEFT JOIN stations s ON s.station_id = b.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = b.oil_type_id
         WHERE b.qr_refrence_code = :code_exact
            OR b.qr_refrence_code LIKE :code_prefix
            OR b.qr_refrence_code = :payload_a
            OR b.qr_code = :code_b
            OR b.qr_code = :payload_b
         ORDER BY CASE
            WHEN b.status = :active_status AND b.filled_gas = 0 AND (b.expire_at IS NULL OR b.expire_at > NOW()) THEN 1
            ELSE 0
         END DESC, b.booking_id DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':code_exact' => $bookingCode,
        ':code_prefix' => $bookingCode . '|%',
        ':payload_a' => $normalized,
        ':code_b' => $bookingCode,
        ':payload_b' => $normalized,
        ':active_status' => 'notfilled',
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        respond(['success' => false, 'message' => 'Booking code not found in the database.'], $ajax, 404);
    }

    $closedStatuses = ['filled', 'completed', 'cancelled', 'expired'];
    if (in_array(strtolower((string)$row['status']), $closedStatuses, true)) {
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

    $row['status'] = $row['status'];
    $row['filled_gas'] = (int)($row['filled_gas'] ?? 0);
    $row['time_of_filled'] = $row['time_of_filled'];

    respond([
        'success' => true,
        'message' => 'Booking found. Enter the liters and confirm fill.',
        'booking' => $row,
    ], $ajax);
} catch (Throwable $e) {
    if ($ajax) {
        respond(['success' => false, 'message' => 'Server error while verifying the booking. Please try again.'], true, 500);
    }
    respond(['success' => false, 'message' => 'Server error while verifying the booking. Please try again.'], false, 500, 'verify_qr.php');
}
