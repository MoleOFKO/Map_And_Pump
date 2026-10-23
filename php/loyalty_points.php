<?php
/**
 * Loyalty Reward Points — helpers (v2 countermeasures)
 *
 * - Customer balance: users.had_point
 * - Internal fills: no customer earn/redeem, no company earn
 * - Fixed-value customer redemption
 * - HQ points funded by customer redemptions
 * - Monthly HQ station capacity and operating fees
 * - Append-only ledger (optional via flag)
 * - Soft-fail: never throw to break fill transaction
 *
 * See: POINTS_COUNTERMEASURES_IMPLEMENTATION_PLAN.md
 * Safety: POINTS_COUNTERMEASURES_SIMULATION_AND_RISKS.md
 */

require_once __DIR__ . '/loyalty_config.php';
require_once __DIR__ . '/billing_config.php';

/** A failed redemption transfer must roll back the enclosing fuel fill. */
class LoyaltyTransferException extends RuntimeException
{
}

// ---------------------------------------------------------------------------
// Balance / normalize / discount (v1, kept)
// ---------------------------------------------------------------------------

/**
 * Current loyalty balance for a user.
 */
function loyalty_get_balance(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(had_point, 0) FROM users WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('loyalty_get_balance: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Normalize redeem intent to allowed values: 0, 10, or 20 (and ≤ max).
 */
function loyalty_normalize_points_intent(int $points): int
{
    $unit = (int) LOYALTY_REDEEM_UNIT_POINTS;
    $max = (int) LOYALTY_MAX_POINTS_PER_FILL;
    if ($points <= 0 || $unit <= 0) {
        return 0;
    }
    $points = min($points, $max);
    $points = (int) (floor($points / $unit) * $unit);
    return max(0, min($points, $max));
}

/**
 * Compute a fixed-value discount. The bill total caps the discount so the
 * amount due cannot become negative.
 *
 * @return array{points:int, units:int, discount_pct:float, discount_mmk:float}
 */
function loyalty_compute_discount(float $filledLiters, ?float $totalCost, int $pointsToUse): array
{
    $points = loyalty_normalize_points_intent($pointsToUse);
    $unit = (int) LOYALTY_REDEEM_UNIT_POINTS;
    $units = $unit > 0 ? (int) ($points / $unit) : 0;

    if ($units <= 0 || $filledLiters <= 0 || $totalCost === null || $totalCost <= 0) {
        return [
            'points' => 0,
            'units' => 0,
            'discount_pct' => 0.0,
            'discount_mmk' => 0.0,
        ];
    }

    // Keep the fixed 10 MMK/point value exact: when the bill is below the
    // requested redemption value, use only whole points that the bill can cover.
    $maxAffordablePoints = (int) floor(round((float) $totalCost, 2) / (float) LOYALTY_MMK_PER_POINT);
    // Redemptions are issued in full units (currently 10 points = 100 MMK).
    $maxAffordablePoints = $unit > 0
        ? (int) (floor($maxAffordablePoints / $unit) * $unit)
        : 0;
    $points = min($points, max(0, $maxAffordablePoints));
    if ($points <= 0) {
        return [
            'points' => 0,
            'units' => 0,
            'discount_pct' => 0.0,
            'discount_mmk' => 0.0,
        ];
    }

    $discountMmk = min(
        round((float) $totalCost, 2),
        round($points * (float) LOYALTY_MMK_PER_POINT, 2)
    );
    $discountMmk = max(0.0, $discountMmk);
    $discountPct = $totalCost > 0 ? ($discountMmk / (float) $totalCost) * 100.0 : 0.0;

    return [
        'points' => $points,
        'units' => $unit > 0 ? (int) ceil($points / $unit) : 0,
        'discount_pct' => round($discountPct, 2),
        'discount_mmk' => $discountMmk,
    ];
}

// ---------------------------------------------------------------------------
// Tier resolution (single source of truth)
// ---------------------------------------------------------------------------

/**
 * Resolve tier code from point balance.
 */
function loyalty_resolve_tier(int $balance): string
{
    $balance = max(0, $balance);
    foreach (loyalty_tier_thresholds() as $code => $minPts) {
        if ($balance >= (int) $minPts) {
            return (string) $code;
        }
    }
    return 'bronze';
}

/**
 * Human label for tier code.
 */
function loyalty_tier_label(string $tierCode): string
{
    $labels = loyalty_tier_labels();
    $code = strtolower(trim($tierCode));
    return $labels[$code] ?? ucfirst($code !== '' ? $code : 'bronze');
}

// ---------------------------------------------------------------------------
// Schema (fail-soft)
// ---------------------------------------------------------------------------

/**
 * Ensure optional loyalty columns exist on booking / usage_history (best-effort).
 * Safe to call repeatedly; does not create a ledger table.
 */
function loyalty_schema_ready(PDO $pdo): bool
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
        // booking.points_to_redeem
        $col = $pdo->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'booking'
               AND COLUMN_NAME = 'points_to_redeem'"
        );
        $col->execute();
        if ((int) $col->fetchColumn() === 0) {
            $pdo->exec(
                'ALTER TABLE booking ADD COLUMN points_to_redeem INT NOT NULL DEFAULT 0 AFTER filled_gas'
            );
        }

        // usage_history loyalty columns
        foreach (
            [
                'points_earned' => 'INT NOT NULL DEFAULT 0',
                'points_redeemed' => 'INT NOT NULL DEFAULT 0',
                'discount_mmk' => 'DECIMAL(12,2) NOT NULL DEFAULT 0',
                'is_internal' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'admin_share_mmk' => 'DECIMAL(12,2) NULL DEFAULT NULL',
                'company_share_mmk' => 'DECIMAL(12,2) NULL DEFAULT NULL',
                'company_points_earned' => 'INT NOT NULL DEFAULT 0',
            ] as $name => $def
        ) {
            $c = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'usage_history'
                   AND COLUMN_NAME = ?"
            );
            $c->execute([$name]);
            if ((int) $c->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE usage_history ADD COLUMN {$name} {$def}");
            }
        }

        $ready = true;
        return true;
    } catch (Throwable $e) {
        error_log('loyalty_schema_ready: ' . $e->getMessage());
        return false;
    }
}

/**
 * Countermeasures tables: ledger, tier pool, HQ wallets, and monthly station billing.
 * Never throws to caller; returns false on failure.
 */
function loyalty_countermeasures_schema_ready(PDO $pdo): bool
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
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS loyalty_ledger (
                ledger_id INT NOT NULL AUTO_INCREMENT,
                party_type VARCHAR(16) NOT NULL,
                party_id INT NOT NULL,
                direction VARCHAR(16) NOT NULL,
                points INT NOT NULL DEFAULT 0,
                discount_mmk DECIMAL(12,2) NULL DEFAULT NULL,
                admin_share_mmk DECIMAL(12,2) NULL DEFAULT NULL,
                company_share_mmk DECIMAL(12,2) NULL DEFAULT NULL,
                booking_id INT NULL DEFAULT NULL,
                usage_id INT NULL DEFAULT NULL,
                station_id INT NULL DEFAULT NULL,
                staff_user_id INT NULL DEFAULT NULL,
                is_internal TINYINT(1) NOT NULL DEFAULT 0,
                meta JSON NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (ledger_id),
                KEY idx_loyalty_ledger_party (party_type, party_id, created_at),
                KEY idx_loyalty_ledger_usage (usage_id),
                KEY idx_loyalty_ledger_station (station_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS loyalty_tier_pool (
                pool_id INT NOT NULL AUTO_INCREMENT,
                tier_code VARCHAR(20) NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                budget_mmk DECIMAL(14,2) NOT NULL DEFAULT 0,
                spent_mmk DECIMAL(14,2) NOT NULL DEFAULT 0,
                PRIMARY KEY (pool_id),
                UNIQUE KEY uq_loyalty_tier_pool (tier_code, `year_month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS company_loyalty (
                headquarter_id INT NOT NULL,
                balance INT NOT NULL DEFAULT 0,
                lifetime_earned INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (headquarter_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS company_loyalty_monthly (
                headquarter_id INT NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                earned INT NOT NULL DEFAULT 0,
                PRIMARY KEY (headquarter_id, `year_month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS company_loyalty_station_slots (
                headquarter_id INT NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                slots_purchased INT NOT NULL DEFAULT 0,
                mmk_slots_purchased INT NOT NULL DEFAULT 0,
                points_spent INT NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (headquarter_id, `year_month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS company_loyalty_station_fees (
                headquarter_id INT NOT NULL,
                station_id INT NOT NULL,
                `year_month` CHAR(7) NOT NULL,
                points_paid INT NOT NULL DEFAULT 0,
                payment_method VARCHAR(16) NOT NULL DEFAULT 'points',
                payment_transfer_id BIGINT UNSIGNED DEFAULT NULL,
                paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (headquarter_id, station_id, `year_month`),
                KEY idx_company_station_fees_station_month (station_id, `year_month`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        foreach ([
            'company_loyalty_station_slots' => [
                'mmk_slots_purchased' => 'INT NOT NULL DEFAULT 0 AFTER slots_purchased',
            ],
            'company_loyalty_station_fees' => [
                'payment_method' => "VARCHAR(16) NOT NULL DEFAULT 'points' AFTER points_paid",
                'payment_transfer_id' => 'BIGINT UNSIGNED DEFAULT NULL AFTER payment_method',
            ],
        ] as $tableName => $columns) {
            foreach ($columns as $columnName => $columnDefinition) {
                $columnCheck = $pdo->prepare(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
                );
                $columnCheck->execute([$tableName, $columnName]);
                if ((int) $columnCheck->fetchColumn() === 0) {
                    $pdo->exec("ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` {$columnDefinition}");
                }
            }
        }

        $ready = true;
        return true;
    } catch (Throwable $e) {
        error_log('loyalty_countermeasures_schema_ready: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// Internal-fill detection (strict)
// ---------------------------------------------------------------------------

/**
 * True when booker is staff/manager of the fill station, or business-role
 * user under the same HQ as the station. Pure customers with a stale
 * headquarter_id are NOT treated as internal.
 */
function loyalty_is_internal_fill(PDO $pdo, int $bookerUserId, int $stationId): bool
{
    if (!LOYALTY_CM_INTERNAL_DETECT || $bookerUserId <= 0 || $stationId <= 0) {
        return false;
    }

    try {
        // (A) Active staff assigned to this station/branch
        $staff = $pdo->prepare(
            "SELECT 1
             FROM staff st
             INNER JOIN users u ON u.user_id = st.user_id
             WHERE st.user_id = ?
               AND st.branch_id = ?
               AND st.is_active = 1
               AND LOWER(COALESCE(u.status, 'active')) NOT IN ('disabled', 'suspended', 'removed')
             LIMIT 1"
        );
        $staff->execute([$bookerUserId, $stationId]);
        if ($staff->fetchColumn()) {
            return true;
        }

        // (B) Branch manager of this station (branches.manager_user_id)
        $mgr = $pdo->prepare(
            "SELECT 1
             FROM branches b
             INNER JOIN users u ON u.user_id = b.manager_user_id
             WHERE b.branch_id = ?
               AND b.manager_user_id = ?
               AND LOWER(COALESCE(u.status, 'active')) NOT IN ('disabled', 'suspended', 'removed')
             LIMIT 1"
        );
        $mgr->execute([$stationId, $bookerUserId]);
        if ($mgr->fetchColumn()) {
            return true;
        }

        // Also: station_business_scope.branch_manager_user_id
        $sbsMgr = $pdo->prepare(
            "SELECT 1
             FROM station_business_scope sbs
             INNER JOIN users u ON u.user_id = sbs.branch_manager_user_id
             WHERE sbs.station_id = ?
               AND sbs.branch_manager_user_id = ?
               AND LOWER(COALESCE(u.status, 'active')) NOT IN ('disabled', 'suspended', 'removed')
             LIMIT 1"
        );
        $sbsMgr->execute([$stationId, $bookerUserId]);
        if ($sbsMgr->fetchColumn()) {
            return true;
        }

        // (C) Business role (HQ=3, Manager=4, Staff=5) + same HQ as station
        $userStmt = $pdo->prepare(
            "SELECT u.role_id, u.business_role_id, u.headquarter_id,
                    COALESCE(u.business_role_id, u.role_id) AS effective_role
             FROM users u
             WHERE u.user_id = ?
             LIMIT 1"
        );
        $userStmt->execute([$bookerUserId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return false;
        }

        $effectiveRole = (int) ($user['effective_role'] ?? 0);
        // Only business-side roles — pure customer (6) is never internal via HQ alone
        if (!in_array($effectiveRole, [3, 4, 5], true)) {
            return false;
        }

        $hqId = (int) ($user['headquarter_id'] ?? 0);
        if ($hqId <= 0 && function_exists('map_pump_current_hq_id')) {
            $hqId = (int) map_pump_current_hq_id($pdo, $bookerUserId);
        }
        if ($hqId <= 0) {
            return false;
        }

        if (function_exists('map_pump_station_in_hq')) {
            return map_pump_station_in_hq($pdo, $stationId, $hqId);
        }

        $hqCheck = $pdo->prepare(
            "SELECT 1 FROM station_business_scope
             WHERE station_id = ? AND headquarter_id = ?
             LIMIT 1"
        );
        $hqCheck->execute([$stationId, $hqId]);
        return (bool) $hqCheck->fetchColumn();
    } catch (Throwable $e) {
        error_log('loyalty_is_internal_fill: ' . $e->getMessage());
        // Fail open on detection errors so a DB glitch does not mass-deny points
        return false;
    }
}

/**
 * Resolve headquarter_id for a station (0 if unknown).
 */
function loyalty_station_hq_id(PDO $pdo, int $stationId): int
{
    if ($stationId <= 0) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT headquarter_id FROM station_business_scope WHERE station_id = ? LIMIT 1'
        );
        $stmt->execute([$stationId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('loyalty_station_hq_id: ' . $e->getMessage());
        return 0;
    }
}

// ---------------------------------------------------------------------------
// Tier pool helpers
// ---------------------------------------------------------------------------

function loyalty_current_year_month(): string
{
    return date('Y-m');
}

/**
 * Ensure pool row exists for tier + month; returns remaining MMK or 0 on failure.
 */
function loyalty_ensure_tier_pool(PDO $pdo, string $tierCode, ?string $yearMonth = null): float
{
    $tierCode = strtolower(trim($tierCode));
    if ($tierCode === '') {
        $tierCode = 'bronze';
    }
    $ym = $yearMonth ?: loyalty_current_year_month();
    $budgets = loyalty_tier_pool_budgets();
    $budget = (float) ($budgets[$tierCode] ?? $budgets['bronze'] ?? 0);

    try {
        loyalty_countermeasures_schema_ready($pdo);
        $ins = $pdo->prepare(
            'INSERT IGNORE INTO loyalty_tier_pool (tier_code, year_month, budget_mmk, spent_mmk)
             VALUES (?, ?, ?, 0)'
        );
        $ins->execute([$tierCode, $ym, $budget]);

        $sel = $pdo->prepare(
            'SELECT budget_mmk, spent_mmk FROM loyalty_tier_pool
             WHERE tier_code = ? AND year_month = ? LIMIT 1'
        );
        $sel->execute([$tierCode, $ym]);
        $row = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return 0.0;
        }
        return max(0.0, (float) $row['budget_mmk'] - (float) $row['spent_mmk']);
    } catch (Throwable $e) {
        error_log('loyalty_ensure_tier_pool: ' . $e->getMessage());
        return 0.0;
    }
}

function loyalty_get_pool_remaining(PDO $pdo, string $tierCode, ?string $yearMonth = null): float
{
    if (!LOYALTY_CM_TIER_POOL) {
        return PHP_FLOAT_MAX; // effectively unlimited when flag off
    }
    return loyalty_ensure_tier_pool($pdo, $tierCode, $yearMonth);
}

/**
 * Atomically consume up to $amountMmk from pool. Returns amount actually consumed.
 */
function loyalty_try_consume_pool(PDO $pdo, string $tierCode, float $amountMmk, ?string $yearMonth = null): float
{
    if (!LOYALTY_CM_TIER_POOL || $amountMmk <= 0) {
        return $amountMmk > 0 && !LOYALTY_CM_TIER_POOL ? $amountMmk : 0.0;
    }

    $tierCode = strtolower(trim($tierCode));
    if ($tierCode === '') {
        $tierCode = 'bronze';
    }
    $ym = $yearMonth ?: loyalty_current_year_month();
    $amountMmk = round($amountMmk, 2);

    try {
        loyalty_ensure_tier_pool($pdo, $tierCode, $ym);

        // Try full amount first
        $upd = $pdo->prepare(
            'UPDATE loyalty_tier_pool
             SET spent_mmk = spent_mmk + :amt
             WHERE tier_code = :tier AND year_month = :ym
               AND spent_mmk + :amt2 <= budget_mmk'
        );
        $upd->execute([
            ':amt' => $amountMmk,
            ':amt2' => $amountMmk,
            ':tier' => $tierCode,
            ':ym' => $ym,
        ]);
        if ($upd->rowCount() === 1) {
            return $amountMmk;
        }

        // Partial: consume remaining
        try {
            $sel = $pdo->prepare(
                'SELECT GREATEST(0, budget_mmk - spent_mmk) AS remaining
                 FROM loyalty_tier_pool
                 WHERE tier_code = ? AND year_month = ? LIMIT 1 FOR UPDATE'
            );
            $sel->execute([$tierCode, $ym]);
        } catch (Throwable $e) {
            $sel = $pdo->prepare(
                'SELECT GREATEST(0, budget_mmk - spent_mmk) AS remaining
                 FROM loyalty_tier_pool
                 WHERE tier_code = ? AND year_month = ? LIMIT 1'
            );
            $sel->execute([$tierCode, $ym]);
        }
        $remaining = round((float) ($sel->fetchColumn() ?: 0), 2);
        if ($remaining <= 0) {
            return 0.0;
        }

        $partial = min($amountMmk, $remaining);
        $upd2 = $pdo->prepare(
            'UPDATE loyalty_tier_pool
             SET spent_mmk = spent_mmk + :amt
             WHERE tier_code = :tier AND year_month = :ym
               AND spent_mmk + :amt2 <= budget_mmk'
        );
        $upd2->execute([
            ':amt' => $partial,
            ':amt2' => $partial,
            ':tier' => $tierCode,
            ':ym' => $ym,
        ]);
        return $upd2->rowCount() === 1 ? $partial : 0.0;
    } catch (Throwable $e) {
        error_log('loyalty_try_consume_pool: ' . $e->getMessage());
        return 0.0;
    }
}

// ---------------------------------------------------------------------------
// Ledger (soft)
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $row
 */
function loyalty_ledger_write(PDO $pdo, array $row): void
{
    if (!LOYALTY_CM_LEDGER) {
        return;
    }
    try {
        if (!loyalty_countermeasures_schema_ready($pdo)) {
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO loyalty_ledger
                (party_type, party_id, direction, points, discount_mmk, admin_share_mmk, company_share_mmk,
                 booking_id, usage_id, station_id, staff_user_id, is_internal, meta, created_at)
             VALUES
                (:party_type, :party_id, :direction, :points, :discount_mmk, :admin_share_mmk, :company_share_mmk,
                 :booking_id, :usage_id, :station_id, :staff_user_id, :is_internal, :meta, NOW())'
        );
        $meta = $row['meta'] ?? null;
        if (is_array($meta)) {
            $meta = json_encode($meta);
        }
        $stmt->execute([
            ':party_type' => (string) ($row['party_type'] ?? 'customer'),
            ':party_id' => (int) ($row['party_id'] ?? 0),
            ':direction' => (string) ($row['direction'] ?? 'earn'),
            ':points' => (int) ($row['points'] ?? 0),
            ':discount_mmk' => isset($row['discount_mmk']) ? (float) $row['discount_mmk'] : null,
            ':admin_share_mmk' => isset($row['admin_share_mmk']) ? (float) $row['admin_share_mmk'] : null,
            ':company_share_mmk' => isset($row['company_share_mmk']) ? (float) $row['company_share_mmk'] : null,
            ':booking_id' => isset($row['booking_id']) ? (int) $row['booking_id'] : null,
            ':usage_id' => isset($row['usage_id']) ? (int) $row['usage_id'] : null,
            ':station_id' => isset($row['station_id']) ? (int) $row['station_id'] : null,
            ':staff_user_id' => isset($row['staff_user_id']) ? (int) $row['staff_user_id'] : null,
            ':is_internal' => !empty($row['is_internal']) ? 1 : 0,
            ':meta' => $meta,
        ]);
    } catch (Throwable $e) {
        error_log('loyalty_ledger_write: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Company points
// ---------------------------------------------------------------------------

function company_loyalty_get_balance(PDO $pdo, int $headquarterId): int
{
    if ($headquarterId <= 0) {
        return 0;
    }
    try {
        loyalty_countermeasures_schema_ready($pdo);
        $stmt = $pdo->prepare(
            'SELECT COALESCE(balance, 0) FROM company_loyalty WHERE headquarter_id = ? LIMIT 1'
        );
        $stmt->execute([$headquarterId]);
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('company_loyalty_get_balance: ' . $e->getMessage());
        return 0;
    }
}

/** Number of additional station slots purchased for the current month. */
function company_loyalty_current_month_slots(PDO $pdo, int $headquarterId): int
{
    if ($headquarterId <= 0 || !loyalty_countermeasures_schema_ready($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(slots_purchased, 0) + COALESCE(mmk_slots_purchased, 0)
             FROM company_loyalty_station_slots
             WHERE headquarter_id = ? AND year_month = ? LIMIT 1'
        );
        $stmt->execute([$headquarterId, loyalty_current_year_month()]);
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('company_loyalty_current_month_slots: ' . $e->getMessage());
        return 0;
    }
}

/** True only when the station's owning HQ has paid this month's station fee. */
function company_loyalty_station_is_paid(PDO $pdo, int $stationId): bool
{
    if ($stationId <= 0 || !loyalty_countermeasures_schema_ready($pdo)) {
        return false;
    }
    try {
        $stationStmt = $pdo->prepare(
                "SELECT sbs.headquarter_id, GREATEST(0, COALESCE(h.station_count, 0)) AS base_slots,
                    h.included_stations_free_until,
                    (SELECT COUNT(*)
                     FROM branches ordered_branch
                     INNER JOIN station_business_scope ordered_scope ON ordered_scope.station_id = ordered_branch.branch_id
                     WHERE ordered_scope.headquarter_id = sbs.headquarter_id
                       AND ordered_branch.branch_id <= b.branch_id) AS station_order
             FROM station_business_scope sbs
             INNER JOIN branches b ON b.branch_id = sbs.station_id
             INNER JOIN headquarter h ON h.headquarter_id = sbs.headquarter_id
             WHERE sbs.station_id = ?
             LIMIT 1"
        );
        $stationStmt->execute([$stationId]);
        $station = $stationStmt->fetch(PDO::FETCH_ASSOC);
        if (!$station) {
            return false;
        }

        $baseSlots = (int) $station['base_slots'];
        $extraSlotRequired = $baseSlots < 1 ? 0 : max(0, (int) $station['station_order'] - $baseSlots);
        if ($extraSlotRequired > company_loyalty_current_month_slots($pdo, (int) $station['headquarter_id'])) {
            return false;
        }
        if ((int) $station['station_order'] <= BILLING_HQ_INCLUDED_STATION_SLOTS) {
            return true;
        }

        $stmt = $pdo->prepare(
            'SELECT 1
             FROM station_business_scope sbs
             INNER JOIN company_loyalty_station_fees f
                ON f.headquarter_id = sbs.headquarter_id
               AND f.station_id = sbs.station_id
               AND f.year_month = ?
             WHERE sbs.station_id = ?
             LIMIT 1'
        );
        $stmt->execute([loyalty_current_year_month(), $stationId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('company_loyalty_station_is_paid: ' . $e->getMessage());
        return false;
    }
}

/**
 * Credit the owning HQ with the customer's redeemed points. The surrounding
 * fill transaction owns atomicity; failures propagate so the fill can roll
 * back instead of consuming customer points without crediting the HQ.
 */
function company_loyalty_transfer_customer_redemption(
    PDO $pdo,
    int $headquarterId,
    int $points,
    ?int $bookingId = null,
    ?int $usageId = null,
    ?int $stationId = null,
    ?int $staffUserId = null
): void {
    if ($points <= 0) {
        return;
    }
    if (!LOYALTY_CM_COMPANY_POINTS || $headquarterId <= 0) {
        throw new LoyaltyTransferException('Could not identify the station HQ for this points transfer.');
    }
    if (!loyalty_countermeasures_schema_ready($pdo)) {
        throw new LoyaltyTransferException('The HQ points wallet is unavailable.');
    }

    try {
        $pdo->prepare(
            'INSERT IGNORE INTO company_loyalty (headquarter_id, balance, lifetime_earned)
             VALUES (?, 0, 0)'
        )->execute([$headquarterId]);

        $credit = $pdo->prepare(
            'UPDATE company_loyalty
             SET balance = balance + ?, lifetime_earned = lifetime_earned + ?
             WHERE headquarter_id = ?'
        );
        $credit->execute([$points, $points, $headquarterId]);
        if ($credit->rowCount() !== 1) {
            throw new LoyaltyTransferException('Could not credit the station HQ points wallet.');
        }

        $headquarterCredit = $pdo->prepare(
            'UPDATE headquarter
             SET has_points = COALESCE(has_points, 0) + ?
             WHERE headquarter_id = ?'
        );
        $headquarterCredit->execute([$points, $headquarterId]);
        if ($headquarterCredit->rowCount() !== 1) {
            throw new LoyaltyTransferException('Could not save the redeemed points to the station HQ account.');
        }

        loyalty_ledger_write($pdo, [
            'party_type' => 'company',
            'party_id' => $headquarterId,
            'direction' => 'transfer_in',
            'points' => $points,
            'booking_id' => $bookingId,
            'usage_id' => $usageId,
            'station_id' => $stationId,
            'staff_user_id' => $staffUserId,
            'is_internal' => 0,
            'meta' => ['source' => 'customer_redemption'],
        ]);
    } catch (LoyaltyTransferException $e) {
        throw $e;
    } catch (Throwable $e) {
        error_log('company_loyalty_transfer_customer_redemption: ' . $e->getMessage());
        throw new LoyaltyTransferException('Could not transfer redeemed points to the station HQ.', 0, $e);
    }
}

/**
 * Redeem company points as platform/station credit only (no cash). Returns success.
 */
function company_loyalty_redeem_credit(PDO $pdo, int $headquarterId, int $points, string $reason = ''): bool
{
    if (!LOYALTY_CM_COMPANY_POINTS || $headquarterId <= 0 || $points <= 0) {
        return false;
    }
    try {
        loyalty_countermeasures_schema_ready($pdo);
        $debit = $pdo->prepare(
            'UPDATE company_loyalty
             SET balance = balance - ?
             WHERE headquarter_id = ? AND balance >= ?'
        );
        $debit->execute([$points, $headquarterId, $points]);
        if ($debit->rowCount() !== 1) {
            return false;
        }
        loyalty_ledger_write($pdo, [
            'party_type' => 'company',
            'party_id' => $headquarterId,
            'direction' => 'redeem',
            'points' => $points,
            'meta' => ['reason' => $reason, 'credit_only' => true],
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('company_loyalty_redeem_credit: ' . $e->getMessage());
        return false;
    }
}

// ---------------------------------------------------------------------------
// Apply on fill (v2) — soft-fail, never throws
// ---------------------------------------------------------------------------

/**
 * Apply redeem (optional) + earn on successful fill.
 * Internal fills: zero earn/redeem, no company points, no pool consume.
 *
 * New parameters have defaults. Never throws to caller.
 *
 * @return array{
 *   points_earned:int,
 *   points_redeemed:int,
 *   discount_pct:float,
 *   discount_mmk:float,
 *   admin_share_mmk:float,
 *   company_share_mmk:float,
 *   company_points_earned:int,
 *   is_internal:bool,
 *   tier:string,
 *   pool_limited:bool,
 *   balance_after:int,
 *   loyalty_error:?string
 * }
 */
function loyalty_apply_on_fill(
    PDO $pdo,
    int $userId,
    float $filledLiters,
    ?float $totalCost,
    int $pointsIntent,
    ?int $bookingId = null,
    ?int $staffUserId = null,
    bool $isInternal = false,
    ?int $stationId = null,
    ?int $usageId = null
): array {
    $result = [
        'points_earned' => 0,
        'points_redeemed' => 0,
        'discount_pct' => 0.0,
        'discount_mmk' => 0.0,
        'admin_share_mmk' => 0.0,
        'company_share_mmk' => 0.0,
        'company_points_earned' => 0,
        'is_internal' => $isInternal,
        'tier' => 'bronze',
        'pool_limited' => false,
        'balance_after' => 0,
        'loyalty_error' => null,
    ];

    try {
        $result['balance_after'] = loyalty_get_balance($pdo, $userId);
        $result['tier'] = loyalty_resolve_tier((int) $result['balance_after']);

        if ($userId <= 0 || $filledLiters <= 0) {
            return $result;
        }

        // Best-effort schema (never fatal)
        try {
            loyalty_schema_ready($pdo);
            loyalty_countermeasures_schema_ready($pdo);
        } catch (Throwable $e) {
            // ignore
        }

        // Internal: no earn, no redeem, no company, no pool
        if ($isInternal) {
            $result['is_internal'] = true;
            if (function_exists('map_pump_log_action') && $staffUserId !== null && $staffUserId > 0) {
                try {
                    $msg = 'Loyalty user #' . $userId . ': internal fill — 0 pts (own station/network)';
                    if ($bookingId !== null && $bookingId > 0) {
                        $msg .= ' (booking #' . $bookingId . ')';
                    }
                    map_pump_log_action($pdo, $staffUserId, $msg, 5);
                } catch (Throwable $e) {
                    // non-fatal
                }
            }
            return $result;
        }

        $balance = loyalty_get_balance($pdo, $userId);
        $result['tier'] = loyalty_resolve_tier($balance);
        $intent = loyalty_normalize_points_intent($pointsIntent);
        $intent = min($intent, $balance);

        $discount = loyalty_compute_discount($filledLiters, $totalCost, $intent);
        $pointsToRedeem = (int) $discount['points'];
        $discountMmk = (float) $discount['discount_mmk'];
        $discountPct = (float) $discount['discount_pct'];

        // Debit redeem first (conditional — never go negative)
        if ($pointsToRedeem > 0) {
            $debit = $pdo->prepare(
                'UPDATE users
                 SET had_point = had_point - :pts
                 WHERE user_id = :uid AND had_point >= :pts'
            );
            $debit->execute([
                ':pts' => $pointsToRedeem,
                ':uid' => $userId,
            ]);
            if ($debit->rowCount() === 1) {
                $result['points_redeemed'] = $pointsToRedeem;
                $result['discount_pct'] = $discountPct;
                $result['discount_mmk'] = $discountMmk;

                if (LOYALTY_CM_COST_SHARE && $discountMmk > 0) {
                    $adminPct = (float) LOYALTY_ADMIN_DISCOUNT_SHARE_PCT;
                    $result['admin_share_mmk'] = round($discountMmk * $adminPct / 100.0, 2);
                    $result['company_share_mmk'] = round($discountMmk - $result['admin_share_mmk'], 2);
                }

                loyalty_ledger_write($pdo, [
                    'party_type' => 'customer',
                    'party_id' => $userId,
                    'direction' => 'redeem',
                    'points' => $pointsToRedeem,
                    'discount_mmk' => $result['discount_mmk'],
                    'admin_share_mmk' => $result['admin_share_mmk'],
                    'company_share_mmk' => $result['company_share_mmk'],
                    'booking_id' => $bookingId,
                    'usage_id' => $usageId,
                    'station_id' => $stationId,
                    'staff_user_id' => $staffUserId,
                    'is_internal' => 0,
                    'meta' => $result['pool_limited'] ? ['pool_limited' => true] : null,
                ]);

                // Move redeemed points to the HQ that owns this station.
                // The caller's fill transaction makes the debit and credit atomic.
                if ($stationId === null || $stationId <= 0) {
                    throw new LoyaltyTransferException('Could not identify the station for this points transfer.');
                }
                $hqId = loyalty_station_hq_id($pdo, $stationId);
                company_loyalty_transfer_customer_redemption(
                    $pdo,
                    $hqId,
                    $pointsToRedeem,
                    $bookingId,
                    $usageId,
                    $stationId,
                    $staffUserId
                );
                $result['company_points_earned'] = $pointsToRedeem;
            }
        }

        // Credit earn (+3 per successful external fill)
        $earnPts = (int) LOYALTY_POINTS_PER_FILL;
        if ($earnPts > 0) {
            $credit = $pdo->prepare(
                'UPDATE users SET had_point = had_point + :pts WHERE user_id = :uid'
            );
            $credit->execute([
                ':pts' => $earnPts,
                ':uid' => $userId,
            ]);
            if ($credit->rowCount() === 1) {
                $result['points_earned'] = $earnPts;
                loyalty_ledger_write($pdo, [
                    'party_type' => 'customer',
                    'party_id' => $userId,
                    'direction' => 'earn',
                    'points' => $earnPts,
                    'booking_id' => $bookingId,
                    'usage_id' => $usageId,
                    'station_id' => $stationId,
                    'staff_user_id' => $staffUserId,
                    'is_internal' => 0,
                ]);
            }
        }

        $result['balance_after'] = loyalty_get_balance($pdo, $userId);
        $result['tier'] = loyalty_resolve_tier((int) $result['balance_after']);

        // Soft audit via existing activity log
        if (function_exists('map_pump_log_action') && $staffUserId !== null && $staffUserId > 0) {
            $parts = [];
            if ($result['points_earned'] > 0) {
                $parts[] = '+' . $result['points_earned'] . ' earn';
            }
            if ($result['points_redeemed'] > 0) {
                $parts[] = '-' . $result['points_redeemed'] . ' redeem';
                if ($result['discount_mmk'] > 0) {
                    $parts[] = 'discount ' . number_format($result['discount_mmk'], 2) . ' MMK';
                }
                if ($result['pool_limited']) {
                    $parts[] = 'pool-limited';
                }
            } elseif ($result['pool_limited']) {
                $parts[] = 'redeem skipped (pool empty)';
            }
            if ($result['company_points_earned'] > 0) {
                $parts[] = 'company +' . $result['company_points_earned'];
            }
            if ($parts !== []) {
                $msg = 'Loyalty user #' . $userId . ': ' . implode(', ', $parts);
                if ($bookingId !== null && $bookingId > 0) {
                    $msg .= ' (booking #' . $bookingId . ')';
                }
                try {
                    map_pump_log_action($pdo, $staffUserId, $msg, 5);
                } catch (Throwable $e) {
                    // Non-fatal
                }
            }
        }
    } catch (Throwable $e) {
        if ($e instanceof LoyaltyTransferException) {
            throw $e;
        }
        // Never break the fill
        error_log('loyalty_apply_on_fill soft-fail: ' . $e->getMessage());
        $result['loyalty_error'] = $e->getMessage();
        try {
            $result['balance_after'] = loyalty_get_balance($pdo, $userId);
        } catch (Throwable $e2) {
            // ignore
        }
    }

    return $result;
}

/**
 * Validate redeem intent for booking API (before storing intent).
 * Returns normalized points or throws InvalidArgumentException with message.
 * Does NOT hard-fail on tier pool.
 */
function loyalty_validate_redeem_intent(PDO $pdo, int $userId, int $requestedPoints): int
{
    $unit = (int) LOYALTY_REDEEM_UNIT_POINTS;
    $max = (int) LOYALTY_MAX_POINTS_PER_FILL;

    if ($requestedPoints < 0) {
        throw new InvalidArgumentException('Loyalty points to redeem cannot be negative.');
    }
    if ($requestedPoints > $max) {
        throw new InvalidArgumentException(
            'You can use at most ' . $max . ' loyalty points per fill.'
        );
    }
    if ($requestedPoints > 0 && $unit > 0 && ($requestedPoints % $unit) !== 0) {
        throw new InvalidArgumentException(
            'Loyalty points to redeem must be 0, ' . $unit . ', or ' . $max . '.'
        );
    }

    $normalized = loyalty_normalize_points_intent($requestedPoints);
    if ($normalized > 0) {
        $balance = loyalty_get_balance($pdo, $userId);
        if ($normalized > $balance) {
            throw new InvalidArgumentException(
                'Not enough loyalty points. You have ' . $balance . ' point(s).'
            );
        }
    }
    return $normalized;
}
