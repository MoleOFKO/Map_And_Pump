<?php
session_start();
define('MAP_PUMP_JSON_RESPONSE', true);
require __DIR__ . '/../conn.php';
require_once __DIR__ . '/license_access.php';
require_once __DIR__ . '/car_access.php';
require_once __DIR__ . '/../business_scope.php';

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
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : (isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : 0);

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

$licenseAccess = customer_license_access($pdo, $userId);
if (!$licenseAccess['is_approved']) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Your driver license must be confirmed by a Moderator before booking.'
    ]);
    exit;
}

$displayPayload = isset($_POST['display_payload']) ? trim($_POST['display_payload']) : '';
if ($displayPayload === '') {
    $displayPayload = $bookingCode . '|' . $stationId . '|' . $fuelType . '|' . number_format($quantity, 1, '.', '') . 'L';
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
        "SELECT s.station_id, s.station_name, ot.oil_type_id, ot.oil_name
         FROM stations s
         INNER JOIN ({$latestInventorySql}) soi ON soi.station_id = s.station_id
            AND soi.is_active = 1
         INNER JOIN station_business_scope sbs ON sbs.station_id = s.station_id
         INNER JOIN oil_types ot ON ot.oil_type_id = soi.oil_type_id
            AND LOWER(REPLACE(ot.oil_name, ' ', '')) = LOWER(REPLACE(:fuel_type, ' ', ''))
         INNER JOIN tanks t ON t.branch_id = s.station_id
            AND t.is_enabled = 1
            AND t.current_l > 0
            AND (
                (t.fuel_code = 'p92' AND LOWER(REPLACE(ot.oil_name, ' ', '')) = 'octane92')
                OR (t.fuel_code = 'p95' AND LOWER(REPLACE(ot.oil_name, ' ', '')) = 'octane95')
                OR (t.fuel_code = 'dsl' AND LOWER(REPLACE(ot.oil_name, ' ', '')) IN ('disel', 'diesel'))
                OR (t.fuel_code = 'pdsl' AND LOWER(REPLACE(ot.oil_name, ' ', '')) IN ('premiumdisel', 'premiumdiesel'))
            )
         WHERE s.station_id = :station_id
           AND COALESCE(s.status, 'Active') <> 'Decommissioned'
         ORDER BY soi.updated_at DESC, soi.inventory_id DESC
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

    $quota = customer_fuel_quota($pdo, $userId);
    if (empty($quota['has_active_car'])) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Please add a car license and select the active car before booking.'
        ]);
        exit;
    }

    $activeCarLicenseId = (int) $quota['active_car_id'];
    $remainingQuota = (float) $quota['remaining'];
    if ($remainingQuota <= 0.0) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'This customer has reached the weekly fuel limit of ' . number_format((float) $quota['limit'], 1) . ' liters. Please try again next week.'
        ]);
        exit;
    }

    if ($quantity > $remainingQuota) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Only ' . number_format($remainingQuota, 1) . ' liters remain from your weekly fuel limit.'
        ]);
        exit;
    }

    $stationId = (int)$stationFuel['station_id'];
    $finalStationCheck = $pdo->prepare('SELECT station_id FROM stations WHERE station_id = :station_id LIMIT 1');
    $finalStationCheck->execute([':station_id' => $stationId]);
    if (!$finalStationCheck->fetchColumn()) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This station is no longer available. Please refresh and choose another station.']);
        exit;
    }

    $insert = $pdo->prepare('INSERT INTO booking (user_id, car_license_id, station_id, oil_type_id, qr_code, qr_refrence_code, status, filled_gas, expire_at, created_at) VALUES (:user_id, :car_license_id, :station_id, :oil_type_id, :qr_code, :qr_refrence_code, :status, :filled_gas, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW())');
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

    echo json_encode(['success' => true, 'message' => 'Booking saved.', 'booking_code' => $bookingCode, 'payload' => $displayPayload]);
} catch (PDOException $e) {
    $message = $e->getMessage();
    if ($e->getCode() === '23000' && strpos($message, 'fk_booking_station_id') !== false) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'This station is not available in the database. Please refresh the booking page and choose a listed station.']);
        exit;
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save booking. Please try again.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save booking. Please try again.']);
}

