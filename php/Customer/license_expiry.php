<?php

// Align with Admin MAP_PUMP_EXPIRY_WARNING_DAYS when that constant is loaded first.
if (!defined('MAP_PUMP_LICENSE_WARNING_DAYS')) {
    define('MAP_PUMP_LICENSE_WARNING_DAYS', defined('MAP_PUMP_EXPIRY_WARNING_DAYS') ? (int) MAP_PUMP_EXPIRY_WARNING_DAYS : 10);
}

function customer_license_expiry_schema_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $columns = [
            'user_license.status' => "ALTER TABLE user_license ADD COLUMN status varchar(20) NOT NULL DEFAULT 'pending' AFTER license_last_valid_date",
            'car_license.license_expire_date' => "ALTER TABLE car_license ADD COLUMN license_expire_date date NULL AFTER issue_date",
            'car_license.status' => "ALTER TABLE car_license ADD COLUMN status varchar(20) NOT NULL DEFAULT 'pending' AFTER license_expire_date",
        ];
        foreach ($columns as $columnKey => $alterSql) {
            [$table, $column] = explode('.', $columnKey, 2);
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $check->execute([$table, $column]);
            if ((int) $check->fetchColumn() === 0) {
                $pdo->exec($alterSql);
            }
        }
        return $ready = true;
    } catch (Throwable $e) {
        error_log('License expiry schema check failed: ' . $e->getMessage());
        return $ready = false;
    }
}

function customer_sync_license_expiry(PDO $pdo, int $userId): array
{
    $result = ['driver' => null, 'cars' => []];
    if ($userId <= 0 || !customer_license_expiry_schema_ready($pdo)) {
        return $result;
    }

    $today = new DateTimeImmutable('today');
    $driverStmt = $pdo->prepare(
        'SELECT u.license_id, u.status AS user_status, l.status, l.license_last_valid_date
         FROM users u LEFT JOIN user_license l ON l.license_id = u.license_id
         WHERE u.user_id = ? LIMIT 1'
    );
    $driverStmt->execute([$userId]);
    $driver = $driverStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($driver['license_id']) && !empty($driver['license_last_valid_date'])) {
        $expiry = new DateTimeImmutable((string) $driver['license_last_valid_date']);
        $status = strtolower(trim((string) ($driver['status'] ?? 'pending')));
        if ($expiry < $today) {
            if ($status !== 'expired') {
                $pdo->prepare("UPDATE user_license SET status = 'expired', updated_at = NOW() WHERE license_id = ?")->execute([(int) $driver['license_id']]);
                $status = 'expired';
            }
            $pdo->prepare("UPDATE users SET status = 'license_expired', updated_at = NOW() WHERE user_id = ? AND status IN ('active', 'approved', 'enabled', 'ready')")->execute([$userId]);
            $pdo->prepare("UPDATE booking SET status = 'cancelled' WHERE user_id = ? AND status IN ('notfilled', 'serving')")->execute([$userId]);
        }
        $result['driver'] = [
            'status' => $status,
            'expiry_date' => $expiry->format('Y-m-d'),
            'days_left' => (int) $today->diff($expiry)->format('%r%a'),
        ];
    }

    $carStmt = $pdo->prepare(
        'SELECT c.car_license_id, c.car_plate_number, c.status, c.license_expire_date
         FROM user_car uc INNER JOIN car_license c ON c.car_license_id = uc.car_id
         WHERE uc.user_id = ? AND c.license_expire_date IS NOT NULL'
    );
    $carStmt->execute([$userId]);
    foreach ($carStmt->fetchAll(PDO::FETCH_ASSOC) as $car) {
        $expiry = new DateTimeImmutable((string) $car['license_expire_date']);
        $status = strtolower(trim((string) ($car['status'] ?? 'pending')));
        if ($expiry < $today && $status !== 'expired') {
            $pdo->prepare("UPDATE car_license SET status = 'expired', updated_at = NOW() WHERE car_license_id = ?")->execute([(int) $car['car_license_id']]);
            $status = 'expired';
        }
        $result['cars'][] = [
            'car_id' => (int) $car['car_license_id'],
            'plate' => (string) $car['car_plate_number'],
            'status' => $status,
            'expiry_date' => $expiry->format('Y-m-d'),
            'days_left' => (int) $today->diff($expiry)->format('%r%a'),
        ];
    }

    return $result;
}

function customer_license_expiry_warnings(array $expiryState): array
{
    $warnings = [];
    if (!empty($expiryState['driver']) && (int) $expiryState['driver']['days_left'] >= 0 && (int) $expiryState['driver']['days_left'] <= MAP_PUMP_LICENSE_WARNING_DAYS) {
        $warnings[] = ['type' => 'driver', 'label' => 'Driver license', 'days_left' => (int) $expiryState['driver']['days_left'], 'url' => 'license.php'];
    }
    foreach ($expiryState['cars'] ?? [] as $car) {
        if ((int) $car['days_left'] >= 0 && (int) $car['days_left'] <= MAP_PUMP_LICENSE_WARNING_DAYS) {
            $warnings[] = ['type' => 'car', 'label' => 'Car license ' . $car['plate'], 'days_left' => (int) $car['days_left'], 'url' => 'car_license.php?update=1&car_id=' . (int) $car['car_id']];
        }
    }
    return $warnings;
}
