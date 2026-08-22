<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/../conn.php';
require __DIR__ . '/../usage_history_schema.php';

$bookingCode = '';
$ajax = false;

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    $ajax = true;
}


if (empty($_SESSION['is_logged_in']) || empty($_SESSION['user_id']) || strtolower((string)($_SESSION['role_name'] ?? '')) !== 'staff') {
    respond(['success' => false, 'message' => 'Only staff accounts can verify or confirm fuel fills.'], $ajax, 403, '../Login/login.php');
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
                    b.car_license_id
             FROM booking b
             LEFT JOIN users u ON u.user_id = b.user_id
             WHERE b.booking_id = :booking_id
             LIMIT 1'
        );
        $stmt->execute([':booking_id' => $bookingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            if (map_pump_usage_history_schema_ready($pdo)) {
                $historyRow = find_usage_history_by_booking_id($pdo, $bookingId);
                if ($historyRow) {
                    respond([
                        'success' => false,
                        'message' => 'This booking was already filled and saved in usage history.',
                        'booking' => $historyRow,
                    ], $ajax, 200, 'verify_qr.php');
                }
            }
            respond(['success' => false, 'message' => 'Booking not found.'], $ajax, 404, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $closedStatuses = ['filled', 'completed', 'cancelled', 'expired'];
        if (in_array(strtolower((string)$row['status']), $closedStatuses, true)) {
            respond(['success' => false, 'message' => 'This booking has already been used or is no longer valid.', 'booking' => $row], $ajax, 200, 'verify_qr.php');
        }

        if (!empty($row['expire_at']) && strtotime((string)$row['expire_at']) < time()) {
            $expireUpdate = $pdo->prepare('UPDATE booking SET status = :status, expire_at = expire_at, created_at = created_at WHERE booking_id = :booking_id');
            $expireUpdate->execute([':status' => 'expired', ':booking_id' => $row['booking_id']]);
            respond(['success' => false, 'message' => 'This booking pass has expired.', 'booking' => $row], $ajax, 200, 'verify_qr.php');
        }

        if (!map_pump_usage_history_schema_ready($pdo)) {
            respond(['success' => false, 'message' => 'Usage history table is not ready.'], $ajax, 500, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        $pdo->beginTransaction();
        try {
            $carLicenseId = isset($row['car_license_id']) ? (int)$row['car_license_id'] : 0;
            if ($carLicenseId <= 0) {
                $pdo->rollBack();
                respond(['success' => false, 'message' => 'This booking does not have a car license linked. Ask the customer to add/select a car and book again.'], $ajax, 422, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
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

            $priceStmt = $pdo->prepare(
                'SELECT price_per_litre
                 FROM station_oil_inventory
                 WHERE station_id = :station_id AND oil_type_id = :oil_type_id
                 ORDER BY is_active DESC, updated_at DESC, inventory_id DESC
                 LIMIT 1'
            );
            $priceStmt->execute([
                ':station_id' => (int)$row['station_id'],
                ':oil_type_id' => (int)$row['oil_type_id'],
            ]);
            $pricePerLitre = $priceStmt->fetchColumn();
            $pricePerLitre = $pricePerLitre !== false ? (float)$pricePerLitre : null;
            $totalCost = $pricePerLitre !== null ? round($pricePerLitre * (float)$amountLiters, 2) : null;
            $filledAt = date('Y-m-d H:i:s');
            $staffUserId = (int)($_SESSION['user_id'] ?? 0);

            $historyInsert = $pdo->prepare(
                'INSERT INTO usage_history
                    (booking_id, user_id, car_license_id, station_id, oil_type_id, qr_code, qr_refrence_code,
                     filled_liters, price_per_litre, total_cost, status, booked_at, expire_at, filled_at, staff_user_id, created_at)
                 VALUES
                    (:booking_id, :user_id, :car_license_id, :station_id, :oil_type_id, :qr_code, :qr_refrence_code,
                     :filled_liters, :price_per_litre, :total_cost, :status, :booked_at, :expire_at, :filled_at, :staff_user_id, NOW())'
            );
            $historyInsert->execute([
                ':booking_id' => (int)$row['booking_id'],
                ':user_id' => (int)$row['user_id'],
                ':car_license_id' => $carLicenseId > 0 ? $carLicenseId : null,
                ':station_id' => (int)$row['station_id'],
                ':oil_type_id' => (int)$row['oil_type_id'],
                ':qr_code' => (string)$row['qr_code'],
                ':qr_refrence_code' => (string)$row['qr_refrence_code'],
                ':filled_liters' => (float)$amountLiters,
                ':price_per_litre' => $pricePerLitre,
                ':total_cost' => $totalCost,
                ':status' => 'filled',
                ':booked_at' => $row['created_at'] ?? null,
                ':expire_at' => $row['expire_at'] ?? null,
                ':filled_at' => $filledAt,
                ':staff_user_id' => $staffUserId > 0 ? $staffUserId : null,
            ]);

            $deleteBooking = $pdo->prepare('DELETE FROM booking WHERE booking_id = :booking_id');
            $deleteBooking->execute([':booking_id' => (int)$row['booking_id']]);

            $pdo->commit();
            $row['status'] = 'filled';
            $row['filled_gas'] = (float)$amountLiters;
            $row['time_of_filled'] = $filledAt;
        } catch (Throwable $e) {
            $pdo->rollBack();
            respond(['success' => false, 'message' => 'Failed to save fill amount. Please try again.'], $ajax, 500, 'verify_detail.php?booking_id=' . urlencode((string)$bookingId));
        }

        respond([
            'success' => true,
            'message' => 'Booking confirmed. The fill was saved to usage history and removed from active bookings.',
            'booking' => $row,
        ], $ajax, 200, 'verify_qr.php');
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

function find_usage_history_by_booking_id(PDO $pdo, int $bookingId): ?array
{
    if ($bookingId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT uh.usage_id, uh.booking_id, uh.user_id, uh.station_id, uh.oil_type_id, uh.qr_code, uh.qr_refrence_code,
                uh.status, uh.filled_liters, uh.filled_at, uh.expire_at, uh.booked_at AS created_at,
                s.station_name, o.oil_name
         FROM usage_history uh
         LEFT JOIN stations s ON s.station_id = uh.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
         WHERE uh.booking_id = :booking_id
         LIMIT 1'
    );
    $stmt->execute([':booking_id' => $bookingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ? normalize_usage_history_booking($row) : null;
}

function find_usage_history_by_code(PDO $pdo, string $bookingCode, string $normalized): ?array
{
    $stmt = $pdo->prepare(
        'SELECT uh.usage_id, uh.booking_id, uh.user_id, uh.station_id, uh.oil_type_id, uh.qr_code, uh.qr_refrence_code,
                uh.status, uh.filled_liters, uh.filled_at, uh.expire_at, uh.booked_at AS created_at,
                s.station_name, o.oil_name
         FROM usage_history uh
         LEFT JOIN stations s ON s.station_id = uh.station_id
         LEFT JOIN oil_types o ON o.oil_type_id = uh.oil_type_id
         WHERE uh.qr_refrence_code = :code_exact
            OR uh.qr_refrence_code LIKE :code_prefix
            OR uh.qr_refrence_code = :payload_a
            OR uh.qr_code = :code_b
            OR uh.qr_code = :payload_b
         ORDER BY uh.filled_at DESC, uh.usage_id DESC
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

    return $row ? normalize_usage_history_booking($row) : null;
}

function normalize_usage_history_booking(array $row): array
{
    $row['filled_gas'] = (float)($row['filled_liters'] ?? 0);
    $row['time_of_filled'] = $row['filled_at'] ?? null;
    $row['status'] = $row['status'] ?? 'filled';
    return $row;
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
        if (map_pump_usage_history_schema_ready($pdo)) {
            $historyRow = find_usage_history_by_code($pdo, $bookingCode, $normalized);
            if ($historyRow) {
                respond([
                    'success' => false,
                    'message' => 'This QR code was already filled and saved in usage history.',
                    'booking' => $historyRow,
                ], $ajax, 200);
            }
        }
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


