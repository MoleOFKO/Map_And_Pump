<?php
/**
 * MAP & PUMP billing rules and payment recipients.
 *
 * Station registration and monthly service-package prices are administered in
 * platform_settings. HQ service extensions are payable in MMK only.
 */

if (!defined('BILLING_HQ_REGISTRATION_FEE_MMK')) {
    define('BILLING_HQ_REGISTRATION_FEE_MMK', 2999999);
}
if (!defined('BILLING_HQ_INCLUDED_STATION_SLOTS')) {
    define('BILLING_HQ_INCLUDED_STATION_SLOTS', 3);
}
if (!defined('BILLING_HQ_PACKAGE_MMK_PER_STATION_MONTHLY')) {
    define('BILLING_HQ_PACKAGE_MMK_PER_STATION_MONTHLY', 37000);
}
if (!defined('BILLING_HQ_EXTENSION_MAX_MONTHS')) {
    define('BILLING_HQ_EXTENSION_MAX_MONTHS', 120);
}
if (!defined('BILLING_HQ_EXTENSION_TERM_DISCOUNTS')) {
    define('BILLING_HQ_EXTENSION_TERM_DISCOUNTS', [
        ['min_months' => 60, 'label' => '60+ months', 'percent' => 15],
        ['min_months' => 12, 'label' => '12+ months', 'percent' => 2],
        ['min_months' => 1, 'label' => 'Under 12 months', 'percent' => 0],
    ]);
}
function billing_admin_setting(PDO $pdo, string $key, int $default): int
{
    $stmt = $pdo->prepare('SELECT setting_value FROM platform_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return $default;
    }

    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false || $parsed < 0) {
        throw new RuntimeException('The configured billing amount is invalid.');
    }

    return $parsed;
}

if (!defined('BILLING_HQ_VOLUME_DISCOUNTS')) {
    define('BILLING_HQ_VOLUME_DISCOUNTS', [
        ['label' => '1–7 paid', 'min' => 1, 'percent' => 0],
        ['label' => '8–22 paid', 'min' => 8, 'percent' => 10],
        ['label' => '23+ paid', 'min' => 23, 'percent' => 20],
    ]);
}
if (!defined('BILLING_HQ_TERM_DISCOUNTS')) {
    define('BILLING_HQ_TERM_DISCOUNTS', [
        ['months' => 1, 'label' => 'Monthly', 'percent' => 0],
        ['months' => 3, 'label' => '3 months', 'percent' => 5],
        ['months' => 6, 'label' => '6 months', 'percent' => 10],
        ['months' => 12, 'label' => '12 months', 'percent' => 20],
    ]);
}

if (!defined('BILLING_PLATFORM_RECIPIENT_NAME')) {
    define('BILLING_PLATFORM_RECIPIENT_NAME', 'MAP & PUMP Co., Ltd');
}
if (!defined('BILLING_PLATFORM_RECEIVER_ROLE')) {
    define('BILLING_PLATFORM_RECEIVER_ROLE', 'admin');
}
if (!defined('BILLING_CUSTOMER_FUEL_RECEIVER_ROLE')) {
    define('BILLING_CUSTOMER_FUEL_RECEIVER_ROLE', 'station_hq');
}
if (!defined('BILLING_PAYMENT_DEFAULT_PHONE')) {
    define('BILLING_PAYMENT_DEFAULT_PHONE', '09 795 123 456');
}

/** Existing payment destinations displayed on the public HQ registration form. */
function billing_payment_receiving_accounts(): array
{
    $phone = BILLING_PAYMENT_DEFAULT_PHONE;

    return [
        'KPay' => ['label' => 'KPay phone number', 'number' => $phone],
        'Wave Pay' => ['label' => 'Wave Pay phone number', 'number' => $phone],
        'AYA Pay' => ['label' => 'AYA Pay phone number', 'number' => $phone],
        'CB Pay' => ['label' => 'CB Pay phone number', 'number' => $phone],
        'AYA Bank' => ['label' => 'AYA Bank account number', 'number' => '0012 3456 7890 1234', 'phone' => $phone],
        'CB Bank' => ['label' => 'CB Bank account number', 'number' => '0022 3456 7890 1234', 'phone' => $phone],
        'KBZ Bank' => ['label' => 'KBZ Bank account number', 'number' => '0032 3456 7890 1234', 'phone' => $phone],
        'YOMA Bank' => ['label' => 'YOMA Bank account number', 'number' => '0042 3456 7890 1234', 'phone' => $phone],
    ];
}
