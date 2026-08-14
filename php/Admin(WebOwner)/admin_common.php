<?php
/**
 * Shared helpers for the admin dashboard, profile, report, and log pages.
 */
require_once __DIR__ . '/hq_account_common.php';

function admin_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['admin_registry_flash'] = ['type' => $type, 'message' => $message];
}

function admin_pull_flash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $flash = $_SESSION['admin_registry_flash'] ?? null;
    unset($_SESSION['admin_registry_flash']);
    return is_array($flash) ? $flash : null;
}

function admin_redirect(string $location = 'admin_dashboard.php'): never
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

function admin_current_user(bool $allowDevelopmentFallback = true): ?array
{
    global $conn;

    if (!hq_db_available()) {
        return null;
    }

    $baseSql = <<<'SQL'
SELECT u.user_id, u.user_name, u.email, u.hashed_password, u.role_id,
       u.status, u.created_at, u.updated_at, u.last_login_at,
       u.oauth_avatar_url, u.headquarter_id, r.role_name,
       h.company_name AS hq_name
FROM users u
LEFT JOIN roles r ON r.role_id = u.role_id
LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
SQL;

    $sessionUserId = 0;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    }

    if ($sessionUserId > 0) {
        $statement = $conn->prepare($baseSql . " WHERE u.user_id = ? AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator') LIMIT 1");
        if ($statement) {
            $statement->bind_param('i', $sessionUserId);
            $statement->execute();
            $user = $statement->get_result()?->fetch_assoc();
            $statement->close();
            if ($user) {
                return $user;
            }
        }
    }

    if (!$allowDevelopmentFallback) {
        return null;
    }

    $result = $conn->query(
        $baseSql . " WHERE u.headquarter_id IS NULL
         AND LOWER(TRIM(r.role_name)) IN ('admin', 'moderator')
         ORDER BY CASE WHEN LOWER(u.status) IN ('approved', 'active') THEN 0 ELSE 1 END,
                  u.user_id ASC LIMIT 1"
    );
    return $result ? ($result->fetch_assoc() ?: null) : null;
}

function admin_record_log(?int $userId, string $action): void
{
    global $conn;

    if (!$userId || !hq_db_available()) {
        return;
    }

    $statement = $conn->prepare('INSERT INTO log (user_id, action, created_at) VALUES (?, ?, NOW())');
    if ($statement) {
        $statement->bind_param('is', $userId, $action);
        $statement->execute();
        $statement->close();
    }
}

function admin_date(?string $value): string
{
    if (!$value) {
        return 'Not available';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y · g:i A', $timestamp) : $value;
}

function admin_report_metadata(?string $message): array
{
    $message = (string) $message;
    $metadata = [
        'scope' => 'audit_logs',
        'format' => 'record',
        'start_date' => null,
        'end_date' => null,
        'summary' => $message,
    ];

    if (preg_match('/\[scope=([a-z_]+)\]\[format=([a-z]+)\]\[start=([^\]]*)\]\[end=([^\]]*)\]\s*(.*)/s', $message, $matches)) {
        $metadata['scope'] = $matches[1];
        $metadata['format'] = $matches[2];
        $metadata['start_date'] = $matches[3] !== '' ? $matches[3] : null;
        $metadata['end_date'] = $matches[4] !== '' ? $matches[4] : null;
        $metadata['summary'] = trim($matches[5]);
    }

    return $metadata;
}

function admin_scope_label(string $scope): string
{
    return match ($scope) {
        'hq_performance' => 'HQ Performance',
        'station_status', 'stations' => 'Station Logs',
        'audit_logs', 'requests' => 'Audit & Request Logs',
        'system' => 'System Security',
        default => 'Stored Report',
    };
}

function admin_format_label(string $format): string
{
    return match ($format) {
        'pdf' => 'Print / PDF',
        'csv' => 'CSV',
        default => 'Database Record',
    };
}

function admin_log_level(string $action): string
{
    $action = strtolower($action);
    if (preg_match('/security|auth|login|password|permission|suspend|remove|approve|reject/', $action)) {
        return 'security';
    }
    if (preg_match('/error|failed|exception|fatal/', $action)) {
        return 'error';
    }
    if (preg_match('/warn|warning|latency|expired/', $action)) {
        return 'warn';
    }
    return 'info';
}
