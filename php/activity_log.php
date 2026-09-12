<?php

/**
 * Write a user action to the shared audit log without making a completed
 * business operation fail when audit storage is temporarily unavailable.
 */
if (!function_exists('map_pump_log_action')) {
    function map_pump_log_action(PDO $pdo, int $userId, string $action, ?int $actingRoleId = null): void
    {
        if ($userId <= 0 || trim($action) === '') {
            return;
        }

        try {
            static $hasCurrentRoleColumn = null;
            if ($hasCurrentRoleColumn === null) {
                $columnCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'current_role_id'");
                $hasCurrentRoleColumn = $columnCheck && (bool) $columnCheck->fetchColumn();
            }

            $roleId = (int) $actingRoleId;
            if ($roleId <= 0) {
                $roleColumn = $hasCurrentRoleColumn
                    ? 'COALESCE(current_role_id, business_role_id, role_id)'
                    : 'COALESCE(business_role_id, role_id)';
                $roleStmt = $pdo->prepare("SELECT {$roleColumn} FROM users WHERE user_id = ? LIMIT 1");
                $roleStmt->execute([$userId]);
                $roleId = (int) ($roleStmt->fetchColumn() ?: 0);
            }

            if ($roleId <= 0) {
                return;
            }

            $action = function_exists('mb_substr')
                ? mb_substr(trim($action), 0, 255)
                : substr(trim($action), 0, 255);
            $insert = $pdo->prepare('INSERT INTO log (user_id, role_id, action) VALUES (?, ?, ?)');
            $insert->execute([$userId, $roleId, $action]);
        } catch (Throwable $e) {
            error_log('Unable to write audit log entry: ' . $e->getMessage());
        }
    }
}
