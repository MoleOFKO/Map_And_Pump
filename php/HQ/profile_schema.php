<?php

function hq_profile_ensure_avatar_schema(PDO $pdo): void
{
    $readColumn = static function () use ($pdo): int {
        $stmt = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'headquarter'
               AND COLUMN_NAME = 'profile_avatar_url'"
        );
        return (int) $stmt->fetchColumn();
    };

    if ($readColumn() > 0) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE headquarter ADD COLUMN profile_avatar_url VARCHAR(500) NULL');
    } catch (PDOException $exception) {
        if ($readColumn() === 0) {
            throw $exception;
        }
    }
}