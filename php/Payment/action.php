<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../payment_transfers.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if (empty($_SESSION['is_logged_in']) || $userId <= 0) {
    http_response_code(401);
    exit('Please sign in to continue.');
}
$currentUserStmt = $pdo->prepare('SELECT email FROM users WHERE user_id = ? LIMIT 1');
$currentUserStmt->execute([$userId]);
$currentUserEmail = strtolower(trim((string) $currentUserStmt->fetchColumn()));

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

$action = trim((string) ($_POST['action'] ?? ''));
$transferId = (int) ($_POST['transfer_id'] ?? 0);
$statusMessage = '';
$redirect = '/php/payment_center.php';
if ($action === 'customer_paid') {
    $redirect = '/php/Customer/usage_history.php';
} elseif ($action === 'create_extra_station') {
    $redirect = '/php/HQ/station.php';
}

try {
    if ($action === 'customer_paid') {
        if ((string) ($_POST['i_paid'] ?? '') !== '1') {
            throw new RuntimeException('Confirm that you made the external payment before submitting.');
        }
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
             WHERE usage_id = ? AND status IN ('pending_transfer', 'confirmed')
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
            'payment_method' => 'paid at station',
        ]);
        $pdo->commit();
        payment_transfer_send_notices($pdo, $transferId, 'recipient', 'pending');
        payment_transfer_send_notices($pdo, $transferId, 'payer', 'pending');
        $redirect = '/php/Customer/usage_history.php?payment=pending';
    } elseif ($action === 'create_extra_station') {
        if ((string) ($_POST['i_paid'] ?? '') !== '1') {
            throw new RuntimeException('Confirm that you made the external transfer before submitting.');
        }
        $hqStmt = $pdo->prepare('SELECT headquarter_id, email FROM users WHERE user_id = ? LIMIT 1');
        $hqStmt->execute([$userId]);
        $payer = $hqStmt->fetch(PDO::FETCH_ASSOC);
        $headquarterId = (int) ($payer['headquarter_id'] ?? 0);
        if ($headquarterId <= 0 || !payment_transfer_user_owns_hq($pdo, $userId, $headquarterId)) {
            throw new RuntimeException('Only the owning HQ account can request extra-station payment.');
        }
        $quantity = (int) ($_POST['quantity'] ?? 1);
        $termMonths = (int) ($_POST['term_months'] ?? 1);
        $paymentMethod = trim((string) ($_POST['payment_method'] ?? ''));
        $allowedMethods = ['KPay', 'Wave Pay', 'AYA Pay', 'CB Pay', 'AYA Bank', 'CB Bank', 'KBZ Bank', 'YOMA Bank'];
        $allowedTerms = array_map(static fn (array $term): int => (int) $term['months'], BILLING_HQ_TERM_DISCOUNTS);
        if ($quantity < 1 || $quantity > 60 || !in_array($termMonths, $allowedTerms, true) || !in_array($paymentMethod, $allowedMethods, true)) {
            throw new RuntimeException('Choose 1–60 extra stations, a valid billing term, and a payment method.');
        }

        $volumePercent = 0;
        foreach (BILLING_HQ_VOLUME_DISCOUNTS as $tier) {
            if ($quantity >= (int) $tier['min']) {
                $volumePercent = (int) $tier['percent'];
            }
        }
        $termPercent = 0;
        foreach (BILLING_HQ_TERM_DISCOUNTS as $term) {
            if ((int) $term['months'] === $termMonths) {
                $termPercent = (int) $term['percent'];
                break;
            }
        }
        $baseAmount = (float) BILLING_HQ_EXTRA_STATION_MMK_MONTHLY * $quantity * $termMonths;
        $amount = round($baseAmount * (1 - $volumePercent / 100) * (1 - $termPercent / 100), 2);
        if ($amount <= 0) {
            throw new RuntimeException('The calculated payment amount is invalid.');
        }

        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT headquarter_id FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
        $lock->execute([$headquarterId]);
        if (!$lock->fetchColumn()) {
            throw new RuntimeException('Could not verify this HQ account.');
        }
        $duplicate = $pdo->prepare(
            "SELECT 1 FROM payment_transfers
             WHERE payer_headquarter_id = ? AND payment_type = 'hq_extra_station'
               AND status = 'pending_transfer' LIMIT 1"
        );
        $duplicate->execute([$headquarterId]);
        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('This HQ already has an extra-station payment awaiting confirmation.');
        }
        $transferId = payment_transfer_create($pdo, [
            'payment_type' => 'hq_extra_station',
            'amount_mmk' => $amount,
            'description' => sprintf('%d extra station(s) for %d month(s), including capacity and operation', $quantity, $termMonths),
            'quantity' => $quantity,
            'term_months' => $termMonths,
            'service_start_month' => date('Y-m'),
            'payer_user_id' => $userId,
            'payer_email' => (string) ($payer['email'] ?? ''),
            'payer_headquarter_id' => $headquarterId,
            'recipient_role' => BILLING_PLATFORM_RECEIVER_ROLE,
            'payment_method' => $paymentMethod,
        ]);
        $pdo->commit();
        payment_transfer_send_notices($pdo, $transferId, 'recipient', 'pending');
        payment_transfer_send_notices($pdo, $transferId, 'payer', 'pending');
        $redirect = '/php/HQ/station.php?payment=pending';
    } elseif (in_array($action, ['confirm_received', 'mark_not_received', 'cancel_transfer'], true)) {
        $pdo->beginTransaction();
        $transferStmt = $pdo->prepare('SELECT * FROM payment_transfers WHERE payment_transfer_id = ? FOR UPDATE');
        $transferStmt->execute([$transferId]);
        $transfer = $transferStmt->fetch(PDO::FETCH_ASSOC);
        if (!$transfer) {
            throw new RuntimeException('Payment record not found.');
        }
        if ((string) $transfer['status'] !== 'pending_transfer') {
            throw new RuntimeException('This payment record has already been updated.');
        }

        if ($action === 'cancel_transfer') {
            $isPayer = (int) ($transfer['payer_user_id'] ?? 0) === $userId
                || ($currentUserEmail !== '' && $currentUserEmail === strtolower((string) ($transfer['payer_email'] ?? '')));
            if (!$isPayer) {
                throw new RuntimeException('Only the payer can cancel this pending payment notice.');
            }
            $nextStatus = 'cancelled';
            $update = $pdo->prepare("UPDATE payment_transfers SET status = 'cancelled' WHERE payment_transfer_id = ? AND status = 'pending_transfer'");
            $update->execute([$transferId]);
            $noticeAction = 'cancelled';
            $noticeSide = 'recipient';
        } else {
            $canReceive = $transfer['recipient_role'] === 'station_hq'
                ? payment_transfer_user_owns_hq($pdo, $userId, (int) $transfer['recipient_headquarter_id'])
                : payment_transfer_user_is_admin($pdo, $userId);
            $isPayer = (int) ($transfer['payer_user_id'] ?? 0) === $userId
                || ($currentUserEmail !== '' && $currentUserEmail === strtolower((string) ($transfer['payer_email'] ?? '')));
            if (!$canReceive || $isPayer) {
                throw new RuntimeException('Your account is not authorized to update this payment record.');
            }
            if ($action === 'confirm_received') {
                $nextStatus = 'confirmed';
                $update = $pdo->prepare("UPDATE payment_transfers SET status = 'confirmed', confirmed_by_user_id = ?, confirmed_at = NOW() WHERE payment_transfer_id = ? AND status = 'pending_transfer'");
                $update->execute([$userId, $transferId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('This payment record has already been updated.');
                }
                if ($transfer['payment_type'] === 'hq_extra_station') {
                    payment_transfer_apply_hq_entitlements($pdo, $transfer);
                }
                $noticeAction = 'confirmed';
            } else {
                $note = trim((string) ($_POST['decision_note'] ?? ''));
                $note = substr($note, 0, 500);
                $nextStatus = 'rejected';
                $update = $pdo->prepare("UPDATE payment_transfers SET status = 'rejected', rejected_by_user_id = ?, rejected_at = NOW(), decision_note = ? WHERE payment_transfer_id = ? AND status = 'pending_transfer'");
                $update->execute([$userId, $note !== '' ? $note : 'Recipient reported that the external payment was not received.', $transferId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('This payment record has already been updated.');
                }
                $noticeAction = 'rejected';
            }
            $noticeSide = 'payer';
        }
        $pdo->commit();
        payment_transfer_send_notices($pdo, $transferId, $noticeSide, $noticeAction);
        $redirect = $transfer['recipient_role'] === 'admin'
            ? '/php/payment_center.php?updated=' . rawurlencode($nextStatus)
            : ($transfer['recipient_role'] === 'station_hq'
                ? '/php/payment_center.php?updated=' . rawurlencode($nextStatus)
                : '/php/payment_center.php?updated=' . rawurlencode($nextStatus));
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

$_SESSION['payment_transfer_flash'] = ['type' => 'success', 'message' => 'Payment status recorded. This does not process or move money through the website.'];
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
                (headquarter_id, year_month, slots_purchased, mmk_slots_purchased, points_spent)
             VALUES (?, ?, 0, ?, 0)
             ON DUPLICATE KEY UPDATE mmk_slots_purchased = mmk_slots_purchased + VALUES(mmk_slots_purchased)'
        );
        $slotInsert->execute([$hqId, $yearMonth, $quantity]);

        $entitlement = $pdo->prepare(
            'INSERT INTO hq_station_mmk_entitlements (payment_transfer_id, headquarter_id, year_month, slot_number)
             VALUES (?, ?, ?, ?)'
        );
        for ($slotNumber = 1; $slotNumber <= $quantity; $slotNumber++) {
            $entitlement->execute([(int) $transfer['payment_transfer_id'], $hqId, $yearMonth, $slotNumber]);
        }
        payment_transfer_assign_mmk_entitlements($pdo, $hqId, $yearMonth);
    }
}
