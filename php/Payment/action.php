<?php
require_once __DIR__ . '/../Login/session_helper.php';
map_pump_configure_session(!empty($_COOKIE[MAP_PUMP_REMEMBER_EMAIL_COOKIE]));
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../business_scope.php';
require_once __DIR__ . '/../payment_transfers.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ((empty($_SESSION['is_logged_in']) || $userId <= 0)
    && ((int) ($_SESSION['payment_transfer_access_id'] ?? 0) <= 0 || (int) ($_SESSION['payment_transfer_access_expires'] ?? 0) < time())) {
    http_response_code(401);
    exit('Please sign in to continue.');
}
$currentUserEmail = '';
if ($userId > 0) {
    $currentUserStmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
    $currentUserStmt->execute([$userId]);
    $currentUserEmail = strtolower(trim((string) $currentUserStmt->fetchColumn()));
}

if (empty($_SESSION['payment_transfer_csrf'])) {
    $_SESSION['payment_transfer_csrf'] = bin2hex(random_bytes(32));
}
if (!hash_equals((string) $_SESSION['payment_transfer_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Your session expired. Refresh the page and try again.');
}
if (!payment_transfer_schema_ready($pdo)) {
    http_response_code(503);
    exit('Payment status tracking is temporarily unavailable.');
}
if (!payment_transfer_ensure_hq_service_expiry_schema($pdo)) {
    http_response_code(503);
    exit('HQ service-period storage is temporarily unavailable.');
}

$action = trim((string) ($_POST['action'] ?? ''));
$transferId = (int) ($_POST['transfer_id'] ?? 0);
$statusMessage = '';
$redirect = '/php/payment_center.php';
$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$allowedReturnTargets = [
    '/php/Admin(WebOwner)/view_requests.php',
    '/php/Moderators(UnderAdmin)/view_requests(M).php',
];
if (in_array($returnTo, $allowedReturnTargets, true)) {
    $redirect = $returnTo;
}
if ($action === 'create_fuel_payment') {
    $redirect = '/php/Customer/usage_history.php';
} elseif ($action === 'create_extra_station') {
    $redirect = '../HQ/station.php?payment=station_limit_pending';
} elseif ($action === 'recreate_registration_request') {
    $redirect = '/php/payment_center.php';
} elseif ($action === 'create_operating_fee_quote') {
    $redirect = '/php/payment_center.php';
}

try {
    if ($action === 'create_operating_fee_quote') {
        throw new RuntimeException('Standalone station-fee quotes are retired. Use the all-in-one MMK station package.');
    } elseif ($action === 'recreate_registration_request') {
        $pdo->beginTransaction();
        $oldStmt = $pdo->prepare("SELECT * FROM payment_transfers WHERE payment_transfer_id = ? AND payment_type = 'hq_registration' FOR UPDATE");
        $oldStmt->execute([$transferId]);
        $oldTransfer = $oldStmt->fetch(PDO::FETCH_ASSOC);
        $isOldPayer = $oldTransfer && (($userId > 0 && (int) ($oldTransfer['payer_user_id'] ?? 0) === $userId)
            || ($currentUserEmail !== '' && $currentUserEmail === strtolower((string) ($oldTransfer['payer_email'] ?? '')))
            || (int) ($_SESSION['payment_transfer_access_id'] ?? 0) === $transferId);
        if (!$oldTransfer || !$isOldPayer || !in_array((string) $oldTransfer['status'], ['rejected', 'cancelled'], true)) {
            throw new RuntimeException('Only the payer can replace a cancelled or rejected HQ registration payment request.');
        }
        $request = $pdo->prepare("SELECT 1 FROM system_requests WHERE request_id = ? AND request_type = 'hq_registration' AND status = 'pending' LIMIT 1 FOR UPDATE");
        $request->execute([(int) $oldTransfer['request_id']]);
        if (!$request->fetchColumn()) {
            throw new RuntimeException('This registration is no longer awaiting review.');
        }
        $duplicate = $pdo->prepare("SELECT 1 FROM payment_transfers WHERE request_id = ? AND payment_transfer_id <> ? AND status IN ('pending_payment', 'payer_reported_paid', 'received') LIMIT 1");
        $duplicate->execute([(int) $oldTransfer['request_id'], $transferId]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('This registration already has an active or received payment request.');
        }
        $newAccessToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $newTransferId = payment_transfer_create($pdo, [
            'payment_type' => 'hq_registration',
            'amount_mmk' => (float) $oldTransfer['amount_mmk'],
            'description' => (string) $oldTransfer['description'],
            'quantity' => (int) $oldTransfer['quantity'],
            'term_months' => (int) $oldTransfer['term_months'],
            'payer_user_id' => $oldTransfer['payer_user_id'],
            'payer_email' => (string) $oldTransfer['payer_email'],
            'recipient_role' => BILLING_PLATFORM_RECEIVER_ROLE,
            'request_id' => (int) $oldTransfer['request_id'],
            'payment_method' => $oldTransfer['payment_method'],
            'payer_access_token_hash' => hash('sha256', $newAccessToken),
            'payer_access_expires_at' => date('Y-m-d H:i:s', time() + 30 * 86400),
        ]);
        $pdo->commit();
        payment_transfer_send_notices($pdo, $newTransferId, 'recipient', 'pending_payment');
        $secureUrl = payment_transfer_absolute_url('/php/payment_center.php?access=' . rawurlencode($newAccessToken));
        payment_transfer_send_notices($pdo, $newTransferId, 'payer', 'pending_payment', ['payment_url' => $secureUrl]);
        if ($userId <= 0) {
            $_SESSION['payment_transfer_access_id'] = $newTransferId;
            $_SESSION['payment_transfer_access_expires'] = time() + 30 * 86400;
        }
        $_SESSION['payment_transfer_flash'] = ['type' => 'success', 'message' => 'A replacement request was created. The fixed amount and recipient were set by MAP & PUMP.'];
        header('Location: ' . $redirect);
        exit;
    } elseif ($action === 'create_fuel_payment') {
        $usageId = (int) ($_POST['usage_id'] ?? 0);
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            "SELECT uh.usage_id, uh.user_id, uh.station_id, uh.total_cost, uh.is_internal,
                    b.branch_name, sbs.headquarter_id, u.email
             FROM usage_history uh
             INNER JOIN users u ON u.user_id = uh.user_id
             INNER JOIN branches b ON b.branch_id = uh.station_id
             INNER JOIN station_business_scope sbs ON sbs.station_id = uh.station_id
             WHERE uh.usage_id = ? AND uh.user_id = ? AND LOWER(uh.status) = 'filled'
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$usageId, $userId]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usage || (int) $usage['is_internal'] === 1) {
            throw new RuntimeException('This filled usage record cannot create an external fuel payment notice.');
        }
        $amount = max(0.0, round((float) ($usage['total_cost'] ?? 0), 2));
        if ($amount <= 0) {
            throw new RuntimeException('No MMK payment is due for this fill.');
        }
        $existing = $pdo->prepare(
            "SELECT status FROM payment_transfers
             WHERE usage_id = ? AND status IN ('pending_payment', 'payer_reported_paid', 'received')
             ORDER BY payment_transfer_id DESC LIMIT 1"
        );
        $existing->execute([$usageId]);
        if ($existing->fetchColumn()) {
            throw new RuntimeException('A payment status is already recorded for this fill.');
        }

        $transferId = payment_transfer_create($pdo, [
            'payment_type' => 'fuel_purchase',
            'amount_mmk' => $amount,
            'description' => 'Fuel purchase at ' . trim((string) $usage['branch_name']),
            'payer_user_id' => $userId,
            'payer_email' => (string) $usage['email'],
            'recipient_role' => BILLING_CUSTOMER_FUEL_RECEIVER_ROLE,
            'recipient_headquarter_id' => (int) $usage['headquarter_id'],
            'usage_id' => $usageId,
            'station_id' => (int) $usage['station_id'],
            'payment_method' => null,
        ]);
        $pdo->commit();
        payment_transfer_send_notices($pdo, $transferId, 'recipient', 'pending_payment');
        payment_transfer_send_notices($pdo, $transferId, 'payer', 'pending_payment');
        $redirect = '/php/Customer/usage_history.php?payment=pending';
    } elseif ($action === 'create_extra_station') {
        $hqStmt = $pdo->prepare('SELECT headquarter_id, email FROM users WHERE user_id = ? LIMIT 1');
        $hqStmt->execute([$userId]);
        $payer = $hqStmt->fetch(PDO::FETCH_ASSOC);
        $headquarterId = map_pump_current_hq_id($pdo, $userId);
        if ($headquarterId <= 0 || !payment_transfer_user_owns_hq($pdo, $userId, $headquarterId)) {
            throw new RuntimeException('Only the owning HQ account can request extra-station payment.');
        }
        $extraQuantity = (int) ($_POST['quantity'] ?? 1);
        $termMonths = (int) ($_POST['term_months'] ?? 1);
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
        $allowedMethods = ['KPay', 'Wave Pay', 'AYA Pay', 'CB Pay', 'AYA Bank', 'CB Bank', 'KBZ Bank', 'YOMA Bank'];
        if ($extraQuantity < 1 || $extraQuantity > 60 || !in_array($termMonths, BILLING_HQ_STATION_LIMIT_TERMS, true) || !in_array($paymentMethod, $allowedMethods, true)) {
            throw new RuntimeException('Choose 1–60 extra stations, a valid billing term, and a payment method.');
        }

        $receiptFile = $_FILES['receipt'] ?? null;
        if (!is_array($receiptFile)
            || (int) ($receiptFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || (int) ($receiptFile['size'] ?? 0) <= 0
            || (int) ($receiptFile['size'] ?? 0) > 5 * 1024 * 1024
            || !is_uploaded_file((string) ($receiptFile['tmp_name'] ?? ''))) {
            throw new RuntimeException('Upload a payment screenshot no larger than 5 MB.');
        }
        $imageInfo = @getimagesize((string) $receiptFile['tmp_name']);
        $receiptMime = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
        if (!in_array($receiptMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('The payment screenshot must be a JPG, PNG, or WEBP image.');
        }
        $receiptData = file_get_contents((string) $receiptFile['tmp_name']);
        if ($receiptData === false) {
            throw new RuntimeException('The payment screenshot could not be read. Please try again.');
        }

        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT station_count FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
        $lock->execute([$headquarterId]);
        $baseStationLimit = $lock->fetchColumn();
        if ($baseStationLimit === false) {
            throw new RuntimeException('Could not verify this HQ account.');
        }
        if ((int) $baseStationLimit < 1) {
            throw new RuntimeException('This HQ has no finite station limit to extend.');
        }
        $stationCountStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM branches b
             INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
             WHERE sbs.headquarter_id = ? AND LOWER(COALESCE(b.status, 'active')) <> 'closed'"
        );
        $stationCountStmt->execute([$headquarterId]);
        $currentStations = (int) $stationCountStmt->fetchColumn();
        $currentMonthSlots = hq_billing_current_month_slots($pdo, $headquarterId);
        $currentCapacity = max($currentStations, (int) $baseStationLimit + $currentMonthSlots);
        $packageStationCount = $currentCapacity + $extraQuantity;
        $packageRate = billing_admin_setting($pdo, 'hq_package_mmk_per_station_month', BILLING_HQ_PACKAGE_MMK_PER_STATION_MONTHLY);

        $termPercent = 0;
        foreach (BILLING_HQ_STATION_LIMIT_TERM_DISCOUNTS as $discount) {
            if ($termMonths >= (int) $discount['min_months']) {
                $termPercent = (int) $discount['percent'];
                break;
            }
        }
        $baseAmount = (float) $packageRate * $packageStationCount * $termMonths;
        $amount = round($baseAmount * (1 - $termPercent / 100), 2);
        if ($amount <= 0) {
            throw new RuntimeException('The calculated payment amount is invalid.');
        }

        $duplicate = $pdo->prepare(
            "SELECT payment_transfer_id, reference_code, payment_type, status
             FROM payment_transfers
             WHERE payer_headquarter_id = ? AND payment_type IN ('hq_station_package', 'hq_period_extension', 'hq_extra_station')
               AND status IN ('pending_payment', 'payer_reported_paid') LIMIT 1"
        );
        $duplicate->execute([$headquarterId]);
        $existingPayment = $duplicate->fetch(PDO::FETCH_ASSOC);
        if ($existingPayment) {
            throw new RuntimeException(sprintf(
                'Payment %s (%s) is already awaiting confirmation. Admin can find it under View Requests → Payment Verification.',
                (string) $existingPayment['reference_code'],
                (string) $existingPayment['status']
            ));
        }
        $transferId = payment_transfer_create($pdo, [
            'payment_type' => 'hq_station_package',
            'amount_mmk' => $amount,
            'description' => sprintf('All-in-one package for %d station(s) (%d additional capacity slot(s)) for %d month(s)', $packageStationCount, $extraQuantity, $termMonths),
            'quantity' => $packageStationCount,
            'term_months' => $termMonths,
            'service_start_month' => date('Y-m'),
            'payer_user_id' => $userId,
            'payer_email' => (string) ($payer['email'] ?? ''),
            'payer_headquarter_id' => $headquarterId,
            'recipient_role' => BILLING_PLATFORM_RECEIVER_ROLE,
            'payment_method' => $paymentMethod,
        ]);
        $receipt = $pdo->prepare('INSERT INTO payment_transfer_receipts (payment_transfer_id, file_name, mime_type, file_size, file_data, uploaded_by_user_id, uploaded_by_email) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $receipt->bindValue(1, $transferId, PDO::PARAM_INT);
        $receipt->bindValue(2, substr(basename((string) ($receiptFile['name'] ?? 'payment-screenshot')), 0, 255));
        $receipt->bindValue(3, $receiptMime);
        $receipt->bindValue(4, (int) $receiptFile['size'], PDO::PARAM_INT);
        $receipt->bindValue(5, $receiptData, PDO::PARAM_LOB);
        $receipt->bindValue(6, $userId, PDO::PARAM_INT);
        $receipt->bindValue(7, strtolower(trim((string) ($payer['email'] ?? ''))));
        $receipt->execute();
        $reportedPaid = $pdo->prepare("UPDATE payment_transfers SET status = 'payer_reported_paid', reported_paid_at = NOW(), paid_declared_at = NOW() WHERE payment_transfer_id = ? AND status = 'pending_payment'");
        $reportedPaid->execute([$transferId]);
        if ($reportedPaid->rowCount() !== 1) {
            throw new RuntimeException('This payment request could not be submitted for review.');
        }
        payment_transfer_record_event(
            $pdo,
            $transferId,
            'pending_payment',
            'payer_reported_paid',
            $userId,
            strtolower(trim((string) ($payer['email'] ?? ''))),
            'HQ owner submitted the station package payment screenshot for Admin or Moderator review.'
        );
        $pdo->commit();
        payment_transfer_send_notices($pdo, $transferId, 'recipient', 'payer_reported_paid');
        $redirect = '../HQ/station.php?payment=station_limit_pending';
    } elseif (in_array($action, ['report_paid', 'confirm_received', 'mark_not_received', 'cancel_transfer'], true)) {
        $pdo->beginTransaction();
        $transferStmt = $pdo->prepare('SELECT * FROM payment_transfers WHERE payment_transfer_id = ? FOR UPDATE');
        $transferStmt->execute([$transferId]);
        $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);
        if (!$transfer) {
            throw new RuntimeException('Payment record not found.');
        }
        $isPayer = ($userId > 0 && (int) ($transfer['payer_user_id'] ?? 0) === $userId)
            || ($currentUserEmail !== '' && $currentUserEmail === strtolower((string) ($transfer['payer_email'] ?? '')))
            || (int) ($_SESSION['payment_transfer_access_id'] ?? 0) === $transferId;
        if ($action === 'report_paid') {
            if (!$isPayer || (string) $transfer['status'] !== 'pending_payment') {
                throw new RuntimeException('Only the payer can report payment for a request awaiting payment.');
            }
            if ($transfer['payment_type'] === 'hq_registration'
                && (!isset($_FILES['receipt']) || (int) ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) {
                throw new RuntimeException('Upload a payment screenshot before reporting the registration payment.');
            }
            $update = $pdo->prepare("UPDATE payment_transfers SET status = 'payer_reported_paid', reported_paid_at = NOW(), paid_declared_at = NOW() WHERE payment_transfer_id = ? AND status = 'pending_payment'");
            $update->execute([$transferId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('This payment request has already changed.');
            }
            if (isset($_FILES['receipt']) && (int) ($_FILES['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['receipt'];
                if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 5 * 1024 * 1024 || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                    throw new RuntimeException('The optional receipt must be a valid file no larger than 5 MB.');
                }
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
                    throw new RuntimeException('The receipt must be a JPG, PNG, WEBP, or PDF file.');
                }
                $receiptData = file_get_contents((string) $file['tmp_name']);
                $receipt = $pdo->prepare('INSERT INTO payment_transfer_receipts (payment_transfer_id, file_name, mime_type, file_size, file_data, uploaded_by_user_id, uploaded_by_email) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE file_name = VALUES(file_name), mime_type = VALUES(mime_type), file_size = VALUES(file_size), file_data = VALUES(file_data), uploaded_by_user_id = VALUES(uploaded_by_user_id), uploaded_by_email = VALUES(uploaded_by_email)');
                $receipt->bindValue(1, $transferId, PDO::PARAM_INT);
                $receipt->bindValue(2, substr(basename((string) ($file['name'] ?? 'receipt')), 0, 255));
                $receipt->bindValue(3, (string) $mime);
                $receipt->bindValue(4, (int) $file['size'], PDO::PARAM_INT);
                $receipt->bindValue(5, $receiptData, PDO::PARAM_LOB);
                $receipt->bindValue(6, $userId > 0 ? $userId : null, $userId > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
                $receipt->bindValue(7, $currentUserEmail !== '' ? $currentUserEmail : strtolower((string) $transfer['payer_email']));
                $receipt->execute();
            }
            payment_transfer_record_event($pdo, $transferId, 'pending_payment', 'payer_reported_paid', $userId > 0 ? $userId : null, $currentUserEmail !== '' ? $currentUserEmail : strtolower((string) $transfer['payer_email']), 'Payer reported payment; this is not independent verification.');
            $nextStatus = 'payer_reported_paid';
            $noticeAction = 'payer_reported_paid';
            $noticeSide = 'recipient';
        } elseif ($action === 'cancel_transfer') {
            if (!$isPayer || !in_array((string) $transfer['status'], ['pending_payment', 'payer_reported_paid'], true)) {
                throw new RuntimeException('Only the payer can cancel an open payment request.');
            }
            $nextStatus = 'cancelled';
            $update = $pdo->prepare("UPDATE payment_transfers SET status = 'cancelled' WHERE payment_transfer_id = ? AND status IN ('pending_payment', 'payer_reported_paid')");
            $update->execute([$transferId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('This payment request has already changed.');
            }
            payment_transfer_record_event($pdo, $transferId, (string) $transfer['status'], $nextStatus, $userId > 0 ? $userId : null, $currentUserEmail !== '' ? $currentUserEmail : strtolower((string) $transfer['payer_email']), 'Payer cancelled the payment request.');
            $noticeAction = 'cancelled';
            $noticeSide = 'recipient';
        } else {
            $canReceive = in_array($transfer['payment_type'], ['hq_period_extension', 'hq_station_package', 'hq_registration'], true)
                ? payment_transfer_user_is_admin_or_moderator($pdo, $userId)
                : ($transfer['recipient_role'] === 'station_hq'
                    ? payment_transfer_user_owns_hq($pdo, $userId, (int) $transfer['recipient_headquarter_id'])
                    : payment_transfer_user_is_admin($pdo, $userId));
            if (!$canReceive || $isPayer || (string) $transfer['status'] !== 'payer_reported_paid') {
                throw new RuntimeException('Your account is not authorized to update this payment record.');
            }
            if ($action === 'confirm_received') {
                $nextStatus = 'received';
                $update = $pdo->prepare("UPDATE payment_transfers SET status = 'received', received_by_user_id = ?, received_at = NOW(), confirmed_by_user_id = ?, confirmed_at = NOW() WHERE payment_transfer_id = ? AND status = 'payer_reported_paid'");
                $update->execute([$userId, $userId, $transferId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('This payment record has already been updated.');
                }
                if ($transfer['payment_type'] === 'hq_extra_station') {
                    payment_transfer_apply_hq_entitlements($pdo, $transfer);
                }
                $eventNote = 'Designated recipient recorded receipt.';
                if ($transfer['payment_type'] === 'hq_registration') {
                    $registrationActivated = payment_transfer_activate_hq_registration($pdo, $transfer);
                    $eventNote = $registrationActivated
                        ? 'Admin or Moderator confirmed the registration payment for the approved HQ.'
                        : 'Admin or Moderator confirmed the registration payment. The HQ service package will activate after the request is approved.';
                }
                if (in_array($transfer['payment_type'], ['hq_period_extension', 'hq_station_package'], true)) {
                    if (!payment_transfer_ensure_hq_service_expiry_schema($pdo)) {
                        throw new RuntimeException('HQ service-period storage is unavailable.');
                    }
                    $headquarterId = (int) ($transfer['payer_headquarter_id'] ?? 0);
                    $months = (int) ($transfer['term_months'] ?? 0);
                    if ($headquarterId <= 0 || $months < 1 || $months > BILLING_HQ_EXTENSION_MAX_MONTHS) {
                        throw new RuntimeException('The service extension details are invalid.');
                    }
                    $expiryStmt = $pdo->prepare('SELECT service_expires_at FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
                    $expiryStmt->execute([$headquarterId]);
                    $currentExpiry = $expiryStmt->fetchColumn();
                    if ($currentExpiry === false) {
                        throw new RuntimeException('The company account for this extension no longer exists.');
                    }
                    if ($transfer['payment_type'] === 'hq_station_package') {
                        payment_transfer_apply_hq_station_package($pdo, $transfer);
                    }
                    $now = new DateTimeImmutable();
                    $start = $currentExpiry && new DateTimeImmutable((string) $currentExpiry) > $now
                        ? new DateTimeImmutable((string) $currentExpiry)
                        : $now;
                    $newExpiry = payment_transfer_add_months_clamped($start, $months)->format('Y-m-d H:i:s');
                    $expiryUpdate = $pdo->prepare('UPDATE headquarter SET service_expires_at = ? WHERE headquarter_id = ?');
                    $expiryUpdate->execute([$newExpiry, $headquarterId]);
                                        $ownerStatus = $pdo->prepare(
                                                "UPDATE users u
                                                 INNER JOIN headquarter h ON h.headquarters_owner_id = u.user_id
                                                 SET u.status = 'active', u.updated_at = NOW()
                                                 WHERE h.headquarter_id = ? AND LOWER(COALESCE(u.status, '')) = 'expired'
                                                     AND (h.business_license_expire_date IS NULL OR h.business_license_expire_date = '' OR h.business_license_expire_date >= CURDATE())"
                                        );
                                        $ownerStatus->execute([$headquarterId]);
                    $eventNote = $transfer['payment_type'] === 'hq_station_package'
                        ? 'Admin or Moderator confirmed the all-in-one station package and extended HQ service through ' . $newExpiry . '.'
                        : 'Admin or Moderator recorded receipt and extended HQ service through ' . $newExpiry . '.';
                }
                if ($transfer['payment_type'] === 'hq_station_operating_quote') {
                    $applied = payment_transfer_apply_operating_fee($pdo, $transfer);
                    $eventNote = $applied
                        ? 'Designated recipient recorded receipt and applied the quoted monthly station operating fee.'
                        : 'Designated recipient recorded receipt; the station is closed, so no operating entitlement was applied.';
                }
                payment_transfer_record_event($pdo, $transferId, 'payer_reported_paid', 'received', $userId, $currentUserEmail, $eventNote);
                $noticeAction = 'received';
            } else {
                $note = trim((string) ($_POST['decision_note'] ?? ''));
                $note = substr($note, 0, 500);
                $nextStatus = 'rejected';
                $update = $pdo->prepare("UPDATE payment_transfers SET status = 'rejected', rejected_by_user_id = ?, rejected_at = NOW(), decision_note = ? WHERE payment_transfer_id = ? AND status = 'payer_reported_paid'");
                $update->execute([$userId, $note !== '' ? $note : 'Recipient reported that the external payment was not received.', $transferId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('This payment record has already been updated.');
                }
                payment_transfer_record_event($pdo, $transferId, 'payer_reported_paid', 'rejected', $userId, $currentUserEmail, $note !== '' ? $note : 'Recipient did not confirm receipt.');
                $noticeAction = 'rejected';
            }
            $noticeSide = 'payer';
        }
        $pdo->commit();
        payment_transfer_send_notices($pdo, $transferId, $noticeSide, $noticeAction);
        $redirect = in_array($returnTo, $allowedReturnTargets, true)
            ? $returnTo
            : '/php/payment_center.php?updated=' . rawurlencode($nextStatus);
    } else {
        throw new RuntimeException('Unknown payment action.');
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['payment_transfer_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
    header('Location: ' . $redirect);
    exit;
}

$_SESSION['payment_transfer_flash'] = ['type' => 'success', 'message' => 'Payment request or status updated. The website does not move money; only the designated recipient’s recorded receipt can unlock the related feature.'];
header('Location: ' . $redirect);
exit;

function payment_transfer_apply_hq_entitlements(PDO $pdo, array $transfer): void
{
    $hqId = (int) $transfer['payer_headquarter_id'];
    $quantity = max(1, (int) $transfer['quantity']);
    $months = max(1, (int) $transfer['term_months']);
    $month = DateTimeImmutable::createFromFormat('!Y-m', (string) $transfer['service_start_month']);
    if (!$month) {
        throw new RuntimeException('The service period on this payment record is invalid.');
    }

    for ($monthIndex = 0; $monthIndex < $months; $monthIndex++) {
        $yearMonth = $month->modify('+' . $monthIndex . ' months')->format('Y-m');
        $slotInsert = $pdo->prepare(
            'INSERT INTO company_loyalty_station_slots
                (headquarter_id, `year_month`, slots_purchased, mmk_slots_purchased, points_spent)
             VALUES (?, ?, 0, ?, 0)
             ON DUPLICATE KEY UPDATE mmk_slots_purchased = mmk_slots_purchased + VALUES(mmk_slots_purchased)'
        );
        $slotInsert->execute([$hqId, $yearMonth, $quantity]);

        $entitlement = $pdo->prepare(
            'INSERT INTO hq_station_mmk_entitlements (payment_transfer_id, headquarter_id, `year_month`, slot_number)
             VALUES (?, ?, ?, ?)'
        );
        for ($slotNumber = 1; $slotNumber <= $quantity; $slotNumber++) {
            $entitlement->execute([(int) $transfer['payment_transfer_id'], $hqId, $yearMonth, $slotNumber]);
        }
        payment_transfer_assign_mmk_entitlements($pdo, $hqId, $yearMonth);
    }
}

function payment_transfer_create_pending_registration_hq(PDO $pdo, array $transfer, int $reviewerId): int
{
    $requestId = (int) ($transfer['request_id'] ?? 0);
    $transferId = (int) ($transfer['payment_transfer_id'] ?? 0);
    if ($requestId <= 0 || $transferId <= 0) {
        throw new RuntimeException('The registration payment is not linked to a valid request.');
    }

    $requestStmt = $pdo->prepare('SELECT * FROM system_requests WHERE request_id = ? FOR UPDATE');
    $requestStmt->execute([$requestId]);
    $request = $requestStmt->fetch(PDO::FETCH_ASSOC);
    if (!$request || (string) $request['request_type'] !== 'hq_registration') {
        throw new RuntimeException('The HQ registration request could not be verified.');
    }
    if ((int) ($request['headquarter_id'] ?? 0) > 0) {
        throw new RuntimeException('This registration request is already linked to an HQ.');
    }
    if (strtolower((string) $request['status']) !== 'pending') {
        throw new RuntimeException('Only a pending HQ registration can create an HQ profile.');
    }

    $details = json_decode((string) ($request['request_details'] ?? ''), true);
    if (!is_array($details)) {
        throw new RuntimeException('The company registration details are invalid.');
    }
    $stationCount = (int) ($details['requested_station_count'] ?? 0);
    $months = (int) ($details['requested_service_months'] ?? 1);
    $extraStationCount = $stationCount - BILLING_HQ_INCLUDED_STATION_SLOTS;
    if ($extraStationCount < 0 || $stationCount > 60 || $months < 1 || $months > 24) {
        throw new RuntimeException('The paid station count or service period is invalid.');
    }

    $paymentCheck = $pdo->prepare(
        "SELECT status, amount_mmk, quantity, term_months, service_start_month
         FROM payment_transfers
         WHERE payment_transfer_id = ? AND request_id = ? AND payment_type = 'hq_registration'
         FOR UPDATE"
    );
    $paymentCheck->execute([$transferId, $requestId]);
    $payment = $paymentCheck->fetch(PDO::FETCH_ASSOC);
    if (!$payment || (string) $payment['status'] !== 'received' || (float) $payment['amount_mmk'] <= 0
        || (int) $payment['quantity'] !== $stationCount || (int) $payment['term_months'] !== $months) {
        throw new RuntimeException('The received payment does not match the requested HQ service term.');
    }
    $receiptCheck = $pdo->prepare('SELECT 1 FROM payment_transfer_receipts WHERE payment_transfer_id = ? LIMIT 1');
    $receiptCheck->execute([$transferId]);
    if (!$receiptCheck->fetchColumn()) {
        throw new RuntimeException('The registration payment screenshot is missing.');
    }

    $ownerEmail = strtolower(trim((string) ($request['headquarter_owner_email'] ?: $request['sender_email'])));
    $ownerStmt = $pdo->prepare('SELECT user_id, headquarter_id FROM users WHERE LOWER(TRIM(email)) = ? LIMIT 1 FOR UPDATE');
    $ownerStmt->execute([$ownerEmail]);
    $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
    if (!$owner || (int) ($owner['headquarter_id'] ?? 0) > 0) {
        throw new RuntimeException('The owner account is unavailable or already linked to an HQ.');
    }
    $roleStmt = $pdo->prepare("SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) IN ('hq', 'headquarter', 'headquarters') ORDER BY (role_id = 3) DESC LIMIT 1");
    $roleStmt->execute();
    $hqRoleId = (int) ($roleStmt->fetchColumn() ?: 0);
    if ($hqRoleId !== 3) {
        throw new RuntimeException('The HQ account role must use role ID 3.');
    }

    $licenseExpiryDate = substr(trim((string) ($request['headquarter_expire_at'] ?? '')), 0, 10);
    $licenseExpiry = DateTimeImmutable::createFromFormat('!Y-m-d', $licenseExpiryDate);
    $expiryErrors = DateTimeImmutable::getLastErrors();
    if (!$licenseExpiry || ($expiryErrors !== false && ($expiryErrors['warning_count'] > 0 || $expiryErrors['error_count'] > 0))
        || $licenseExpiry->format('Y-m-d') !== $licenseExpiryDate) {
        throw new RuntimeException('The HQ registration is missing a valid license expiry date.');
    }

    $serviceExpiry = payment_transfer_add_months_clamped(new DateTimeImmutable(), $months)->format('Y-m-d H:i:s');
    $headquarterInsert = $pdo->prepare(
        "INSERT INTO headquarter
            (company_name, company_logo, contact_number, headquarter_email, location,
             business_license, business_license_image, business_license_expire_date,
             expire_at, station_count, status, service_expires_at, included_stations_free_until)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NULL)"
    );
    $headquarterInsert->execute([
        (string) $request['sender_entity'],
        (string) $request['headquarter_company_logo'],
        (string) $request['headquarter_contact_number'],
        (string) $request['headquarter_email'],
        (string) $request['location'],
        (string) $request['document_name'],
        (string) $request['document_path'],
        $licenseExpiryDate,
        $licenseExpiryDate . ' 23:59:59',
        BILLING_HQ_INCLUDED_STATION_SLOTS,
        $serviceExpiry,
    ]);
    $headquarterId = (int) $pdo->lastInsertId();

    $ownerUpdate = $pdo->prepare(
        "UPDATE users SET headquarter_id = ?, business_role_id = ?, status = 'pending', updated_at = NOW()
         WHERE user_id = ? AND (headquarter_id IS NULL OR headquarter_id = 0)"
    );
    $ownerUpdate->execute([$headquarterId, $hqRoleId, (int) $owner['user_id']]);
    if ($ownerUpdate->rowCount() !== 1) {
        throw new RuntimeException('Could not link the owner account to the pending HQ.');
    }
    $hqOwnerUpdate = $pdo->prepare('UPDATE headquarter SET headquarters_owner_id = ? WHERE headquarter_id = ?');
    $hqOwnerUpdate->execute([(int) $owner['user_id'], $headquarterId]);

    $scope = $pdo->prepare(
        "INSERT INTO business_account_scope
            (user_id, business_role_id, headquarter_id, branch_manager_user_id, station_id, status)
         VALUES (?, ?, ?, NULL, NULL, 'pending')
         ON DUPLICATE KEY UPDATE headquarter_id = VALUES(headquarter_id), status = 'pending'"
    );
    $scope->execute([(int) $owner['user_id'], $hqRoleId, $headquarterId]);

    $paymentLink = $pdo->prepare('UPDATE payment_transfers SET payer_headquarter_id = ? WHERE payment_transfer_id = ?');
    $paymentLink->execute([$headquarterId, $transferId]);
    $packageTransfer = [
        'payment_transfer_id' => $transferId,
        'payer_headquarter_id' => $headquarterId,
        'quantity' => (int) $payment['quantity'],
        'term_months' => $months,
        'service_start_month' => (string) $payment['service_start_month'],
    ];
    payment_transfer_apply_hq_station_package($pdo, $packageTransfer);
    $requestUpdate = $pdo->prepare(
        "UPDATE system_requests
         SET headquarter_id = ?, status = 'approved', review_reason = 'Payment receipt confirmed; HQ pending activation.',
             reviewed_at = NOW(), reviewed_by = ?, updated_at = NOW()
         WHERE request_id = ? AND status = 'pending' AND headquarter_id IS NULL"
    );
    $requestUpdate->execute([$headquarterId, $reviewerId, $requestId]);
    if ($requestUpdate->rowCount() !== 1) {
        throw new RuntimeException('The registration request changed before the HQ could be linked.');
    }

    return $headquarterId;
}
