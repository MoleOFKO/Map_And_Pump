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
require_once __DIR__ . '/../booking_reservations.php';
require_once __DIR__ . '/../loyalty_points.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$bookingCode = isset($_POST['booking_code']) ? strtoupper(preg_replace('/\s+/', '', trim((string)$_POST['booking_code']))) : '';
$stationId = isset($_POST['station_id']) ? (int)$_POST['station_id'] : 0;
$fuelType = isset($_POST['fuel_type']) ? trim($_POST['fuel_type']) : '';
$quantity = isset($_POST['quantity_liters']) ? round((float)$_POST['quantity_liters'], 1) : 0;
$requestedCarLicenseId = isset($_POST['car_license_id']) ? (int)$_POST['car_license_id'] : 0;
$requestedPointsToRedeem = isset($_POST['points_to_redeem']) ? (int)$_POST['points_to_redeem'] : 0;
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($bookingCode === '' || $stationId <= 0 || $fuelType === '' || !is_finite($quantity) || $quantity <= 0 || $requestedCarLicenseId < 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Missing booking details.']);
    exit;
}

if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in with a valid customer account before booking.']);
    exit;
}
$bookingCsrf = (string)($_SESSION['booking_csrf_token'] ?? '');
if ($bookingCsrf === '' || !hash_equals($bookingCsrf, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session expired. Refresh the booking page and try again.']);
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

map_pump_booking_configure_timezone($pdo);
if (!customer_car_schema_ready($pdo) || !map_pump_booking_schema_ready($pdo) || !map_pump_usage_history_schema_ready($pdo) || !loyalty_countermeasures_schema_ready($pdo) || !loyalty_schema_ready($pdo)) {
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

    $latestInventorySql = map_pump_latest_station_inventory_sql();
    $stationStmt = $pdo->prepare(
        "SELECT b.branch_id AS station_id, b.branch_name AS station_name,
                ot.oil_type_id, ot.oil_name, t.current_l AS available_liters,
                latest_inventory.price_per_litre
         FROM branches b
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
         INNER JOIN ({$latestInventorySql}) latest_inventory
            ON latest_inventory.station_id = b.branch_id
            AND latest_inventory.oil_type_id = ot.oil_type_id
            AND latest_inventory.is_active = 1
            AND latest_inventory.price_per_litre > 0
         WHERE b.branch_id = :station_id
           AND LOWER(COALESCE(b.status, 'active')) = 'active'
           AND EXISTS (
               SELECT 1
               FROM station_business_scope sbs
               INNER JOIN company_loyalty_station_fees monthly_fee
                   ON monthly_fee.headquarter_id = sbs.headquarter_id
                  AND monthly_fee.station_id = sbs.station_id
                  AND monthly_fee.year_month = :billing_month
               WHERE sbs.station_id = b.branch_id
           )
           AND (
               b.is_24hr = 1
               OR b.opening_time IS NULL
               OR b.closing_time IS NULL
               OR (b.opening_time <= b.closing_time AND CURRENT_TIME() BETWEEN b.opening_time AND b.closing_time)
               OR (b.opening_time > b.closing_time AND (CURRENT_TIME() >= b.opening_time OR CURRENT_TIME() <= b.closing_time))
           )
         LIMIT 1"
    );
    $stationStmt->execute([
        ':station_id' => $stationId,
        ':fuel_type' => $fuelType,
        ':billing_month' => loyalty_current_year_month(),
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

    // Serialize account-wide reservations, then lock the station tank before
    // counting existing holds. A competing reservation or fill must wait.
    $pdo->beginTransaction();
    $userLock = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE');
    $userLock->execute([$userId]);
    if (!$userLock->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in again before booking.']);
        exit;
    }
    if (!customer_license_valid_for_booking($pdo, $userId, true)) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your approved driver license is expired or no longer valid.']);
        exit;
    }
    if ($requestedCarLicenseId > 0) {
        $carLock = $pdo->prepare(
            'SELECT uc.car_id
             FROM user_car uc
             WHERE uc.user_id = :user_id AND uc.car_id = :car_id
             LIMIT 1 FOR UPDATE'
        );
        $carLock->execute([':user_id' => $userId, ':car_id' => $requestedCarLicenseId]);
        $lockedCarId = (int)($carLock->fetchColumn() ?: 0);
        if ($lockedCarId <= 0) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'The selected car is not linked to your account.']);
            exit;
        }
        $quota = customer_fuel_quota_for_car($pdo, $userId, $lockedCarId);
    } else {
        $carLock = $pdo->prepare(
            'SELECT uc.car_id
             FROM user_car uc
             WHERE uc.user_id = :user_id AND uc.is_active = 1
             LIMIT 1 FOR UPDATE'
        );
        $carLock->execute([':user_id' => $userId]);
        $quota = customer_fuel_quota($pdo, $userId);
    }
    if (empty($quota['has_active_car'])) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Please add a car license and select the active car before booking.'
        ]);
        exit;
    }

    $activeCarLicenseId = $requestedCarLicenseId > 0 ? $requestedCarLicenseId : (int)$quota['active_car_id'];
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
           AND status IN ({$statusPlaceholders})
           AND expire_at > NOW()
         LIMIT 1 FOR UPDATE"
    );
    $activeBooking->execute(array_merge([$userId], $activeStatuses));
    if ($activeBooking->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'You already have an active fuel reservation. Use, cancel, or wait for it to expire before creating another one.'
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
    $tankFuelCode = match (strtolower(preg_replace('/\s+/', '', $fuelType))) {
        'octane92' => 'p92', 'octane95' => 'p95', 'disel', 'diesel' => 'dsl',
        'premiumdisel', 'premiumdiesel' => 'pdsl', default => '',
    };
    $tankStmt = $pdo->prepare('SELECT tank_id, current_l FROM tanks WHERE branch_id = ? AND fuel_code = ? AND is_enabled = 1 LIMIT 1 FOR UPDATE');
    $tankStmt->execute([$stationId, $tankFuelCode]);
    $tankRow = $tankStmt->fetch(PDO::FETCH_ASSOC);
    if (!$tankRow || $tankFuelCode === '') {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This station or fuel type is no longer available. Please refresh and choose another station.']);
        exit;
    }

    // Lock and recheck the mutable station predicates inside the reservation
    // transaction. Price writers take the same branch lock before appending.
    $stationLock = $pdo->prepare("SELECT branch_id, branch_name
        FROM branches
        WHERE branch_id = ?
          AND LOWER(COALESCE(status, 'active')) = 'active'
          AND (
              is_24hr = 1
              OR opening_time IS NULL
              OR closing_time IS NULL
              OR (opening_time <= closing_time AND CURRENT_TIME() BETWEEN opening_time AND closing_time)
              OR (opening_time > closing_time AND (CURRENT_TIME() >= opening_time OR CURRENT_TIME() <= closing_time))
          )
        LIMIT 1 FOR UPDATE");
    $stationLock->execute([$stationId]);
    $lockedStation = $stationLock->fetch(PDO::FETCH_ASSOC);
    if (!$lockedStation) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This station is closed or unavailable now. Please refresh and choose another station.']);
        exit;
    }

    $feeLock = $pdo->prepare("SELECT sbs.headquarter_id
        FROM station_business_scope sbs
        INNER JOIN company_loyalty_station_fees fee
            ON fee.headquarter_id = sbs.headquarter_id
           AND fee.station_id = sbs.station_id
           AND fee.year_month = ?
        WHERE sbs.station_id = ?
        LIMIT 1 FOR UPDATE");
    $feeLock->execute([loyalty_current_year_month(), $stationId]);
    if (!$feeLock->fetchColumn()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This station is not eligible to accept bookings for the current month. Please refresh and choose another station.']);
        exit;
    }

    $priceLock = $pdo->prepare("SELECT inventory_id, price_per_litre, is_active
        FROM station_oil_inventory
        WHERE station_id = ? AND oil_type_id = ?
        ORDER BY inventory_id DESC
        LIMIT 1 FOR UPDATE");
    $priceLock->execute([$stationId, $oilTypeId]);
    $lockedPrice = $priceLock->fetch(PDO::FETCH_ASSOC);
    if (!$lockedPrice || (int)$lockedPrice['is_active'] !== 1 || (float)$lockedPrice['price_per_litre'] <= 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This fuel is no longer offered at the selected station. Please refresh and choose another option.']);
        exit;
    }
    $stationFuel['price_per_litre'] = (float)$lockedPrice['price_per_litre'];
    $stationName = (string)$lockedStation['branch_name'];
    $displayPayload = $bookingCode . '|' . $stationName . '|' . $fuelType . '|' . rtrim(rtrim(number_format($quantity, 1, '.', ''), '0'), '.') . 'L';

    $reservedLiters = map_pump_booking_reserved_liters($pdo, $stationId, $oilTypeId, null, true);
    $availableLiters = max(0.0, (float)$tankRow['current_l'] - $reservedLiters);
    if ($quantity > $availableLiters) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only ' . number_format($availableLiters, 1) . ' liters remain available after existing reservations.']);
        exit;
    }

    // Loyalty redeem intent (0 / 10 / 20). Debit happens only on successful fill.
    $pointsToRedeem = 0;
    try {
        $pointsToRedeem = loyalty_validate_redeem_intent($pdo, $userId, $requestedPointsToRedeem);
    } catch (InvalidArgumentException $loyaltyEx) {
        $pdo->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $loyaltyEx->getMessage()]);
        exit;
    }

    $expiryHours = (int) MAP_PUMP_BOOKING_EXPIRY_HOURS;
    $insert = $pdo->prepare('INSERT INTO booking (user_id, car_license_id, station_id, oil_type_id, requested_liters, qr_code, qr_refrence_code, status, filled_gas, expire_at, created_at) VALUES (:user_id, :car_license_id, :station_id, :oil_type_id, :requested_liters, :qr_code, :qr_refrence_code, :status, :filled_gas, DATE_ADD(NOW(), INTERVAL ' . $expiryHours . ' HOUR), NOW())');
    $insert->execute([
        ':user_id' => $userId,
        ':car_license_id' => $activeCarLicenseId,
        ':station_id' => $stationId,
        ':oil_type_id' => $oilTypeId,
        ':requested_liters' => round($quantity, 2),
        ':qr_code' => $displayPayload,
        ':qr_refrence_code' => $bookingCode,
        ':status' => 'notfilled',
        ':filled_gas' => 0
    ]);
    $bookingId = (int) $pdo->lastInsertId();
    $reservation = $pdo->prepare('INSERT INTO booking_reservations (booking_id, station_id, oil_type_id, requested_liters, price_per_litre) VALUES (?, ?, ?, ?, ?)');
    $reservation->execute([$bookingId, $stationId, $oilTypeId, round($quantity, 2), (float)$stationFuel['price_per_litre']]);

    if ($pointsToRedeem > 0 && $bookingId > 0) {
        try {
            $ptsUpdate = $pdo->prepare(
                'UPDATE booking SET points_to_redeem = :pts WHERE booking_id = :bid'
            );
            $ptsUpdate->execute([
                ':pts' => $pointsToRedeem,
                ':bid' => $bookingId,
            ]);
        } catch (Throwable $ptsEx) {
            // Column may be missing if schema alter failed; booking still valid without intent.
            error_log('Could not store points_to_redeem on booking: ' . $ptsEx->getMessage());
        }
    }

    $expiryStmt = $pdo->prepare('SELECT expire_at, created_at FROM booking WHERE booking_id = ? LIMIT 1');
    $expiryStmt->execute([$bookingId]);
    $bookingTimestamps = $expiryStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $expireAt = $bookingTimestamps['expire_at'] ?? null;
    $createdAt = $bookingTimestamps['created_at'] ?? null;

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Booking saved.',
        'booking_id' => $bookingId,
        'booking_code' => $bookingCode,
        'payload' => $displayPayload,
        'expire_at' => $expireAt,
        'created_at' => $createdAt,
        'points_to_redeem' => $pointsToRedeem,
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = $e->getMessage();
    error_log('Booking save failed: ' . $message);
    $driverErrorCode = (int)($e->errorInfo[1] ?? 0);
    $isTransactionConflict = (string)$e->getCode() === '40001'
        || in_array($driverErrorCode, [1205, 1213, 9007], true)
        || stripos($message, 'write conflict') !== false;
    if ($isTransactionConflict) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Stock or booking details changed while you were reserving. Refresh the station information and try again.']);
        exit;
    }
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

