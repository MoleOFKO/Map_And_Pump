<?php
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/admin_common.php';
require_once __DIR__ . '/user_account_common.php';
require_once __DIR__ . '/../Login/mail_templates.php';

$reviewer = admin_require_staff();
$returnTo = admin_user_has_role($reviewer, ['moderator']) ? 'view_requests(M).php' : 'view_requests.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hq_db_available()) {
    request_flash('danger', 'The duplicate-license decision could not be processed.');
    request_redirect($returnTo);
}

$licenseType = strtolower(trim((string) ($_POST['license_type'] ?? '')));
$recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$decision = strtolower(trim((string) ($_POST['decision'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));
$blocking = in_array($decision, ['block_current', 'block_all'], true);
if (!in_array($licenseType, ['driver', 'car'], true) || !$recordId || !in_array($decision, ['allow_all', 'block_current', 'block_all'], true)
    || ($blocking && $reason === '') || mb_strlen($reason) > 2000) {
    request_flash('danger', $blocking && $reason === '' ? 'A reason is required when blocking a duplicate license.' : 'The duplicate-license decision was invalid.');
    request_redirect($returnTo);
}
if (!user_action_table_ready()) {
    request_flash('danger', 'License decision history is unavailable. No changes were made.');
    request_redirect($returnTo);
}

global $conn;
$transactionStarted = false;
$records = [];
$recipients = [];
$actionIds = [];
$licenseNumber = '';
$licenseKindLabel = $licenseType === 'car' ? 'car license plate' : 'driver license';

try {
    $conn->begin_transaction();
    $transactionStarted = true;

    if ($licenseType === 'driver') {
        $targetQuery = $conn->prepare('SELECT license_id, license_number FROM user_license WHERE license_id = ? FOR UPDATE');
        if (!$targetQuery) {
            throw new RuntimeException('Could not prepare driver-license lookup.');
        }
        $targetQuery->bind_param('i', $recordId);
        $targetQuery->execute();
        $target = $targetQuery->get_result()?->fetch_assoc();
        $targetQuery->close();
        if (!$target) {
            throw new RuntimeException('The selected driver license was not found.');
        }
        $licenseNumber = trim((string) $target['license_number']);
        $normalizedNumber = strtoupper(preg_replace('/\s+/', '', $licenseNumber) ?: $licenseNumber);
        $matches = $conn->prepare(
            "SELECT l.license_id AS record_id, l.license_number, l.status AS license_status,
                    u.user_id, u.user_name, u.email
             FROM user_license l
             INNER JOIN users u ON u.license_id = l.license_id
             WHERE UPPER(REPLACE(TRIM(l.license_number), ' ', '')) = ?
             ORDER BY l.license_id
             FOR UPDATE"
        );
        if (!$matches) {
            throw new RuntimeException('Could not prepare driver-license comparison.');
        }
        $matches->bind_param('s', $normalizedNumber);
        $matches->execute();
        $result = $matches->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $row['record_id'] = (int) $row['record_id'];
            $row['user_id'] = (int) $row['user_id'];
            $records[] = $row;
        }
        $matches->close();
    } else {
        $targetQuery = $conn->prepare('SELECT car_license_id, car_plate_number FROM car_license WHERE car_license_id = ? FOR UPDATE');
        if (!$targetQuery) {
            throw new RuntimeException('Could not prepare car-license lookup.');
        }
        $targetQuery->bind_param('i', $recordId);
        $targetQuery->execute();
        $target = $targetQuery->get_result()?->fetch_assoc();
        $targetQuery->close();
        if (!$target) {
            throw new RuntimeException('The selected car license was not found.');
        }
        $licenseNumber = trim((string) $target['car_plate_number']);
        $normalizedNumber = strtoupper(preg_replace('/[^0-9A-Z]/', '', $licenseNumber) ?: '');
        $matches = $conn->prepare(
            "SELECT car_license_id AS record_id, car_plate_number AS license_number, status AS license_status
             FROM car_license
             WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(car_plate_number), '/', ''), ' ', ''), '-', '')) = ?
             ORDER BY car_license_id
             FOR UPDATE"
        );
        if (!$matches) {
            throw new RuntimeException('Could not prepare car-license comparison.');
        }
        $matches->bind_param('s', $normalizedNumber);
        $matches->execute();
        $result = $matches->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $row['record_id'] = (int) $row['record_id'];
            $records[] = $row;
        }
        $matches->close();
        if ($records) {
            $recordIds = array_column($records, 'record_id');
            $placeholders = implode(',', array_fill(0, count($recordIds), '?'));
            $ownerQuery = $conn->prepare(
                "SELECT DISTINCT uc.car_id AS record_id, u.user_id, u.user_name, u.email
                 FROM user_car uc INNER JOIN users u ON u.user_id = uc.user_id
                 WHERE uc.car_id IN ({$placeholders})"
            );
            if (!$ownerQuery) {
                throw new RuntimeException('Could not prepare car-license owner lookup.');
            }
            $bindTypes = str_repeat('i', count($recordIds));
            hq_bind($ownerQuery, $bindTypes, $recordIds);
            $ownerQuery->execute();
            $ownerResult = $ownerQuery->get_result();
            $ownersByCar = [];
            while ($ownerResult && ($owner = $ownerResult->fetch_assoc())) {
                $ownersByCar[(int) $owner['record_id']][] = $owner;
            }
            $ownerQuery->close();
            foreach ($records as &$record) {
                $record['owners'] = $ownersByCar[$record['record_id']] ?? [];
            }
            unset($record);
        }
    }

    if (count($records) < 2) {
        throw new RuntimeException('The duplicate is no longer present. Refresh the review and compare again.');
    }
    $targetFound = false;
    foreach ($records as $record) {
        if ((int) $record['record_id'] === (int) $recordId) {
            $targetFound = true;
            break;
        }
    }
    if (!$targetFound) {
        throw new RuntimeException('The selected record is not part of this duplicate group.');
    }

    $updateDriver = $licenseType === 'driver'
        ? $conn->prepare('UPDATE user_license SET status = ?, updated_at = NOW() WHERE license_id = ?')
        : null;
    $updateCar = $licenseType === 'car'
        ? $conn->prepare('UPDATE car_license SET status = ?, updated_at = NOW() WHERE car_license_id = ?')
        : null;
    $updateUser = $licenseType === 'driver'
        ? $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?')
        : null;
    $actionInsert = $conn->prepare(
        'INSERT INTO user_account_actions (user_id, action_type, reason, notification_status, notification_note)
         VALUES (?, ?, ?, \'pending\', \'Duplicate-license decision recorded; email notification is being attempted.\')'
    );
    if (($licenseType === 'driver' && (!$updateDriver || !$updateUser)) || ($licenseType === 'car' && !$updateCar) || !$actionInsert) {
        throw new RuntimeException('Could not prepare duplicate-license review updates.');
    }

    foreach ($records as $record) {
        $recordIsBlocked = $decision === 'block_all'
            || ($decision === 'block_current' && (int) $record['record_id'] === (int) $recordId);
        $newStatus = $recordIsBlocked ? 'rejected' : 'approved';
        $actionType = $licenseType . ($recordIsBlocked ? '_blocked' : '_allowed');
        $actionReason = $recordIsBlocked
            ? $reason
            : sprintf('Duplicate %s reviewed by staff; this record was allowed.', $licenseKindLabel);

        if ($licenseType === 'driver') {
            $updateDriver->bind_param('si', $newStatus, $record['record_id']);
            if (!$updateDriver->execute()) {
                throw new RuntimeException('Could not save a driver-license decision.');
            }
            $updateUser->bind_param('si', $newStatus, $record['user_id']);
            if (!$updateUser->execute()) {
                throw new RuntimeException('Could not save the driver account decision.');
            }
            $owners = [[
                'user_id' => (int) $record['user_id'],
                'user_name' => $record['user_name'],
                'email' => $record['email'],
            ]];
        } else {
            $updateCar->bind_param('si', $newStatus, $record['record_id']);
            if (!$updateCar->execute()) {
                throw new RuntimeException('Could not save a car-license decision.');
            }
            $owners = $record['owners'] ?? [];
        }

        foreach ($owners as $owner) {
            $ownerId = (int) $owner['user_id'];
            if ($ownerId <= 0) {
                continue;
            }
            $actionInsert->bind_param('iss', $ownerId, $actionType, $actionReason);
            if (!$actionInsert->execute()) {
                throw new RuntimeException('Could not record the license decision reason.');
            }
            $actionIds[] = ['action_id' => (int) $actionInsert->insert_id, 'user_id' => $ownerId];

            if (!isset($recipients[$ownerId])) {
                $recipients[$ownerId] = [
                    'name' => (string) ($owner['user_name'] ?? ''),
                    'email' => (string) ($owner['email'] ?? ''),
                    'blocked' => false,
                    'allowed' => false,
                ];
            }
            $recipients[$ownerId]['blocked'] = $recipients[$ownerId]['blocked'] || $recordIsBlocked;
            $recipients[$ownerId]['allowed'] = $recipients[$ownerId]['allowed'] || !$recordIsBlocked;
        }
    }

    $updateDriver?->close();
    $updateCar?->close();
    $updateUser?->close();
    $actionInsert->close();
    $conn->commit();
    $transactionStarted = false;

    $anyBlocked = in_array($decision, ['block_current', 'block_all'], true);
    $allBlocked = $decision === 'block_all';
    $notificationUpdate = $conn->prepare('UPDATE user_account_actions SET notification_status = ?, notification_note = ? WHERE action_id = ?');
    $mailFailures = 0;
    foreach ($recipients as $userId => $recipient) {
        $recipientDecision = !$anyBlocked
            ? 'allowed_all'
            : ($allBlocked ? 'blocked_all' : ($recipient['blocked'] ? 'blocked' : 'other_blocked'));
        $notice = mail_send_account_notice((string) $recipient['email'], [
            'kind' => 'license_conflict',
            'action' => $recipientDecision,
            'license_kind' => $licenseType,
            'license_number' => $licenseNumber,
            'recipient_decision' => $recipientDecision,
            'reason' => $recipient['blocked'] ? $reason : '',
        ]);
        if (empty($notice['sent'])) {
            $mailFailures++;
        }
        foreach ($actionIds as $actionRow) {
            if ($actionRow['user_id'] !== (int) $userId) {
                continue;
            }
            $noticeStatus = (string) ($notice['status'] ?? 'failed');
            $noticeNote = substr((string) ($notice['note'] ?? ''), 0, 255);
            if ($notificationUpdate) {
                $notificationUpdate->bind_param('ssi', $noticeStatus, $noticeNote, $actionRow['action_id']);
                $notificationUpdate->execute();
            }
        }
    }
    if ($notificationUpdate) {
        $notificationUpdate->close();
    }

    admin_record_log((int) $reviewer['user_id'], sprintf(
        '%s duplicate %s decision %s for record #%d.',
        ucfirst((string) ($reviewer['user_name'] ?? 'Staff')),
        $licenseKindLabel,
        $decision,
        (int) $recordId
    ));
    $message = $mailFailures === 0
        ? 'Duplicate-license decision saved and notification emails were sent.'
        : 'Duplicate-license decision saved. One or more notification emails could not be delivered; delivery status was recorded.';
    request_flash($mailFailures === 0 ? 'success' : 'warning', $message);
} catch (Throwable $error) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    error_log('[Duplicate license review] Decision failed: ' . $error->getMessage());
    request_flash('danger', 'The duplicate-license decision could not be saved. Please try again.');
}

request_redirect($returnTo);
