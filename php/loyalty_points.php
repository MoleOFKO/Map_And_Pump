<?php
/**
 * Loyalty Reward Points — helpers (v2 countermeasures)
 *
 * - Customer balance: users.had_point
 * - Internal fills: no customer earn/redeem, no company earn
 * - Fixed-value customer redemption
 * - Shared monthly tier pools
 * - Admin-controlled MMK discount balance and payout history
 * - Monthly HQ station capacity and operating fees
 * - Atomic point and discount-ledger updates inside the fill transaction
 *
 * See: POINTS_COUNTERMEASURES_IMPLEMENTATION_PLAN.md
 * Safety: POINTS_COUNTERMEASURES_SIMULATION_AND_RISKS.md
 */

require_once __DIR__ . '/loyalty_config.php';
require_once __DIR__ . '/billing_config.php';
require_once __DIR__ . '/booking_rules.php';

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

function loyalty_points_per_liter(PDO $pdo): float
{
    $stmt = $pdo->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute(['loyalty_points_per_liter']);
    $value = $stmt->fetchColumn();
    if ($value === false || !is_numeric($value) || (float) $value < 0) {
        throw new RuntimeException('The customer earning rate is unavailable or invalid.');
    }
    return (float) $value;
}

/** Balance after subtracting points held by active, unexpired bookings. */
function loyalty_get_available_balance(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !loyalty_schema_ready($pdo)) {
        return 0;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT GREATEST(0, COALESCE(u.had_point, 0) - COALESCE((
                SELECT SUM(bk.points_to_redeem)
                FROM booking bk
                WHERE bk.user_id = u.user_id
                  AND bk.status IN ('notfilled', 'waiting', 'serving')
                  AND bk.expire_at > NOW()
            ), 0))
             FROM users u
             WHERE u.user_id = ?
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('loyalty_get_available_balance: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Normalize redeem intent to one of the configured fixed redemption options.
 */
function loyalty_normalize_points_intent(int $points): int
{
    return array_key_exists($points, LOYALTY_REDEMPTION_OPTIONS) ? $points : 0;
}

/** @return array<int, float> points required => MMK discount */
function loyalty_redemption_options(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT points_required, discount_mmk
         FROM loyalty_redemption_rules
         ORDER BY points_required ASC'
    );
    $options = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $options[(int) $row['points_required']] = (float) $row['discount_mmk'];
    }
    if ($options === []) {
        throw new RuntimeException('Loyalty redemption rules are unavailable. Apply the loyalty redesign migration.');
    }
    return $options;
}

/**
 * Compute a fixed-value discount. The bill total caps the discount so the
 * amount due cannot become negative.
 *
 * @return array{points:int, units:int, discount_pct:float, discount_mmk:float}
 */
function loyalty_compute_discount(float $filledLiters, ?float $totalCost, int $pointsToUse, array $options = LOYALTY_REDEMPTION_OPTIONS): array
{
    if ($pointsToUse <= 0 || $filledLiters <= 0 || $totalCost === null || $totalCost <= 0) {
        return [
            'points' => 0,
            'units' => 0,
            'discount_pct' => 0.0,
            'discount_mmk' => 0.0,
        ];
    }

    $points = 0;
    $discountMmk = 0.0;
    krsort($options, SORT_NUMERIC);
    foreach ($options as $optionPoints => $optionDiscount) {
        if ((int) $optionPoints <= $pointsToUse && (float) $optionDiscount <= round($totalCost, 2)) {
            $points = (int) $optionPoints;
            $discountMmk = (float) $optionDiscount;
            break;
        }
    }
    if ($points <= 0) {
        return [
            'points' => 0,
            'units' => 0,
            'discount_pct' => 0.0,
            'discount_mmk' => 0.0,
        ];
    }

    $discountPct = $totalCost > 0 ? ($discountMmk / (float) $totalCost) * 100.0 : 0.0;

    return [
        'points' => $points,
        'units' => 1,
        'discount_pct' => round($discountPct, 2),
        'discount_mmk' => $discountMmk,
    ];
}

// ---------------------------------------------------------------------------
// Tier resolution (single source of truth)
// ---------------------------------------------------------------------------

/**
 * Resolve a tier using cumulative EXP. The balance argument is retained for compatibility.
 */
function loyalty_resolve_tier(int $balance, int $exp = 0, ?PDO $pdo = null): string
{
    $rules = $pdo ? loyalty_get_tier_rules($pdo) : [];
    $expThresholds = loyalty_tier_exp_thresholds();
    foreach (['diamond', 'platinum', 'gold', 'silver', 'bronze'] as $code) {
        $minExp = (int) ($rules[$code]['exp_threshold'] ?? $expThresholds[$code] ?? 0);
        if ($exp >= $minExp) {
            return (string) $code;
        }
    }
    return 'bronze';
}

/** @return array<string, array{points_cap:int,exp_threshold:int,monthly_budget_mmk:float}> */
function loyalty_get_tier_rules(PDO $pdo, bool $forUpdate = false): array
{
    $rules = [];
    $sql = 'SELECT tier_code, points_threshold AS points_cap, exp_threshold, monthly_budget_mmk
            FROM loyalty_tier_rules
            ORDER BY tier_code';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->query($sql);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $code = strtolower((string) $row['tier_code']);
        $rules[$code] = [
            'points_cap' => max(0, (int) $row['points_cap']),
            'exp_threshold' => max(0, (int) $row['exp_threshold']),
            'monthly_budget_mmk' => max(0.0, (float) $row['monthly_budget_mmk']),
        ];
    }

    foreach (loyalty_tier_labels() as $code => $_label) {
        if (!isset($rules[$code])) {
            throw new RuntimeException('Loyalty tier rules are incomplete. Apply the loyalty redesign migration.');
        }
    }

    return $rules;
}

/**
 * Calculate capped points for a fill and whether it should earn EXP instead.
 *
 * @return array{points_earned:int,exp_eligible:bool}
 */
function loyalty_calculate_fill_earnings(
    float $filledLiters,
    float $pointsPerLiter,
    int $balance,
    int $pointsCap
): array {
    $balance = max(0, $balance);
    $pointsCap = max(0, $pointsCap);
    $pointsAvailable = max(0, $pointsCap - $balance);
    $potentialPoints = (int) floor(max(0.0, $filledLiters) * max(0.0, $pointsPerLiter));
    $pointsEarned = min($potentialPoints, $pointsAvailable);

    return [
        'points_earned' => $pointsEarned,
        'exp_eligible' => $balance >= $pointsCap && $pointsEarned === 0,
    ];
}

/**
 * Load and persist the tier matching the customer's cumulative EXP.
 * The caller may include this in a larger transaction.
 *
 * @return array{exp:int,tier_code:string,next_tier:?string,next_exp:int,points_cap:int}
 */
function loyalty_update_tier_progress(PDO $pdo, int $userId, int $pointBalance): array
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('A valid customer is required to evaluate tier progress.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare(
            "INSERT IGNORE INTO loyalty_user_tier_progress (user_id, exp, tier_code, last_evaluated_week)
             VALUES (?, 0, 'bronze', NULL)"
        )->execute([$userId]);

        $progressStmt = $pdo->prepare(
            'SELECT exp
             FROM loyalty_user_tier_progress
             WHERE user_id = ? LIMIT 1 FOR UPDATE'
        );
        $progressStmt->execute([$userId]);
        $progress = $progressStmt->fetch(PDO::FETCH_ASSOC);
        if (!$progress) {
            throw new RuntimeException('Could not load customer tier progress.');
        }

        $rules = loyalty_get_tier_rules($pdo);
        $exp = max(0, (int) $progress['exp']);
        $tierCode = loyalty_resolve_tier($pointBalance, $exp, $pdo);
        $tierOrder = array_keys(loyalty_tier_labels());
        $tierIndex = array_search($tierCode, $tierOrder, true);
        $nextTier = $tierIndex !== false ? ($tierOrder[$tierIndex + 1] ?? null) : null;
        $result = [
            'exp' => $exp,
            'tier_code' => $tierCode,
            'next_tier' => $nextTier,
            'next_exp' => $nextTier !== null ? $rules[$nextTier]['exp_threshold'] : 0,
            'points_cap' => $rules[$tierCode]['points_cap'],
        ];
        $save = $pdo->prepare(
            'UPDATE loyalty_user_tier_progress
             SET tier_code = ?
             WHERE user_id = ?'
        );
        $save->execute([$tierCode, $userId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array{tier_code:string,exp:int,next_tier:?string,next_exp:int,points_cap:int,points_remaining_to_cap:int,tiers:array<string,array{label:string,points_cap:int,points_remaining:int,exp_threshold:int,exp_remaining:int,pool_remaining_mmk:float,monthly_budget_mmk:float}>}
 */
function loyalty_customer_tier_status(PDO $pdo, int $userId, int $pointBalance): array
{
    $progress = loyalty_update_tier_progress($pdo, $userId, $pointBalance);
    $rules = loyalty_get_tier_rules($pdo);
    $tiers = [];
    foreach (loyalty_tier_labels() as $code => $label) {
        $poolRemaining = loyalty_ensure_tier_pool($pdo, $code);
        $tiers[$code] = [
            'label' => $label,
            'points_cap' => $rules[$code]['points_cap'],
            'points_remaining' => max(0, $rules[$code]['points_cap'] - $pointBalance),
            'exp_threshold' => $rules[$code]['exp_threshold'],
            'exp_remaining' => max(0, $rules[$code]['exp_threshold'] - $progress['exp']),
            'pool_remaining_mmk' => $poolRemaining,
            'monthly_budget_mmk' => $rules[$code]['monthly_budget_mmk'],
        ];
    }

    return $progress + [
        'points_remaining_to_cap' => max(0, $progress['points_cap'] - $pointBalance),
        'tiers' => $tiers,
    ];
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
        throw new RuntimeException('Could not verify whether this fill is internal.', 0, $e);
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

function loyalty_monthly_pool_budget(PDO $pdo): float
{
    $stmt = $pdo->prepare(
        'SELECT setting_value
         FROM platform_settings
         WHERE setting_key = ?
         LIMIT 1'
    );
    $stmt->execute(['loyalty_monthly_budget_mmk']);
    $value = $stmt->fetchColumn();
    if ($value !== false) {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new RuntimeException('The configured monthly loyalty budget is invalid.');
        }
        return (float) $value;
    }

    return array_sum(array_column(loyalty_get_tier_rules($pdo), 'monthly_budget_mmk'));
}

/**
 * Lock the global budget setting to serialize budget changes with redemptions.
 */
function loyalty_lock_monthly_pool_budget(PDO $pdo): float
{
    $fallbackBudget = loyalty_monthly_pool_budget($pdo);
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO platform_settings (setting_key, setting_value)
         VALUES (?, ?)'
    );
    $insert->execute(['loyalty_monthly_budget_mmk', number_format($fallbackBudget, 2, '.', '')]);

    $lock = $pdo->prepare(
        'SELECT setting_value
         FROM platform_settings
         WHERE setting_key = ?
         LIMIT 1
         FOR UPDATE'
    );
    $lock->execute(['loyalty_monthly_budget_mmk']);
    $value = $lock->fetchColumn();
    if ($value === false || !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
        throw new RuntimeException('The monthly loyalty budget could not be locked safely.');
    }

    return (float) $value;
}

/**
 * Allocate a monthly MMK budget proportionally, rounding only to whole cents.
 *
 * @param array<string, float|int> $weights
 * @return array<string, float>
 */
function loyalty_distribute_monthly_pool_budget(float $budgetMmk, array $weights): array
{
    if (!is_finite($budgetMmk) || $budgetMmk < 0) {
        throw new InvalidArgumentException('The monthly loyalty budget must be a non-negative amount.');
    }

    $codes = array_keys(loyalty_tier_labels());
    $normalizedWeights = [];
    foreach ($codes as $code) {
        $weight = (float) ($weights[$code] ?? 0);
        $normalizedWeights[$code] = is_finite($weight) ? max(0.0, $weight) : 0.0;
    }
    if (array_sum($normalizedWeights) <= 0) {
        $normalizedWeights = loyalty_tier_pool_budgets();
    }

    $weightTotal = array_sum($normalizedWeights);
    if ($weightTotal <= 0) {
        throw new RuntimeException('Tier pool allocation weights are unavailable.');
    }

    $totalCents = (int) round($budgetMmk * 100);
    $allocationsCents = [];
    $remainders = [];
    $allocatedCents = 0;
    foreach ($codes as $index => $code) {
        $exactCents = $totalCents * ($normalizedWeights[$code] / $weightTotal);
        $wholeCents = (int) floor($exactCents);
        $allocationsCents[$code] = $wholeCents;
        $allocatedCents += $wholeCents;
        $remainders[$code] = ['fraction' => $exactCents - $wholeCents, 'index' => $index];
    }

    uasort($remainders, static function (array $left, array $right): int {
        $fractionOrder = $right['fraction'] <=> $left['fraction'];
        return $fractionOrder !== 0 ? $fractionOrder : ($left['index'] <=> $right['index']);
    });
    $remainingCents = $totalCents - $allocatedCents;
    foreach (array_keys($remainders) as $code) {
        if ($remainingCents <= 0) {
            break;
        }
        $allocationsCents[$code]++;
        $remainingCents--;
    }

    $allocations = [];
    foreach ($codes as $code) {
        $allocations[$code] = $allocationsCents[$code] / 100;
    }
    return $allocations;
}

function loyalty_current_year_month(): string
{
    return date('Y-m');
}

/**
 * Ensure pool row exists for tier + month; returns remaining MMK or 0 on failure.
 */
function loyalty_ensure_tier_pool(PDO $pdo, string $tierCode, ?string $yearMonth = null, ?array $rules = null): float
{
    $tierCode = strtolower(trim($tierCode));
    if ($tierCode === '') {
        $tierCode = 'bronze';
    }
    $ym = $yearMonth ?: loyalty_current_year_month();
    $rules ??= loyalty_get_tier_rules($pdo);
    $budget = (float) ($rules[$tierCode]['monthly_budget_mmk'] ?? $rules['bronze']['monthly_budget_mmk']);
    $ins = $pdo->prepare(
        'INSERT INTO loyalty_tier_pool (tier_code, `year_month`, budget_mmk, spent_mmk)
         VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE budget_mmk = VALUES(budget_mmk)'
    );
    $ins->execute([$tierCode, $ym, $budget]);

    $sel = $pdo->prepare(
        'SELECT budget_mmk, spent_mmk FROM loyalty_tier_pool
         WHERE tier_code = ? AND `year_month` = ? LIMIT 1'
    );
    $sel->execute([$tierCode, $ym]);
    $row = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Could not load the shared loyalty pool.');
    }
    return max(0.0, (float) $row['budget_mmk'] - (float) $row['spent_mmk']);
}

function loyalty_get_pool_remaining(PDO $pdo, string $tierCode, ?string $yearMonth = null): float
{
    return loyalty_ensure_tier_pool($pdo, $tierCode, $yearMonth);
}

/**
 * Atomically consume up to $amountMmk from pool. Returns amount actually consumed.
 */
function loyalty_try_consume_pool(PDO $pdo, string $tierCode, float $amountMmk, ?string $yearMonth = null): float
{
    if ($amountMmk <= 0) {
        return 0.0;
    }
    $tierCode = strtolower(trim($tierCode));
    if ($tierCode === '') {
        $tierCode = 'bronze';
    }
    $ym = $yearMonth ?: loyalty_current_year_month();
    $amountMmk = round($amountMmk, 2);

    $monthlyBudget = loyalty_lock_monthly_pool_budget($pdo);
    $rules = loyalty_get_tier_rules($pdo, true);
    $spentStmt = $pdo->prepare(
        'SELECT spent_mmk
         FROM loyalty_tier_pool
         WHERE `year_month` = ?'
         . ' FOR UPDATE'
    );
    $spentStmt->execute([$ym]);
    $spent = 0.0;
    while (($spentValue = $spentStmt->fetchColumn()) !== false) {
        $spent += (float) $spentValue;
    }
    if ($spent + $amountMmk > $monthlyBudget + 0.0001) {
        return 0.0;
    }

    loyalty_ensure_tier_pool($pdo, $tierCode, $ym, $rules);
    $upd = $pdo->prepare(
        'UPDATE loyalty_tier_pool
         SET spent_mmk = spent_mmk + ?
         WHERE tier_code = ? AND `year_month` = ?
           AND spent_mmk + ? <= budget_mmk'
    );
    $upd->execute([$amountMmk, $tierCode, $ym, $amountMmk]);
    return $upd->rowCount() === 1 ? $amountMmk : 0.0;
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
        throw new RuntimeException('The loyalty ledger is disabled; financial loyalty changes cannot be recorded.');
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
        $meta = json_encode($meta, JSON_THROW_ON_ERROR);
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
}

/** Number of additional station slots purchased for the current month. */
function hq_billing_current_month_slots(PDO $pdo, int $headquarterId): int
{
    if ($headquarterId <= 0 || !loyalty_countermeasures_schema_ready($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(mmk_slots_purchased, 0)
             FROM company_loyalty_station_slots
             WHERE headquarter_id = ? AND `year_month` = ? LIMIT 1'
        );
        $stmt->execute([$headquarterId, loyalty_current_year_month()]);
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable $e) {
        error_log('hq_billing_current_month_slots: ' . $e->getMessage());
        return 0;
    }
}

/** True only when the station's owning HQ has paid this month's station fee. */
function hq_billing_station_is_paid(PDO $pdo, int $stationId): bool
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
        if ($extraSlotRequired > hq_billing_current_month_slots($pdo, (int) $station['headquarter_id'])) {
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
                AND f.`year_month` = ?
             WHERE sbs.station_id = ?
             LIMIT 1'
        );
        $stmt->execute([loyalty_current_year_month(), $stationId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('hq_billing_station_is_paid: ' . $e->getMessage());
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
 *   is_internal:bool,
 *   tier:string,
 *   exp_earned:int,
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
        'is_internal' => $isInternal,
        'tier' => 'bronze',
        'exp_earned' => 0,
        'pool_limited' => false,
        'balance_after' => 0,
        'loyalty_error' => null,
    ];

    if (!$pdo->inTransaction()) {
        throw new LogicException('Loyalty balance changes must run inside the successful-fill transaction.');
    }
    if ($userId <= 0 || $filledLiters <= 0) {
        return $result;
    }
    if (!loyalty_schema_ready($pdo) || !loyalty_countermeasures_schema_ready($pdo)) {
        throw new RuntimeException('Loyalty storage is unavailable; the fill cannot be settled safely.');
    }

    $balanceStmt = $pdo->prepare('SELECT COALESCE(had_point, 0) FROM users WHERE user_id = ? LIMIT 1 FOR UPDATE');
    $balanceStmt->execute([$userId]);
    $balanceValue = $balanceStmt->fetchColumn();
    if ($balanceValue === false) {
        throw new RuntimeException('Customer account is unavailable for loyalty settlement.');
    }
    $balance = max(0, (int) $balanceValue);
    $progress = loyalty_update_tier_progress($pdo, $userId, $balance);
    $result['balance_after'] = $balance;
    $result['tier'] = $progress['tier_code'];

    if ($isInternal) {
        $result['is_internal'] = true;
        return $result;
    }

    $discount = loyalty_compute_discount(
        $filledLiters,
        $totalCost,
        min(max(0, $pointsIntent), $balance),
        loyalty_redemption_options($pdo)
    );
    $pointsToRedeem = (int) $discount['points'];
    $discountMmk = (float) $discount['discount_mmk'];
    if ($pointsToRedeem > 0) {
        $allocated = loyalty_try_consume_pool($pdo, $progress['tier_code'], $discountMmk);
        if ($allocated + 0.0001 < $discountMmk) {
            $pointsToRedeem = 0;
            $discountMmk = 0.0;
            $result['pool_limited'] = true;
        }
    }

    if ($pointsToRedeem > 0) {
        $debit = $pdo->prepare(
            'UPDATE users SET had_point = had_point - ?
             WHERE user_id = ? AND had_point >= ?'
        );
        $debit->execute([$pointsToRedeem, $userId, $pointsToRedeem]);
        if ($debit->rowCount() !== 1) {
            throw new RuntimeException('Customer points changed during redemption; retry the fill.');
        }

        $headquarterId = null;
        if ($stationId !== null && $stationId > 0) {
            $headquarterStmt = $pdo->prepare(
                'SELECT headquarter_id
                 FROM station_business_scope
                 WHERE station_id = ?
                 LIMIT 1'
            );
            $headquarterStmt->execute([$stationId]);
            $mappedHeadquarterId = $headquarterStmt->fetchColumn();
            if ($mappedHeadquarterId !== false) {
                $headquarterId = (int) $mappedHeadquarterId;
            }
        }

        if ($headquarterId !== null && $headquarterId > 0) {
            $accountCredit = $pdo->prepare(
                'INSERT INTO loyalty_company_discount_accounts (headquarter_id, balance_mmk)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE balance_mmk = balance_mmk + VALUES(balance_mmk)'
            );
            $accountCredit->execute([$headquarterId, $discountMmk]);
        } else {
            $accountCredit = $pdo->prepare(
                'UPDATE loyalty_discount_account
                 SET balance_mmk = balance_mmk + ?
                 WHERE account_id = 1'
            );
            $accountCredit->execute([$discountMmk]);
            if ($accountCredit->rowCount() !== 1) {
                throw new RuntimeException('Could not credit the unallocated Total Discounted Amount balance.');
            }
        }

        $result['points_redeemed'] = $pointsToRedeem;
        $result['discount_mmk'] = $discountMmk;
        $result['discount_pct'] = $totalCost > 0 ? round($discountMmk / $totalCost * 100, 2) : 0.0;
        loyalty_ledger_write($pdo, [
            'party_type' => 'customer',
            'party_id' => $userId,
            'direction' => 'redeem',
            'points' => $pointsToRedeem,
            'discount_mmk' => $discountMmk,
            'booking_id' => $bookingId,
            'usage_id' => $usageId,
            'station_id' => $stationId,
            'staff_user_id' => $staffUserId,
            'is_internal' => 0,
            'meta' => [
                'total_discounted_amount_credited' => true,
                'headquarter_id' => $headquarterId,
                'unallocated' => $headquarterId === null || $headquarterId <= 0,
            ],
        ]);
    }

    $balanceAfterRedemption = $balance - $pointsToRedeem;
    $pointsPerLiter = $balanceAfterRedemption < $progress['points_cap']
        ? loyalty_points_per_liter($pdo)
        : 0.0;
    $fillEarnings = loyalty_calculate_fill_earnings(
        $filledLiters,
        $pointsPerLiter,
        $balanceAfterRedemption,
        $progress['points_cap']
    );
    $earnPoints = $fillEarnings['points_earned'];
    if ($earnPoints > 0) {
        $credit = $pdo->prepare('UPDATE users SET had_point = had_point + ? WHERE user_id = ?');
        $credit->execute([$earnPoints, $userId]);
        if ($credit->rowCount() !== 1) {
            throw new RuntimeException('Could not credit points for the completed fill.');
        }
        $result['points_earned'] = $earnPoints;
        loyalty_ledger_write($pdo, [
            'party_type' => 'customer',
            'party_id' => $userId,
            'direction' => 'earn',
            'points' => $earnPoints,
            'booking_id' => $bookingId,
            'usage_id' => $usageId,
            'station_id' => $stationId,
            'staff_user_id' => $staffUserId,
            'is_internal' => 0,
        ]);
    } elseif ($fillEarnings['exp_eligible']) {
        $awardExp = $pdo->prepare(
            'UPDATE loyalty_user_tier_progress SET exp = exp + 1 WHERE user_id = ?'
        );
        $awardExp->execute([$userId]);
        $result['exp_earned'] = 1;
    }

    $balanceStmt->execute([$userId]);
    $result['balance_after'] = max(0, (int) $balanceStmt->fetchColumn());
    $progress = loyalty_update_tier_progress($pdo, $userId, (int) $result['balance_after']);
    $result['tier'] = $progress['tier_code'];

    if (function_exists('map_pump_log_action') && $staffUserId !== null && $staffUserId > 0) {
        $parts = [];
        if ($result['points_earned'] > 0) {
            $parts[] = '+' . $result['points_earned'] . ' points';
        }
        if ($result['exp_earned'] > 0) {
            $parts[] = '+' . $result['exp_earned'] . ' EXP';
        }
        if ($result['points_redeemed'] > 0) {
            $parts[] = '-' . $result['points_redeemed'] . ' points';
            $parts[] = number_format($result['discount_mmk'], 2) . ' MMK discount';
        } elseif ($result['pool_limited']) {
            $parts[] = 'redemption skipped (tier pool exhausted)';
        }
        if ($parts !== []) {
            $message = 'Loyalty user #' . $userId . ': ' . implode(', ', $parts);
            if ($bookingId !== null && $bookingId > 0) {
                $message .= ' (booking #' . $bookingId . ')';
            }
            try {
                map_pump_log_action($pdo, $staffUserId, $message, 5);
            } catch (Throwable $e) {
                error_log('loyalty_apply_on_fill activity log: ' . $e->getMessage());
            }
        }
    }

    return $result;
}

/**
 * Validate redeem intent for booking API (before storing intent).
 * Returns selected points or throws InvalidArgumentException with message.
 * Does NOT hard-fail on tier pool.
 */
function loyalty_validate_redeem_intent(PDO $pdo, int $userId, int $requestedPoints): int
{
    if ($requestedPoints < 0) {
        throw new InvalidArgumentException('Loyalty points to redeem cannot be negative.');
    }
    $options = loyalty_redemption_options($pdo);
    if ($requestedPoints > 0 && !array_key_exists($requestedPoints, $options)) {
        throw new InvalidArgumentException(
            'Choose one of the available fixed loyalty reward options.'
        );
    }

    if ($requestedPoints > 0) {
        $balance = loyalty_get_balance($pdo, $userId);
        if ($requestedPoints > $balance) {
            throw new InvalidArgumentException(
                'Not enough loyalty points. You have ' . $balance . ' point(s).'
            );
        }
    }
    return $requestedPoints;
}
