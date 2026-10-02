<?php
/**
 * Daily license-expiry enforcement for HQ owners and customer users.
 *
 * Run via cron, e.g.:
 *   15 2 * * * /usr/bin/php /path/to/php/expiry_cron.php >> /var/log/map_pump_expiry.log 2>&1
 *
 * Safe to run multiple times: only active/approved accounts past deadline are suspended once.
 */

declare(strict_types=1);

// CLI only (optional HTTP key for hosted cron).
$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
$httpKey = getenv('MAP_PUMP_EXPIRY_CRON_KEY') ?: '';
$providedKey = (string) ($_GET['key'] ?? '');

if (!$isCli) {
    if ($httpKey === '' || !hash_equals($httpKey, $providedKey)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden\n";
        exit(1);
    }
}

require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/Admin(WebOwner)/hq_account_common.php';
require_once __DIR__ . '/Admin(WebOwner)/user_account_common.php';
require_once __DIR__ . '/Admin(WebOwner)/admin_common.php';
require_once __DIR__ . '/expiry_alerts.php';

$started = date('c');
$sync = expiry_sync_all();
expiry_clear_payload_cache();

$hqCount = count($sync['hq'] ?? []);
$userCount = count($sync['users'] ?? []);

$lines = [
    "[{$started}] Map & Pump expiry cron",
    "  HQ owners suspended: {$hqCount}",
    "  Users suspended: {$userCount}",
];

foreach ($sync['hq'] ?? [] as $row) {
    $lines[] = '  - HQ #' . (int) ($row['headquarter_id'] ?? 0)
        . ' ' . (string) ($row['company_name'] ?? '')
        . ' expired ' . (string) ($row['expire_at'] ?? '');
}
foreach ($sync['users'] ?? [] as $row) {
    $lines[] = '  - User #' . (int) ($row['user_id'] ?? 0)
        . ' ' . (string) ($row['user_name'] ?? '')
        . ' expired ' . (string) ($row['expire_at'] ?? '');
}

$out = implode("\n", $lines) . "\n";
if ($isCli) {
    echo $out;
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $out;
}

exit(0);
