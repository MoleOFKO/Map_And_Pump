<?php

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
