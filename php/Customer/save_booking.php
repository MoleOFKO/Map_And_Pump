<?php
session_start();
define('MAP_PUMP_JSON_RESPONSE', true);
require __DIR__ . '/../localconn.php';
require_once __DIR__ . '/license_access.php';
require_once __DIR__ . '/car_access.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$bookingCode = isset($_POST['booking_code']) ? trim($_POST['booking_code']) : '';
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
    $lookup = $pdo->prepare('SELECT booking_id FROM booking WHERE qr_refrence_code = :code LIMIT 1');
    $lookup->execute([':code' => $bookingCode]);

    if ($lookup->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Booking code already exists.']);
        exit;
    }

    $oilTypeId = 1;
    if (stripos($fuelType, 'diesel') !== false) {
        $oilTypeId = 2;
    } elseif (stripos($fuelType, 'octane 95') !== false) {
        $oilTypeId = 3;
    } elseif (stripos($fuelType, 'octane 92') !== false) {
        $oilTypeId = 4;
    }

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
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save booking: ' . $e->getMessage()]);
}

