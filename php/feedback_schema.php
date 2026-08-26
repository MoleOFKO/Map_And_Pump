<?php

function map_pump_feedback_schema_ready(PDO $pdo): bool
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS feedback (
                feedback_id int NOT NULL AUTO_INCREMENT,
                user_id int NOT NULL,
                feedback text NOT NULL,
                created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (feedback_id),
                KEY fk_feedback_user_id_idx (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $columns = [
            'usage_id' => "ALTER TABLE feedback ADD COLUMN usage_id int DEFAULT NULL AFTER user_id",
            'station_id' => "ALTER TABLE feedback ADD COLUMN station_id int DEFAULT NULL AFTER usage_id",
            'rating' => "ALTER TABLE feedback ADD COLUMN rating tinyint DEFAULT NULL AFTER station_id",
        ];

        foreach ($columns as $column => $sql) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'feedback'
                   AND COLUMN_NAME = ?"
            );
            $stmt->execute([$column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec($sql);
            }
        }

        return true;
    } catch (Throwable $e) {
        error_log('Could not prepare feedback schema: ' . $e->getMessage());
        return false;
    }
}
