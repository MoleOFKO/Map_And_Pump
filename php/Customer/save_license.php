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

function license_face_image_column_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_license'
               AND COLUMN_NAME = 'face_image'"
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            return $ready = true;
        }

        $pdo->exec("ALTER TABLE user_license ADD COLUMN face_image varchar(255) NULL AFTER license_image");
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Could not prepare user_license.face_image column: ' . $e->getMessage());
        return $ready = false;
    }
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
$licenseImageData = trim((string) ($_POST['license_image_data'] ?? ''));

if (!preg_match('/^([LABCDET])\/(\d{5})\/(\d{2})$/', $licenseNumber, $licenseMatches)) {
    license_json(false, 'License number must use the format type/5 digits/year, for example L/12345/26.');
}

if ((int) $licenseMatches[3] > (int) date('y')) {
    license_json(false, 'License year cannot be later than the current year.');
}

if ($licenseName === '' || strlen($licenseName) > 255) {
    license_json(false, 'Please enter the name shown on the license.');
}

if (!preg_match('/^(09|\+959)\d{1,9}$/', $phoneNumber)) {
    license_json(false, 'Phone number must start with 09 or +959, followed by up to 9 digits.');
}

if (!preg_match('/^(1[0-4]|[1-9])\/[A-Z]{3,9}\([NEPT]\)\d{6}$/', $nrcNumber)) {
    license_json(false, 'NRC number must use the format 12/TOWNSHIP(N)123456.');
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
    $hasFaceImageColumn = license_face_image_column_ready($pdo);
    if (!$hasFaceImageColumn) {
        license_json(false, 'Database needs the new face_image column. Please import the updated db.sql first.');
    }

    $userStmt = $pdo->prepare(
        'SELECT u.license_id, l.*
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
    $licensePublicPath = (string) ($existingLicense['license_image'] ?? '');
    $hasLicenseUpload = isset($_FILES['license_image']) && is_uploaded_file($_FILES['license_image']['tmp_name']);

    if (!$existingLicenseId && !$hasLicenseUpload) {
        license_json(false, 'Please upload a driver license image.');
    }

    if ($hasLicenseUpload) {
        $licenseUpload = $_FILES['license_image'];
        if (($licenseUpload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            license_json(false, 'Could not read the uploaded driver license image.');
        }

        if (($licenseUpload['size'] ?? 0) > 5 * 1024 * 1024) {
            license_json(false, 'Driver license image must be 5 MB or smaller.');
        }

        $licenseMime = null;
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $licenseMime = $finfo->file($licenseUpload['tmp_name']);
        } elseif (function_exists('mime_content_type')) {
            $licenseMime = mime_content_type($licenseUpload['tmp_name']);
        }

        if ($licenseMime === null && function_exists('exif_imagetype')) {
            $imageType = exif_imagetype($licenseUpload['tmp_name']);
            $mimeMap = [
                IMAGETYPE_JPEG => 'image/jpeg',
                IMAGETYPE_PNG => 'image/png',
                IMAGETYPE_WEBP => 'image/webp',
            ];
            $licenseMime = $mimeMap[$imageType] ?? null;
        }

        if ($licenseMime === null) {
            $fileExt = strtolower(pathinfo((string) ($licenseUpload['name'] ?? ''), PATHINFO_EXTENSION));
            $extensionMap = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'webp' => 'image/webp',
            ];
            $licenseMime = $extensionMap[$fileExt] ?? null;
        }

        $licenseExtensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($licenseExtensions[$licenseMime])) {
            license_json(false, 'Driver license image must be JPG, PNG, or WEBP.');
        }
    }

    if ($licenseImageData === '') {
        license_json(false, 'Please take a real-time face photo with the camera.');
    }

    if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+\/=]+)$/', $licenseImageData, $matches)) {
        license_json(false, 'Face photo must come from the live camera capture.');
    }

    $imageBytes = base64_decode($matches[2], true);
    if ($imageBytes === false || strlen($imageBytes) === 0) {
        license_json(false, 'Could not read the captured face photo.');
    }

    if (strlen($imageBytes) > 5 * 1024 * 1024) {
        license_json(false, 'Captured face photo must be 5 MB or smaller.');
    }

    $imageInfo = @getimagesizefromstring($imageBytes);
    $mime = $imageInfo['mime'] ?? '';
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mime])) {
        license_json(false, 'Captured face photo must be JPG, PNG, or WEBP.');
    }

    $uploadDir = __DIR__ . '/uploads/licenses';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        license_json(false, 'Could not prepare the license upload folder.');
    }

    if ($hasLicenseUpload) {
        $licenseFileName = 'license-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $licenseExtensions[$licenseMime];
        $licenseTargetPath = $uploadDir . '/' . $licenseFileName;
        $licensePublicPath = '/php/Customer/uploads/licenses/' . $licenseFileName;
    }

    $faceFileName = 'license-face-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    $faceTargetPath = $uploadDir . '/' . $faceFileName;
    $facePublicPath = '/php/Customer/uploads/licenses/' . $faceFileName;

    $pdo->beginTransaction();

    if ($hasLicenseUpload && !move_uploaded_file($licenseUpload['tmp_name'], $licenseTargetPath)) {
        throw new RuntimeException('Could not move the uploaded driver license image. Check the upload folder permission.');
    }

    if (file_put_contents($faceTargetPath, $imageBytes) === false) {
        throw new RuntimeException('Could not save the captured face photo. Check the upload folder permission.');
    }

    if ($existingLicenseId) {
        $statusSql = $hasStatusColumn ? ', status = ?' : '';
        $updateLicense = $pdo->prepare(
            "UPDATE user_license
             SET license_image = ?, face_image = ?, license_number = ?, license_name = ?, phone_number = ?, nrc_number = ?,
                 date_of_birth = ?, blood_type = ?, license_last_valid_date = ?{$statusSql}, updated_at = NOW()
             WHERE license_id = ?"
        );
        $updateParams = [
            $licensePublicPath,
            $facePublicPath,
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
                (license_image, face_image, license_number, license_name, phone_number, nrc_number, date_of_birth, blood_type, license_last_valid_date{$statusColumnSql}, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?{$statusValueSql}, NOW(), NOW())"
        );
        $insertParams = [
            $licensePublicPath,
            $facePublicPath,
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
            $idLookup->execute([$licensePublicPath]);
            $licenseId = (int) $idLookup->fetchColumn();
        }

        if ($licenseId <= 0) {
            throw new RuntimeException('License record was created but its ID could not be read.');
        }

        $update = $pdo->prepare("UPDATE users SET license_id = ?, status = 'pending_verification', updated_at = NOW() WHERE user_id = ?");
        $update->execute([$licenseId, $userId]);
    }

    $markPending = $pdo->prepare("UPDATE users SET status = 'pending_verification', updated_at = NOW() WHERE user_id = ?");
    $markPending->execute([$userId]);

    $pdo->commit();
    $_SESSION['license_id'] = $licenseId;
    $_SESSION['license_status'] = 'pending';
    license_json(true, 'Driver license saved. Please wait for Moderator confirmation.', ['redirect' => '../Customer/license.php']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (isset($licenseTargetPath) && is_file($licenseTargetPath)) {
        @unlink($licenseTargetPath);
    }
    if (isset($faceTargetPath) && is_file($faceTargetPath)) {
        @unlink($faceTargetPath);
    }
    $message = $e->getMessage();
    error_log('License save failed: ' . $message);
    license_json(false, 'Could not save your driver license: ' . $message);
}
