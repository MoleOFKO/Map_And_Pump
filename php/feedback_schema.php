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
            'staff_read_at' => "ALTER TABLE feedback ADD COLUMN staff_read_at datetime DEFAULT NULL AFTER created_at",
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

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS feedback_replies (
                reply_id int NOT NULL AUTO_INCREMENT,
                feedback_id int NOT NULL,
                staff_id int NOT NULL,
                reply_text text NOT NULL,
                customer_read_at datetime DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (reply_id),
                KEY feedback_replies_feedback_idx (feedback_id),
                KEY feedback_replies_staff_idx (staff_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $replyReadColumn = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'feedback_replies'
               AND COLUMN_NAME = 'customer_read_at'"
        );
        $replyReadColumn->execute();
        if ((int)$replyReadColumn->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE feedback_replies ADD COLUMN customer_read_at datetime DEFAULT NULL AFTER reply_text");
        }

        return true;
    } catch (Throwable $e) {
        error_log('Could not prepare feedback schema: ' . $e->getMessage());
        return false;
    }
}
