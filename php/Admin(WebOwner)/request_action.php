<?php
require_once __DIR__ . '/request_common.php';
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    request_redirect();
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$reason = trim((string) ($_POST['reason'] ?? ''));

if (!$requestId || !in_array($action, ['approve', 'reject'], true)) {
    request_flash('danger', 'The request action was invalid.');
    request_redirect();
}
if ($action === 'reject' && $reason === '') {
    request_flash('danger', 'A rejection reason is required.');
    request_redirect();
}
if (mb_strlen($reason) > 2000) {
    request_flash('danger', 'The rejection reason must be 2,000 characters or fewer.');
    request_redirect();
}
if (!hq_db_available() || !request_schema_ready()) {
    request_flash('danger', 'The request database is temporarily unavailable.');
    request_redirect();
}

global $conn;
$reviewer = admin_current_user(false);
$reviewerRole = strtolower(trim((string) ($reviewer['role_name'] ?? '')));
if (!$reviewer || !in_array($reviewerRole, ['admin', 'moderator'], true)) {
    request_flash('danger', 'A signed-in administrator or moderator account is required to review requests.');
    request_redirect();
}
$reviewerId = (int) $reviewer['user_id'];

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
        throw new RuntimeException('Only pending requests can be approved or rejected.');
    }

    $isHqRegistration = request_is_hq_registration($target['request_type'] ?? null);
    $newStatus = $action === 'approve' ? 'approved' : 'rejected';
    $linkedHqId = !empty($target['headquarter_id']) ? (int) $target['headquarter_id'] : null;
    $createdHq = false;
    $createdOwnerUserId = null;
    $createdUsername = null;
    $temporaryPassword = null;
    $recipientUserId = !empty($target['existing_user_id']) ? (int) $target['existing_user_id'] : null;
    $recipientEmail = trim((string) ($target['user_email'] ?: $target['headquarter_owner_email'] ?: $target['sender_email']));
    $reviewReason = $action === 'approve' ? 'Approved by administrator.' : $reason;

    if ($action === 'approve' && $isHqRegistration && $linkedHqId === null) {
        $companyName = trim((string) ($target['sender_entity'] ?? ''));
        $companyEmail = strtolower(trim((string) ($target['headquarter_email'] ?? '')));
        $ownerName = trim((string) ($target['sender_name'] ?? ''));
        $ownerEmail = strtolower(trim((string) ($target['headquarter_owner_email'] ?? $target['sender_email'] ?? '')));
        $companyLogo = trim((string) ($target['headquarter_company_logo'] ?? ''));
        $contactNumber = trim((string) ($target['headquarter_contact_number'] ?? ''));
        $location = trim((string) ($target['location'] ?? ''));
        $businessLicense = trim((string) ($target['document_path'] ?? ''));
        $expireAt = trim((string) ($target['headquarter_expire_at'] ?? ''));

        if ($companyName === '' || $companyLogo === '' || $contactNumber === '' || $location === '' || $businessLicense === '' || !filter_var($companyEmail, FILTER_VALIDATE_EMAIL) || $ownerName === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) || $expireAt === '') {
            throw new RuntimeException('The pending HQ request is missing required registration details.');
        }
        if (strtotime($expireAt) === false || strtotime($expireAt) < strtotime(date('Y-m-d'))) {
            throw new RuntimeException('The pending HQ request has an invalid or expired business license date.');
        }

        $emailCheck = $conn->prepare(
            'SELECT 1 FROM users WHERE LOWER(email) = LOWER(?)
             UNION ALL
             SELECT 1 FROM headquarter WHERE LOWER(headquarter_email) = LOWER(?)
             LIMIT 1'
        );
        if (!$emailCheck) {
            throw new RuntimeException('Could not validate the HQ registration email addresses.');
        }
        $emailCheck->bind_param('ss', $ownerEmail, $companyEmail);
        $emailCheck->execute();
        $emailAlreadyExists = (bool) $emailCheck->get_result()?->fetch_row();
        $emailCheck->close();
        if ($emailAlreadyExists) {
            throw new RuntimeException('The HQ owner email or company email is already registered.');
        }

        $roleId = hq_find_role_id();
        if ($roleId === false) {
            throw new RuntimeException('The HQ role is not available in the database.');
        }

        $temporaryPassword = bin2hex(random_bytes(5));
        $createdUsername = hq_unique_username($ownerName, $ownerEmail);
        $hashedPassword = password_hash($temporaryPassword, PASSWORD_DEFAULT);

        $hqInsert = $conn->prepare(
            'INSERT INTO headquarter
                (company_name, company_logo, contact_number, headquarter_email, location, business_license, created_at, expire_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)'
        );
        if (!$hqInsert) {
            throw new RuntimeException('Could not prepare the HQ insert.');
        }
        $hqInsert->bind_param('sssssss', $companyName, $companyLogo, $contactNumber, $companyEmail, $location, $businessLicense, $expireAt);
        if (!$hqInsert->execute()) {
            throw new RuntimeException('Could not create the HQ record: ' . $hqInsert->error);
        }
        $linkedHqId = (int) $hqInsert->insert_id;
        $hqInsert->close();

        $userInsert = $conn->prepare(
            "INSERT INTO users
                (user_name, email, hashed_password, headquarter_id, role_id, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'approved', NOW(), NOW())"
        );
        if (!$userInsert) {
            throw new RuntimeException('Could not prepare the HQ owner account insert.');
        }
        $userInsert->bind_param('sssii', $createdUsername, $ownerEmail, $hashedPassword, $linkedHqId, $roleId);
        if (!$userInsert->execute()) {
            throw new RuntimeException('Could not create the HQ owner account: ' . $userInsert->error);
        }
        $createdOwnerUserId = (int) $userInsert->insert_id;
        $userInsert->close();

        $ownerLink = $conn->prepare('UPDATE headquarter SET headquarters_owner_id = ? WHERE headquarter_id = ?');
        if ($ownerLink) {
            $ownerLink->bind_param('ii', $createdOwnerUserId, $linkedHqId);
            $ownerLink->execute();
            $ownerLink->close();
        }

        $createdHq = true;
        $recipientUserId = $createdOwnerUserId;
        $recipientEmail = $ownerEmail;
        $reviewReason = 'Approved by administrator. HQ #' . $linkedHqId . ' and its owner account were created.';
    }

    $requestUpdate = $conn->prepare(
        "UPDATE system_requests
         SET status = ?, review_reason = ?, reviewed_at = NOW(), reviewed_by = ?, headquarter_id = ?, updated_at = NOW()
         WHERE request_id = ? AND status = 'pending'"
    );
    if (!$requestUpdate) {
        throw new RuntimeException('Could not prepare the request update.');
    }
    $requestUpdate->bind_param('ssiii', $newStatus, $reviewReason, $reviewerId, $linkedHqId, $requestId);
    if (!$requestUpdate->execute() || $requestUpdate->affected_rows !== 1) {
        throw new RuntimeException('The request was already changed or could not be updated.');
    }
    $requestUpdate->close();

    // A newly submitted HQ request has no owner user until approval. Do not
    // change the status of the admin/moderator who submitted it.
    $shouldUpdateExistingUser = !$isHqRegistration || ($isHqRegistration && $linkedHqId !== null && !$createdHq);
    if ($shouldUpdateExistingUser && !empty($target['existing_user_id'])) {
        $userUpdate = $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?');
        if (!$userUpdate) {
            throw new RuntimeException('Could not prepare the account status update.');
        }
        $existingUserId = (int) $target['existing_user_id'];
        $userUpdate->bind_param('si', $newStatus, $existingUserId);
        if (!$userUpdate->execute()) {
            throw new RuntimeException('Could not update the account status.');
        }
        $userUpdate->close();
    }

    $subject = sprintf('Map & Pump request %s: %s', $target['reference_code'], ucfirst($newStatus));
    if ($createdHq) {
        $message = sprintf(
            "Your HQ registration %s has been approved.\n\nHQ account username: %s\nTemporary password: %s\n\nPlease change the password after first sign-in.",
            $target['reference_code'],
            $createdUsername,
            $temporaryPassword
        );
    } elseif ($action === 'approve') {
        $message = sprintf('Your request %s for %s has been approved.', $target['reference_code'], $target['sender_entity']);
    } else {
        $message = sprintf("Your request %s for %s was rejected.\n\nReason:\n%s", $target['reference_code'], $target['sender_entity'], $reason);
    }
    $notification = request_send_notification($recipientEmail, $subject, $message);

    $notificationInsert = $conn->prepare(
        'INSERT INTO notifications
            (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$notificationInsert) {
        throw new RuntimeException('Could not prepare the notification record.');
    }
    $notificationType = 'request_' . $newStatus;
    $deliveryStatus = $notification['status'];
    $deliveryNote = $notification['note'];
    $notificationInsert->bind_param(
        'iissssss',
        $requestId,
        $recipientUserId,
        $recipientEmail,
        $notificationType,
        $subject,
        $message,
        $deliveryStatus,
        $deliveryNote
    );
    if (!$notificationInsert->execute()) {
        throw new RuntimeException('Could not record the notification.');
    }
    $notificationInsert->close();

    $conn->commit();
    request_flash('success', $createdHq
        ? sprintf('HQ registration %s was approved. HQ #%d and its owner account were created.', $target['reference_code'], $linkedHqId)
        : sprintf('Request %s was %s. The notification was recorded for delivery.', $target['reference_code'], $newStatus));
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Request registry] Action failed: ' . $exception->getMessage());
    request_flash('danger', $exception->getMessage());
}

request_redirect();
