<?php
/**
 * Loyalty Reward Points — configuration (v2 countermeasures)
 * See: POINTS_COUNTERMEASURES_IMPLEMENTATION_PLAN.md
 * Safety: POINTS_COUNTERMEASURES_SIMULATION_AND_RISKS.md
 */

// ---------------------------------------------------------------------------
// Core earn / redeem (unchanged product rules)
// ---------------------------------------------------------------------------

// Earn: flat points per successful external fill
if (!defined('LOYALTY_POINTS_PER_FILL')) {
    define('LOYALTY_POINTS_PER_FILL', 3);
}

// Redeem: multiples of this unit only (0, 10, 20)
if (!defined('LOYALTY_REDEEM_UNIT_POINTS')) {
    define('LOYALTY_REDEEM_UNIT_POINTS', 10);
}

// Hard max points a customer may apply on a single fill
if (!defined('LOYALTY_MAX_POINTS_PER_FILL')) {
    define('LOYALTY_MAX_POINTS_PER_FILL', 20);
}

// Hard cap on discount as percent of that fill's total_cost
if (!defined('LOYALTY_MAX_DISCOUNT_PCT')) {
    define('LOYALTY_MAX_DISCOUNT_PCT', 20);
}

// Liter → percentage per redeem unit:
// raw_pct_per_unit = min(LOYALTY_PCT_PER_UNIT_CAP, filled_liters * LOYALTY_PCT_PER_LITER)
if (!defined('LOYALTY_PCT_PER_LITER')) {
    define('LOYALTY_PCT_PER_LITER', 0.5);
}
if (!defined('LOYALTY_PCT_PER_UNIT_CAP')) {
    define('LOYALTY_PCT_PER_UNIT_CAP', 10);
}

// ---------------------------------------------------------------------------
// Feature flags (F12) — allow partial disable without code revert
// ---------------------------------------------------------------------------

if (!defined('LOYALTY_CM_INTERNAL_DETECT')) {
    define('LOYALTY_CM_INTERNAL_DETECT', true);
}
if (!defined('LOYALTY_CM_TIER_POOL')) {
    define('LOYALTY_CM_TIER_POOL', true);
}
if (!defined('LOYALTY_CM_COMPANY_POINTS')) {
    define('LOYALTY_CM_COMPANY_POINTS', true);
}
if (!defined('LOYALTY_CM_COST_SHARE')) {
    define('LOYALTY_CM_COST_SHARE', true);
}
if (!defined('LOYALTY_CM_INVENTORY_PROOF_ENFORCE')) {
    // Default OFF until Branch Manager proof UI is verified (F6)
    define('LOYALTY_CM_INVENTORY_PROOF_ENFORCE', false);
}
if (!defined('LOYALTY_CM_LEDGER')) {
    define('LOYALTY_CM_LEDGER', true);
}

// ---------------------------------------------------------------------------
// Cost-share (Admin vs Company portion of customer discount)
// ---------------------------------------------------------------------------

if (!defined('LOYALTY_ADMIN_DISCOUNT_SHARE_PCT')) {
    define('LOYALTY_ADMIN_DISCOUNT_SHARE_PCT', 60);
}
if (!defined('LOYALTY_COMPANY_DISCOUNT_SHARE_PCT')) {
    define('LOYALTY_COMPANY_DISCOUNT_SHARE_PCT', 40);
}

// ---------------------------------------------------------------------------
// Company points
// ---------------------------------------------------------------------------

if (!defined('LOYALTY_COMPANY_POINTS_PER_EXTERNAL_FILL')) {
    define('LOYALTY_COMPANY_POINTS_PER_EXTERNAL_FILL', 1);
}
if (!defined('LOYALTY_COMPANY_MONTHLY_EARN_CAP')) {
    define('LOYALTY_COMPANY_MONTHLY_EARN_CAP', 500);
}
if (!defined('LOYALTY_COMPANY_POINT_EXPIRY_DAYS')) {
    define('LOYALTY_COMPANY_POINT_EXPIRY_DAYS', 90);
}

// ---------------------------------------------------------------------------
// Inventory trust thresholds (enforce gated by LOYALTY_CM_INVENTORY_PROOF_ENFORCE)
// ---------------------------------------------------------------------------

if (!defined('LOYALTY_INVENTORY_PROOF_SINGLE_L')) {
    define('LOYALTY_INVENTORY_PROOF_SINGLE_L', 2000);
}
if (!defined('LOYALTY_INVENTORY_PROOF_ROLLING_7D_L')) {
    define('LOYALTY_INVENTORY_PROOF_ROLLING_7D_L', 5000);
}

// ---------------------------------------------------------------------------
// Customer tiers (shared monthly pool budgets in MMK — placeholders)
// Thresholds: balance → tier (aligned with previous dashboard display)
// ---------------------------------------------------------------------------

if (!function_exists('loyalty_tier_thresholds')) {
    /**
     * @return array<string, int> tier_code => min points (descending check order)
     */
    function loyalty_tier_thresholds(): array
    {
        return [
            'diamond' => 100,
            'platinum' => 80,
            'gold' => 60,
            'silver' => 40,
            'bronze' => 0,
        ];
    }
}

if (!function_exists('loyalty_tier_pool_budgets')) {
    /**
     * Monthly shared pool budget per tier (discount MMK).
     * @return array<string, float>
     */
    function loyalty_tier_pool_budgets(): array
    {
        return [
            'bronze' => 100000.0,
            'silver' => 250000.0,
            'gold' => 500000.0,
            'platinum' => 1000000.0,
            'diamond' => 2000000.0,
        ];
    }
}

if (!function_exists('loyalty_tier_labels')) {
    /**
     * @return array<string, string>
     */
    function loyalty_tier_labels(): array
    {
        return [
            'bronze' => 'Bronze',
            'silver' => 'Silver',
            'gold' => 'Gold',
            'platinum' => 'Platinum',
            'diamond' => 'Diamond',
        ];
    }
}
