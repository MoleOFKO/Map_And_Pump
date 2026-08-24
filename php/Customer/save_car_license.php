<?php
session_start();
ini_set('display_errors', '0');

define('MAP_PUMP_JSON_RESPONSE', true);
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/car_access.php';

header('Content-Type: application/json; charset=utf-8');

function car_license_json(bool $ok, string $message, array $extra = []): never
{
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

function customer_car_uploaded_image_path(int $userId, array $file, string $label): ?string
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Could not read the uploaded car license ' . $label . ' image.');
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Car license ' . $label . ' image must be 5 MB or smaller.');
    }

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($file['tmp_name']);
    }

    if ($mime === null && function_exists('exif_imagetype')) {
        $imageType = exif_imagetype($file['tmp_name']);
        $mimeMap = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
        ];
        $mime = $mimeMap[$imageType] ?? null;
    }

    if ($mime === null) {
        $fileExt = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extensionMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $mime = $extensionMap[$fileExt] ?? null;
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Car license ' . $label . ' image must be JPG, PNG, or WEBP.');
    }

    $uploadDir = __DIR__ . '/uploads/car-licenses';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new RuntimeException('Could not prepare the car license upload folder.');
    }

    $fileName = 'car-license-' . $label . '-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    $targetPath = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not save the uploaded car license ' . $label . ' image.');
    }

    return '/php/Customer/uploads/car-licenses/' . $fileName;
}
function customer_car_license_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS car_license (
                car_license_id int NOT NULL AUTO_INCREMENT,
                car_plate_number varchar(50) NOT NULL,
                car_owner_name varchar(100) DEFAULT NULL,
                car_license_front_image varchar(255) DEFAULT NULL,
                car_license_back_image varchar(255) DEFAULT NULL,
                model_year int NOT NULL,
                vehicle_name varchar(100) DEFAULT NULL,
                vehicle_type varchar(100) NOT NULL,
                chassis_number varchar(20) DEFAULT NULL,
                location varchar(255) NOT NULL,
                filled_gas int NOT NULL DEFAULT 0,
                issue_date date DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (car_license_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        foreach ([
            'car_license_front_image' => "ALTER TABLE car_license ADD COLUMN car_license_front_image varchar(255) NULL AFTER car_owner_name",
            'car_license_back_image' => "ALTER TABLE car_license ADD COLUMN car_license_back_image varchar(255) NULL AFTER car_license_front_image",
        ] as $column => $sql) {
            $columnStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'car_license'
                   AND COLUMN_NAME = ?"
            );
            $columnStmt->execute([$column]);
            if ((int) $columnStmt->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_car (
                user_car_id int NOT NULL AUTO_INCREMENT,
                user_id int NOT NULL,
                car_id int NOT NULL,
                PRIMARY KEY (user_car_id),
                KEY fk_user_car_user_id_idx (user_id),
                KEY fk_user_car_car_id_idx (car_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        customer_car_schema_ready($pdo);
        return true;
    } catch (Throwable $e) {
        error_log('Could not prepare car license schema: ' . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    car_license_json(false, 'Car license request is invalid.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    car_license_json(false, 'Please sign in before adding a car license.', ['redirect' => '/php/Login/login.php']);
}

$action = trim((string) ($_POST['action'] ?? 'save'));
$carId = (int) ($_POST['car_license_id'] ?? 0);

if (!customer_car_license_schema_ready($pdo)) {
    car_license_json(false, 'Database needs the car license tables. Please import the updated db.sql first.');
}

try {
    if ($action === 'switch') {
        if ($carId <= 0) {
            car_license_json(false, 'Choose a car license to switch to.');
        }

        $owned = $pdo->prepare('SELECT car_id FROM user_car WHERE user_id = ? AND car_id = ? LIMIT 1');
        $owned->execute([$userId, $carId]);
        if (!$owned->fetchColumn()) {
            car_license_json(false, 'That car license is not linked to your account.');
        }

        if (!customer_set_active_car($pdo, $userId, $carId)) {
            car_license_json(false, 'That car license is not linked to your account.');
        }
        $_SESSION['car_license_id'] = $carId;
        car_license_json(true, 'Selected car license switched.', ['redirect' => '/php/Customer/car_license.php?car_id=' . $carId]);
    }

    $rawPlateNumber = strtoupper(trim((string) ($_POST['car_plate_number'] ?? '')));
    $plateCompact = preg_replace('/[^0-9A-Z]/', '', $rawPlateNumber) ?: '';
    if (strlen($plateCompact) === 6) {
        $plateNumber = substr($plateCompact, 0, 2) . '/' . substr($plateCompact, 2);
    } else {
        $plateNumber = $rawPlateNumber;
    }
    $ownerName = trim((string) ($_POST['car_owner_name'] ?? ''));
    $modelYear = (int) ($_POST['model_year'] ?? 0);
    $vehicleName = trim((string) ($_POST['vehicle_name'] ?? ''));
    $vehicleType = trim((string) ($_POST['vehicle_type'] ?? ''));
    $chassisNumber = strtoupper(trim((string) ($_POST['chassis_number'] ?? '')));
    $location = trim((string) ($_POST['location'] ?? ''));
    $issueDate = trim((string) ($_POST['issue_date'] ?? ''));

    if (!preg_match('/^[1-9][A-Z]\\/(\\d{4})$/', $plateNumber, $plateMatches) || (int) $plateMatches[1] < 1111) {
        car_license_json(false, 'Car license plate number must use the format 1A/1234, with the last four digits from 1111 to 9999.');
    }
    if ($ownerName === '' || strlen($ownerName) > 100) {
        car_license_json(false, 'Please enter the car owner name.');
    }
    if ($modelYear < 1950 || $modelYear > (int) date('Y') + 1) {
        car_license_json(false, 'Please enter a valid model year.');
    }
    if ($vehicleName === '' || strlen($vehicleName) > 100) {
        car_license_json(false, 'Please enter the vehicle name.');
    }
    if ($vehicleType === '' || strlen($vehicleType) > 100) {
        car_license_json(false, 'Please enter the vehicle type.');
    }
    if ($chassisNumber === '' || strlen($chassisNumber) > 20) {
        car_license_json(false, 'Please enter a chassis number with 20 characters or fewer.');
    }
    if ($location === '' || strlen($location) > 255) {
        car_license_json(false, 'Please enter the issued location.');
    }

    $issuedAt = DateTime::createFromFormat('Y-m-d', $issueDate);
    if (!$issuedAt || $issuedAt->format('Y-m-d') !== $issueDate) {
        car_license_json(false, 'Please enter a valid issued date.');
    }
    if ($issuedAt > new DateTime('today')) {
        car_license_json(false, 'Issued date cannot be in the future.');
    }

    $existingCar = [];
    if ($carId > 0) {
        $owned = $pdo->prepare(
            'SELECT c.*
             FROM user_car uc
             INNER JOIN car_license c ON c.car_license_id = uc.car_id
             WHERE uc.user_id = ? AND uc.car_id = ?
             LIMIT 1'
        );
        $owned->execute([$userId, $carId]);
        $existingCar = $owned->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$existingCar) {
            throw new RuntimeException('That car license is not linked to your account.');
        }
    }

    $frontImagePath = (string) ($existingCar['car_license_front_image'] ?? '');
    $backImagePath = (string) ($existingCar['car_license_back_image'] ?? '');
    $frontUploadFile = $_FILES['car_license_front_image'] ?? [];
    $backUploadFile = $_FILES['car_license_back_image'] ?? [];
    if ($frontImagePath === '' && !is_uploaded_file($frontUploadFile['tmp_name'] ?? '')) {
        car_license_json(false, 'Please upload the front image of the car license.');
    }
    if ($backImagePath === '' && !is_uploaded_file($backUploadFile['tmp_name'] ?? '')) {
        car_license_json(false, 'Please upload the back image of the car license.');
    }
    $newFrontImagePath = customer_car_uploaded_image_path($userId, $frontUploadFile, 'front');
    $newBackImagePath = customer_car_uploaded_image_path($userId, $backUploadFile, 'back');
    if ($newFrontImagePath !== null) {
        $frontImagePath = $newFrontImagePath;
    }
    if ($newBackImagePath !== null) {
        $backImagePath = $newBackImagePath;
    }
    if ($frontImagePath === '') {
        car_license_json(false, 'Please upload the front image of the car license.');
    }
    if ($backImagePath === '') {
        car_license_json(false, 'Please upload the back image of the car license.');
    }

    $pdo->beginTransaction();

    if ($carId > 0) {
        $update = $pdo->prepare(
            'UPDATE car_license
             SET car_plate_number = ?, car_owner_name = ?, car_license_front_image = ?, car_license_back_image = ?, model_year = ?, vehicle_name = ?, vehicle_type = ?, chassis_number = ?, location = ?, issue_date = ?, updated_at = NOW()
             WHERE car_license_id = ?'
        );
        $update->execute([$plateNumber, $ownerName, $frontImagePath, $backImagePath, $modelYear, $vehicleName, $vehicleType, $chassisNumber, $location, $issueDate, $carId]);
        $savedCarId = $carId;
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO car_license
                (car_plate_number, car_owner_name, car_license_front_image, car_license_back_image, model_year, vehicle_name, vehicle_type, chassis_number, location, filled_gas, issue_date, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())'
        );
        $insert->execute([$plateNumber, $ownerName, $frontImagePath, $backImagePath, $modelYear, $vehicleName, $vehicleType, $chassisNumber, $location, $issueDate]);
        $savedCarId = (int) $pdo->lastInsertId();
        if ($savedCarId <= 0) {
            $lookup = $pdo->prepare('SELECT car_license_id FROM car_license WHERE car_plate_number = ? ORDER BY car_license_id DESC LIMIT 1');
            $lookup->execute([$plateNumber]);
            $savedCarId = (int) $lookup->fetchColumn();
        }
        if ($savedCarId <= 0) {
            throw new RuntimeException('Car license record was created but its ID could not be read.');
        }

        $link = $pdo->prepare('INSERT INTO user_car (user_id, car_id, is_active) SELECT ?, ?, 0 WHERE NOT EXISTS (SELECT 1 FROM user_car WHERE user_id = ? AND car_id = ?)');
        $link->execute([$userId, $savedCarId, $userId, $savedCarId]);
    }

    $clearActive = $pdo->prepare('UPDATE user_car SET is_active = 0, updated_at = NOW() WHERE user_id = ?');
    $clearActive->execute([$userId]);
    $setActive = $pdo->prepare('UPDATE user_car SET is_active = 1, updated_at = NOW() WHERE user_id = ? AND car_id = ?');
    $setActive->execute([$userId, $savedCarId]);

    $pdo->commit();
    $_SESSION['car_license_id'] = $savedCarId;
    car_license_json(true, 'Car license saved.', ['redirect' => '/php/Customer/car_license.php?car_id=' . $savedCarId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ([$newFrontImagePath ?? null, $newBackImagePath ?? null] as $newImagePath) {
        if (is_string($newImagePath) && strncmp($newImagePath, '/php/Customer/uploads/car-licenses/', strlen('/php/Customer/uploads/car-licenses/')) === 0) {
            $localPath = __DIR__ . substr($newImagePath, strlen('/php/Customer'));
            if (is_file($localPath)) {
                @unlink($localPath);
            }
        }
    }
    error_log('Car license save failed: ' . $e->getMessage());
    car_license_json(false, 'Could not save your car license: ' . $e->getMessage());
}

