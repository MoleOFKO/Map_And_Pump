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
    $returnTo = trim((string) ($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
    $allowedReturnTargets = [
        'moderator_dashboard.php',
        'manage_hq(M).php',
        'manage_users(M).php',
        'view_hq_and_user_account(M).php',
        'view_users(M).php',
        'view_users.php',
        'view_requests(M).php',
        'view_report(M).php',
        'edit_profile(M).php',
        'view_logs(M).php',
    ];
    if (in_array($returnTo, $allowedReturnTargets, true)) {
        $location = $returnTo;
    }
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

    $refs = [];
    foreach ($params as $index => $value) {
        $refs[] = &$params[$index];
    }

    try {
        return $statement->bind_param($types, ...$refs);
    } catch (Throwable $exception) {
        error_log('[HQ registry] bind_param failed: ' . $exception->getMessage());
        return false;
    }
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
 * Confirm that the live map_and_pump schema contains the columns used by the
 * admin registry. This check is intentionally read-only: the current TiDB
 * schema has foreign-key definitions owned by other modules, so running
 * generic CREATE/ALTER statements on every page load is unsafe.
 */
function hq_registry_schema_ready(): bool
{
    global $conn;

    if (!hq_db_available()) {
        return false;
    }

    $requiredColumns = [
        'roles' => ['role_id', 'role_name'],
        'headquarter' => [
            'headquarter_id', 'company_name', 'company_logo', 'contact_number',
            'headquarter_email', 'location', 'business_license', 'created_at', 'expire_at',
        ],
        'users' => [
            'user_id', 'user_name', 'email', 'hashed_password', 'headquarter_id',
            'role_id', 'status', 'created_at', 'updated_at',
        ],
    ];

    $tables = array_keys($requiredColumns);
    $tableSql = "'" . implode("','", $tables) . "'";
    $result = $conn->query(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($tableSql)"
    );

    if (!$result) {
        error_log('[HQ registry] Schema check failed: ' . $conn->error);
        return false;
    }

    $available = [];
    while ($column = $result->fetch_assoc()) {
        $available[$column['TABLE_NAME']][$column['COLUMN_NAME']] = true;
    }

    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            if (empty($available[$table][$column])) {
                error_log(sprintf('[HQ registry] Required schema column is missing: %s.%s', $table, $column));
                return false;
            }
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

function hq_send_notification(string $recipient, string $subject, string $body): array
{
    $recipient = trim($recipient);

    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return [
            'sent' => false,
            'status' => 'skipped',
            'note' => 'No valid email address was available; the reason was recorded in the action history.',
        ];
    }

    $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $safeBody = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111827">'
        . '<h2>' . $safeSubject . '</h2>'
        . '<p>' . $safeBody . '</p>'
        . '<p style="color:#6b7280;font-size:13px">Map & Pump account registry</p>'
        . '</div>';

    try {
        smtp_send_mail($recipient, $subject, $html, $body);

        return [
            'sent' => true,
            'status' => 'sent',
            'note' => 'Email sent to account owner.',
        ];
    } catch (Throwable $e) {
        $note = 'Email delivery failed: ' . $e->getMessage();
        error_log('[HQ registry] ' . $note);

        return [
            'sent' => false,
            'status' => 'failed',
            'note' => substr($note, 0, 255),
        ];
    }
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

function hq_hq_role_id(): int
{
    $roleId = hq_find_role_id();
    return $roleId !== false ? (int) $roleId : 0;
}

function hq_hq_role_sql(string $tableAlias = 'r'): string
{
    return "LOWER(TRIM({$tableAlias}.role_name)) IN ('hq', 'headquarter', 'headquarters')";
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

    $statement = $conn->prepare('SELECT user_id FROM users WHERE user_name = ? LIMIT 1');
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

