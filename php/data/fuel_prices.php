<?php
/* ==========================================================================
   MAP & PUMP — public fuel price and news data
   Loaded by HomePage.php and News.php.

   All figures come from branch-manager price/stock updates in the database.
   When no manager-approved data exists yet, pages show empty states instead
   of sample numbers.
   ========================================================================== */

require_once __DIR__ . '/public_market_data.php';

$marketData = map_pump_build_public_market_data();

$PRICE_UPDATED_AT = (string) ($marketData['updated_at'] ?? 'Awaiting manager price updates');
$FUEL_GRADES = $marketData['fuel_grades'] ?? [];
$STATION_FUEL_TYPES = $marketData['station_fuel_types'] ?? [];
$STATION_FUEL_PRICES = $marketData['station_fuel_prices'] ?? [];
$COMPANY_PRICES = $marketData['company_prices'] ?? [];
$REGION_PRICES = $marketData['region_prices'] ?? [];
$NEWS_POSTS = $marketData['news_posts'] ?? [];
$LIVE_TICKER_ITEMS = $marketData['live_ticker'] ?? [];
$PLATFORM_STATS = $marketData['platform_stats'] ?? ['stations' => 0, 'drivers' => 0];
$PUBLIC_MARKET_DATA_SOURCE = (string) ($marketData['source'] ?? 'empty');

if (!function_exists('mp_money')) {
    function mp_money($n)
    {
        return $n === null ? '—' : number_format((float) $n);
    }
}

if (!function_exists('mp_trend')) {
    function mp_trend($now, $prev)
    {
        if ($now === null || $prev === null) {
            return ['dir' => 'flat', 'class' => 'trend-flat', 'icon' => 'fa-minus', 'delta' => '—'];
        }
        $diff = (float) $now - (float) $prev;
        if (abs($diff) < 0.01) {
            return ['dir' => 'flat', 'class' => 'trend-flat', 'icon' => 'fa-minus', 'delta' => 'No change'];
        }
        if ($diff > 0) {
            return [
                'dir' => 'up',
                'class' => 'trend-up',
                'icon' => 'fa-arrow-trend-up',
                'delta' => '+' . mp_money(round($diff)) . ' MMK',
            ];
        }

        return [
            'dir' => 'down',
            'class' => 'trend-down',
            'icon' => 'fa-arrow-trend-down',
            'delta' => '−' . mp_money(round(abs($diff))) . ' MMK',
        ];
    }
}

if (!function_exists('mp_tag_label')) {
    function mp_tag_label($tag)
    {
        $map = [
            'price' => 'Price update',
            'supply' => 'Supply',
            'policy' => 'Policy',
            'station' => 'Stations',
        ];

        return $map[$tag] ?? ucfirst((string) $tag);
    }
}
