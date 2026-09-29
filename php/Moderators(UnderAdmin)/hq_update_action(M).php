<?php
require_once __DIR__ . '/moderator_common.php';

$moderatorUser = moderator_require_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    hq_redirect('view_hq_and_user_account(M).php');
}

$headquarterId = filter_var($_POST['headquarter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
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

$redirect = moderator_return_target('view_hq_and_user_account(M).php');

if (!$headquarterId || $hqName === '' || $companyLogo === '' || $contactNumber === '' || $ownerName === '' || $address === '' || $businessLicense === '' || $expiryDate === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
    hq_flash('danger', 'Please complete every required HQ, owner, contact, address, license, and expiry field.');
    hq_redirect($redirect);
}

if (!in_array($region, $allowedRegions, true)) {
    hq_flash('danger', 'Please select a valid headquarters region.');
    hq_redirect($redirect);
}

$expiry = DateTime::createFromFormat('!Y-m-d', $expiryDate);
$expiryErrors = DateTime::getLastErrors();
if (!$expiry || ($expiryErrors !== false && ($expiryErrors['warning_count'] > 0 || $expiryErrors['error_count'] > 0)) || $expiry->format('Y-m-d') !== $expiryDate || $expiryDate < date('Y-m-d')) {
    hq_flash('danger', 'Please provide a valid HQ expiry date that is today or later.');
    hq_redirect($redirect);
}

if (strlen($hqName) > 255 || strlen($companyLogo) > 255 || strlen($contactNumber) > 50 || strlen($ownerName) > 225 || strlen($ownerEmail) > 225 || strlen($businessLicense) > 255 || strlen($address) > 220) {
    hq_flash('danger', 'One or more fields are longer than the database allows.');
    hq_redirect($redirect);
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    hq_flash('danger', hq_db_error_message());
    hq_redirect($redirect);
}

global $conn;
$targetQuery = $conn->prepare(
    'SELECT h.headquarter_id, h.created_at, owner.user_id AS owner_user_id
     FROM headquarter h
     LEFT JOIN (
         SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id
         FROM users u
         WHERE (u.role_id = 3 OR u.business_role_id = 3)
         GROUP BY headquarter_id
     ) first_owner ON first_owner.headquarter_id = h.headquarter_id
     LEFT JOIN users owner ON owner.user_id = first_owner.owner_user_id
     WHERE h.headquarter_id = ?
     LIMIT 1'
);
if (!$targetQuery) {
    hq_flash('danger', 'The HQ could not be loaded for editing.');
    hq_redirect($redirect);
}
$targetQuery->bind_param('i', $headquarterId);
$targetQuery->execute();
$target = $targetQuery->get_result()?->fetch_assoc();
$targetQuery->close();

if (!$target) {
    hq_flash('danger', 'The selected HQ account could not be found.');
    hq_redirect($redirect);
}

$registeredDate = substr((string) ($target['created_at'] ?? ''), 0, 10);
if ($registeredDate !== '' && $expiryDate <= $registeredDate) {
    hq_flash('danger', 'The license expiry date must be after the HQ registration date.');
    hq_redirect($redirect);
}

$ownerUserId = $target['owner_user_id'] !== null ? (int) $target['owner_user_id'] : 0;
$emailCheck = $conn->prepare(
    'SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) AND user_id <> ?
     UNION ALL
     SELECT headquarter_id FROM headquarter WHERE LOWER(headquarter_email) = LOWER(?) AND headquarter_id <> ?
     LIMIT 1'
);
if (!$emailCheck) {
    hq_flash('danger', 'The owner email could not be checked.');
    hq_redirect($redirect);
}
$emailCheck->bind_param('sisi', $ownerEmail, $ownerUserId, $ownerEmail, $headquarterId);
$emailCheck->execute();
$duplicate = $emailCheck->get_result()?->fetch_assoc();
$emailCheck->close();
if ($duplicate) {
    hq_flash('danger', 'That owner email is already registered to another account or HQ.');
    hq_redirect($redirect);
}

$location = ucfirst($region) . ' Region — ' . $address;
if (strlen($location) > 255) {
    hq_flash('danger', 'The region and physical address together must be 255 characters or fewer.');
    hq_redirect($redirect);
}
$expireAt = $expiryDate . ' 23:59:59';

try {
    $conn->begin_transaction();

    $hqUpdate = $conn->prepare(
        'UPDATE headquarter
         SET company_name = ?, company_logo = ?, contact_number = ?, headquarter_email = ?,
             location = ?, business_license = ?, expire_at = ?
         WHERE headquarter_id = ?'
    );
    if (!$hqUpdate) {
        throw new RuntimeException('Could not prepare the HQ update.');
    }
    $hqUpdate->bind_param('sssssssi', $hqName, $companyLogo, $contactNumber, $ownerEmail, $location, $businessLicense, $expireAt, $headquarterId);
    if (!$hqUpdate->execute()) {
        throw new RuntimeException('Could not update the HQ record.');
    }
    $hqUpdate->close();

    if ($ownerUserId > 0) {
        $ownerUpdate = $conn->prepare('UPDATE users SET user_name = ?, email = ?, updated_at = NOW() WHERE user_id = ?');
        if (!$ownerUpdate) {
            throw new RuntimeException('Could not prepare the owner update.');
        }
        $ownerUpdate->bind_param('ssi', $ownerName, $ownerEmail, $ownerUserId);
        if (!$ownerUpdate->execute()) {
            throw new RuntimeException('Could not update the HQ owner account.');
        }
        $ownerUpdate->close();
    }

    admin_record_log((int) $moderatorUser['user_id'], 'Updated HQ #' . $headquarterId . ' (' . $hqName . ').');
    $conn->commit();
    hq_flash('success', 'HQ details and owner account were updated successfully.');
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Moderator HQ] Update failed: ' . $exception->getMessage());
    hq_flash('danger', 'The HQ could not be updated. No partial changes were saved.');
}

hq_redirect($redirect);
