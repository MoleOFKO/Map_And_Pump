<?php
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/user_account_common.php';

admin_require_staff();
header('Content-Type: application/json; charset=utf-8');

$carId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$carId || !hq_db_available()) {
    http_response_code($carId ? 503 : 400);
    echo json_encode(['success' => false, 'message' => 'A valid car-license id is required.']);
    exit;
}

global $conn;
$carQuery = $conn->prepare(
    'SELECT car_license_id, car_plate_number, car_owner_name, vehicle_name, vehicle_type,
            chassis_number, location, issue_date, license_expire_date, status, created_at,
            car_license_front_image, car_license_back_image
     FROM car_license WHERE car_license_id = ? LIMIT 1'
);
if (!$carQuery) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not prepare car-license lookup.']);
    exit;
}
$carQuery->bind_param('i', $carId);
$carQuery->execute();
$car = $carQuery->get_result()?->fetch_assoc();
$carQuery->close();
if (!$car) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Car license not found.']);
    exit;
}

$ownerQuery = $conn->prepare(
    'SELECT u.user_id, u.user_name, u.email
     FROM user_car uc INNER JOIN users u ON u.user_id = uc.user_id
     WHERE uc.car_id = ? ORDER BY u.user_id'
);
$ownerQuery->bind_param('i', $carId);
$ownerQuery->execute();
$ownerResult = $ownerQuery->get_result();
$owners = [];
while ($ownerResult && ($owner = $ownerResult->fetch_assoc())) {
    $owners[] = ['user_id' => (int) $owner['user_id'], 'user_name' => $owner['user_name'], 'email' => $owner['email']];
}
$ownerQuery->close();

$conflictQuery = $conn->prepare(
    "SELECT c.car_license_id, c.car_plate_number, c.car_owner_name, c.vehicle_name,
            c.vehicle_type, c.chassis_number, c.location, c.issue_date,
            c.license_expire_date, c.status, c.car_license_front_image,
             c.car_license_back_image,
             (SELECT MIN(u.user_id) FROM user_car owner_link INNER JOIN users u ON u.user_id = owner_link.user_id
              WHERE owner_link.car_id = c.car_license_id) AS user_id,
             (SELECT u.user_name FROM user_car owner_link INNER JOIN users u ON u.user_id = owner_link.user_id
              WHERE owner_link.car_id = c.car_license_id ORDER BY u.user_id LIMIT 1) AS user_name,
             (SELECT u.email FROM user_car owner_link INNER JOIN users u ON u.user_id = owner_link.user_id
              WHERE owner_link.car_id = c.car_license_id ORDER BY u.user_id LIMIT 1) AS email
     FROM car_license c
     WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(c.car_plate_number), '/', ''), ' ', ''), '-', '')) =
           UPPER(REPLACE(REPLACE(REPLACE(TRIM(?), '/', ''), ' ', ''), '-', ''))
       AND c.car_license_id <> ?
     ORDER BY c.car_license_id DESC"
);
$conflictQuery->bind_param('si', $car['car_plate_number'], $carId);
$conflictQuery->execute();
$conflictResult = $conflictQuery->get_result();
$conflicts = [];
while ($conflictResult && ($match = $conflictResult->fetch_assoc())) {
    $conflicts[] = [
        'car_license_id' => (int) $match['car_license_id'],
        'car_plate_number' => $match['car_plate_number'],
        'car_owner_name' => $match['car_owner_name'],
        'vehicle_name' => $match['vehicle_name'],
        'vehicle_type' => $match['vehicle_type'],
        'chassis_number' => $match['chassis_number'],
        'location' => $match['location'],
        'issue_date' => $match['issue_date'],
        'license_expire_date' => $match['license_expire_date'],
        'status' => $match['status'],
        'front_image' => $match['car_license_front_image'],
        'back_image' => $match['car_license_back_image'],
        'user_id' => isset($match['user_id']) ? (int) $match['user_id'] : null,
        'user_name' => $match['user_name'],
        'email' => $match['email'],
    ];
}
$conflictQuery->close();

echo json_encode([
    'success' => true,
    'car' => [
        'car_license_id' => (int) $car['car_license_id'],
        'car_plate_number' => $car['car_plate_number'],
        'car_owner_name' => $car['car_owner_name'],
        'vehicle_name' => $car['vehicle_name'],
        'vehicle_type' => $car['vehicle_type'],
        'chassis_number' => $car['chassis_number'],
        'location' => $car['location'],
        'issue_date' => $car['issue_date'],
        'license_expire_date' => $car['license_expire_date'],
        'status' => $car['status'],
        'created_at' => $car['created_at'],
        'front_image' => $car['car_license_front_image'],
        'back_image' => $car['car_license_back_image'],
        'owners' => $owners,
    ],
    'conflicts' => $conflicts,
]);