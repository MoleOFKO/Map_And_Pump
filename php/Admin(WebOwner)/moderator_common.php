<?php
/**
 * Shared helpers for the admin moderator registry.
 */
require_once __DIR__ . '/hq_account_common.php';

function moderator_flash(string $type, string $message, array $context = []): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['moderator_registry_flash'] = [
        'type' => $type,
        'message' => $message,
        'context' => $context,
    ];
}

function moderator_pull_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $flash = $_SESSION['moderator_registry_flash'] ?? null;
    unset($_SESSION['moderator_registry_flash']);

    return is_array($flash) ? $flash : null;
}

function moderator_redirect(string $location = 'manage_moderators.php'): never
{
    header('Location: ' . $location);
    exit;
}

function moderator_schema_ready(): bool
{
    global $conn;

    if (!hq_db_available()) {
        return false;
    }

    $requiredColumns = [
        'roles' => ['role_id', 'role_name'],
        'users' => [
            'user_id', 'user_name', 'email', 'hashed_password', 'role_id',
            'headquarter_id', 'status', 'created_at', 'updated_at',
        ],
    ];
    $tableNames = array_keys($requiredColumns);
    $tableSql = "'" . implode("','", $tableNames) . "'";
    $result = $conn->query(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($tableSql)"
    );

    if (!$result) {
        error_log('[Moderator registry] Schema check failed: ' . $conn->error);
        return false;
    }

    $available = [];
    while ($column = $result->fetch_assoc()) {
        $available[$column['TABLE_NAME']][$column['COLUMN_NAME']] = true;
    }

    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            if (empty($available[$table][$column])) {
                error_log(sprintf('[Moderator registry] Required schema column is missing: %s.%s', $table, $column));
                return false;
            }
        }
    }

    return true;
}

/**
 * Only system-level roles are available in this screen. HQ, manager, staff,
 * and customer roles belong to other account areas.
 */
function moderator_role_options(): array
{
    global $conn;

    if (!moderator_schema_ready()) {
        return [];
    }

    $statement = $conn->prepare(
        "SELECT role_id, role_name
         FROM roles
         WHERE LOWER(TRIM(role_name)) IN ('admin', 'moderator')
         ORDER BY CASE LOWER(TRIM(role_name)) WHEN 'moderator' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END, role_name"
    );
    if (!$statement || !$statement->execute()) {
        $statement?->close();
        return [];
    }

    $result = $statement->get_result();
    $roles = [];
    while ($result && ($role = $result->fetch_assoc())) {
        $roles[] = [
            'role_id' => (int) $role['role_id'],
            'role_name' => (string) $role['role_name'],
        ];
    }
    $statement->close();

    return $roles;
}

function moderator_role_is_allowed(int $roleId): bool
{
    foreach (moderator_role_options() as $role) {
        if ((int) $role['role_id'] === $roleId) {
            return true;
        }
    }

    return false;
}

function moderator_role_label(?string $roleName): string
{
    $label = trim((string) $roleName);
    return $label !== '' ? ucwords($label) : 'Unassigned';
}

function moderator_role_class(?string $roleName): string
{
    return strtolower(trim((string) $roleName)) === 'admin' ? 'moderator-role-admin' : 'moderator-role-standard';
}

function moderator_status_label(?string $status): string
{
    return match (strtolower(trim((string) $status))) {
        'approved', 'active' => 'Active',
        'pending', 'pending_verification' => 'Pending',
        'suspended' => 'Suspended',
        'removed' => 'Revoked',
        'rejected' => 'Rejected',
        default => $status !== null && trim($status) !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Unknown',
    };
}

function moderator_status_class(?string $status): string
{
    return match (strtolower(trim((string) $status))) {
        'approved', 'active' => 'moderator-status-active',
        'suspended' => 'moderator-status-suspended',
        'removed', 'rejected' => 'moderator-status-removed',
        default => 'moderator-status-pending',
    };
}
