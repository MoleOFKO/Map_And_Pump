<?php

function license_conflict_process_pair(string $licenseType, int $recordId, string $decision, string $reason, array $reviewer, string $returnTo): never
{
    global $conn;

    $existingRecordId = filter_var($_POST['existing_record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $validDecisions = ['approve_new_disable_existing', 'keep_existing_reject_new', 'accept_both', 'disable_both'];
    if (!in_array($licenseType, ['driver', 'car'], true) || $recordId <= 0 || !$existingRecordId || $recordId === $existingRecordId
        || !in_array($decision, $validDecisions, true) || !user_action_table_ready()) {
        request_flash('danger', 'The duplicate pair could not be reviewed. Refresh the details and try again.');
        request_redirect($returnTo);
    }
    if ($decision !== 'accept_both' && $reason === '') {
        request_flash('danger', 'Enter a reason for rejecting or disabling either account.');
        request_redirect($returnTo);
    }
    if (mb_strlen($reason) > 2000) {
        request_flash('danger', 'The duplicate decision reason must be 2,000 characters or fewer.');
        request_redirect($returnTo);
    }

    $licenseKindLabel = $licenseType === 'car' ? 'car license plate' : 'driver license';
    $transactionStarted = false;
    $newRecord = null;
    $existingRecord = null;
    $newOwner = null;
    $existingOwner = null;
    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        if ($licenseType === 'driver') {
            $targetQuery = $conn->prepare(
                'SELECT l.license_id, l.license_number, l.nrc_number, l.status AS license_status,
                        u.user_id, u.user_name, u.email, u.status AS account_status
                 FROM user_license l INNER JOIN users u ON u.license_id = l.license_id
                 WHERE l.license_id = ? FOR UPDATE'
            );
            if (!$targetQuery) {
                throw new RuntimeException('Could not prepare the new driver-license lookup.');
            }
            $targetQuery->bind_param('i', $recordId);
            $targetQuery->execute();
            $newRecord = $targetQuery->get_result()?->fetch_assoc();
            $targetQuery->close();
            if (!$newRecord || strtolower(trim((string) $newRecord['license_status'])) !== 'pending'
                || strtolower(trim((string) $newRecord['account_status'])) !== 'pending_verification') {
                throw new RuntimeException('The new driver-license request is no longer pending.');
            }

            $existingQuery = $conn->prepare(
                "SELECT l.license_id, l.license_number, l.nrc_number, l.status AS license_status,
                        u.user_id, u.user_name, u.email, u.status AS account_status
                 FROM user_license l INNER JOIN users u ON u.license_id = l.license_id
                 WHERE ((? <> '' AND UPPER(REPLACE(TRIM(l.license_number), ' ', '')) = UPPER(REPLACE(TRIM(?), ' ', '')))
                     OR (? <> '' AND UPPER(REPLACE(TRIM(l.nrc_number), ' ', '')) = UPPER(REPLACE(TRIM(?), ' ', ''))))
                   AND l.license_id <> ?
                   AND LOWER(TRIM(COALESCE(u.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'deleted')
                 ORDER BY u.created_at ASC, u.user_id ASC
                 LIMIT 1 FOR UPDATE"
            );
            if (!$existingQuery) {
                throw new RuntimeException('Could not prepare the existing driver-license lookup.');
            }
            $licenseNumber = trim((string) ($newRecord['license_number'] ?? ''));
            $nrcNumber = trim((string) ($newRecord['nrc_number'] ?? ''));
            $existingQuery->bind_param('ssssi', $licenseNumber, $licenseNumber, $nrcNumber, $nrcNumber, $recordId);
            $existingQuery->execute();
            $existingRecord = $existingQuery->get_result()?->fetch_assoc();
            $existingQuery->close();
            $newOwner = $newRecord;
            $existingOwner = $existingRecord;
            $newLicenseId = (int) $newRecord['license_id'];
            $existingLicenseId = (int) ($existingRecord['license_id'] ?? 0);
            $licenseTable = 'user_license';
            $licenseIdColumn = 'license_id';
        } else {
            $targetQuery = $conn->prepare(
                "SELECT c.car_license_id, c.car_plate_number, c.status,
                        owner.user_id, owner.user_name, owner.email, owner.status AS account_status
                 FROM car_license c
                 INNER JOIN (
                     SELECT uc.car_id, MIN(u.user_id) AS user_id
                     FROM user_car uc INNER JOIN users u ON u.user_id = uc.user_id
                     WHERE LOWER(TRIM(COALESCE(u.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'deleted')
                     GROUP BY uc.car_id
                 ) eligible_owner ON eligible_owner.car_id = c.car_license_id
                 INNER JOIN users owner ON owner.user_id = eligible_owner.user_id
                 WHERE c.car_license_id = ? FOR UPDATE"
            );
            if (!$targetQuery) {
                throw new RuntimeException('Could not prepare the new car-license lookup.');
            }
            $targetQuery->bind_param('i', $recordId);
            $targetQuery->execute();
            $newRecord = $targetQuery->get_result()?->fetch_assoc();
            $targetQuery->close();
            if (!$newRecord || strtolower(trim((string) $newRecord['status'])) !== 'pending') {
                throw new RuntimeException('The new car-license request is no longer pending.');
            }

            $existingQuery = $conn->prepare(
                "SELECT c.car_license_id, c.car_plate_number, c.status,
                        owner.user_id, owner.user_name, owner.email, owner.status AS account_status
                 FROM car_license c
                 INNER JOIN (
                     SELECT uc.car_id, MIN(u.user_id) AS user_id
                     FROM user_car uc INNER JOIN users u ON u.user_id = uc.user_id
                     WHERE LOWER(TRIM(COALESCE(u.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'deleted')
                     GROUP BY uc.car_id
                 ) eligible_owner ON eligible_owner.car_id = c.car_license_id
                 INNER JOIN users owner ON owner.user_id = eligible_owner.user_id
                 WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(c.car_plate_number), '/', ''), ' ', ''), '-', '')) =
                       UPPER(REPLACE(REPLACE(REPLACE(TRIM(?), '/', ''), ' ', ''), '-', ''))
                   AND c.car_license_id <> ?
                 ORDER BY c.created_at ASC, c.car_license_id ASC
                 LIMIT 1 FOR UPDATE"
            );
            if (!$existingQuery) {
                throw new RuntimeException('Could not prepare the existing car-license lookup.');
            }
            $licenseNumber = trim((string) $newRecord['car_plate_number']);
            $existingQuery->bind_param('si', $licenseNumber, $recordId);
            $existingQuery->execute();
            $existingRecord = $existingQuery->get_result()?->fetch_assoc();
            $existingQuery->close();
            $newOwner = $newRecord;
            $existingOwner = $existingRecord;
            $newLicenseId = (int) $newRecord['car_license_id'];
            $existingLicenseId = (int) ($existingRecord['car_license_id'] ?? 0);
            $licenseTable = 'car_license';
            $licenseIdColumn = 'car_license_id';
        }

        if (!$existingRecord || $existingLicenseId !== (int) $existingRecordId) {
            throw new RuntimeException('The selected existing account is no longer the first eligible duplicate. Refresh and compare again.');
        }
        if ($licenseType === 'car' && (int) $newOwner['user_id'] === (int) $existingOwner['user_id'] && $decision !== 'accept_both') {
            throw new RuntimeException('Both matching car licenses belong to the same account. Only accepting both records is available for this pair.');
        }

        $approveNew = in_array($decision, ['approve_new_disable_existing', 'accept_both'], true);
        $disableNew = in_array($decision, ['keep_existing_reject_new', 'disable_both'], true);
        $disableExisting = in_array($decision, ['approve_new_disable_existing', 'disable_both'], true);
        $newLicenseStatus = $approveNew ? 'approved' : 'rejected';
        $existingLicenseStatus = $disableExisting ? 'rejected' : 'approved';
        $licenseUpdate = $conn->prepare("UPDATE {$licenseTable} SET status = ?, updated_at = NOW() WHERE {$licenseIdColumn} = ?");
        if (!$licenseUpdate) {
            throw new RuntimeException('Could not prepare duplicate-license status updates.');
        }
        $licenseUpdate->bind_param('si', $newLicenseStatus, $newLicenseId);
        if (!$licenseUpdate->execute()) {
            throw new RuntimeException('Could not save the new license decision.');
        }
        if ($disableExisting || $decision === 'accept_both') {
            $licenseUpdate->bind_param('si', $existingLicenseStatus, $existingLicenseId);
            if (!$licenseUpdate->execute()) {
                throw new RuntimeException('Could not save the existing license decision.');
            }
        }
        $licenseUpdate->close();

        $usersToDisable = [];
        $usersToApprove = [];
        if ($licenseType === 'driver') {
            if ($disableNew) {
                $usersToDisable[] = $newOwner;
            } else {
                $usersToApprove[] = $newOwner;
            }
            if ($disableExisting) {
                $usersToDisable[] = $existingOwner;
            } elseif ($decision === 'accept_both' && strtolower(trim((string) $existingOwner['account_status'])) === 'pending_verification') {
                $usersToApprove[] = $existingOwner;
            }
        } else {
            if ($disableNew) {
                $usersToDisable[] = $newOwner;
            }
            if ($disableExisting) {
                $usersToDisable[] = $existingOwner;
            }
        }

        $statusUpdate = $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?');
        $actionInsert = $conn->prepare(
            "INSERT INTO user_account_actions
                (user_id, action_type, reason, notification_status, notification_note)
             VALUES (?, 'suspend', ?, 'pending', 'Duplicate review action; email delivery is pending.')"
        );
        if (!$statusUpdate || !$actionInsert) {
            throw new RuntimeException('Could not prepare duplicate account actions.');
        }
        foreach ($usersToApprove as $approvedUser) {
            $approvedStatus = 'approved';
            $approvedUserId = (int) $approvedUser['user_id'];
            $statusUpdate->bind_param('si', $approvedStatus, $approvedUserId);
            if (!$statusUpdate->execute()) {
                throw new RuntimeException('Could not approve a matching account.');
            }
        }
        $disabledUsers = [];
        foreach ($usersToDisable as $disabledUser) {
            $disabledUserId = (int) $disabledUser['user_id'];
            if (isset($disabledUsers[$disabledUserId])) {
                continue;
            }
            $disabledUsers[$disabledUserId] = $disabledUser;
            $disabledStatus = 'disabled';
            $disabledReason = $reason !== '' ? $reason : 'Account disabled because the submitted license duplicated an existing record.';
            $statusUpdate->bind_param('si', $disabledStatus, $disabledUserId);
            if (!$statusUpdate->execute()) {
                throw new RuntimeException('Could not disable a matching account.');
            }
            $actionInsert->bind_param('is', $disabledUserId, $disabledReason);
            if (!$actionInsert->execute()) {
                throw new RuntimeException('Could not record the duplicate account action.');
            }
        }
        $statusUpdate->close();
        $actionInsert->close();
        $conn->commit();
        $transactionStarted = false;

        foreach ($disabledUsers as $disabledUser) {
            if (trim((string) ($disabledUser['email'] ?? '')) !== '') {
                mail_send_account_notice((string) $disabledUser['email'], [
                    'kind' => 'account',
                    'action' => 'suspend',
                    'account_label' => (string) ($disabledUser['user_name'] ?? ''),
                    'reason' => $reason !== '' ? $reason : 'Your account was disabled because its license details duplicated an existing record.',
                ]);
            }
        }
        admin_record_log((int) $reviewer['user_id'], sprintf(
            'Recorded duplicate %s decision %s for new record #%d and existing record #%d.',
            $licenseKindLabel,
            $decision,
            $recordId,
            $existingRecordId
        ));
        request_flash('success', 'The duplicate decision was saved. Disabled accounts can be restored from View/Manage Drivers.');
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $conn->rollback();
        }
        error_log('[Duplicate license review] Pair decision failed: ' . $error->getMessage());
        request_flash('danger', 'The duplicate decision could not be saved. Refresh the review and try again.');
    }

    request_redirect($returnTo);
}