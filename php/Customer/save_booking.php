<?php
session_start();
define('MAP_PUMP_JSON_RESPONSE', true);
require __DIR__ . '/../conn.php';
require_once __DIR__ . '/license_access.php';
require_once __DIR__ . '/license_expiry.php';
require_once __DIR__ . '/car_access.php';
require_once __DIR__ . '/../usage_history_schema.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../booking_rules.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$bookingCode = isset($_POST['booking_code']) ? strtoupper(preg_replace('/\s+/', '', trim((string)$_POST['booking_code']))) : '';
$stationId = isset($_POST['station_id']) ? (int)$_POST['station_id'] : 0;
$fuelType = isset($_POST['fuel_type']) ? trim($_POST['fuel_type']) : '';
$quantity = isset($_POST['quantity_liters']) ? (float)$_POST['quantity_liters'] : 0;
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($bookingCode === '' || $stationId <= 0 || $fuelType === '' || $quantity <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing booking details.']);
    exit;
}

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in with a valid customer account before booking.']);
    exit;
}

$licenseExpiryState = customer_sync_license_expiry($pdo, $userId);
$licenseAccess = customer_license_access($pdo, $userId);
if (!$licenseAccess['is_approved']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => ($licenseExpiryState['driver']['status'] ?? '') === 'expired'
            ? 'Your driver license has expired. Please update it and wait for Moderator confirmation before booking.'
            : 'Your driver license must be confirmed by a Moderator before booking.'
    ]);
    exit;
}

$displayPayload = isset($_POST['display_payload']) ? trim($_POST['display_payload']) : '';
if ($displayPayload === '') {
    $displayPayload = $bookingCode . '|' . $stationId . '|' . $fuelType . '|' . number_format($quantity, 1, '.', '') . 'L';
}

if (!customer_car_schema_ready($pdo) || !map_pump_usage_history_schema_ready($pdo)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Booking service is temporarily unavailable. Please try again later.']);
    exit;
}

try {
    $lookup = $pdo->prepare('SELECT booking_id FROM booking WHERE qr_refrence_code = :code OR qr_code = :payload LIMIT 1');
    $lookup->execute([':code' => $bookingCode, ':payload' => $displayPayload]);

    if ($lookup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Booking code already exists.']);
        exit;
    }

    $stationStmt = $pdo->prepare(
        "SELECT b.branch_id AS station_id, b.branch_name AS station_name, ot.oil_type_id, ot.oil_name
         FROM branches b
         LEFT JOIN (
             SELECT station_id, oil_type_id, MAX(inventory_id) AS inventory_id
             FROM station_oil_inventory
             GROUP BY station_id, oil_type_id
         ) latest_inventory ON latest_inventory.station_id = b.branch_id
         INNER JOIN tanks t ON t.branch_id = b.branch_id
            AND t.is_enabled = 1
            AND t.current_l > 0
         INNER JOIN oil_types ot ON LOWER(REPLACE(ot.oil_name, ' ', '')) = LOWER(REPLACE(:fuel_type, ' ', ''))
            AND (
                (t.fuel_code = 'p92' AND LOWER(REPLACE(ot.oil_name, ' ', '')) = 'octane92')
                OR (t.fuel_code = 'p95' AND LOWER(REPLACE(ot.oil_name, ' ', '')) = 'octane95')
                OR (t.fuel_code = 'dsl' AND LOWER(REPLACE(ot.oil_name, ' ', '')) IN ('disel', 'diesel'))
                OR (t.fuel_code = 'pdsl' AND LOWER(REPLACE(ot.oil_name, ' ', '')) IN ('premiumdisel', 'premiumdiesel'))
            )
         WHERE b.branch_id = :station_id
           AND LOWER(COALESCE(b.status, 'active')) = 'active'
           AND (
               b.is_24hr = 1
               OR b.opening_time IS NULL
               OR b.closing_time IS NULL
               OR (b.opening_time <= b.closing_time AND CURRENT_TIME() BETWEEN b.opening_time AND b.closing_time)
               OR (b.opening_time > b.closing_time AND (CURRENT_TIME() >= b.opening_time OR CURRENT_TIME() <= b.closing_time))
           )
         ORDER BY latest_inventory.inventory_id DESC
         LIMIT 1"
    );
    $stationStmt->execute([
        ':station_id' => $stationId,
        ':fuel_type' => $fuelType,
    ]);
    $stationFuel = $stationStmt->fetch(PDO::FETCH_ASSOC);

    if (!$stationFuel) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This station or fuel type is not available. Please refresh and choose another station.']);
        exit;
    }

    if (empty($stationFuel['oil_type_id'])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This fuel type is not available at the selected station. Please choose another fuel type.']);
        exit;
    }

    $oilTypeId = (int)$stationFuel['oil_type_id'];
    $fuelType = (string)$stationFuel['oil_name'];
    $stationName = (string)($stationFuel['station_name'] ?? ('Station #' . $stationId));
    $displayPayload = $bookingCode . '|' . $stationName . '|' . $fuelType . '|' . rtrim(rtrim(number_format($quantity, 1, '.', ''), '0'), '.') . 'L';

    /*
     * Lock the selected car while checking and creating the booking. This
     * makes the "one active booking per customer/car" rule reliable even if
     * the customer submits the form twice or has two browser tabs open.
     */
    $pdo->beginTransaction();
    $carLock = $pdo->prepare(
        'SELECT uc.car_id
         FROM user_car uc
         WHERE uc.user_id = :user_id AND uc.is_active = 1
         LIMIT 1 FOR UPDATE'
    );
    $carLock->execute([':user_id' => $userId]);

    $quota = customer_fuel_quota($pdo, $userId);
    if (empty($quota['has_active_car'])) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Please add a car license and select the active car before booking.'
        ]);
        exit;
    }

    $activeCarLicenseId = (int) $quota['active_car_id'];
    $carLicenseCheck = $pdo->prepare(
        "SELECT status, license_expire_date
         FROM car_license
         WHERE car_license_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $carLicenseCheck->execute([$activeCarLicenseId]);
    $activeCarLicense = $carLicenseCheck->fetch(PDO::FETCH_ASSOC) ?: [];
    $carLicenseStatus = strtolower(trim((string) ($activeCarLicense['status'] ?? 'pending')));
    $carLicenseExpired = $carLicenseStatus === 'expired'
        || (!empty($activeCarLicense['license_expire_date']) && $activeCarLicense['license_expire_date'] < date('Y-m-d'));
    if ($carLicenseExpired) {
        $pdo->rollBack();
        $pdo->prepare("UPDATE car_license SET status = 'expired', updated_at = NOW() WHERE car_license_id = ?")->execute([$activeCarLicenseId]);
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your selected car license has expired. Please update it before booking.'
        ]);
        exit;
    }
    $activeStatuses = map_pump_booking_active_statuses();
    $statusPlaceholders = implode(',', array_fill(0, count($activeStatuses), '?'));
    $activeBooking = $pdo->prepare(
        "SELECT booking_id
         FROM booking
         WHERE user_id = ?
           AND car_license_id = ?
           AND status IN ({$statusPlaceholders})
           AND (expire_at IS NULL OR expire_at > NOW())
         LIMIT 1 FOR UPDATE"
    );
    $activeBooking->execute(array_merge([$userId, $activeCarLicenseId], $activeStatuses));
    if ($activeBooking->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'This vehicle already has an active fuel booking. Use, cancel, or wait for that booking to expire before creating another one.'
        ]);
        exit;
    }
    $remainingQuota = (float) $quota['remaining'];
    $quotaLabel = map_pump_fuel_quota_period_label();
    if ($remainingQuota <= 0.0) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'This customer has reached the ' . $quotaLabel . ' fuel limit of ' . number_format((float) $quota['limit'], 1) . ' liters. Please try again next ' . rtrim($quotaLabel, 'ly') . '.'
        ]);
        exit;
    }

    if ($quantity > $remainingQuota) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Only ' . number_format($remainingQuota, 1) . ' liters remain from your ' . $quotaLabel . ' fuel limit.'
        ]);
        exit;
    }

    $stationId = (int)$stationFuel['station_id'];
    $finalStationCheck = $pdo->prepare('SELECT branch_id FROM branches WHERE branch_id = :station_id LIMIT 1');
    $finalStationCheck->execute([':station_id' => $stationId]);
    if (!$finalStationCheck->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This station is no longer available. Please refresh and choose another station.']);
        exit;
    }

    $expiryHours = (int) MAP_PUMP_BOOKING_EXPIRY_HOURS;
    $insert = $pdo->prepare('INSERT INTO booking (user_id, car_license_id, station_id, oil_type_id, qr_code, qr_refrence_code, status, filled_gas, expire_at, created_at) VALUES (:user_id, :car_license_id, :station_id, :oil_type_id, :qr_code, :qr_refrence_code, :status, :filled_gas, DATE_ADD(NOW(), INTERVAL ' . $expiryHours . ' HOUR), NOW())');
    $insert->execute([
        ':user_id' => $userId,
        ':car_license_id' => $activeCarLicenseId,
        ':station_id' => $stationId,
        ':oil_type_id' => $oilTypeId,
        ':qr_code' => $displayPayload,
        ':qr_refrence_code' => $bookingCode,
        ':status' => 'notfilled',
        ':filled_gas' => 0
    ]);
    $bookingId = (int) $pdo->lastInsertId();
    $expiryStmt = $pdo->prepare('SELECT expire_at FROM booking WHERE booking_id = ? LIMIT 1');
    $expiryStmt->execute([$bookingId]);
    $expireAt = $expiryStmt->fetchColumn() ?: null;

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Booking saved.', 'booking_code' => $bookingCode, 'payload' => $displayPayload, 'expire_at' => $expireAt]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = $e->getMessage();
    error_log('Booking save failed: ' . $message);
    if ($e->getCode() === '23000' && (strpos($message, 'fk_booking_branch_id') !== false || strpos($message, 'fk_booking_station_id') !== false)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This station is not available in the database. Please refresh the booking page and choose a listed station.']);
        exit;
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save booking. Please try again.']);
} catch (Throwable $e) {
    error_log('Booking save failed: ' . $e->getMessage());
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save booking. Please try again.']);
}

