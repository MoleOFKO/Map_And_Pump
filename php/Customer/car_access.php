<?php

require_once __DIR__ . '/../booking_rules.php';

function customer_car_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS user_car (
                user_car_id int NOT NULL AUTO_INCREMENT,
                user_id int NOT NULL,
                car_id int NOT NULL,
                is_active tinyint(1) NOT NULL DEFAULT 0,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_car_id),
                KEY fk_user_car_user_id_idx (user_id),
                KEY fk_user_car_car_id_idx (car_id),
                UNIQUE KEY uq_user_car_user_car (user_id, car_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $columns = [
            'is_active' => "ALTER TABLE user_car ADD COLUMN is_active tinyint(1) NOT NULL DEFAULT 0 AFTER car_id",
            'created_at' => "ALTER TABLE user_car ADD COLUMN created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active",
            'updated_at' => "ALTER TABLE user_car ADD COLUMN updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
        ];

        foreach ($columns as $column => $sql) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'user_car'
                   AND COLUMN_NAME = ?"
            );
            $stmt->execute([$column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        }

        return true;
    } catch (Throwable $e) {
        error_log('Could not prepare user_car schema: ' . $e->getMessage());
        return false;
    }
}

function customer_active_car_id(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !customer_car_schema_ready($pdo)) {
        return 0;
    }

    $stmt = $pdo->prepare(
        'SELECT uc.car_id
         FROM user_car uc
         INNER JOIN car_license c ON c.car_license_id = uc.car_id
         WHERE uc.user_id = ?
         ORDER BY uc.is_active DESC, c.updated_at DESC, c.car_license_id DESC
         LIMIT 1'
    );
    $stmt->execute([$userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

function customer_set_active_car(PDO $pdo, int $userId, int $carId): bool
{
    if ($userId <= 0 || $carId <= 0 || !customer_car_schema_ready($pdo)) {
        return false;
    }

    $owned = $pdo->prepare('SELECT user_car_id FROM user_car WHERE user_id = ? AND car_id = ? LIMIT 1');
    $owned->execute([$userId, $carId]);
    if (!$owned->fetchColumn()) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $clear = $pdo->prepare('UPDATE user_car SET is_active = 0, updated_at = NOW() WHERE user_id = ?');
        $clear->execute([$userId]);

        $set = $pdo->prepare('UPDATE user_car SET is_active = 1, updated_at = NOW() WHERE user_id = ? AND car_id = ?');
        $set->execute([$userId, $carId]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function customer_fuel_quota(PDO $pdo, int $userId): array
{
    $limit = MAP_PUMP_FUEL_QUOTA_LITERS;
    $quota = [
        'limit' => $limit,
        'used' => 0.0,
        'remaining' => $limit,
        'active_car_id' => 0,
        'has_active_car' => false,
    ];

    if ($userId <= 0 || !customer_car_schema_ready($pdo)) {
        return $quota;
    }

    $carStmt = $pdo->prepare(
        'SELECT uc.car_id AS car_license_id, c.filled_gas, c.updated_at
         FROM user_car uc
         INNER JOIN car_license c ON c.car_license_id = uc.car_id
         WHERE uc.user_id = ?
         ORDER BY uc.is_active DESC, c.updated_at DESC, c.car_license_id DESC
         LIMIT 1'
    );
    $carStmt->execute([$userId]);
    $car = $carStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $activeCarId = (int) ($car['car_license_id'] ?? 0);
    if ($activeCarId <= 0) {
        return $quota;
    }

    return customer_fuel_quota_for_car($pdo, $userId, $activeCarId);
}

function customer_fuel_quota_for_car(PDO $pdo, int $userId, int $carId): array
{
    $limit = MAP_PUMP_FUEL_QUOTA_LITERS;
    $intervalSql = map_pump_fuel_quota_interval_sql();
    $quota = [
        'limit' => $limit,
        'used' => 0.0,
        'remaining' => $limit,
        'active_car_id' => 0,
        'has_active_car' => false,
    ];

    if ($userId <= 0 || $carId <= 0) {
        return $quota;
    }

    $carStmt = $pdo->prepare('SELECT filled_gas, updated_at FROM car_license WHERE car_license_id = ? LIMIT 1');
    $carStmt->execute([$carId]);
    $car = $carStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$car) {
        return $quota;
    }

    $quota['active_car_id'] = $carId;
    $quota['has_active_car'] = true;
    $used = 0.0;
    $historyReady = false;

    try {
        require_once __DIR__ . '/../usage_history_schema.php';
        $historyReady = function_exists('map_pump_usage_history_schema_ready') && map_pump_usage_history_schema_ready($pdo);
    } catch (Throwable $e) {
        $historyReady = false;
    }

    if ($historyReady) {
        $usageStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(filled_liters), 0)
             FROM usage_history
             WHERE user_id = ?
               AND car_license_id = ?
               AND filled_at >= DATE_SUB(NOW(), {$intervalSql})"
        );
        $usageStmt->execute([$userId, $carId]);
        $used = (float)$usageStmt->fetchColumn();
    }

    if ($used <= 0.0) {
        $updatedAt = (string)($car['updated_at'] ?? '');
        $resetDays = MAP_PUMP_FUEL_QUOTA_PERIOD === 'monthly' ? '-30 days' : '-7 days';
        if ($updatedAt !== '' && strtotime($updatedAt) < strtotime($resetDays)) {
            $resetStmt = $pdo->prepare('UPDATE car_license SET filled_gas = 0, updated_at = NOW() WHERE car_license_id = ?');
            $resetStmt->execute([$carId]);
            $used = 0.0;
        } else {
            $used = (float)($car['filled_gas'] ?? 0.0);
        }
    }

    $used = max(0.0, min($limit, $used));
    $quota['used'] = $used;
    $quota['remaining'] = max(0.0, $limit - $used);

    return $quota;
}
function customer_refresh_session(PDO $pdo, int $userId, ?array $licenseAccess = null): array
{
    if ($licenseAccess === null) {
        $licenseAccess = customer_license_access($pdo, $userId);
    }
    $quota = customer_fuel_quota($pdo, $userId);

    $_SESSION['license_id'] = (int) ($licenseAccess['license_id'] ?? 0);
    $_SESSION['license_status'] = (string) ($licenseAccess['status'] ?? 'pending');
    $_SESSION['car_license_id'] = (int) ($quota['active_car_id'] ?? 0);
    $_SESSION['oil_limit'] = (float) ($quota['limit'] ?? MAP_PUMP_FUEL_QUOTA_LITERS);
    $_SESSION['oil_used'] = (float) ($quota['used'] ?? 0.0);
    $_SESSION['oil_remaining'] = (float) ($quota['remaining'] ?? max(0.0, $_SESSION['oil_limit'] - $_SESSION['oil_used']));

    return ['license' => $licenseAccess, 'quota' => $quota];
}

