<?php

function map_pump_usage_history_schema_ready(PDO $pdo): bool
{
    static $ready = false;
    if ($ready) {
        return true;
    }

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS usage_history (
                usage_id int NOT NULL AUTO_INCREMENT,
                booking_id int NOT NULL,
                user_id int NOT NULL,
                car_license_id int DEFAULT NULL,
                station_id int NOT NULL,
                oil_type_id int NOT NULL,
                qr_code varchar(255) NOT NULL,
                qr_refrence_code varchar(255) NOT NULL,
                filled_liters decimal(10,2) NOT NULL DEFAULT 0,
                price_per_litre decimal(12,2) DEFAULT NULL,
                total_cost decimal(12,2) DEFAULT NULL,
                status varchar(50) NOT NULL DEFAULT 'filled',
                booked_at timestamp NULL DEFAULT NULL,
                expire_at timestamp NULL DEFAULT NULL,
                filled_at datetime NOT NULL,
                staff_user_id int DEFAULT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (usage_id),
                UNIQUE KEY uq_usage_history_booking (booking_id),
                KEY idx_usage_history_user_filled (user_id, filled_at),
                KEY idx_usage_history_station (station_id),
                KEY idx_usage_history_car (car_license_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $staffColumn = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'usage_history'
               AND COLUMN_NAME = 'staff_user_id'"
        );
        $staffColumn->execute();
        if ((int)$staffColumn->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE usage_history ADD COLUMN staff_user_id int DEFAULT NULL AFTER filled_at");
        }

        $ready = true;
        return true;
    } catch (Throwable $e) {
        error_log('Could not prepare usage_history table: ' . $e->getMessage());
        return false;
    }
}