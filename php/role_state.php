<?php

function map_pump_current_role_column_ready(PDO $pdo): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'current_role_id'"
        );
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            return $ready = true;
        }

        $pdo->exec('ALTER TABLE users ADD COLUMN current_role_id int NULL AFTER business_role_id');
        return $ready = true;
    } catch (Throwable $e) {
        error_log('Could not prepare users.current_role_id column: ' . $e->getMessage());
        return $ready = false;
    }
}

function map_pump_set_current_role(PDO $pdo, int $userId, int $roleId): void
{
    if ($userId <= 0 || $roleId <= 0 || !map_pump_current_role_column_ready($pdo)) {
        return;
    }

    try {
        $stmt = $pdo->prepare('UPDATE users SET current_role_id = ?, updated_at = NOW() WHERE user_id = ?');
        $stmt->execute([$roleId, $userId]);
    } catch (Throwable $e) {
        error_log('Could not update users.current_role_id: ' . $e->getMessage());
    }
}

function map_pump_session_role(array $user): array
{
    $baseRoleId = (int) ($user['role_id'] ?? 0);
    $businessRoleId = (int) ($user['business_role_id'] ?? 0);
    $currentRoleId = (int) ($user['current_role_id'] ?? 0);

    $baseRoleName = (string) ($user['role_name'] ?? 'customer');
    $businessRoleName = (string) ($user['business_role_name'] ?? '');
    $currentRoleName = (string) ($user['current_role_name'] ?? '');
    $currentRoleKey = strtolower(trim($currentRoleName));

    if (
        $currentRoleId <= 0
        || ($currentRoleId !== $baseRoleId && $currentRoleId !== $businessRoleId && $currentRoleKey !== 'customer')
    ) {
        $currentRoleId = $baseRoleId;
    }

    if ($currentRoleId === $businessRoleId && $businessRoleName !== '') {
        $currentRoleName = $businessRoleName;
    } elseif ($currentRoleId === $baseRoleId || $currentRoleName === '') {
        $currentRoleName = $baseRoleName;
    }

    return [$currentRoleId, $currentRoleName !== '' ? $currentRoleName : 'customer'];
}