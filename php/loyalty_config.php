<?php
/**
 * Loyalty Reward Points — configuration (v1)
 * See: LOYALTY_POINTS_IMPLEMENTATION_PLAN.md
 */

// Earn: flat points per successful fill
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
// Example: 20 L → 10% per 10-point unit; 10 L → 5% per unit
if (!defined('LOYALTY_PCT_PER_LITER')) {
    define('LOYALTY_PCT_PER_LITER', 0.5);
}
if (!defined('LOYALTY_PCT_PER_UNIT_CAP')) {
    define('LOYALTY_PCT_PER_UNIT_CAP', 10);
}
