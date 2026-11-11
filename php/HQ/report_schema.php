<?php

function hq_report_ensure_scope_schema(PDO $pdo): void
{
    $readColumns = static function () use ($pdo): array {
        $columns = $pdo->query(
            "SELECT COLUMN_NAME, IS_NULLABLE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'report'
               AND COLUMN_NAME IN ('user_id', 'headquarter_id', 'status')"
        );
        return $columns->fetchAll(PDO::FETCH_KEY_PAIR);
    };

    $columns = $readColumns();
    if (!isset($columns['user_id'])) {
        throw new RuntimeException('The report table is missing its user ownership column.');
    }

    if (!isset($columns['headquarter_id'])) {
        try {
            $pdo->exec('ALTER TABLE report ADD COLUMN headquarter_id INT NULL');
        } catch (PDOException $e) {
            if (!isset($readColumns()['headquarter_id'])) {
                throw $e;
            }
        }
    }

    if (!isset($columns['status'])) {
        try {
            $pdo->exec("ALTER TABLE report ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'unread'");
        } catch (PDOException $e) {
            if (!isset($readColumns()['status'])) {
                throw $e;
            }
        }
    }

    if (strtoupper((string) $columns['user_id']) !== 'YES') {
        try {
            $pdo->exec('ALTER TABLE report MODIFY user_id INT NULL');
        } catch (PDOException $e) {
            if (strtoupper((string) ($readColumns()['user_id'] ?? 'NO')) !== 'YES') {
                throw $e;
            }
        }
    }
}
