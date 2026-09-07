<?php
/**
 * MAP & PUMP — booking and fuel-quota rules (single source of truth).
 *
 * Product direction:
 * - One active booking per customer vehicle at a time.
 * - Booking quantity is chosen by the driver up to the remaining quota.
 * - Quota resets weekly (or monthly when configured).
 * - Bookings expire after a short window if not filled at the station.
 */

if (!defined('MAP_PUMP_BOOKING_MAX_ACTIVE_PER_CAR')) {
    define('MAP_PUMP_BOOKING_MAX_ACTIVE_PER_CAR', 1);
}

if (!defined('MAP_PUMP_FUEL_QUOTA_LITERS')) {
    define('MAP_PUMP_FUEL_QUOTA_LITERS', 35.0);
}

if (!defined('MAP_PUMP_FUEL_QUOTA_PERIOD')) {
    $period = strtolower(trim((string) (getenv('MAP_PUMP_FUEL_QUOTA_PERIOD') ?: 'weekly')));
    define('MAP_PUMP_FUEL_QUOTA_PERIOD', in_array($period, ['weekly', 'monthly'], true) ? $period : 'weekly');
}

if (!defined('MAP_PUMP_BOOKING_EXPIRY_HOURS')) {
    define('MAP_PUMP_BOOKING_EXPIRY_HOURS', 1);
}

if (!function_exists('map_pump_fuel_quota_interval_sql')) {
    function map_pump_fuel_quota_interval_sql(): string
    {
        return MAP_PUMP_FUEL_QUOTA_PERIOD === 'monthly' ? 'INTERVAL 30 DAY' : 'INTERVAL 7 DAY';
    }
}

if (!function_exists('map_pump_fuel_quota_period_label')) {
    function map_pump_fuel_quota_period_label(): string
    {
        return MAP_PUMP_FUEL_QUOTA_PERIOD === 'monthly' ? 'monthly' : 'weekly';
    }
}

if (!function_exists('map_pump_booking_active_statuses')) {
    /** Status values that count as an open booking for the one-booking-per-car rule. */
    function map_pump_booking_active_statuses(): array
    {
        return ['notfilled', 'serving'];
    }
}

if (!function_exists('map_pump_booking_rules_summary')) {
    function map_pump_booking_rules_summary(): array
    {
        return [
            'marketplace' => 'Shared marketplace for drivers and participating fuel companies.',
            'one_active_booking_per_car' => true,
            'max_active_bookings_per_car' => MAP_PUMP_BOOKING_MAX_ACTIVE_PER_CAR,
            'quota_liters' => MAP_PUMP_FUEL_QUOTA_LITERS,
            'quota_period' => map_pump_fuel_quota_period_label(),
            'booking_expiry_hours' => MAP_PUMP_BOOKING_EXPIRY_HOURS,
            'quantity_rule' => 'Driver selects liters up to remaining quota when booking.',
            'stock_rule' => 'Branch managers update live tank stock and prices; public pages read the latest approved rows.',
            'company_isolation' => 'Each HQ company only sees and changes its own stations, staff, and inventory.',
        ];
    }
}
