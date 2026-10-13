<?php
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$reviewer = admin_require_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    request_redirect();
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));

if (!$requestId || !in_array($action, ['approve', 'reject', 'flag'], true)) {
    request_flash('danger', 'The request action was invalid.');
    request_redirect();
}
if (($action === 'reject' || $action === 'flag') && $reason === '') {
    request_flash('danger', $action === 'flag' ? 'A flag reason is required.' : 'A rejection reason is required.');
    request_redirect();
}
if (mb_strlen($reason) > 2000) {
    request_flash('danger', 'The reason must be 2,000 characters or fewer.');
    request_redirect();
}
if (!hq_db_available() || !request_schema_ready()) {
    request_flash('danger', 'The request database is temporarily unavailable.');
    request_redirect();
}

global $conn;

try {
    $conn->begin_transaction();

    $targetQuery = $conn->prepare(
        'SELECT r.*, u.user_id AS existing_user_id, u.email AS user_email
         FROM system_requests r
         LEFT JOIN users u ON u.user_id = r.submitter_user_id
         WHERE r.request_id = ?
         FOR UPDATE'
    );
    if (!$targetQuery) {
        throw new RuntimeException('Could not prepare the request lookup.');
    }
    $targetQuery->bind_param('i', $requestId);
    $targetQuery->execute();
    $target = $targetQuery->get_result()?->fetch_assoc();
    $targetQuery->close();

    if (!$target) {
        throw new RuntimeException('That request could not be found.');
    }
    if (strtolower((string) $target['status']) !== 'pending') {
        throw new RuntimeException('Only pending requests can be approved, rejected, or flagged.');
    }
    if (strtolower((string) $target['request_type']) === 'hq_registration' && !admin_is_admin($reviewer)) {
        throw new RuntimeException('Only an Admin can approve, reject, or flag an HQ registration request.');
    }
    if (strtolower((string) $target['request_type']) === 'license_modification' && !admin_is_admin($reviewer)) {
        throw new RuntimeException('Only an Admin can approve, reject, or flag an HQ license renewal request.');
    }

    // Flag keeps the request open for follow-up without approving or rejecting.
    if ($action === 'flag') {
        $reviewerId = (int) $reviewer['user_id'];
        $flagUpdate = $conn->prepare(
            "UPDATE system_requests
             SET status = 'flagged', review_reason = ?, reviewed_at = NOW(), reviewed_by = ?, updated_at = NOW()
             WHERE request_id = ? AND status = 'pending'"
        );
        if (!$flagUpdate) {
            throw new RuntimeException('Could not prepare the flag update.');
        }
        $flagUpdate->bind_param('sii', $reason, $reviewerId, $requestId);
        if (!$flagUpdate->execute() || $flagUpdate->affected_rows !== 1) {
            throw new RuntimeException('The request was already changed or could not be flagged.');
        }
        $flagUpdate->close();
        $conn->commit();
        admin_record_log(
            (int) $reviewer['user_id'],
            'Flagged request #' . $requestId . ' (' . $target['reference_code'] . ').'
        );
        request_flash('success', sprintf('Request %s was flagged for follow-up.', $target['reference_code']));
        request_redirect();
    }

    $createdHeadquarterId = null;
    $createdOwnerUserId = null;
    $createdOwnerEmail = null;
    $requestType = strtolower((string) $target['request_type']);
    $renewalDetails = [];
    if ($requestType === 'license_modification') {
        $renewalDetails = json_decode((string) ($target['request_details'] ?? ''), true);
        if (!is_array($renewalDetails)) {
            throw new RuntimeException('The license renewal details are invalid.');
        }
        $renewalLicense = trim((string) ($renewalDetails['renewal_license_number'] ?? ''));
        $renewalExpiry = trim((string) ($target['headquarter_expire_at'] ?? ''));
        $renewalExpiryDate = substr($renewalExpiry, 0, 10);
        $parsedRenewalExpiry = DateTime::createFromFormat('!Y-m-d', $renewalExpiryDate);
        $renewalExpiryErrors = DateTime::getLastErrors();
        if ($renewalLicense === '' || strlen($renewalLicense) > 255 || !$parsedRenewalExpiry
            || ($renewalExpiryErrors !== false && ($renewalExpiryErrors['warning_count'] > 0 || $renewalExpiryErrors['error_count'] > 0))
            || $parsedRenewalExpiry->format('Y-m-d') !== $renewalExpiryDate) {
            throw new RuntimeException('The submitted license number or expiry date is invalid.');
        }
        if (empty($target['headquarter_id'])) {
            throw new RuntimeException('The license renewal is not linked to an HQ account.');
        }
        if ($action === 'approve') {
            $expiryTimestamp = $renewalExpiryDate . ' 23:59:59';
            $hqLicenseUpdate = $conn->prepare(
                'UPDATE headquarter
                 SET business_license = ?, business_license_expire_date = ?, expire_at = ?
                 WHERE headquarter_id = ?'
            );
            if (!$hqLicenseUpdate) {
                throw new RuntimeException('Could not prepare the HQ license update.');
            }
            $headquarterId = (int) $target['headquarter_id'];
            $hqLicenseUpdate->bind_param('sssi', $renewalLicense, $renewalExpiryDate, $expiryTimestamp, $headquarterId);
            if (!$hqLicenseUpdate->execute()) {
                throw new RuntimeException('Could not apply the approved HQ license renewal.');
            }
            $hqLicenseUpdate->close();
        }
    }

    if ($action === 'approve' && strtolower((string) $target['request_type']) === 'hq_registration' && empty($target['headquarter_id'])) {
        $details = json_decode((string) ($target['request_details'] ?? ''), true);
        if (!is_array($details)) {
            throw new RuntimeException('The company registration details are invalid.');
        }

        $headquarterInsert = $conn->prepare(
            'INSERT INTO headquarter
                (company_name, company_logo, contact_number, headquarter_email, location,
                 business_license, business_license_image, business_license_expire_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$headquarterInsert) {
            throw new RuntimeException('Could not prepare the company account creation.');
        }
        $licenseExpiry = trim((string) ($target['headquarter_expire_at'] ?? ''));
        $licenseExpiry = $licenseExpiry !== '' ? substr($licenseExpiry, 0, 10) : null;
        $companyName = (string) $target['sender_entity'];
        $companyLogo = (string) $target['headquarter_company_logo'];
        $companyPhone = (string) $target['headquarter_contact_number'];
        $companyEmail = (string) $target['headquarter_email'];
        $companyLocation = (string) $target['location'];
        $businessLicense = (string) $target['document_name'];
        $licenseImage = (string) $target['document_path'];
        $headquarterInsert->bind_param(
            'ssssssss',
            $companyName,
            $companyLogo,
            $companyPhone,
            $companyEmail,
            $companyLocation,
            $businessLicense,
            $licenseImage,
            $licenseExpiry
        );
        if (!$headquarterInsert->execute()) {
            throw new RuntimeException('Could not create the approved company account.');
        }
        $createdHeadquarterId = (int) $conn->insert_id;
        $headquarterInsert->close();

        // A public registration has no submitter account.  Ensure every
        // approved HQ still receives a linked, role-3 login owner.
        $hqRoleId = hq_hq_role_id();
        if ($hqRoleId <= 0) {
            throw new RuntimeException('Could not identify the HQ account role.');
        }
        $ownerEmail = strtolower(trim((string) ($target['headquarter_owner_email'] ?: $target['sender_email'])));
        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The approved HQ has no valid owner email address.');
        }
        $ownerName = trim((string) ($target['sender_name'] ?: $target['sender_entity']));
        $ownerName = $ownerName !== '' ? $ownerName : $companyName;
        $ownerLookup = $conn->prepare('SELECT user_id FROM users WHERE email = ? FOR UPDATE');
        if (!$ownerLookup) {
            throw new RuntimeException('Could not look up the HQ owner account.');
        }
        $ownerLookup->bind_param('s', $ownerEmail);
        $ownerLookup->execute();
        $existingOwner = $ownerLookup->get_result()?->fetch_assoc();
        $ownerLookup->close();

        if ($existingOwner) {
            $createdOwnerUserId = (int) $existingOwner['user_id'];
            $ownerUpdate = $conn->prepare(
                'UPDATE users SET headquarter_id = ?, role_id = ?, business_role_id = ?, current_role_id = ?, status = \'approved\', updated_at = NOW() WHERE user_id = ?'
            );
            if (!$ownerUpdate) {
                throw new RuntimeException('Could not link the existing HQ owner account.');
            }
            $ownerUpdate->bind_param('iiiii', $createdHeadquarterId, $hqRoleId, $hqRoleId, $hqRoleId, $createdOwnerUserId);
            if (!$ownerUpdate->execute()) {
                throw new RuntimeException('Could not link the existing HQ owner account.');
            }
            $ownerUpdate->close();
        } else {
            $ownerUserName = hq_unique_username($ownerName, $ownerEmail);
            $oneTimeSecret = bin2hex(random_bytes(24));
            $passwordHash = password_hash($oneTimeSecret, PASSWORD_DEFAULT);
            $ownerInsert = $conn->prepare(
                "INSERT INTO users (user_name, email, hashed_password, headquarter_id, role_id, business_role_id, current_role_id, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'approved', NOW(), NOW())"
            );
            if (!$ownerInsert) {
                throw new RuntimeException('Could not prepare the HQ owner account.');
            }
            $ownerInsert->bind_param('sssiiii', $ownerUserName, $ownerEmail, $passwordHash, $createdHeadquarterId, $hqRoleId, $hqRoleId, $hqRoleId);
            if (!$ownerInsert->execute()) {
                throw new RuntimeException('Could not create the HQ owner account.');
            }
            $createdOwnerUserId = (int) $ownerInsert->insert_id;
            $ownerInsert->close();
        }
        $createdOwnerEmail = $ownerEmail;

        $hqOwnerUpdate = $conn->prepare('UPDATE headquarter SET headquarters_owner_id = ? WHERE headquarter_id = ?');
        if (!$hqOwnerUpdate) {
            throw new RuntimeException('Could not link the HQ owner.');
        }
        $hqOwnerUpdate->bind_param('ii', $createdOwnerUserId, $createdHeadquarterId);
        if (!$hqOwnerUpdate->execute()) {
            throw new RuntimeException('Could not link the HQ owner.');
        }
        $hqOwnerUpdate->close();

        $requestHqUpdate = $conn->prepare('UPDATE system_requests SET headquarter_id = ? WHERE request_id = ?');
        if (!$requestHqUpdate) {
            throw new RuntimeException('Could not link the approved company request.');
        }
        $requestHqUpdate->bind_param('ii', $createdHeadquarterId, $requestId);
        if (!$requestHqUpdate->execute()) {
            throw new RuntimeException('Could not link the approved company request.');
        }
        $requestHqUpdate->close();

        $receiptUpdate = $conn->prepare(
            "UPDATE hq_receipt SET hq_id = ? WHERE request_id = ? AND hq_id IS NULL"
        );
        if (!$receiptUpdate) {
            throw new RuntimeException('Could not link the registration receipt.');
        }
        $receiptUpdate->bind_param('ii', $createdHeadquarterId, $requestId);
        if (!$receiptUpdate->execute()) {
            throw new RuntimeException('Could not link the registration receipt.');
        }
        $receiptUpdate->close();
    }

    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $reviewReason = $action === 'approve' ? 'Approved by administrator.' : $reason;
    $reviewerId = (int) $reviewer['user_id'];
    $recipientUserId = $createdOwnerUserId ?? (!empty($target['existing_user_id']) ? (int) $target['existing_user_id'] : null);
    $recipientEmail = $createdOwnerEmail ?? trim((string) ($target['user_email'] ?: $target['sender_email']));

    $requestUpdate = $conn->prepare(
        "UPDATE system_requests
         SET status = ?, review_reason = ?, reviewed_at = NOW(), reviewed_by = ?, updated_at = NOW()
         WHERE request_id = ? AND status = 'pending'"
    );
    if (!$requestUpdate) {
        throw new RuntimeException('Could not prepare the request update.');
    }
    $requestUpdate->bind_param('ssii', $newStatus, $reviewReason, $reviewerId, $requestId);
    if (!$requestUpdate->execute() || $requestUpdate->affected_rows !== 1) {
        throw new RuntimeException('The request was already changed or could not be updated.');
    }
    $requestUpdate->close();

    if ($recipientUserId !== null && in_array($requestType, ['hq_registration', 'license_modification'], true)) {
        $accountStatus = $newStatus;
        if ($requestType === 'license_modification' && $action === 'reject') {
            $previousStatus = strtolower(trim((string) ($renewalDetails['previous_status'] ?? 'expired')));
            $accountStatus = in_array($previousStatus, ['active', 'approved', 'enabled', 'ready', 'expired'], true)
                ? $previousStatus
                : 'expired';
        }
        $userUpdate = $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?');
        if (!$userUpdate) {
            throw new RuntimeException('Could not prepare the related account update.');
        }
        $userUpdate->bind_param('si', $accountStatus, $recipientUserId);
        if (!$userUpdate->execute()) {
            throw new RuntimeException('Could not update the related account status.');
        }
        $userUpdate->close();
    }

    $message = $action === 'approve'
        ? sprintf('Your request %s for %s has been approved.', $target['reference_code'], $target['sender_entity'])
        : sprintf("Your request %s for %s was rejected.\n\nReason:\n%s", $target['reference_code'], $target['sender_entity'], $reason);
    $notification = request_send_notification($recipientEmail, '', '', [
        'kind' => 'request',
        'action' => $action === 'approve' ? 'approve' : 'reject',
        'reason' => $action === 'approve' ? '' : $reason,
        'effect' => $action === 'approve'
            ? 'Your request was approved in Map & Pump.'
            : 'Your request was not approved. Sign in to review the request details.',
        'reference_code' => (string) $target['reference_code'],
    ]);
    $subject = (string) ($notification['subject'] ?? sprintf('Map & Pump request %s: %s', $target['reference_code'], ucfirst($newStatus)));

    $notificationInsert = $conn->prepare(
        'INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$notificationInsert) {
        throw new RuntimeException('Could not prepare the notification record.');
    }
    $notificationType = 'request_' . $newStatus;
    $notificationInsert->bind_param(
        'iissssss',
        $requestId,
        $recipientUserId,
        $recipientEmail,
        $notificationType,
        $subject,
        $message,
        $notification['status'],
        $notification['note']
    );
    if (!$notificationInsert->execute()) {
        throw new RuntimeException('Could not record the notification.');
    }
    $notificationInsert->close();

    $conn->commit();
    admin_record_log(
        (int) $reviewer['user_id'],
        ucfirst($action) . ' request #' . $requestId . ' (' . $target['reference_code'] . ').'
    );
    request_flash('success', sprintf('Request %s was %s.', $target['reference_code'], $newStatus));
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Request registry] Review failed: ' . $exception->getMessage());
    request_flash('danger', 'The request could not be updated. No partial changes were saved.');
}

request_redirect();
