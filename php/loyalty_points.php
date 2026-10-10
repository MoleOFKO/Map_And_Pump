<?php
/**
 * Loyalty Reward Points — helpers (v1, users.had_point only; no ledger)
 * See: LOYALTY_POINTS_IMPLEMENTATION_PLAN.md
 */

require_once __DIR__ . '/loyalty_config.php';

/**
 * Current loyalty balance for a user.
 */
function loyalty_get_balance(PDO $pdo, int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT COALESCE(had_point, 0) FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    return max(0, (int) $stmt->fetchColumn());
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
    // Force multiple of unit
    $points = (int) (floor($points / $unit) * $unit);
    return max(0, min($points, $max));
}

/**
 * Compute discount percent and MMK for a fill.
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

    $rawPctPerUnit = min(
        (float) LOYALTY_PCT_PER_UNIT_CAP,
        (float) $filledLiters * (float) LOYALTY_PCT_PER_LITER
    );
    $discountPct = min((float) LOYALTY_MAX_DISCOUNT_PCT, $rawPctPerUnit * $units);
    $discountMmk = round(((float) $totalCost) * $discountPct / 100.0, 2);
    $moneyCap = round(((float) $totalCost) * ((float) LOYALTY_MAX_DISCOUNT_PCT) / 100.0, 2);
    if ($discountMmk > $moneyCap) {
        $discountMmk = $moneyCap;
    }
    if ($discountMmk < 0) {
        $discountMmk = 0.0;
    }

    return [
        'points' => $points,
        'units' => $units,
        'discount_pct' => round($discountPct, 2),
        'discount_mmk' => $discountMmk,
    ];
}

/**
 * Apply redeem (optional) + earn (+3) on successful fill.
 * Must be called inside the caller's open transaction.
 *
 * @return array{
 *   points_earned:int,
 *   points_redeemed:int,
 *   discount_pct:float,
 *   discount_mmk:float,
 *   balance_after:int
 * }
 */
function loyalty_apply_on_fill(
    PDO $pdo,
    int $userId,
    float $filledLiters,
    ?float $totalCost,
    int $pointsIntent,
    ?int $bookingId = null,
    ?int $staffUserId = null
): array {
    $result = [
        'points_earned' => 0,
        'points_redeemed' => 0,
        'discount_pct' => 0.0,
        'discount_mmk' => 0.0,
        'balance_after' => loyalty_get_balance($pdo, $userId),
    ];

    if ($userId <= 0 || $filledLiters <= 0) {
        return $result;
    }

    $balance = loyalty_get_balance($pdo, $userId);
    $intent = loyalty_normalize_points_intent($pointsIntent);
    $intent = min($intent, $balance);

    $discount = loyalty_compute_discount($filledLiters, $totalCost, $intent);
    $pointsToRedeem = (int) $discount['points'];

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
            $result['discount_pct'] = (float) $discount['discount_pct'];
            $result['discount_mmk'] = (float) $discount['discount_mmk'];
        }
        // If rowCount === 0, skip redeem silently (race / insufficient)
    }

    // Credit earn (+3 per successful fill)
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
        }
    }

    $result['balance_after'] = loyalty_get_balance($pdo, $userId);

    // Soft audit via existing activity log when available
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

    return $result;
}

/**
 * Validate redeem intent for booking API (before storing intent).
 * Returns normalized points or throws InvalidArgumentException with message.
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

/**
 * Ensure optional loyalty columns exist on booking / usage_history (best-effort).
 * Safe to call repeatedly; does not create a ledger table.
 */
function loyalty_schema_ready(PDO $pdo): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }

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
