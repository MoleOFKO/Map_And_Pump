<?php
require_once __DIR__ . '/billing_config.php';
require_once __DIR__ . '/loyalty_points.php';
require_once __DIR__ . '/Login/mail_templates.php';

/** Ensure the external-payment status tables exist. No money is moved by this schema. */
function payment_transfer_schema_ready(PDO $pdo): bool
{
    static $ready = false;
    static $attempted = false;
    if ($ready) {
        return true;
    }
    if ($attempted) {
        return false;
    }
    $attempted = true;

    try {
        if (!loyalty_countermeasures_schema_ready($pdo)) {
            return false;
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS payment_transfers (
                payment_transfer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference_code VARCHAR(48) NOT NULL,
                payment_type VARCHAR(40) NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending_transfer',
                amount_mmk DECIMAL(14,2) NOT NULL,
                description VARCHAR(255) NOT NULL,
                quantity INT NOT NULL DEFAULT 1,
                term_months INT NOT NULL DEFAULT 1,
                service_start_month CHAR(7) DEFAULT NULL,
                payer_user_id INT DEFAULT NULL,
                payer_email VARCHAR(255) DEFAULT NULL,
                payer_headquarter_id INT DEFAULT NULL,
                recipient_role VARCHAR(24) NOT NULL,
                recipient_headquarter_id INT DEFAULT NULL,
                request_id INT UNSIGNED DEFAULT NULL,
                usage_id INT DEFAULT NULL,
                station_id INT DEFAULT NULL,
                payment_method VARCHAR(32) DEFAULT NULL,
                paid_declared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_by_user_id INT DEFAULT NULL,
                confirmed_at DATETIME DEFAULT NULL,
                rejected_by_user_id INT DEFAULT NULL,
                rejected_at DATETIME DEFAULT NULL,
                decision_note VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (payment_transfer_id),
                UNIQUE KEY uq_payment_transfer_reference (reference_code),
                KEY idx_payment_transfer_request (request_id),
                KEY idx_payment_transfer_status (status, recipient_role, recipient_headquarter_id),
                KEY idx_payment_transfer_payer (payer_user_id, created_at),
                KEY idx_payment_transfer_usage (usage_id),
                KEY idx_payment_transfer_hq (payer_headquarter_id, payment_type, service_start_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS hq_station_mmk_entitlements (
                entitlement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_transfer_id BIGINT UNSIGNED NOT NULL,
                headquarter_id INT NOT NULL,
                year_month CHAR(7) NOT NULL,
                slot_number INT NOT NULL,
                station_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (entitlement_id),
                UNIQUE KEY uq_hq_mmk_entitlement (payment_transfer_id, year_month, slot_number),
                KEY idx_hq_mmk_entitlement_available (headquarter_id, year_month, station_id),
                KEY idx_hq_mmk_entitlement_station (station_id, year_month)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Upgrade installations that created the first version of this table.
        $oldUnique = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transfers'
               AND INDEX_NAME = 'uq_payment_transfer_request' AND NON_UNIQUE = 0"
        );
        $oldUnique->execute();
        if ((int) $oldUnique->fetchColumn() > 0) {
            $pdo->exec('ALTER TABLE payment_transfers DROP INDEX uq_payment_transfer_request');
        }
        $ready = true;
        return true;
    } catch (Throwable $e) {
        error_log('payment_transfer_schema_ready: ' . $e->getMessage());
        return false;
    }
}

function payment_transfer_new_reference(): string
{
    return 'PAY-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

/** Insert a status record for an external MMK payment, within the caller's transaction. */
function payment_transfer_create(PDO $pdo, array $values): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO payment_transfers
            (reference_code, payment_type, status, amount_mmk, description, quantity, term_months,
             service_start_month, payer_user_id, payer_email, payer_headquarter_id, recipient_role,
             recipient_headquarter_id, request_id, usage_id, station_id, payment_method)
         VALUES (?, ?, \'pending_transfer\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        payment_transfer_new_reference(),
        (string) $values['payment_type'],
        number_format((float) $values['amount_mmk'], 2, '.', ''),
        (string) $values['description'],
        max(1, (int) ($values['quantity'] ?? 1)),
        max(1, (int) ($values['term_months'] ?? 1)),
        $values['service_start_month'] ?? null,
        !empty($values['payer_user_id']) ? (int) $values['payer_user_id'] : null,
        !empty($values['payer_email']) ? strtolower(trim((string) $values['payer_email'])) : null,
        !empty($values['payer_headquarter_id']) ? (int) $values['payer_headquarter_id'] : null,
        (string) $values['recipient_role'],
        !empty($values['recipient_headquarter_id']) ? (int) $values['recipient_headquarter_id'] : null,
        !empty($values['request_id']) ? (int) $values['request_id'] : null,
        !empty($values['usage_id']) ? (int) $values['usage_id'] : null,
        !empty($values['station_id']) ? (int) $values['station_id'] : null,
        !empty($values['payment_method']) ? substr(trim((string) $values['payment_method']), 0, 32) : null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Return active platform Admin accounts that may receive platform-fee notices. */
function payment_transfer_admin_recipients(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT DISTINCT u.user_id, u.email
         FROM users u
         WHERE LOWER(COALESCE(u.status, 'active')) IN ('active', 'approved')
           AND (
             EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.role_id AND LOWER(TRIM(r.role_name)) = 'admin')
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.business_role_id AND LOWER(TRIM(r.role_name)) = 'admin')
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.current_role_id AND LOWER(TRIM(r.role_name)) = 'admin')
           )
         ORDER BY u.user_id"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Return the owning HQ account contact for customer fuel payment notices. */
function payment_transfer_hq_recipient(PDO $pdo, int $headquarterId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.user_id, u.email
         FROM headquarter h
         INNER JOIN users u ON u.user_id = h.headquarters_owner_id
         WHERE h.headquarter_id = ? AND LOWER(COALESCE(u.status, \'active\')) IN (\'active\', \'approved\')
         LIMIT 1'
    );
    $stmt->execute([$headquarterId]);
    $recipient = $stmt->fetch(PDO::FETCH_ASSOC);
    return $recipient ?: null;
}

function payment_transfer_user_is_admin(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM users u
         WHERE u.user_id = ? AND LOWER(COALESCE(u.status, 'active')) IN ('active', 'approved')
           AND (
             EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.role_id AND LOWER(TRIM(r.role_name)) = 'admin')
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.business_role_id AND LOWER(TRIM(r.role_name)) = 'admin')
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.current_role_id AND LOWER(TRIM(r.role_name)) = 'admin')
           )
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

function payment_transfer_user_owns_hq(PDO $pdo, int $userId, int $headquarterId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM users u
         INNER JOIN headquarter h ON h.headquarter_id = u.headquarter_id
         WHERE u.user_id = ? AND h.headquarter_id = ?
           AND LOWER(COALESCE(u.status, 'active')) IN ('active', 'approved')
           AND (
             u.user_id = h.headquarters_owner_id
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.role_id AND LOWER(TRIM(r.role_name)) IN ('hq', 'headquarter', 'headquarters'))
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.business_role_id AND LOWER(TRIM(r.role_name)) IN ('hq', 'headquarter', 'headquarters'))
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.current_role_id AND LOWER(TRIM(r.role_name)) IN ('hq', 'headquarter', 'headquarters'))
           )
         LIMIT 1"
    );
    $stmt->execute([$userId, $headquarterId]);
    return (bool) $stmt->fetchColumn();
}

/** Attach confirmed monthly extra-station MMK coverage to existing unpaid extra stations. */
function payment_transfer_assign_mmk_entitlements(PDO $pdo, int $headquarterId, string $yearMonth): void
{
    $baseStmt = $pdo->prepare('SELECT station_count FROM headquarter WHERE headquarter_id = ? FOR UPDATE');
    $baseStmt->execute([$headquarterId]);
    $baseLimit = (int) $baseStmt->fetchColumn();
    if ($baseLimit < 1) {
        return; // Legacy HQs with station_count = 0 have unlimited capacity.
    }
    $pointSlotsStmt = $pdo->prepare(
        'SELECT COALESCE(slots_purchased, 0)
         FROM company_loyalty_station_slots
         WHERE headquarter_id = ? AND year_month = ? LIMIT 1'
    );
    $pointSlotsStmt->execute([$headquarterId, $yearMonth]);
    $pointSlots = max(0, (int) $pointSlotsStmt->fetchColumn());
    $stationsStmt = $pdo->prepare(
        "SELECT b.branch_id
         FROM branches b
         INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
         WHERE sbs.headquarter_id = ? AND LOWER(COALESCE(b.status, 'active')) <> 'closed'
         ORDER BY b.branch_id"
    );
    $stationsStmt->execute([$headquarterId]);
    // Points capacity covers the first extra station ranks. MMK entitlements
    // must start after those ranks so a paid MMK slot maps to a distinct station.
    $stationIds = array_slice(
        array_map('intval', $stationsStmt->fetchAll(PDO::FETCH_COLUMN)),
        $baseLimit + $pointSlots
    );

    foreach ($stationIds as $stationId) {
        $entitlement = $pdo->prepare(
            "SELECT e.entitlement_id, e.payment_transfer_id
             FROM hq_station_mmk_entitlements e
             INNER JOIN payment_transfers pt ON pt.payment_transfer_id = e.payment_transfer_id
             WHERE e.headquarter_id = ? AND e.year_month = ? AND e.station_id IS NULL
               AND pt.status = 'confirmed'
             ORDER BY e.entitlement_id
             LIMIT 1 FOR UPDATE"
        );
        $entitlement->execute([$headquarterId, $yearMonth]);
        $credit = $entitlement->fetch(PDO::FETCH_ASSOC);
        if (!$credit) {
            break;
        }

        $assign = $pdo->prepare('UPDATE hq_station_mmk_entitlements SET station_id = ? WHERE entitlement_id = ? AND station_id IS NULL');
        $assign->execute([$stationId, (int) $credit['entitlement_id']]);
        if ($assign->rowCount() !== 1) {
            continue;
        }
        $feeCheck = $pdo->prepare(
            'SELECT 1 FROM company_loyalty_station_fees
             WHERE headquarter_id = ? AND station_id = ? AND year_month = ? LIMIT 1'
        );
        $feeCheck->execute([$headquarterId, $stationId, $yearMonth]);
        if ($feeCheck->fetchColumn()) {
            continue; // Capacity is consumed; preserve any points-paid fee record.
        }
        $fee = $pdo->prepare(
            "INSERT INTO company_loyalty_station_fees
                (headquarter_id, station_id, year_month, points_paid, payment_method, payment_transfer_id)
             VALUES (?, ?, ?, 0, 'mmk', ?)"
        );
        $fee->execute([$headquarterId, $stationId, $yearMonth, (int) $credit['payment_transfer_id']]);
    }
}

/** Attach already-paid monthly MMK capacity to a newly registered station. */
function payment_transfer_assign_available_entitlements(PDO $pdo, int $headquarterId): void
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT year_month FROM hq_station_mmk_entitlements
         WHERE headquarter_id = ? AND station_id IS NULL ORDER BY year_month'
    );
    $stmt->execute([$headquarterId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $yearMonth) {
        payment_transfer_assign_mmk_entitlements($pdo, $headquarterId, (string) $yearMonth);
    }
}

/** Notify payers and recipients after status transactions commit. */
function payment_transfer_send_notices(PDO $pdo, int $transferId, string $recipientSide, string $action): void
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM payment_transfers WHERE payment_transfer_id = ? LIMIT 1');
        $stmt->execute([$transferId]);
        $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transfer) {
            return;
        }

        if ($recipientSide === 'recipient') {
            $recipients = $transfer['recipient_role'] === 'station_hq'
                ? array_filter([payment_transfer_hq_recipient($pdo, (int) $transfer['recipient_headquarter_id'])])
                : payment_transfer_admin_recipients($pdo);
        } else {
            $recipients = [[
                'user_id' => $transfer['payer_user_id'] ?? null,
                'email' => $transfer['payer_email'] ?? null,
            ]];
        }

        $subjectText = match ($action) {
            'pending' => 'External payment reported; recipient confirmation is pending.',
            'confirmed' => 'The recipient confirmed the external payment was received.',
            'rejected' => 'The recipient reported that the external payment was not received.',
            'cancelled' => 'The payer cancelled the pending external payment notice.',
            default => 'The external payment status was updated.',
        };
        $type = 'payment_' . $action;
        $message = $subjectText . ' Reference ' . $transfer['reference_code'] . '; amount ' . number_format((float) $transfer['amount_mmk'], 2) . ' MMK. This is a status record for a payment made outside the website.';
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            $userId = (int) ($recipient['user_id'] ?? 0) ?: null;
            $notification = $pdo->prepare(
                'INSERT INTO notifications
                    (request_id, recipient_user_id, recipient_email, notification_type, subject, message, delivery_status, delivery_note)
                 VALUES (?, ?, ?, ?, ?, ?, \'not_configured\', NULL)'
            );
            $notification->execute([
                $transfer['request_id'] ? (int) $transfer['request_id'] : null,
                $userId,
                $email !== '' ? $email : null,
                $type,
                'Map & Pump external payment: ' . ucfirst($action),
                $message,
            ]);
            $notificationId = (int) $pdo->lastInsertId();
            $mailResult = mail_send_account_notice($email, [
                'kind' => 'payment',
                'action' => $action,
                'reference_code' => (string) $transfer['reference_code'],
                'amount_mmk' => (string) $transfer['amount_mmk'],
                'description' => (string) $transfer['description'],
            ]);
            $update = $pdo->prepare('UPDATE notifications SET subject = ?, delivery_status = ?, delivery_note = ? WHERE notification_id = ?');
            $update->execute([
                (string) ($mailResult['subject'] ?? 'Map & Pump external payment status'),
                (string) ($mailResult['status'] ?? 'skipped'),
                (string) ($mailResult['note'] ?? ''),
                $notificationId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('payment_transfer_send_notices: ' . $e->getMessage());
    }
}

