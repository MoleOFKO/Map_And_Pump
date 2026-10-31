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
                status VARCHAR(24) NOT NULL DEFAULT 'pending_payment',
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
                reported_paid_at DATETIME DEFAULT NULL,
                received_by_user_id INT DEFAULT NULL,
                received_at DATETIME DEFAULT NULL,
                payer_access_token_hash CHAR(64) DEFAULT NULL,
                payer_access_expires_at DATETIME DEFAULT NULL,
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
                `year_month` CHAR(7) NOT NULL,
                slot_number INT NOT NULL,
                station_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (entitlement_id),
                UNIQUE KEY uq_hq_mmk_entitlement (payment_transfer_id, `year_month`, slot_number),
                KEY idx_hq_mmk_entitlement_available (headquarter_id, `year_month`, station_id),
                KEY idx_hq_mmk_entitlement_station (station_id, `year_month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS hq_station_package_coverage (
                payment_transfer_id BIGINT UNSIGNED NOT NULL,
                headquarter_id INT NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                first_station_number INT NOT NULL DEFAULT 1,
                station_count INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (payment_transfer_id, `year_month`),
                KEY idx_hq_package_coverage_month (headquarter_id, `year_month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Upgrade older installations without dropping their payment records.
        $columns = [
            'reported_paid_at' => 'DATETIME DEFAULT NULL',
            'received_by_user_id' => 'INT DEFAULT NULL',
            'received_at' => 'DATETIME DEFAULT NULL',
            'payer_access_token_hash' => 'CHAR(64) DEFAULT NULL',
            'payer_access_expires_at' => 'DATETIME DEFAULT NULL',
        ];
        foreach ($columns as $column => $definition) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transfers' AND COLUMN_NAME = ?");
            $check->execute([$column]);
            if ((int) $check->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE payment_transfers ADD COLUMN {$column} {$definition}");
            }
        }
        $tokenIndex = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payment_transfers' AND INDEX_NAME = 'idx_payment_transfer_access_token'");
        $tokenIndex->execute();
        if ((int) $tokenIndex->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE payment_transfers ADD KEY idx_payment_transfer_access_token (payer_access_token_hash)');
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS payment_transfer_events (
                event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_transfer_id BIGINT UNSIGNED NOT NULL,
                previous_status VARCHAR(24) DEFAULT NULL,
                new_status VARCHAR(24) NOT NULL,
                actor_user_id INT DEFAULT NULL,
                actor_email VARCHAR(255) DEFAULT NULL,
                note VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (event_id),
                KEY idx_payment_transfer_events (payment_transfer_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS payment_transfer_receipts (
                payment_transfer_id BIGINT UNSIGNED NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(80) NOT NULL,
                file_size INT UNSIGNED NOT NULL,
                file_data MEDIUMBLOB NOT NULL,
                uploaded_by_user_id INT DEFAULT NULL,
                uploaded_by_email VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (payment_transfer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $pdo->exec("INSERT INTO payment_transfer_events (payment_transfer_id, previous_status, new_status, actor_user_id, note, created_at)
                    SELECT payment_transfer_id, 'pending_transfer', 'payer_reported_paid', payer_user_id, 'Legacy payer declaration migrated to the audited payment lifecycle.', COALESCE(paid_declared_at, created_at)
                    FROM payment_transfers WHERE status = 'pending_transfer'");
        $pdo->exec("INSERT INTO payment_transfer_events (payment_transfer_id, previous_status, new_status, actor_user_id, note, created_at)
                    SELECT payment_transfer_id, 'confirmed', 'received', confirmed_by_user_id, 'Legacy recipient confirmation migrated to received status.', COALESCE(confirmed_at, created_at)
                    FROM payment_transfers WHERE status = 'confirmed'");
        $pdo->exec("UPDATE payment_transfers SET status = 'payer_reported_paid', reported_paid_at = COALESCE(reported_paid_at, paid_declared_at) WHERE status = 'pending_transfer'");
        $pdo->exec("UPDATE payment_transfers SET status = 'received', received_by_user_id = COALESCE(received_by_user_id, confirmed_by_user_id), received_at = COALESCE(received_at, confirmed_at) WHERE status = 'confirmed'");
        $pdo->exec("INSERT INTO payment_transfer_events (payment_transfer_id, new_status, actor_user_id, note, created_at)
                    SELECT pt.payment_transfer_id, pt.status,
                           CASE WHEN pt.status = 'rejected' THEN pt.rejected_by_user_id WHEN pt.status = 'received' THEN pt.received_by_user_id ELSE pt.payer_user_id END,
                           'Existing payment status imported into the review history.',
                           CASE WHEN pt.status = 'rejected' THEN COALESCE(pt.rejected_at, pt.updated_at) WHEN pt.status = 'received' THEN COALESCE(pt.received_at, pt.updated_at) ELSE pt.created_at END
                    FROM payment_transfers pt
                    WHERE pt.status IN ('pending_payment', 'payer_reported_paid', 'received', 'rejected', 'cancelled')
                      AND NOT EXISTS (SELECT 1 FROM payment_transfer_events e WHERE e.payment_transfer_id = pt.payment_transfer_id)");

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

function payment_transfer_ensure_hq_service_expiry_schema(PDO $pdo): bool
{
    try {
        foreach ([
            'service_expires_at' => 'DATETIME DEFAULT NULL AFTER expire_at',
            'included_stations_free_until' => 'DATETIME DEFAULT NULL AFTER service_expires_at',
        ] as $column => $definition) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'headquarter'
                   AND COLUMN_NAME = ?"
            );
            $stmt->execute([$column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE headquarter ADD COLUMN `{$column}` {$definition}");
            }
        }
        return true;
    } catch (Throwable $error) {
        error_log('HQ service expiry schema upgrade failed: ' . $error->getMessage());
        return false;
    }
}

function payment_transfer_new_reference(): string
{
    return 'PAY-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function payment_transfer_add_months_clamped(DateTimeImmutable $start, int $months): DateTimeImmutable
{
    $targetMonth = $start->modify('first day of this month')->modify('+' . $months . ' months');
    $day = min((int) $start->format('j'), (int) $targetMonth->format('t'));
    return $targetMonth->setDate((int) $targetMonth->format('Y'), (int) $targetMonth->format('n'), $day)
        ->setTime((int) $start->format('H'), (int) $start->format('i'), (int) $start->format('s'));
}

/** Insert a status record for an external MMK payment, within the caller's transaction. */
function payment_transfer_create(PDO $pdo, array $values): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO payment_transfers
            (reference_code, payment_type, status, amount_mmk, description, quantity, term_months,
             service_start_month, payer_user_id, payer_email, payer_headquarter_id, recipient_role,
             recipient_headquarter_id, request_id, usage_id, station_id, payment_method,
             payer_access_token_hash, payer_access_expires_at)
         VALUES (?, ?, \'pending_payment\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        payment_transfer_new_reference(),
        (string) $values['payment_type'],
        number_format((float) $values['amount_mmk'], 2, '.', ''),
        substr((string) $values['description'], 0, 255),
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
        !empty($values['payer_access_token_hash']) ? (string) $values['payer_access_token_hash'] : null,
        !empty($values['payer_access_expires_at']) ? (string) $values['payer_access_expires_at'] : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    payment_transfer_record_event(
        $pdo,
        $id,
        null,
        'pending_payment',
        array_key_exists('event_actor_user_id', $values) ? ($values['event_actor_user_id'] !== null ? (int) $values['event_actor_user_id'] : null) : (!empty($values['payer_user_id']) ? (int) $values['payer_user_id'] : null),
        array_key_exists('event_actor_email', $values) ? ($values['event_actor_email'] !== null ? (string) $values['event_actor_email'] : null) : (!empty($values['payer_email']) ? (string) $values['payer_email'] : null),
        (string) ($values['event_note'] ?? 'Payment request created with server-calculated amount and recipient.')
    );
    return $id;
}

function payment_transfer_record_event(PDO $pdo, int $transferId, ?string $previous, string $next, ?int $actorId, ?string $actorEmail, ?string $note = null): void
{
    $stmt = $pdo->prepare('INSERT INTO payment_transfer_events (payment_transfer_id, previous_status, new_status, actor_user_id, actor_email, note) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$transferId, $previous, $next, $actorId, $actorEmail, $note !== null ? substr($note, 0, 500) : null]);
}

function payment_transfer_absolute_url(string $path): string
{
    $base = trim((string) (getenv('APP_BASE_URL') ?: ''));
    if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
        $host = preg_replace('/[^a-zA-Z0-9.:-]/', '', (string) $_SERVER['HTTP_HOST']);
        $hostName = strtolower((string) preg_replace('/:\d+$/', '', $host));
        $isLocal = in_array($hostName, ['localhost', '127.0.0.1'], true);
        $forwardedHttps = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
        $scheme = $isLocal && empty($_SERVER['HTTPS']) && !$forwardedHttps ? 'http' : 'https';
        $base = $scheme . '://' . $host;
    } elseif ($base !== '') {
        $parts = parse_url($base);
        if (is_array($parts) && !empty($parts['host'])) {
            $local = in_array(strtolower((string) $parts['host']), ['localhost', '127.0.0.1'], true);
            $base = ($local ? ((string) ($parts['scheme'] ?? 'http')) : 'https') . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
                . (isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '');
        }
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
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

/** Return active Admin and Moderator accounts for extension-payment review notices. */
function payment_transfer_review_recipients(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT DISTINCT u.user_id, u.email
         FROM users u
         WHERE LOWER(COALESCE(u.status, 'active')) IN ('active', 'approved')
           AND (
             EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.role_id AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator'))
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.business_role_id AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator'))
             OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.current_role_id AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator'))
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

function payment_transfer_user_is_admin_or_moderator(PDO $pdo, int $userId): bool
{
        $stmt = $pdo->prepare(
                "SELECT 1 FROM users u
                 WHERE u.user_id = ? AND LOWER(COALESCE(u.status, 'active')) IN ('active', 'approved')
                     AND (
                         EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.role_id AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator'))
                         OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.business_role_id AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator'))
                         OR EXISTS (SELECT 1 FROM roles r WHERE r.role_id = u.current_role_id AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator'))
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
    $stationsStmt = $pdo->prepare(
        "SELECT b.branch_id
         FROM branches b
         INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
         WHERE sbs.headquarter_id = ? AND LOWER(COALESCE(b.status, 'active')) <> 'closed'
         ORDER BY b.branch_id"
    );
    $stationsStmt->execute([$headquarterId]);
    $stationIds = array_slice(array_map('intval', $stationsStmt->fetchAll(PDO::FETCH_COLUMN)), max(0, $baseLimit));

    foreach ($stationIds as $stationId) {
        $assignedCheck = $pdo->prepare(
            'SELECT 1 FROM hq_station_mmk_entitlements
             WHERE headquarter_id = ? AND station_id = ? AND `year_month` = ? LIMIT 1'
        );
        $assignedCheck->execute([$headquarterId, $stationId, $yearMonth]);
        if ($assignedCheck->fetchColumn()) {
            continue;
        }
        $entitlement = $pdo->prepare(
            "SELECT e.entitlement_id, e.payment_transfer_id
             FROM hq_station_mmk_entitlements e
             INNER JOIN payment_transfers pt ON pt.payment_transfer_id = e.payment_transfer_id
             WHERE e.headquarter_id = ? AND e.`year_month` = ? AND e.station_id IS NULL
               AND pt.status = 'received'
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
        $fee = $pdo->prepare(
            "INSERT IGNORE INTO company_loyalty_station_fees
                (headquarter_id, station_id, `year_month`, payment_method, payment_transfer_id)
             VALUES (?, ?, ?, 'mmk', ?)"
        );
        $fee->execute([$headquarterId, $stationId, $yearMonth, (int) $credit['payment_transfer_id']]);
    }
}

/** Attach already-paid monthly MMK capacity to a newly registered station. */
function payment_transfer_assign_available_entitlements(PDO $pdo, int $headquarterId): void
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT `year_month` FROM hq_station_mmk_entitlements
         WHERE headquarter_id = ? AND station_id IS NULL ORDER BY `year_month`'
    );
    $stmt->execute([$headquarterId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $yearMonth) {
        payment_transfer_assign_mmk_entitlements($pdo, $headquarterId, (string) $yearMonth);
    }
}

/** Apply already-paid package fees to a newly registered station when covered. */
function payment_transfer_assign_package_fees(PDO $pdo, int $headquarterId, int $stationId): void
{
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM branches b
         INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
         WHERE sbs.headquarter_id = ? AND LOWER(COALESCE(b.status, 'active')) <> 'closed'"
    );
    $countStmt->execute([$headquarterId]);
    $stationCount = (int) $countStmt->fetchColumn();

    $coverageStmt = $pdo->prepare(
        "SELECT c.payment_transfer_id, c.`year_month`
         FROM hq_station_package_coverage c
         INNER JOIN payment_transfers pt ON pt.payment_transfer_id = c.payment_transfer_id
         WHERE c.headquarter_id = ? AND c.station_count >= ?
           AND c.first_station_number <= ?
           AND c.`year_month` >= ? AND pt.status = 'received'
         ORDER BY c.`year_month`, c.payment_transfer_id"
    );
    $coverageStmt->execute([$headquarterId, $stationCount, $stationCount, date('Y-m')]);
    $insertFee = $pdo->prepare(
        "INSERT IGNORE INTO company_loyalty_station_fees
            (headquarter_id, station_id, `year_month`, payment_method, payment_transfer_id)
         VALUES (?, ?, ?, 'mmk_package', ?)"
    );
    foreach ($coverageStmt->fetchAll(PDO::FETCH_ASSOC) as $coverage) {
        $insertFee->execute([
            $headquarterId,
            $stationId,
            (string) $coverage['year_month'],
            (int) $coverage['payment_transfer_id'],
        ]);
    }
}

/** Apply a negotiated MMK operating-fee quote only after its recipient records receipt. */
function payment_transfer_apply_operating_fee(PDO $pdo, array $transfer): bool
{
    $headquarterId = (int) ($transfer['payer_headquarter_id'] ?? 0);
    $stationId = (int) ($transfer['station_id'] ?? 0);
    $yearMonth = (string) ($transfer['service_start_month'] ?? '');
    if ($headquarterId <= 0 || $stationId <= 0 || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $yearMonth)) {
        throw new RuntimeException('The negotiated station-fee quote is missing its station or service month.');
    }
    $stationStmt = $pdo->prepare("SELECT b.status FROM branches b INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id WHERE b.branch_id = ? AND sbs.headquarter_id = ? LIMIT 1 FOR UPDATE");
    $stationStmt->execute([$stationId, $headquarterId]);
    $stationStatus = $stationStmt->fetchColumn();
    if ($stationStatus === false) {
        throw new RuntimeException('The station is no longer linked to this HQ.');
    }
    if (strtolower((string) $stationStatus) === 'closed') {
        return false;
    }
    $feeStmt = $pdo->prepare('SELECT payment_transfer_id FROM company_loyalty_station_fees WHERE headquarter_id = ? AND station_id = ? AND `year_month` = ? FOR UPDATE');
    $feeStmt->execute([$headquarterId, $stationId, $yearMonth]);
    if ($feeStmt->fetchColumn()) {
        throw new RuntimeException('This station already has a fee record for the quoted month. Reject this quote and resolve the duplicate before confirming payment.');
    }
    $insert = $pdo->prepare("INSERT INTO company_loyalty_station_fees (headquarter_id, station_id, `year_month`, payment_method, payment_transfer_id) VALUES (?, ?, ?, 'mmk_quote', ?)");
    $insert->execute([$headquarterId, $stationId, $yearMonth, (int) $transfer['payment_transfer_id']]);
    return true;
}

/** Apply monthly capacity and operation entitlements for an all-in-one package. */
function payment_transfer_apply_hq_station_package(PDO $pdo, array $transfer): void
{
    $headquarterId = (int) ($transfer['payer_headquarter_id'] ?? 0);
    $stationCount = (int) ($transfer['quantity'] ?? 0);
    $months = (int) ($transfer['term_months'] ?? 0);
    $transferId = (int) ($transfer['payment_transfer_id'] ?? 0);
    $month = DateTimeImmutable::createFromFormat('!Y-m', (string) ($transfer['service_start_month'] ?? ''));
    if ($headquarterId <= 0 || $stationCount < 1 || $months < 1
        || $months > BILLING_HQ_EXTENSION_MAX_MONTHS || $transferId <= 0 || !$month) {
        throw new RuntimeException('The all-in-one station package details are invalid.');
    }

    $hqStmt = $pdo->prepare('SELECT station_count FROM headquarter WHERE headquarter_id = ? LIMIT 1 FOR UPDATE');
    $hqStmt->execute([$headquarterId]);
    $baseLimitValue = $hqStmt->fetchColumn();
    if ($baseLimitValue === false) {
        throw new RuntimeException('The HQ account for this station package no longer exists.');
    }
    $baseLimit = max(0, (int) $baseLimitValue);
    $activeStationsStmt = $pdo->prepare(
        "SELECT b.branch_id
         FROM branches b
         INNER JOIN station_business_scope sbs ON sbs.station_id = b.branch_id
         WHERE sbs.headquarter_id = ? AND LOWER(COALESCE(b.status, 'active')) <> 'closed'
         ORDER BY b.branch_id"
    );
    $activeStationsStmt->execute([$headquarterId]);
    $activeStationIds = array_map('intval', $activeStationsStmt->fetchAll(PDO::FETCH_COLUMN));
    if (count($activeStationIds) > $stationCount) {
        throw new RuntimeException('The package quantity no longer covers every active station. Submit a new package quote.');
    }

    $extraSlotsRequired = $baseLimit > 0 ? max(0, $stationCount - $baseLimit) : 0;
    for ($monthOffset = 0; $monthOffset < $months; $monthOffset++) {
        $yearMonth = $month->modify('+' . $monthOffset . ' months')->format('Y-m');
        $coverageInsert = $pdo->prepare(
            'INSERT INTO hq_station_package_coverage
                (payment_transfer_id, headquarter_id, `year_month`, first_station_number, station_count)
             VALUES (?, ?, ?, 1, ?)'
        );
        $coverageInsert->execute([$transferId, $headquarterId, $yearMonth, $stationCount]);
        $slotsStmt = $pdo->prepare(
            'SELECT COALESCE(mmk_slots_purchased, 0)
             FROM company_loyalty_station_slots
             WHERE headquarter_id = ? AND `year_month` = ? LIMIT 1 FOR UPDATE'
        );
        $slotsStmt->execute([$headquarterId, $yearMonth]);
        $existingSlots = max(0, (int) ($slotsStmt->fetchColumn() ?: 0));
        $slotsToAdd = max(0, $extraSlotsRequired - $existingSlots);

        $slotUpsert = $pdo->prepare(
            'INSERT INTO company_loyalty_station_slots
                (headquarter_id, `year_month`, mmk_slots_purchased)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE mmk_slots_purchased = GREATEST(mmk_slots_purchased, VALUES(mmk_slots_purchased))'
        );
        $slotUpsert->execute([$headquarterId, $yearMonth, $extraSlotsRequired]);

        $entitlementInsert = $pdo->prepare(
            'INSERT INTO hq_station_mmk_entitlements
                (payment_transfer_id, headquarter_id, `year_month`, slot_number)
             VALUES (?, ?, ?, ?)'
        );
        for ($slotNumber = 1; $slotNumber <= $slotsToAdd; $slotNumber++) {
            $entitlementInsert->execute([$transferId, $headquarterId, $yearMonth, $slotNumber]);
        }

        $feeInsert = $pdo->prepare(
            "INSERT IGNORE INTO company_loyalty_station_fees
                (headquarter_id, station_id, `year_month`, payment_method, payment_transfer_id)
             VALUES (?, ?, ?, 'mmk_package', ?)"
        );
        foreach ($activeStationIds as $stationId) {
            $feeInsert->execute([$headquarterId, $stationId, $yearMonth, $transferId]);
        }
        payment_transfer_assign_mmk_entitlements($pdo, $headquarterId, $yearMonth);
    }
}

/** Notify payers and recipients after status transactions commit. */
function payment_transfer_send_notices(PDO $pdo, int $transferId, string $recipientSide, string $action, array $extraContext = []): void
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
                : (in_array($transfer['payment_type'], ['hq_period_extension', 'hq_station_package'], true) && $action === 'payer_reported_paid'
                    ? payment_transfer_review_recipients($pdo)
                    : payment_transfer_admin_recipients($pdo));
        } else {
            $recipients = [[
                'user_id' => $transfer['payer_user_id'] ?? null,
                'email' => $transfer['payer_email'] ?? null,
            ]];
        }

        $subjectText = match ($action) {
            'pending', 'pending_payment' => 'A payment request was created; payment is not yet reported.',
            'payer_reported_paid' => 'The payer reported payment; recipient confirmation is pending.',
            'confirmed', 'received' => 'The recipient recorded that the external payment was received.',
            'rejected' => 'The recipient reported that the external payment was not received.',
            'cancelled' => 'The payer cancelled the external payment request.',
            default => 'The external payment status was updated.',
        };
        $recipientLabel = BILLING_PLATFORM_RECIPIENT_NAME . ' (Admin)';
        if ($transfer['recipient_role'] === 'station_hq') {
            $hqName = $pdo->prepare('SELECT company_name FROM headquarter WHERE headquarter_id = ? LIMIT 1');
            $hqName->execute([(int) $transfer['recipient_headquarter_id']]);
            $recipientLabel = trim((string) ($hqName->fetchColumn() ?: '')) ?: 'Station HQ #' . (int) $transfer['recipient_headquarter_id'];
        }
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
                'recipient_label' => $recipientLabel,
            ] + ($recipientSide === 'payer' ? $extraContext : []));
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
