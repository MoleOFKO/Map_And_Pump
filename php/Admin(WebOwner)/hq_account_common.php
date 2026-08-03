<?php
/**
 * Shared helpers for the admin HQ/account registry.
 */
require_once __DIR__ . '/../conn.php';

function hq_db_available(): bool
{
    global $conn;

    return $conn instanceof mysqli && !$conn->connect_errno;
}

function hq_db_error_message(): string
{
    global $conn;

    if (!$conn instanceof mysqli || $conn->connect_errno) {
        return 'The HQ registry is temporarily unavailable because the database connection could not be established.';
    }

    return 'The requested HQ operation could not be completed.';
}

function hq_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function hq_flash(string $type, string $message, array $context = []): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['hq_registry_flash'] = [
        'type' => $type,
        'message' => $message,
        'context' => $context,
    ];
}

function hq_pull_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $flash = $_SESSION['hq_registry_flash'] ?? null;
    unset($_SESSION['hq_registry_flash']);

    return is_array($flash) ? $flash : null;
}

function hq_redirect(string $location = 'view_hq_and_user_account.php'): never
{
    header('Location: ' . $location);
    exit;
}

function hq_status_label(?string $status): string
{
    return match (strtolower(trim((string) $status))) {
        'active' => 'Active',
        'suspended' => 'Suspended',
        'removed' => 'Removed',
        'pending', 'pending_verification' => 'Pending Verification',
        default => $status !== null && trim($status) !== '' ? ucwords(str_replace('_', ' ', $status)) : 'Pending Verification',
    };
}

function hq_status_class(?string $status): string
{
    return match (strtolower(trim((string) $status))) {
        'active' => 'hq-status-active',
        'suspended' => 'hq-status-suspended',
        'removed' => 'hq-status-removed',
        default => 'hq-status-pending',
    };
}

function hq_date(?string $value): string
{
    if (!$value) {
        return 'Not available';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y · g:i A', $timestamp) : $value;
}

function hq_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = '';

    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
    }

    return $initials ?: 'HQ';
}

function hq_bind(mysqli_stmt $statement, string $types, array &$params): bool
{
    if ($types === '') {
        return true;
    }

    $bind = [$types];
    foreach ($params as $key => &$param) {
        $bind[] = &$param;
    }

    return call_user_func_array([$statement, 'bind_param'], $bind);
}

function hq_prepare(string $sql, string $types = '', array $params = []): mysqli_stmt|false
{
    global $conn;

    if (!hq_db_available()) {
        return false;
    }

    $statement = $conn->prepare($sql);
    if (!$statement) {
        return false;
    }

    if ($types !== '') {
        $boundParams = array_values($params);
        if (!hq_bind($statement, $types, $boundParams)) {
            $statement->close();
            return false;
        }
    }

    return $statement;
}

/**
 * The repository contains the ER diagram but no executable migration. These
 * idempotent DDL statements make the HQ registry usable on a fresh database
 * while leaving existing tables and rows untouched.
 */
function hq_registry_schema_ready(): bool
{
    global $conn;

    if (!hq_db_available()) {
        return false;
    }

    $queries = [
        "CREATE TABLE IF NOT EXISTS roles (
            role_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            role_name VARCHAR(100) NOT NULL,
            PRIMARY KEY (role_id),
            KEY idx_roles_name (role_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS stations (
            station_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            station_name VARCHAR(255) NOT NULL,
            location VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS headquarter (
            headquarter_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            role_id INT UNSIGNED NULL,
            station_id INT UNSIGNED NULL,
            company_name VARCHAR(255) NOT NULL,
            company_logo VARCHAR(255) NULL,
            contact_number VARCHAR(50) NULL,
            location VARCHAR(255) NULL,
            business_license VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expire_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (headquarter_id),
            KEY idx_headquarter_company (company_name),
            KEY idx_headquarter_station (station_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS users (
            user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            hashed_password VARCHAR(255) NOT NULL,
            license_id INT UNSIGNED NULL,
            headquarter_id INT UNSIGNED NULL,
            role_id INT UNSIGNED NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id),
            UNIQUE KEY uq_users_email (email),
            KEY idx_users_hq (headquarter_id),
            KEY idx_users_role (role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $query) {
        if (!$conn->query($query)) {
            error_log('[HQ registry] Schema bootstrap failed: ' . $conn->error);
            return false;
        }
    }

    return true;
}

function hq_action_table_ready(): bool
{
    global $conn;

    if (!hq_db_available()) {
        return false;
    }

    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS hq_account_actions (
    action_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    headquarter_id INT NOT NULL,
    user_id INT NULL,
    action_type VARCHAR(20) NOT NULL,
    reason TEXT NOT NULL,
    notification_status VARCHAR(30) NOT NULL DEFAULT 'not_configured',
    notification_note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (action_id),
    KEY idx_hq_account_actions_hq (headquarter_id),
    KEY idx_hq_account_actions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    return (bool) $conn->query($sql);
}

/**
 * Email delivery is intentionally a safe placeholder until SMTP/provider
 * credentials are configured. Replace the body of this function with the
 * project's mailer integration later without changing the action workflow.
 */
function hq_send_notification(string $recipient, string $subject, string $body): array
{
    error_log(sprintf('[HQ registry] Email placeholder for %s: %s', $recipient, $subject));

    return [
        'sent' => false,
        'status' => 'not_configured',
        'note' => 'Email delivery is not configured; the reason was recorded in the HQ action history.',
    ];
}

function hq_find_role_id(): int|false
{
    global $conn;

    $statement = $conn->prepare("SELECT role_id FROM roles WHERE LOWER(role_name) IN ('hq', 'headquarter', 'headquarters') LIMIT 1");
    if (!$statement || !$statement->execute()) {
        $statement?->close();
        return false;
    }

    $result = $statement->get_result();
    $role = $result?->fetch_assoc();
    $statement->close();

    if ($role) {
        return (int) $role['role_id'];
    }

    $insert = $conn->prepare('INSERT INTO roles (role_name) VALUES (?)');
    if (!$insert) {
        return false;
    }

    $roleName = 'HQ';
    $insert->bind_param('s', $roleName);
    $success = $insert->execute();
    $roleId = $success ? (int) $insert->insert_id : false;
    $insert->close();

    return $roleId;
}

function hq_unique_username(string $ownerName, string $email): string
{
    global $conn;

    $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $ownerName), '_'));
    if ($base === '') {
        $base = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', strstr($email, '@', true) ?: 'hq_admin'));
    }
    $base = substr($base, 0, 80) ?: 'hq_admin';
    $candidate = $base;
    $suffix = 1;

    $statement = $conn->prepare('SELECT user_id FROM users WHERE username = ? LIMIT 1');
    if (!$statement) {
        return $candidate;
    }

    while (true) {
        $statement->bind_param('s', $candidate);
        $statement->execute();
        $result = $statement->get_result();

        if (!$result || !$result->fetch_assoc()) {
            break;
        }

        $candidate = substr($base, 0, 72) . '_' . $suffix++;
    }

    $statement->close();
    return $candidate;
}
