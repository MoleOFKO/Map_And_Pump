<?php
require_once __DIR__ . '/hq_account_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect();
}

$hqName = trim((string) ($_POST['hq_name'] ?? ''));
$ownerName = trim((string) ($_POST['owner_name'] ?? ''));
$ownerEmail = strtolower(trim((string) ($_POST['owner_email'] ?? '')));
$region = strtolower(trim((string) ($_POST['region'] ?? '')));
$address = trim((string) ($_POST['address'] ?? ''));
$allowedRegions = ['yangon', 'mandalay', 'naypyidaw'];

if ($hqName === '' || $ownerName === '' || $address === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    hq_flash('danger', 'Please provide a valid HQ name, owner name, email address, and physical address.');
    hq_redirect();
}

if (!in_array($region, $allowedRegions, true)) {
    hq_flash('danger', 'Please select a valid headquarters region.');
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

$emailCheck = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
if (!$emailCheck) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect();
}

$emailCheck->bind_param('s', $ownerEmail);
$emailCheck->execute();
$existingUser = $emailCheck->get_result()?->fetch_assoc();
$emailCheck->close();

if ($existingUser) {
    hq_flash('danger', 'That owner email is already registered. Use a different email address.');
    hq_redirect();
}

$location = ucfirst($region) . ' Region — ' . $address;
$username = hq_unique_username($ownerName, $ownerEmail);
$hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);

try {
    $conn->begin_transaction();

    $hqInsert = $conn->prepare('INSERT INTO headquarter (role_id, company_name, location, created_at) VALUES (?, ?, ?, NOW())');
    if (!$hqInsert) {
        throw new RuntimeException('Could not prepare the HQ insert.');
    }

    $hqInsert->bind_param('iss', $roleId, $hqName, $location);
    if (!$hqInsert->execute()) {
        throw new RuntimeException('Could not create the HQ record.');
    }
    $headquarterId = (int) $hqInsert->insert_id;
    $hqInsert->close();

    $userInsert = $conn->prepare('INSERT INTO users (user_name, email, hashed_password, headquarter_id, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, \'pending\', NOW(), NOW())');
    if (!$userInsert) {
        throw new RuntimeException('Could not prepare the HQ account insert.');
    }

    $userInsert->bind_param('sssii', $username, $ownerEmail, $hashedPassword, $headquarterId, $roleId);
    if (!$userInsert->execute()) {
        throw new RuntimeException('Could not create the HQ user account.');
    }
    $userInsert->close();

    $conn->commit();

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
