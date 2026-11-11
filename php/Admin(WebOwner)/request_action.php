<?php
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/../billing_config.php';
require_once __DIR__ . '/../payment_transfers.php';
require_once __DIR__ . '/../shared/license_expiry_date.php';
require_once __DIR__ . '/../Login/account_password_setup.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$reviewer = admin_require_staff();
if (!payment_transfer_ensure_hq_service_expiry_schema($pdo)) {
    request_flash('danger', 'Company service-period storage is unavailable.');
    request_redirect();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    request_redirect();
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason = '';

$allowedActions = ['approve', 'reject'];
if (!$requestId || !in_array($action, $allowedActions, true)) {
    request_flash('danger', 'The request action was invalid.');
    request_redirect();
}
if ($action === 'reject') {
    $resolvedReason = request_resolve_rejection_reason($_POST);
    if (empty($resolvedReason['ok'])) {
        request_flash('danger', (string) ($resolvedReason['error'] ?? 'A rejection reason is required.'));
        request_redirect();
    }
    $reason = (string) $resolvedReason['reason'];
}
if (!hq_db_available() || !request_schema_ready()) {
    request_flash('danger', 'The request database is temporarily unavailable.');
    request_redirect();
}

global $conn;
$postCommitMailJobs = [];

try {
    if ($action === 'approve') {
        $requestTypeQuery = $pdo->prepare('SELECT request_type FROM system_requests WHERE request_id = ? LIMIT 1');
        $requestTypeQuery->execute([$requestId]);
        if (strtolower(trim((string) $requestTypeQuery->fetchColumn())) === 'hq_registration') {
            hq_password_setup_ensure_schema($pdo);
        }
    }

    $conn->begin_transaction();

    $targetQuery = $conn->prepare(
        'SELECT r.*, u.user_id AS existing_user_id, u.email AS user_email,
                h.expire_at AS current_hq_expire_at
         FROM system_requests r
         LEFT JOIN users u ON u.user_id = r.submitter_user_id
         LEFT JOIN headquarter h ON h.headquarter_id = r.headquarter_id
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
    $requestType = strtolower(trim((string) ($target['request_type'] ?? '')));
    $duplicateDecision = strtolower(trim((string) ($_POST['duplicate_decision'] ?? '')));
    $duplicateExistingHqId = filter_var($_POST['existing_headquarter_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $businessLicenseMatch = request_business_license_match_for_request($target);
    $businessPairDecisions = ['approve_new_disable_existing', 'keep_existing_reject_new', 'accept_both', 'disable_both'];
    if ($duplicateDecision !== '') {
        if (!in_array($requestType, ['hq_registration', 'license_modification'], true)
            || !in_array($duplicateDecision, $businessPairDecisions, true)
            || !$businessLicenseMatch || !$duplicateExistingHqId
            || (int) $businessLicenseMatch['headquarter_id'] !== (int) $duplicateExistingHqId) {
            throw new RuntimeException('The selected business-license duplicate is no longer the first eligible match.');
        }
        $duplicateApprove = in_array($duplicateDecision, ['approve_new_disable_existing', 'accept_both'], true);
        if ($action !== ($duplicateApprove ? 'approve' : 'reject')) {
            throw new RuntimeException('The business-license decision does not match the requested action.');
        }
        if ($duplicateDecision !== 'accept_both' && trim($reason) === '') {
            throw new RuntimeException('A reason is required when disabling a duplicate company account.');
        }

        $headquartersToDisable = [];
        if (in_array($duplicateDecision, ['approve_new_disable_existing', 'disable_both'], true)) {
            $headquartersToDisable[] = (int) $duplicateExistingHqId;
        }
        if ($duplicateDecision === 'disable_both' && (int) ($target['headquarter_id'] ?? 0) > 0) {
            $headquartersToDisable[] = (int) $target['headquarter_id'];
        } elseif ($requestType === 'hq_registration'
            && in_array($duplicateDecision, ['keep_existing_reject_new', 'disable_both'], true)
            && (int) ($target['headquarter_id'] ?? 0) > 0) {
            $headquartersToDisable[] = (int) $target['headquarter_id'];
        }
        foreach (array_values(array_unique($headquartersToDisable)) as $disableHqId) {
            $disableCompany = $conn->prepare("UPDATE headquarter SET status = 'disabled' WHERE headquarter_id = ?");
            if (!$disableCompany) {
                throw new RuntimeException('Could not prepare a duplicate company disable action.');
            }
            $disableCompany->bind_param('i', $disableHqId);
            if (!$disableCompany->execute()) {
                throw new RuntimeException('Could not disable the duplicate company account.');
            }
            $disableCompany->close();

            $disableOwners = $conn->prepare(
                "UPDATE users u SET u.status = 'disabled', u.updated_at = NOW()
                 WHERE EXISTS (SELECT 1 FROM headquarter h WHERE h.headquarter_id = ? AND h.headquarters_owner_id = u.user_id)
                    OR EXISTS (SELECT 1 FROM business_account_scope bas WHERE bas.headquarter_id = ? AND bas.user_id = u.user_id AND bas.business_role_id = 3)"
            );
            if (!$disableOwners) {
                throw new RuntimeException('Could not prepare the duplicate company owner disable action.');
            }
            $disableOwners->bind_param('ii', $disableHqId, $disableHqId);
            if (!$disableOwners->execute()) {
                throw new RuntimeException('Could not disable the duplicate company owner account.');
            }
            $disableOwners->close();

            $disableScopes = $conn->prepare("UPDATE business_account_scope SET status = 'disabled' WHERE headquarter_id = ? AND business_role_id = 3");
            if ($disableScopes) {
                $disableScopes->bind_param('i', $disableHqId);
                $disableScopes->execute();
                $disableScopes->close();
            }
            if (function_exists('hq_action_table_ready') && hq_action_table_ready()) {
                $history = $conn->prepare(
                    "INSERT INTO hq_account_actions
                        (headquarter_id, action_type, reason, notification_status, notification_note)
                     VALUES (?, 'suspend', ?, 'pending', 'Duplicate business-license review action.')"
                );
                if ($history) {
                    $disableReason = $reason !== '' ? $reason : 'Company disabled because its business license duplicated an existing record.';
                    $history->bind_param('is', $disableHqId, $disableReason);
                    if (!$history->execute()) {
                        throw new RuntimeException('Could not record the duplicate company action.');
                    }
                    $history->close();
                }
            }
        }
    } elseif ($action === 'approve' && $businessLicenseMatch) {
        throw new RuntimeException('This request has a duplicate business license. Use Manage this account to decide the matching pair.');
    }
    if ($action === 'approve' && strtolower((string) $target['request_type']) === 'hq_registration') {
        $registrationDetails = json_decode((string) ($target['request_details'] ?? ''), true);
        $requestedStations = is_array($registrationDetails)
            ? (int) ($registrationDetails['requested_station_count'] ?? 0)
            : 0;
        $requestedMonths = is_array($registrationDetails)
            ? (int) ($registrationDetails['requested_service_months'] ?? 1)
            : 1;
        $extraStationCount = max(0, $requestedStations - BILLING_HQ_INCLUDED_STATION_SLOTS);
        $paymentRow = null;
        $paymentRequired = $extraStationCount > 0;
        $expectedRegistrationAmount = null;
        if (is_array($registrationDetails) && array_key_exists('registration_amount_mmk', $registrationDetails)) {
            $expectedRegistrationAmount = filter_var($registrationDetails['registration_amount_mmk'], FILTER_VALIDATE_FLOAT);
            if ($expectedRegistrationAmount === false || $expectedRegistrationAmount < 0) {
                throw new RuntimeException('The recorded company registration amount is invalid.');
            }
            $paymentRequired = $expectedRegistrationAmount > 0;
        }

        if ($requestedStations < BILLING_HQ_INCLUDED_STATION_SLOTS || $requestedStations > 60
            || $requestedMonths < 1 || $requestedMonths > 24) {
            throw new RuntimeException('The requested station count or service period is invalid.');
        }
        if ($paymentRequired) {
            $paymentCheck = $conn->prepare(
                "SELECT status, amount_mmk, quantity, term_months, service_start_month, payment_transfer_id,
                        reference_code, description, payment_method
                 FROM payment_transfers
                 WHERE request_id = ? AND payment_type = 'hq_registration'
                 ORDER BY payment_transfer_id DESC LIMIT 1"
            );
            if (!$paymentCheck) {
                throw new RuntimeException('Payment status is unavailable. The company registration cannot be approved yet.');
            }
            $paymentCheck->bind_param('i', $requestId);
            if (!$paymentCheck->execute()) {
                $paymentCheck->close();
                throw new RuntimeException('Could not verify the company registration payment status.');
            }
            $paymentRow = $paymentCheck->get_result()?->fetch_assoc();
            $paymentCheck->close();
            $quantityValid = !empty($paymentRow['service_start_month'])
                ? (int) ($paymentRow['quantity'] ?? 0) === $requestedStations
                : (int) ($paymentRow['quantity'] ?? 0) >= $extraStationCount;
            if ((float) ($paymentRow['amount_mmk'] ?? 0) <= 0 || !$quantityValid
                || (int) ($paymentRow['term_months'] ?? 0) !== $requestedMonths
                || ($expectedRegistrationAmount !== null
                    && abs((float) $paymentRow['amount_mmk'] - $expectedRegistrationAmount) > 0.01)) {
                throw new RuntimeException('The company payment request does not match the requested amount, station count, or service term.');
            }
            if (strtolower(trim((string) ($paymentRow['status'] ?? ''))) !== 'received') {
                throw new RuntimeException('Confirm the company registration payment in Payment Verification to complete registration.');
            }
        }
    }

    $createdHeadquarterId = (int) ($target['headquarter_id'] ?? 0) ?: null;
    $createdOwnerUserId = null;
    $createdOwnerEmail = null;
    $createdOwnerSetupUrl = null;
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
            throw new RuntimeException('The license renewal is not linked to a company account.');
        }
        $currentExpiryDate = substr(trim((string) ($target['current_hq_expire_at'] ?? '')), 0, 10);
        if ($currentExpiryDate !== '' && $renewalExpiryDate <= $currentExpiryDate) {
            throw new RuntimeException('The new license expiry date must extend the current company deadline.');
        }
        if ($action === 'approve') {
            $expiryTimestamp = $renewalExpiryDate . ' 23:59:59';
            $hqLicenseUpdate = $conn->prepare(
                'UPDATE headquarter
                 SET business_license = ?, business_license_expire_date = ?, expire_at = ?,
                     created_at = created_at
                 WHERE headquarter_id = ?'
            );
            if (!$hqLicenseUpdate) {
                throw new RuntimeException('License extension approval failed: could not prepare the company license update.');
            }
            $headquarterId = (int) $target['headquarter_id'];
            $hqLicenseUpdate->bind_param('sssi', $renewalLicense, $renewalExpiryDate, $expiryTimestamp, $headquarterId);
            if (!$hqLicenseUpdate->execute()) {
                throw new RuntimeException('License extension approve failed: could not apply the new expiry date.');
            }
            if ($hqLicenseUpdate->affected_rows < 1) {
                error_log('[Request registry] License extension update affected 0 rows for HQ #' . $headquarterId);
            }
            $hqLicenseUpdate->close();
            if ((int) ($target['existing_user_id'] ?? 0) > 0) {
                $ownerId = (int) $target['existing_user_id'];
                $restoreOwner = $conn->prepare(
                    "UPDATE users SET status = 'active', updated_at = NOW()
                     WHERE user_id = ? AND LOWER(TRIM(status)) IN ('expired', 'pending')"
                );
                if (!$restoreOwner) {
                    throw new RuntimeException('Could not restore the renewed company account.');
                }
                $restoreOwner->bind_param('i', $ownerId);
                if (!$restoreOwner->execute()) {
                    throw new RuntimeException('Could not restore the renewed company account.');
                }
                $restoreOwner->close();
            }
        } elseif ((int) ($target['existing_user_id'] ?? 0) > 0) {
            $previousStatus = strtolower(trim((string) ($renewalDetails['previous_status'] ?? 'active')));
            if (!in_array($previousStatus, ['active', 'approved', 'enabled', 'ready', 'expired'], true)) {
                $previousStatus = 'active';
            }
            $ownerId = (int) $target['existing_user_id'];
            $restoreOwner = $conn->prepare(
                "UPDATE users SET status = ?, updated_at = NOW()
                 WHERE user_id = ? AND LOWER(TRIM(status)) = 'pending'"
            );
            if (!$restoreOwner) {
                throw new RuntimeException('Could not restore the company account after the renewal decision.');
            }
            $restoreOwner->bind_param('si', $previousStatus, $ownerId);
            if (!$restoreOwner->execute()) {
                throw new RuntimeException('Could not restore the company account after the renewal decision.');
            }
            $restoreOwner->close();
        }
    }

    if ($action === 'approve' && strtolower((string) $target['request_type']) === 'hq_registration') {
        $details = json_decode((string) ($target['request_details'] ?? ''), true);
        if (!is_array($details)) {
            throw new RuntimeException('The company registration details are invalid.');
        }

        // License expiry must be stored on both business_license_expire_date and expire_at.
        // created_at = registration time; expire_at = submitted license end date (not "now").
        $licenseExpirySource = array_key_exists('license_expiry', $details)
            ? trim((string) $details['license_expiry'])
            : substr(trim((string) ($target['headquarter_expire_at'] ?? '')), 0, 10);
        $licenseExpiryDate = map_pump_parse_license_expiry_date($licenseExpirySource);
        if ($licenseExpiryDate === null) {
            throw new RuntimeException('The company registration is missing a valid license expiry date.');
        }
        $licenseExpiryTs = $licenseExpiryDate . ' 23:59:59';
        $requestedStationCount = max(
            BILLING_HQ_INCLUDED_STATION_SLOTS,
            (int) ($details['requested_station_count'] ?? BILLING_HQ_INCLUDED_STATION_SLOTS)
        );
        $requestedServiceMonths = max(1, min(24, (int) ($details['requested_service_months'] ?? 1)));
        $extraStationCount = $requestedStationCount - BILLING_HQ_INCLUDED_STATION_SLOTS;
        $packageAmount = isset($details['package_amount_mmk'])
            ? max(0.0, (float) $details['package_amount_mmk'])
            : ($extraStationCount > 0 ? (float) ($paymentRow['amount_mmk'] ?? 0) : 0.0);

        $companyName = (string) $target['sender_entity'];
        if ($createdHeadquarterId === null) {
            $headquarterInsert = $conn->prepare(
                'INSERT INTO headquarter
                    (company_name, company_logo, contact_number, headquarter_email, location,
                     business_license, business_license_image, business_license_expire_date,
                     expire_at, station_count, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\')'
            );
            if (!$headquarterInsert) {
                throw new RuntimeException('Could not prepare the company account creation.');
            }
            $companyLogo = (string) $target['headquarter_company_logo'];
            $companyPhone = (string) $target['headquarter_contact_number'];
            $companyEmail = (string) $target['headquarter_email'];
            $companyLocation = (string) $target['location'];
            $businessLicense = (string) $target['document_name'];
            $licenseImage = (string) $target['document_path'];
            $includedStationCount = BILLING_HQ_INCLUDED_STATION_SLOTS;
            $headquarterInsert->bind_param(
                'sssssssssi',
                $companyName,
                $companyLogo,
                $companyPhone,
                $companyEmail,
                $companyLocation,
                $businessLicense,
                $licenseImage,
                $licenseExpiryDate,
                $licenseExpiryTs,
                $includedStationCount
            );
            if (!$headquarterInsert->execute()) {
                throw new RuntimeException('Could not create the approved company account.');
            }
            $createdHeadquarterId = (int) $conn->insert_id;
            $headquarterInsert->close();
        }

        // Set service period without clobbering license expire_at / created_at.
        // Re-assert expire_at here in case the column still has ON UPDATE CURRENT_TIMESTAMP.
        $paymentReceived = ($paymentRow['status'] ?? '') === 'received';
        $serviceExpiry = $packageAmount > 0 && $paymentReceived
            ? payment_transfer_add_months_clamped(new DateTimeImmutable(), $requestedServiceMonths)->format('Y-m-d H:i:s')
            : null;
        $serviceExpiryUpdate = $conn->prepare(
            'UPDATE headquarter
             SET status = \'active\',
                 service_expires_at = ?,
                 included_stations_free_until = NULL,
                 created_at = created_at,
                 expire_at = ?,
                 business_license_expire_date = ?
             WHERE headquarter_id = ?'
        );
        if (!$serviceExpiryUpdate) {
            throw new RuntimeException('Could not initialize the company service period.');
        }
        $serviceExpiryUpdate->bind_param('sssi', $serviceExpiry, $licenseExpiryTs, $licenseExpiryDate, $createdHeadquarterId);
        if (!$serviceExpiryUpdate->execute()) {
            throw new RuntimeException('Could not initialize the company service period.');
        }
        $serviceExpiryUpdate->close();

        $registrationTransferId = (int) ($paymentRow['payment_transfer_id'] ?? 0);
        if ($registrationTransferId > 0) {
            $linkPayment = $conn->prepare('UPDATE payment_transfers SET payer_headquarter_id = ? WHERE payment_transfer_id = ?');
            if (!$linkPayment) {
                throw new RuntimeException('Could not link the registration payment to the new company.');
            }
            $linkPayment->bind_param('ii', $createdHeadquarterId, $registrationTransferId);
            if (!$linkPayment->execute()) {
                throw new RuntimeException('Could not link the registration payment to the new company.');
            }
            $linkPayment->close();
        }
        if ($registrationTransferId > 0 && $packageAmount > 0 && $paymentReceived) {
            $packageStartMonth = (string) ($paymentRow['service_start_month'] ?? '');
            $monthStart = DateTimeImmutable::createFromFormat('!Y-m', $packageStartMonth)
                ?: new DateTimeImmutable('first day of this month 00:00:00');
            $firstCoveredStation = array_key_exists('package_amount_mmk', $details)
                ? 1
                : BILLING_HQ_INCLUDED_STATION_SLOTS + 1;
            $slots = $conn->prepare(
                'INSERT INTO company_loyalty_station_slots
                    (headquarter_id, `year_month`, slots_purchased, mmk_slots_purchased, points_spent)
                 VALUES (?, ?, 0, ?, 0)
                 ON DUPLICATE KEY UPDATE mmk_slots_purchased = GREATEST(mmk_slots_purchased, VALUES(mmk_slots_purchased))'
            );
            if (!$slots) {
                throw new RuntimeException('Could not apply paid extra-station capacity.');
            }
            $entitlements = $conn->prepare(
                'INSERT INTO hq_station_mmk_entitlements
                    (payment_transfer_id, headquarter_id, `year_month`, slot_number)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$entitlements) {
                throw new RuntimeException('Could not create paid extra-station entitlements.');
            }
            $coverage = $conn->prepare(
                'INSERT INTO hq_station_package_coverage
                    (payment_transfer_id, headquarter_id, `year_month`, first_station_number, station_count)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if (!$coverage) {
                throw new RuntimeException('Could not create the paid package coverage record.');
            }
            for ($monthIndex = 0; $monthIndex < $requestedServiceMonths; $monthIndex++) {
                $yearMonth = $monthStart->modify('+' . $monthIndex . ' months')->format('Y-m');
                $slots->bind_param('isi', $createdHeadquarterId, $yearMonth, $extraStationCount);
                if (!$slots->execute()) {
                    throw new RuntimeException('Could not apply paid extra-station capacity.');
                }
                $coverage->bind_param('iisii', $registrationTransferId, $createdHeadquarterId, $yearMonth, $firstCoveredStation, $requestedStationCount);
                if (!$coverage->execute()) {
                    throw new RuntimeException('Could not create the paid package coverage record.');
                }
                for ($slotNumber = 1; $slotNumber <= $extraStationCount; $slotNumber++) {
                    $entitlements->bind_param('iisi', $registrationTransferId, $createdHeadquarterId, $yearMonth, $slotNumber);
                    if (!$entitlements->execute()) {
                        throw new RuntimeException('Could not create paid extra-station entitlements.');
                    }
                }
            }
            $slots->close();
            $entitlements->close();
            $coverage->close();
        }

        $ownerEmail = strtolower(trim((string) ($target['headquarter_owner_email'] ?? '')));
        if ($ownerEmail === '') {
            $ownerEmail = strtolower(trim((string) ($target['headquarter_email'] ?? '')));
        }
        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('The approved company has no valid owner or company email address.');
        }
        $createdOwnerSetupUrl = hq_password_setup_create_link($pdo, (int) $createdHeadquarterId);
        $createdOwnerEmail = $ownerEmail;

        $hqOwnerUpdate = $conn->prepare(
            'UPDATE headquarter
             SET headquarters_owner_id = NULL, created_at = created_at, expire_at = ?, business_license_expire_date = ?
             WHERE headquarter_id = ?'
        );
        if (!$hqOwnerUpdate) {
            throw new RuntimeException('Could not update the approved company record.');
        }
        $hqOwnerUpdate->bind_param('ssi', $licenseExpiryTs, $licenseExpiryDate, $createdHeadquarterId);
        if (!$hqOwnerUpdate->execute()) {
            throw new RuntimeException('Could not update the approved company record.');
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
    $reviewerRoleName = strtolower(trim((string) ($reviewer['role_name'] ?? $reviewer['business_role_name'] ?? '')));
    $reviewerLabel = $reviewerRoleName === 'moderator' ? 'Moderator' : 'Administrator';
    $reviewReason = $action === 'approve' ? ('Approved by ' . $reviewerLabel . '.') : $reason;
    $reviewerId = (int) $reviewer['user_id'];
    $recipientUserId = $requestType === 'hq_registration'
        ? null
        : ($createdOwnerUserId ?? (!empty($target['existing_user_id']) ? (int) $target['existing_user_id'] : null));
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

    if ($requestType === 'hq_registration' && $action === 'reject') {
        // Keep payment verification in sync: rejecting registration must close any open
        // registration payment so it does not remain in the pending verification queue.
        $rejectPayments = $conn->prepare(
            "UPDATE payment_transfers
             SET status = 'rejected',
                 rejected_by_user_id = ?,
                 rejected_at = NOW(),
                 decision_note = ?
             WHERE request_id = ?
               AND payment_type = 'hq_registration'
               AND status IN ('pending_payment', 'payer_reported_paid')"
        );
        if ($rejectPayments) {
            $paymentRejectNote = 'Registration request rejected: ' . $reason;
            if (mb_strlen($paymentRejectNote) > 500) {
                $paymentRejectNote = mb_substr($paymentRejectNote, 0, 500);
            }
            $rejectPayments->bind_param('isi', $reviewerId, $paymentRejectNote, $requestId);
            $rejectPayments->execute();
            $rejectPayments->close();
        }
        if ($createdHeadquarterId !== null) {
            $headquarterReject = $conn->prepare("UPDATE headquarter SET status = 'rejected' WHERE headquarter_id = ?");
            if (!$headquarterReject) {
                throw new RuntimeException('Could not prepare the company registration rejection.');
            }
            $headquarterReject->bind_param('i', $createdHeadquarterId);
            if (!$headquarterReject->execute()) {
                throw new RuntimeException('Could not reject the company registration.');
            }
            $headquarterReject->close();
        }
    } elseif ($recipientUserId !== null && $requestType === 'license_modification') {
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
    $isHqRegistrationApproval = $requestType === 'hq_registration' && $action === 'approve';
    if ($isHqRegistrationApproval) {
        $registrationDetails = json_decode((string) ($target['request_details'] ?? ''), true);
        $registrationDetails = is_array($registrationDetails) ? $registrationDetails : [];
        $paymentStatus = strtolower(trim((string) ($paymentRow['status'] ?? 'not_required')));
        $receiptContext = [
            'kind' => 'hq_registration_receipt',
            'company_name' => (string) $target['sender_entity'],
            'request_reference' => (string) $target['reference_code'],
            'payment_reference' => (string) ($paymentRow['reference_code'] ?? ''),
            'description' => (string) ($paymentRow['description'] ?? 'Company registration payment'),
            'payment_method' => (string) ($paymentRow['payment_method'] ?? ''),
            'payment_status' => $paymentStatus,
            'amount_mmk' => (float) ($paymentRow['amount_mmk'] ?? $registrationDetails['registration_amount_mmk'] ?? 0),
        ];
        $mailContexts = [];
        if ($createdOwnerSetupUrl !== null) {
            $mailContexts[] = [
                'notification_type' => 'hq_password_setup',
                'message' => 'Set up the password for your approved Company account.',
                'context' => [
                    'kind' => 'account_password_setup',
                    'account_label' => 'Company',
                    'account_name' => (string) $target['sender_entity'],
                    'setup_url' => $createdOwnerSetupUrl,
                    'reference_code' => (string) $target['reference_code'],
                ],
            ];
        }
        $mailContexts[] = [
            'notification_type' => 'hq_registration_receipt',
            'message' => 'Your Company registration payment record is available in this email.',
            'context' => $receiptContext,
        ];
        $mailContexts[] = [
            'notification_type' => 'hq_registration_approved',
            'message' => sprintf(
                'Your company registration for %s was approved by a Map & Pump %s.',
                (string) $target['sender_entity'],
                $reviewerLabel
            ),
            'context' => [
                'kind' => 'hq_registration_approved',
                'company_name' => (string) $target['sender_entity'],
                'reviewer_label' => $reviewerLabel,
                'reference_code' => (string) $target['reference_code'],
                'login_url' => payment_transfer_absolute_url('/php/Login/login.php'),
            ],
        ];

        foreach ($mailContexts as $mailJob) {
            $mailSubject = (string) mail_build_account_notice($mailJob['context'])['subject'];
            $notificationType = (string) $mailJob['notification_type'];
            $notificationMessage = (string) $mailJob['message'];
            $pendingStatus = 'pending';
            $pendingNote = 'Email will be sent after the approval transaction commits.';
            $notificationInsert = $conn->prepare(
                'INSERT INTO notifications
                    (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$notificationInsert) {
                throw new RuntimeException('Could not prepare the registration email notification.');
            }
            $notificationInsert->bind_param(
                'iissssss',
                $requestId,
                $recipientUserId,
                $recipientEmail,
                $notificationType,
                $mailSubject,
                $notificationMessage,
                $pendingStatus,
                $pendingNote
            );
            if (!$notificationInsert->execute()) {
                $notificationInsert->close();
                throw new RuntimeException('Could not record a registration email notification.');
            }
            $postCommitMailJobs[] = [
                'notification_id' => (int) $notificationInsert->insert_id,
                'subject' => $mailSubject,
                'context' => $mailJob['context'],
            ];
            $notificationInsert->close();
        }
    } else {
        $notificationContext = [
            'kind' => 'request',
            'action' => $action === 'approve' ? 'approve' : 'reject',
            'reason' => $action === 'approve' ? '' : $reason,
            'effect' => $action === 'approve'
                ? 'Your request was approved in Map & Pump.'
                : 'Your request was not approved. Sign in to review the request details.',
            'reference_code' => (string) $target['reference_code'],
        ];
        $notification = request_send_notification($recipientEmail, '', '', $notificationContext);
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
    }

    $conn->commit();
    foreach ($postCommitMailJobs as $mailJob) {
        try {
            $emailResult = mail_send_account_notice($recipientEmail, $mailJob['context']);
            $deliveryStatus = (string) ($emailResult['status'] ?? 'failed');
            $deliveryNote = substr((string) ($emailResult['note'] ?? 'Email delivery failed.'), 0, 255);
            $emailSubject = (string) ($emailResult['subject'] ?? $mailJob['subject']);
            $notificationId = (int) $mailJob['notification_id'];
            $deliveryUpdate = $conn->prepare(
                'UPDATE notifications
                 SET subject = ?, delivery_status = ?, delivery_note = ?
                 WHERE notification_id = ?'
            );
            if (!$deliveryUpdate) {
                throw new RuntimeException('Could not prepare email delivery status update.');
            }
            $deliveryUpdate->bind_param('sssi', $emailSubject, $deliveryStatus, $deliveryNote, $notificationId);
            if (!$deliveryUpdate->execute()) {
                throw new RuntimeException('Could not save email delivery status.');
            }
            $deliveryUpdate->close();
        } catch (Throwable $mailError) {
            error_log('[Request registry] HQ registration email failed after approval: ' . $mailError->getMessage());
        }
    }
    admin_record_log(
        (int) $reviewer['user_id'],
        ucfirst($action) . ' request #' . $requestId . ' (' . $target['reference_code'] . ') as ' . $reviewerLabel . '.'
    );
    if ($requestType === 'license_modification' && $action === 'approve') {
        $appliedExpiry = substr(trim((string) ($target['headquarter_expire_at'] ?? '')), 0, 10);
        request_flash(
            'success',
            sprintf(
                'License extension request %s was approved by %s. Company license expiry is now %s.',
                $target['reference_code'],
                $reviewerLabel,
                $appliedExpiry !== '' ? $appliedExpiry : 'updated'
            )
        );
    } elseif ($requestType === 'license_modification' && $action === 'reject') {
        request_flash(
            'success',
            sprintf('License extension request %s was rejected by %s.', $target['reference_code'], $reviewerLabel)
        );
    } else {
        request_flash('success', sprintf('Request %s was %s by %s.', $target['reference_code'], $newStatus, $reviewerLabel));
    }
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Request registry] Review failed: ' . $exception->getMessage());
    $companyApprovalError = isset($requestType)
        && $requestType === 'hq_registration'
        && $action === 'approve'
        && $exception instanceof RuntimeException;
    request_flash(
        'danger',
        $companyApprovalError ? $exception->getMessage() : 'The request could not be updated. Please try again.'
    );
}

request_redirect();
