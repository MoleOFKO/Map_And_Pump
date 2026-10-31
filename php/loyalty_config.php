<?php
require_once __DIR__ . '/billing_config.php';

/**
 * Loyalty rewards and customer tier defaults.
 */

// ---------------------------------------------------------------------------
// Core earn / redeem
// ---------------------------------------------------------------------------

// Database settings override this default when the admin changes the rate.
if (!defined('LOYALTY_POINTS_PER_FILL')) {
    define('LOYALTY_POINTS_PER_FILL', 1);
}

// Fixed redemption options: customer points => MMK discount.
if (!defined('LOYALTY_REDEMPTION_OPTIONS')) {
    define('LOYALTY_REDEMPTION_OPTIONS', [
        500 => 1000,
        999 => 2000,
        1499 => 3000,
    ]);
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
// Inventory trust thresholds (enforce gated by LOYALTY_CM_INVENTORY_PROOF_ENFORCE)
// ---------------------------------------------------------------------------

if (!defined('LOYALTY_INVENTORY_PROOF_SINGLE_L')) {
    define('LOYALTY_INVENTORY_PROOF_SINGLE_L', 2000);
}
if (!defined('LOYALTY_INVENTORY_PROOF_ROLLING_7D_L')) {
    define('LOYALTY_INVENTORY_PROOF_ROLLING_7D_L', 5000);
}

// ---------------------------------------------------------------------------
// Defaults are seeded into loyalty_tier_rules by the loyalty redesign migration.
// ---------------------------------------------------------------------------

if (!function_exists('loyalty_tier_thresholds')) {
    /**
     * @return array<string, int> tier_code => min points (descending check order)
     */
    function loyalty_tier_thresholds(): array
    {
        return [
            'diamond' => 2000,
            'platinum' => 1499,
            'gold' => 999,
            'silver' => 500,
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

if (!function_exists('loyalty_tier_exp_thresholds')) {
    /** @return array<string, int> */
    function loyalty_tier_exp_thresholds(): array
    {
        return [
            'diamond' => 8,
            'platinum' => 6,
            'gold' => 4,
            'silver' => 2,
            'bronze' => 0,
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
