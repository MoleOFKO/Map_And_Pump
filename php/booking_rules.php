<?php
/**
 * MAP & PUMP — booking and fuel-quota rules (single source of truth).
 *
 * Product rules:
 * - One active reservation per customer account across all stations.
 * - A reservation holds the requested liters until it expires, is cancelled,
 *   or is fulfilled. A completed fill permanently reduces tank stock.
 * - Weekly fuel quota resets Sunday at 00:00 in Asia/Yangon.
 */

if (!defined('MAP_PUMP_MAX_ACTIVE_PER_USER')) {
    define('MAP_PUMP_MAX_ACTIVE_PER_USER', 1);
}

// Kept as a compatibility constant for any older UI still referring to it.
if (!defined('MAP_PUMP_BOOKING_MAX_ACTIVE_PER_CAR')) {
    define('MAP_PUMP_BOOKING_MAX_ACTIVE_PER_CAR', 1);
}

if (!defined('MAP_PUMP_FUEL_QUOTA_LITERS')) {
    define('MAP_PUMP_FUEL_QUOTA_LITERS', 35.0);
}

/** platform_settings key for the admin-managed weekly fuel quota (liters per vehicle). */
if (!defined('MAP_PUMP_FUEL_QUOTA_SETTING_KEY')) {
    define('MAP_PUMP_FUEL_QUOTA_SETTING_KEY', 'fuel_weekly_quota_liters');
}

if (!defined('MAP_PUMP_FUEL_QUOTA_PERIOD')) {
    define('MAP_PUMP_FUEL_QUOTA_PERIOD', 'weekly');
}

if (!defined('MAP_PUMP_BOOKING_EXPIRY_HOURS')) {
    define('MAP_PUMP_BOOKING_EXPIRY_HOURS', 1);
}

if (!function_exists('map_pump_fuel_quota_liters')) {
    /**
     * Live weekly fuel quota in liters (per vehicle).
     * Reads platform_settings; falls back to MAP_PUMP_FUEL_QUOTA_LITERS.
     */
    function map_pump_fuel_quota_liters(PDO $pdo): float
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1'
            );
            $stmt->execute([MAP_PUMP_FUEL_QUOTA_SETTING_KEY]);
            $value = $stmt->fetchColumn();
            if ($value !== false && is_numeric($value) && is_finite((float) $value)) {
                $liters = round((float) $value, 2);
                if ($liters > 0 && $liters <= 500) {
                    return $liters;
                }
            }
        } catch (Throwable $e) {
            error_log('map_pump_fuel_quota_liters: ' . $e->getMessage());
        }
        return (float) MAP_PUMP_FUEL_QUOTA_LITERS;
    }
}

if (!function_exists('map_pump_save_fuel_quota_liters')) {
    /** Persist weekly fuel quota (liters). Throws RuntimeException on invalid input. */
    function map_pump_save_fuel_quota_liters(PDO $pdo, float $liters): float
    {
        if (!is_finite($liters) || $liters <= 0 || $liters > 500) {
            throw new RuntimeException('Weekly fuel limit must be greater than 0 and at most 500 liters.');
        }
        $liters = round($liters, 2);
        $stmt = $pdo->prepare(
            'INSERT INTO platform_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([MAP_PUMP_FUEL_QUOTA_SETTING_KEY, number_format($liters, 2, '.', '')]);
        return $liters;
    }
}

if (!function_exists('map_pump_booking_configure_timezone')) {
    function map_pump_booking_configure_timezone(PDO $pdo): void
    {
        static $configured = false;
        if ($configured) {
            return;
        }
        date_default_timezone_set('Asia/Yangon');
        // Avoid relying on the database's timezone tables for this fixed offset.
        $pdo->exec("SET time_zone = '+06:30'");
        $configured = true;
    }
}

if (!function_exists('map_pump_fuel_quota_week_window')) {
    /** Return [start, end) in Myanmar local time for the current Sunday week. */
    function map_pump_fuel_quota_week_window(?DateTimeImmutable $now = null): array
    {
        $zone = new DateTimeZone('Asia/Yangon');
        $now = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
        $start = $now->setTime(0, 0, 0)->modify('-' . (int) $now->format('w') . ' days');
        return [$start->format('Y-m-d H:i:s'), $start->modify('+7 days')->format('Y-m-d H:i:s')];
    }
}

if (!function_exists('map_pump_fuel_quota_interval_sql')) {
    function map_pump_fuel_quota_interval_sql(): string
    {
        // Compatibility only. New quota logic must use calendar-week bounds.
        return 'INTERVAL 7 DAY';
    }
}

if (!function_exists('map_pump_fuel_quota_period_label')) {
    function map_pump_fuel_quota_period_label(): string
    {
        return 'weekly';
    }
}

if (!function_exists('map_pump_booking_active_statuses')) {
    /** Statuses that continue to hold a reservation and its stock. */
    function map_pump_booking_active_statuses(): array
    {
        return ['notfilled', 'waiting', 'serving'];
    }
}

if (!function_exists('map_pump_booking_rules_summary')) {
    function map_pump_booking_rules_summary(): array
    {
        return [
            'marketplace' => 'Shared marketplace for drivers and participating fuel companies.',
            'one_active_booking_per_user' => true,
            'max_active_bookings_per_user' => MAP_PUMP_MAX_ACTIVE_PER_USER,
            'quota_liters' => MAP_PUMP_FUEL_QUOTA_LITERS, // default; live value via map_pump_fuel_quota_liters($pdo)
            'quota_period' => 'weekly',
            'quota_timezone' => 'Asia/Yangon',
            'quota_reset' => 'Sunday 00:00 Asia/Yangon',
            'booking_expiry_hours' => MAP_PUMP_BOOKING_EXPIRY_HOURS,
            'quantity_rule' => 'Driver reserves fixed liters up to the selected vehicle quota; accepted partial fills are recorded at actual liters.',
            'stock_rule' => 'Active reservations reduce customer-facing available stock. Completed fills permanently reduce physical tank stock.',
            'company_isolation' => 'Each HQ company only sees and changes its own stations, staff, and inventory.',
        ];
    }
}
