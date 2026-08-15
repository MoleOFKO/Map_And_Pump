<?php
session_start();
ini_set('display_errors', '0');

define('MAP_PUMP_JSON_RESPONSE', true);
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/license_access.php';

header('Content-Type: application/json; charset=utf-8');

function license_json(bool $ok, string $message, array $extra = []): never
{
    echo json_encode(['ok' => $ok, 'message' => $message] + $extra);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    license_json(false, 'License request is invalid.');
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    license_json(false, 'Please sign in before adding your license.', ['redirect' => '/php/Login/login.php']);
}

$licenseNumber = strtoupper(trim((string) ($_POST['license_number'] ?? '')));
$licenseName = trim((string) ($_POST['license_name'] ?? ''));
$phoneNumber = trim((string) ($_POST['phone_number'] ?? ''));
$nrcNumber = strtoupper(trim((string) ($_POST['nrc_number'] ?? '')));
$dateOfBirth = trim((string) ($_POST['date_of_birth'] ?? ''));
$bloodType = strtoupper(trim((string) ($_POST['blood_type'] ?? '')));
$validUntil = trim((string) ($_POST['license_last_valid_date'] ?? ''));

if ($licenseNumber === '' || strlen($licenseNumber) > 100) {
    license_json(false, 'Please enter a valid license number.');
}

if ($licenseName === '' || strlen($licenseName) > 255) {
    license_json(false, 'Please enter the name shown on the license.');
}

if ($phoneNumber === '' || strlen($phoneNumber) > 50) {
    license_json(false, 'Please enter a valid phone number.');
}

if ($nrcNumber === '' || strlen($nrcNumber) > 100) {
    license_json(false, 'Please enter a valid NRC number.');
}

$birthDate = DateTime::createFromFormat('Y-m-d', $dateOfBirth);
$expiryDate = DateTime::createFromFormat('Y-m-d', $validUntil);
if (!$birthDate || $birthDate->format('Y-m-d') !== $dateOfBirth) {
    license_json(false, 'Please enter a valid date of birth.');
}

if (!$expiryDate || $expiryDate->format('Y-m-d') !== $validUntil) {
    license_json(false, 'Please enter a valid license expiry date.');
}

if ($birthDate >= new DateTime('today')) {
    license_json(false, 'Date of birth must be in the past.');
}

if ($expiryDate < new DateTime('today')) {
    license_json(false, 'Your driver license must still be valid.');
}

if ($bloodType === '' || strlen($bloodType) > 10) {
    license_json(false, 'Please select a blood type.');
}

try {
    $hasStatusColumn = customer_license_status_column_ready($pdo);
    $userStmt = $pdo->prepare(
        'SELECT u.license_id, l.license_image
         FROM users u
         LEFT JOIN user_license l ON l.license_id = u.license_id
         WHERE u.user_id = ?
         LIMIT 1'
    );
    $userStmt->execute([$userId]);
    $existingLicense = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$existingLicense) {
        license_json(false, 'Your customer account could not be found. Please log in again.', ['redirect' => '/php/Login/login.php']);
    }

    $existingLicenseId = (int) ($existingLicense['license_id'] ?? 0);
    $publicPath = (string) ($existingLicense['license_image'] ?? '');
    $hasUpload = isset($_FILES['license_image']) && is_uploaded_file($_FILES['license_image']['tmp_name']);

    if (!$existingLicenseId && !$hasUpload) {
        license_json(false, 'Please upload a driver license image.');
    }

    if ($hasUpload) {
        $image = $_FILES['license_image'];
        if (($image['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            license_json(false, 'Could not read the uploaded license image.');
        }

        if (($image['size'] ?? 0) > 5 * 1024 * 1024) {
            license_json(false, 'License image must be 5 MB or smaller.');
        }

        $mime = null;
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($image['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $mime = mime_content_type($image['tmp_name']);
        }

        if ($mime === null && function_exists('exif_imagetype')) {
            $imageType = exif_imagetype($image['tmp_name']);
            $mimeMap = [
                IMAGETYPE_JPEG => 'image/jpeg',
                IMAGETYPE_PNG => 'image/png',
                IMAGETYPE_WEBP => 'image/webp',
            ];
            $mime = $mimeMap[$imageType] ?? null;
        }

        if ($mime === null) {
            $fileExt = strtolower(pathinfo((string) ($image['name'] ?? ''), PATHINFO_EXTENSION));
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
            license_json(false, 'License image must be JPG, PNG, or WEBP.');
        }

        $uploadDir = __DIR__ . '/uploads/licenses';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            license_json(false, 'Could not prepare the license upload folder.');
        }

        $fileName = 'license-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
        $targetPath = $uploadDir . '/' . $fileName;
        $publicPath = '/php/Customer/uploads/licenses/' . $fileName;
    }

    $pdo->beginTransaction();

    if ($hasUpload && !move_uploaded_file($image['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not move the uploaded license image. Check the upload folder permission.');
    }

    if ($existingLicenseId) {
        $statusSql = $hasStatusColumn ? ', status = ?' : '';
        $updateLicense = $pdo->prepare(
            "UPDATE user_license
             SET license_image = ?, license_number = ?, license_name = ?, phone_number = ?, nrc_number = ?,
                 date_of_birth = ?, blood_type = ?, license_last_valid_date = ?{$statusSql}, updated_at = NOW()
             WHERE license_id = ?"
        );
        $updateParams = [
            $publicPath,
            $licenseNumber,
            $licenseName,
            $phoneNumber,
            $nrcNumber,
            $dateOfBirth,
            $bloodType,
            $validUntil,
        ];
        if ($hasStatusColumn) {
            $updateParams[] = 'pending';
        }
        $updateParams[] = $existingLicenseId;
        $updateLicense->execute($updateParams);
        $licenseId = $existingLicenseId;
    } else {
        $statusColumnSql = $hasStatusColumn ? ', status' : '';
        $statusValueSql = $hasStatusColumn ? ', ?' : '';
        $insert = $pdo->prepare(
            "INSERT INTO user_license
                (license_image, license_number, license_name, phone_number, nrc_number, date_of_birth, blood_type, license_last_valid_date{$statusColumnSql}, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?{$statusValueSql}, NOW(), NOW())"
        );
        $insertParams = [
            $publicPath,
            $licenseNumber,
            $licenseName,
            $phoneNumber,
            $nrcNumber,
            $dateOfBirth,
            $bloodType,
            $validUntil,
        ];
        if ($hasStatusColumn) {
            $insertParams[] = 'pending';
        }
        $insert->execute($insertParams);

        $licenseId = (int) $pdo->lastInsertId();
        if ($licenseId <= 0) {
            $idLookup = $pdo->prepare('SELECT license_id FROM user_license WHERE license_image = ? ORDER BY license_id DESC LIMIT 1');
            $idLookup->execute([$publicPath]);
            $licenseId = (int) $idLookup->fetchColumn();
        }

        if ($licenseId <= 0) {
            throw new RuntimeException('License record was created but its ID could not be read.');
        }

        $update = $pdo->prepare('UPDATE users SET license_id = ?, updated_at = NOW() WHERE user_id = ?');
        $update->execute([$licenseId, $userId]);
    }

    $pdo->commit();
    $_SESSION['license_id'] = $licenseId;
    $_SESSION['license_status'] = 'pending';
    license_json(true, 'Driver license saved. Please wait for Moderator confirmation.', ['redirect' => '/php/Customer/license.php']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($targetPath) && is_file($targetPath)) {
        @unlink($targetPath);
    }
    $message = $e->getMessage();
    error_log('License save failed: ' . $message);
    license_json(false, 'Could not save your driver license: ' . $message);
}
