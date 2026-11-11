<?php
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/user_account_common.php';

$reviewer = admin_require_staff();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    request_redirect('view_requests.php');
}

$carId = filter_var($_POST['car_license_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$returnTo = admin_user_has_role($reviewer, ['moderator']) ? 'view_requests(M).php' : 'view_requests.php';
if (!$carId || !in_array($action, ['approve', 'reject'], true) || !hq_db_available()) {
    request_flash('danger', 'The car-license review action was invalid or unavailable.');
    request_redirect($returnTo);
}

global $conn;
$transactionStarted = false;
try {
    $conn->begin_transaction();
    $transactionStarted = true;
    $lookup = $conn->prepare('SELECT car_license_id, car_plate_number, status FROM car_license WHERE car_license_id = ? FOR UPDATE');
    if (!$lookup) {
        throw new RuntimeException('Could not prepare the car-license review lookup.');
    }
    $lookup->bind_param('i', $carId);
    $lookup->execute();
    $car = $lookup->get_result()?->fetch_assoc();
    $lookup->close();
    if (!$car || !in_array(strtolower(trim((string) $car['status'])), ['pending', 'pending_verification'], true)) {
        throw new RuntimeException('Only a pending car license can be approved or rejected.');
    }
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $update = $conn->prepare('UPDATE car_license SET status = ?, updated_at = NOW() WHERE car_license_id = ?');
    if (!$update) {
        throw new RuntimeException('Could not prepare the car-license decision.');
    }
    $update->bind_param('si', $newStatus, $carId);
    if (!$update->execute()) {
        throw new RuntimeException('Could not save the car-license decision.');
    }
    $update->close();
    $conn->commit();
    $transactionStarted = false;

    admin_record_log((int) $reviewer['user_id'], ucfirst($action) . ' car license #' . $carId . ' (' . $car['car_plate_number'] . ').');
    request_flash('success', sprintf('Car license %s was %s.', $car['car_plate_number'], $newStatus));
} catch (Throwable $error) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    error_log('[Car license review] Decision failed: ' . $error->getMessage());
    request_flash('danger', 'The car-license decision could not be saved.');
}

request_redirect($returnTo);
