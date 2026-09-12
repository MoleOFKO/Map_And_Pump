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

    $createdHeadquarterId = null;
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
    $recipientUserId = !empty($target['existing_user_id']) ? (int) $target['existing_user_id'] : null;
    $recipientEmail = trim((string) ($target['user_email'] ?: $target['sender_email']));

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

    if ($recipientUserId !== null) {
        $userUpdate = $conn->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE user_id = ?');
        if (!$userUpdate) {
            throw new RuntimeException('Could not prepare the related account update.');
        }
        $userUpdate->bind_param('si', $newStatus, $recipientUserId);
        if (!$userUpdate->execute()) {
            throw new RuntimeException('Could not update the related account status.');
        }
        $userUpdate->close();
    }

    $subject = sprintf('Map & Pump request %s: %s', $target['reference_code'], ucfirst($newStatus));
    $message = $action === 'approve'
        ? sprintf('Your request %s for %s has been approved.', $target['reference_code'], $target['sender_entity'])
        : sprintf("Your request %s for %s was rejected.\n\nReason:\n%s", $target['reference_code'], $target['sender_entity'], $reason);
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
    request_flash('success', sprintf('Request %s was %s.', $target['reference_code'], $newStatus));
} catch (Throwable $exception) {
    $conn->rollback();
    error_log('[Request registry] Review failed: ' . $exception->getMessage());
    request_flash('danger', 'The request could not be updated. No partial changes were saved.');
}

request_redirect();
