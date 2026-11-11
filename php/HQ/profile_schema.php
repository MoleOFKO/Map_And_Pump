<?php

function hq_profile_ensure_avatar_schema(PDO $pdo, bool $hqAuth = true): void
{
    static $readyTables = [];
    $table = $hqAuth ? 'headquarter' : 'users';
    if (!empty($readyTables[$table])) {
        return;
    }

    $columns = [
        'profile_avatar_data' => 'MEDIUMBLOB NULL',
        'profile_avatar_mime' => 'VARCHAR(50) NULL',
    ];
    if ($hqAuth) {
        $columns = ['profile_avatar_url' => 'VARCHAR(500) NULL'] + $columns;
    }

    foreach ($columns as $column => $definition) {
        $readColumn = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $readColumn->execute([$table, $column]);
        if ((int) $readColumn->fetchColumn() > 0) {
            continue;
        }

        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (PDOException $exception) {
            $readColumn->execute([$table, $column]);
            if ((int) $readColumn->fetchColumn() === 0) {
                throw $exception;
            }
        }
    }

    $readyTables[$table] = true;
}