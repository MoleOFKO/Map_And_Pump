<?php
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('manage_hq.php');
}

$hqName = trim((string) ($_POST['hq_name'] ?? ''));
$companyLogo = trim((string) ($_POST['company_logo'] ?? ''));
$contactNumber = trim((string) ($_POST['contact_number'] ?? ''));
$companyEmail = strtolower(trim((string) ($_POST['headquarter_email'] ?? '')));
$ownerName = trim((string) ($_POST['owner_name'] ?? ''));
$ownerEmail = strtolower(trim((string) ($_POST['owner_email'] ?? '')));
$region = strtolower(trim((string) ($_POST['region'] ?? '')));
$address = trim((string) ($_POST['address'] ?? ''));
$businessLicense = trim((string) ($_POST['business_license'] ?? ''));
$expiryDate = trim((string) ($_POST['expire_at'] ?? ''));
$allowedRegions = ['yangon', 'mandalay', 'naypyidaw'];

if ($hqName === '' || $companyLogo === '' || $contactNumber === '' || $ownerName === '' || $address === '' || $businessLicense === '' || $expiryDate === '' || !filter_var($companyEmail, FILTER_VALIDATE_EMAIL) || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    hq_flash('danger', 'Please complete the HQ name, company email, logo path/URL, contact number, owner details, address, business license, and expiry date.');
    hq_redirect('manage_hq.php');
}

if (!in_array($region, $allowedRegions, true)) {
    hq_flash('danger', 'Please select a valid headquarters region.');
    hq_redirect('manage_hq.php');
}

$expiry = DateTime::createFromFormat('!Y-m-d', $expiryDate);
$expiryErrors = DateTime::getLastErrors();
if (!$expiry || ($expiryErrors !== false && ($expiryErrors['warning_count'] > 0 || $expiryErrors['error_count'] > 0)) || $expiry->format('Y-m-d') !== $expiryDate || $expiryDate < date('Y-m-d')) {
    hq_flash('danger', 'Please provide a valid HQ expiry date that is today or later.');
    hq_redirect('manage_hq.php');
}

if (strlen($hqName) > 255 || strlen($companyLogo) > 255 || strlen($contactNumber) > 50 || strlen($businessLicense) > 255 || strlen($ownerName) > 225 || strlen($ownerEmail) > 225 || strlen($companyEmail) > 225) {
    hq_flash('danger', 'One or more registration fields are longer than the database allows.');
    hq_redirect('manage_hq.php');
}

if (!hq_db_available() || !hq_registry_schema_ready() || !request_schema_ready()) {
    hq_flash('danger', 'The HQ registration request could not be saved because the database is unavailable.');
    hq_redirect('manage_hq.php');
}

global $conn;
$roleId = hq_find_role_id();
if ($roleId === false) {
    hq_flash('danger', 'The HQ role is not available in the database, so the request was not created.');
    hq_redirect('manage_hq.php');
}

$existingCheck = $conn->prepare(
    'SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)
     UNION ALL
     SELECT 1 FROM headquarter WHERE LOWER(headquarter_email) = LOWER(?)
     LIMIT 1'
);
if (!$existingCheck) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('manage_hq.php');
}
$existingCheck->bind_param('ss', $ownerEmail, $companyEmail);
$existingCheck->execute();
$alreadyRegistered = (bool) $existingCheck->get_result()?->fetch_row();
$existingCheck->close();
if ($alreadyRegistered) {
    hq_flash('danger', 'That owner email or company email is already registered. Use a different address.');
    hq_redirect('manage_hq.php');
}

$pendingCheck = $conn->prepare(
    "SELECT request_id FROM system_requests
     WHERE LOWER(request_type) IN ('hq_registration', 'headquarter_registration')
       AND LOWER(status) = 'pending'
       AND (LOWER(headquarter_email) = LOWER(?) OR LOWER(headquarter_owner_email) = LOWER(?))
     LIMIT 1"
);
if (!$pendingCheck) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('manage_hq.php');
}
$pendingCheck->bind_param('ss', $companyEmail, $ownerEmail);
$pendingCheck->execute();
$alreadyPending = (bool) $pendingCheck->get_result()?->fetch_row();
$pendingCheck->close();
if ($alreadyPending) {
    hq_flash('danger', 'A pending HQ registration already uses that company or owner email.');
    hq_redirect('manage_hq.php');
}

$regionLabel = match ($region) {
    'yangon' => 'Yangon Region',
    'mandalay' => 'Mandalay Region',
    default => 'Naypyidaw',
};
$location = $regionLabel . ' - ' . $address;
if (strlen($location) > 255) {
    hq_flash('danger', 'The region and physical address together must be 255 characters or fewer.');
    hq_redirect('manage_hq.php');
}

$submitter = admin_current_user(false);
$submitterUserId = $submitter ? (int) $submitter['user_id'] : null;
$referenceCode = 'HQ-REG-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
$requestDetails = sprintf(
    "New HQ registration awaiting approval.\nCompany email: %s\nOwner email: %s\nContact: %s\nCompany logo: %s\nLicense expiry: %s",
    $companyEmail,
    $ownerEmail,
    $contactNumber,
    $companyLogo,
    $expiryDate
);
$expireAt = $expiryDate . ' 23:59:59';

$requestInsert = $conn->prepare(
    "INSERT INTO system_requests
        (reference_code, request_type, status, submitter_user_id, role_id,
         sender_entity, sender_name, sender_email, location, request_details,
         document_name, document_path, headquarter_owner_email, headquarter_email,
         headquarter_company_logo, headquarter_contact_number, headquarter_expire_at,
         created_at, updated_at)
     VALUES (?, 'hq_registration', 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
);
if (!$requestInsert) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect('manage_hq.php');
}

$documentName = 'Business license';
$params = [
    $referenceCode, $submitterUserId, $roleId, $hqName, $ownerName, $ownerEmail,
    $location, $requestDetails, $documentName, $businessLicense, $ownerEmail,
    $companyEmail, $companyLogo, $contactNumber, $expireAt,
];
$types = 'sii' . str_repeat('s', 12);
if (!hq_bind($requestInsert, $types, $params) || !$requestInsert->execute()) {
    $error = $requestInsert->error;
    $requestInsert->close();
    error_log('[HQ registry] Pending registration request failed: ' . $error);
    hq_flash('danger', 'The HQ registration request could not be saved. No HQ or user account was created.');
    hq_redirect('manage_hq.php');
}
$requestInsert->close();

hq_flash('success', 'HQ registration request submitted. It is pending review; no HQ or HQ user account has been created yet.');
hq_redirect('manage_hq.php');
