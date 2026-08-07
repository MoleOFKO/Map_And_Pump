<?php
require_once __DIR__ . '/hq_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect();
}

$hqName = trim((string) ($_POST['hq_name'] ?? ''));
$companyLogo = trim((string) ($_POST['company_logo'] ?? ''));
$contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
$ownerName = trim((string) ($_POST['owner_name'] ?? ''));
$ownerEmail = strtolower(trim((string) ($_POST['owner_email'] ?? '')));
$region = strtolower(trim((string) ($_POST['region'] ?? '')));
$address = trim((string) ($_POST['address'] ?? ''));
$businessLicense = trim((string) ($_POST['business_license'] ?? ''));
$expiryDate = trim((string) ($_POST['expire_at'] ?? ''));
$allowedRegions = ['yangon', 'mandalay', 'naypyidaw'];

if ($hqName === '' || $companyLogo === '' || $contactNumber === '' || $ownerName === '' || $address === '' || $businessLicense === '' || $expiryDate === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    hq_flash('danger', 'Please complete the HQ name, logo path/URL, contact number, owner details, address, business license, and expiry date.');
    hq_redirect();
}

if (!in_array($region, $allowedRegions, true)) {
    hq_flash('danger', 'Please select a valid headquarters region.');
    hq_redirect();
}

$expiry = DateTime::createFromFormat('!Y-m-d', $expiryDate);
$expiryErrors = DateTime::getLastErrors();
if (!$expiry || ($expiryErrors !== false && ($expiryErrors['warning_count'] > 0 || $expiryErrors['error_count'] > 0)) || $expiry->format('Y-m-d') !== $expiryDate || $expiryDate < date('Y-m-d')) {
    hq_flash('danger', 'Please provide a valid HQ expiry date that is today or later.');
    hq_redirect();
}

if (strlen($hqName) > 255 || strlen($companyLogo) > 255 || strlen($contactNumber) > 50 || strlen($businessLicense) > 255 || strlen($ownerName) > 225 || strlen($ownerEmail) > 225) {
    hq_flash('danger', 'One or more registration fields are longer than the database allows.');
    hq_redirect();
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect();
}

global $conn;
$temporaryPassword = bin2hex(random_bytes(5));
$roleId = hq_find_role_id();

if ($roleId === false) {
    hq_flash('danger', 'The HQ role is not available in the database, so the account was not created.');
    hq_redirect();
}

$emailCheck = $conn->prepare(
    'SELECT user_id AS existing_id FROM users WHERE email = ?
     UNION ALL
     SELECT headquarter_id AS existing_id FROM headquarter WHERE headquarter_email = ?
     LIMIT 1'
);
if (!$emailCheck) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect();
}

$emailCheck->bind_param('ss', $ownerEmail, $ownerEmail);
if (!$emailCheck->execute()) {
    $error = $emailCheck->error;
    $emailCheck->close();
    error_log('[HQ registry] Owner email check failed: ' . $error);
    hq_flash('danger', 'The owner email could not be checked against the database.');
    hq_redirect();
}
$existingUser = $emailCheck->get_result()?->fetch_assoc();
$emailCheck->close();

if ($existingUser) {
    hq_flash('danger', 'That owner email is already registered. Use a different email address.');
    hq_redirect();
}

$logoCheck = $conn->prepare('SELECT headquarter_id FROM headquarter WHERE company_logo = ? LIMIT 1');
if (!$logoCheck) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect();
}
$logoCheck->bind_param('s', $companyLogo);
if (!$logoCheck->execute()) {
    $error = $logoCheck->error;
    $logoCheck->close();
    error_log('[HQ registry] Company logo check failed: ' . $error);
    hq_flash('danger', 'The company logo path could not be checked against the database.');
    hq_redirect();
}
$existingLogo = $logoCheck->get_result()?->fetch_assoc();
$logoCheck->close();

if ($existingLogo) {
    hq_flash('danger', 'That company logo URL or storage path is already registered. Use a different value.');
    hq_redirect();
}

$location = ucfirst($region) . ' Region — ' . $address;
if (strlen($location) > 255) {
    hq_flash('danger', 'The region and physical address together must be 255 characters or fewer.');
    hq_redirect();
}
$expireAt = $expiryDate . ' 23:59:59';
$username = hq_unique_username($ownerName, $ownerEmail);
$hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);

try {
    $conn->begin_transaction();

    $hqInsert = $conn->prepare('INSERT INTO headquarter (company_name, company_logo, contact_number, headquarter_email, location, business_license, created_at, expire_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)');
    if (!$hqInsert) {
        throw new RuntimeException('Could not prepare the HQ insert: ' . $conn->error);
    }

    $hqInsert->bind_param('sssssss', $hqName, $companyLogo, $contactNumber, $ownerEmail, $location, $businessLicense, $expireAt);
    if (!$hqInsert->execute()) {
        throw new RuntimeException('Could not create the HQ record: ' . $hqInsert->error);
    }
    $headquarterId = (int) $hqInsert->insert_id;
    $hqInsert->close();

    $userInsert = $conn->prepare('INSERT INTO users (user_name, email, hashed_password, headquarter_id, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, \'pending\', NOW(), NOW())');
    if (!$userInsert) {
        throw new RuntimeException('Could not prepare the HQ account insert: ' . $conn->error);
    }

    $userInsert->bind_param('sssii', $username, $ownerEmail, $hashedPassword, $headquarterId, $roleId);
    if (!$userInsert->execute()) {
        throw new RuntimeException('Could not create the HQ user account: ' . $userInsert->error);
    }
    $userInsert->close();

    if (!$conn->commit()) {
        throw new RuntimeException('Could not commit the HQ registration: ' . $conn->error);
    }

    hq_flash(
        'success',
        'HQ and user account created successfully. The account is pending verification.',
        [
            'username' => $username,
            'temporary_password' => $temporaryPassword,
            'email' => $ownerEmail,
        ]
    );
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[HQ registry] Registration failed: ' . $exception->getMessage());
    hq_flash('danger', 'The HQ account could not be created. No partial record was saved.');
}

hq_redirect();
