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
        'admin_dashboard.php',
        'moderator_dashboard.php',
        'manage_hq(M).php',
        'manage_users(M).php',
        'view_hq_and_user_account.php',
        'view_hq_and_user_account(M).php',
        'view_users(M).php',
        'view_users.php',
        'view_requests.php',
        'view_requests(M).php',
        'view_report.php',
        'view_report(M).php',
        'edit_profile(M).php',
        'view_logs(M).php',
        'view_logs.php',
        'manage_moderators.php',
    ];
    if (in_array($returnTo, $allowedReturnTargets, true)) {
        $location = $returnTo;
    }
    header('Location: ' . $location);
    exit;
}

/**
 * True when the signed-in account's effective role is admin
 * (current_role → business_role → base role).
 */
function admin_is_admin(?array $user = null): bool
{
    if ($user === null) {
        $user = admin_current_user(false);
    }
    if (!$user) {
        return false;
    }

    if (admin_user_has_role($user, ['admin'])) {
        return true;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionRole = strtolower(trim((string) ($_SESSION['role_name'] ?? '')));
        $sessionBusiness = strtolower(trim((string) ($_SESSION['business_role_name'] ?? '')));
        if ($sessionRole === 'admin' || $sessionBusiness === 'admin') {
            return true;
        }
        if ((int) ($_SESSION['role_id'] ?? 0) === 1 || (int) ($_SESSION['business_role_id'] ?? 0) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Require a signed-in admin. Moderators and anonymous users are redirected.
 */
function admin_require_admin(string $redirect = '../Login/login.php'): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $user = admin_current_user(false);
    $status = strtolower(trim((string) ($user['status'] ?? '')));
    if (!$user || !admin_is_admin($user) || !in_array($status, ['approved', 'active'], true)) {
        header('Location: ' . $redirect);
        exit;
    }

    return $user;
}

/**
 * Check every role slot. A person may retain Customer as their base/current
 * role while holding a privileged business role.
 */
function admin_user_has_role(array $user, array $allowedRoles): bool
{
    $allowedRoles = array_map(static fn (string $role): string => strtolower(trim($role)), $allowedRoles);
    foreach (['role_name', 'primary_role_name', 'business_role_name', 'active_role_name'] as $key) {
        if (in_array(strtolower(trim((string) ($user[$key] ?? ''))), $allowedRoles, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Require an active Admin or Moderator account for handlers shared by both
 * portals. Admin-only pages must use admin_require_admin() instead.
 */
function admin_require_staff(string $redirect = '../Login/login.php'): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $user = admin_current_user(false);
    $status = strtolower(trim((string) ($user['status'] ?? '')));
    if (!$user || !admin_user_has_role($user, ['admin', 'moderator']) || !in_array($status, ['approved', 'active'], true)) {
        header('Location: ' . $redirect);
        exit;
    }

    return $user;
}

function admin_current_user(bool $allowDevelopmentFallback = true): ?array
{
    global $conn;
    global $pdo;

    $baseSql = <<<'SQL'
SELECT u.user_id, u.user_name, u.email, u.hashed_password, u.role_id, u.business_role_id, u.current_role_id,
       u.status, u.created_at, u.updated_at, u.last_login_at,
       u.oauth_avatar_url, u.headquarter_id,
       COALESCE(u.current_role_id, u.business_role_id, u.role_id) AS effective_role_id,
       COALESCE(active_role.role_name, business_role.role_name, primary_role.role_name) AS role_name,
       primary_role.role_name AS primary_role_name,
       business_role.role_name AS business_role_name,
       active_role.role_name AS active_role_name,
       h.company_name AS hq_name
FROM users u
LEFT JOIN roles primary_role ON primary_role.role_id = u.role_id
LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
LEFT JOIN roles active_role ON active_role.role_id = u.current_role_id
LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
SQL;

    $sessionUserId = 0;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionUserId = (int) ($_SESSION['user_id'] ?? 0);
    }

    if (!hq_db_available() && !($pdo instanceof PDO)) {
        return null;
    }

    if (!hq_db_available() && $pdo instanceof PDO) {
        $roleFilter = "(LOWER(TRIM(primary_role.role_name)) IN ('admin', 'moderator') OR LOWER(TRIM(business_role.role_name)) IN ('admin', 'moderator') OR LOWER(TRIM(active_role.role_name)) IN ('admin', 'moderator'))";
        if ($sessionUserId > 0) {
            $statement = $pdo->prepare($baseSql . " WHERE u.user_id = ? AND {$roleFilter} LIMIT 1");
            $statement->execute([$sessionUserId]);
            $user = $statement->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                return $user;
            }
        }

        if (!$allowDevelopmentFallback) {
            return null;
        }

        $statement = $pdo->query(
            $baseSql . " WHERE u.headquarter_id IS NULL AND {$roleFilter}
             ORDER BY CASE WHEN LOWER(u.status) IN ('approved', 'active') THEN 0 ELSE 1 END,
                      u.user_id ASC LIMIT 1"
        );
        return $statement ? ($statement->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    }

    if ($sessionUserId > 0) {
        $statement = $conn->prepare($baseSql . " WHERE u.user_id = ? AND (LOWER(TRIM(primary_role.role_name)) IN ('admin', 'moderator') OR LOWER(TRIM(business_role.role_name)) IN ('admin', 'moderator') OR LOWER(TRIM(active_role.role_name)) IN ('admin', 'moderator')) LIMIT 1");
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
         AND (LOWER(TRIM(primary_role.role_name)) IN ('admin', 'moderator') OR LOWER(TRIM(business_role.role_name)) IN ('admin', 'moderator') OR LOWER(TRIM(active_role.role_name)) IN ('admin', 'moderator'))
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

/**
 * Normalize a free-form or legacy scope token into dashboard buckets.
 */
function admin_normalize_report_scope(string $scope): string
{
    $scope = strtolower(trim($scope));
    return match ($scope) {
        'hq', 'hq_performance', 'headquarter', 'headquarters', 'hq_report' => 'hq_performance',
        'station', 'stations', 'station_status', 'station_logs', 'pump', 'pumps' => 'stations',
        'request', 'requests', 'approvals', 'rejections' => 'requests',
        'system', 'security', 'system_security' => 'system',
        'audit', 'audit_logs', 'logs', 'log' => 'audit_logs',
        default => $scope !== '' ? $scope : 'system',
    };
}

/**
 * Infer report scope from structured message tags, title text, body text, and optional role.
 *
 * @param array{short_summery?:string,short_summary?:string,role_id?:int|string|null,role_name?:string|null} $context
 */
function admin_report_metadata(?string $message, array $context = []): array
{
    $message = (string) $message;
    $title = trim((string) ($context['short_summery'] ?? $context['short_summary'] ?? ''));
    $roleId = (int) ($context['role_id'] ?? 0);
    $roleName = strtolower(trim((string) ($context['role_name'] ?? '')));

    $metadata = [
        'scope' => 'system',
        'format' => 'record',
        'start_date' => null,
        'end_date' => null,
        'summary' => $message !== '' ? $message : $title,
    ];

    // Structured admin/moderator generated reports:
    // [scope=hq_performance][format=pdf][start=...][end=...] summary text
    if (preg_match('/\[scope=([a-z_]+)\]\[format=([a-z]+)\]\[start=([^\]]*)\]\[end=([^\]]*)\]\s*(.*)/is', $message, $matches)) {
        $metadata['scope'] = admin_normalize_report_scope((string) $matches[1]);
        $metadata['format'] = strtolower((string) $matches[2]);
        $metadata['start_date'] = $matches[3] !== '' ? $matches[3] : null;
        $metadata['end_date'] = $matches[4] !== '' ? $matches[4] : null;
        $metadata['summary'] = trim((string) $matches[5]);
        return $metadata;
    }

    // Partial tag: [scope=stations] anywhere in the message
    if (preg_match('/\[scope=([a-z_]+)\]/i', $message, $matches)) {
        $metadata['scope'] = admin_normalize_report_scope((string) $matches[1]);
        $metadata['summary'] = trim(preg_replace('/\[scope=[a-z_]+\]/i', '', $message) ?? $message);
        return $metadata;
    }

    $haystack = strtolower(trim($title . ' ' . $message));

    if ($haystack !== '') {
        if (preg_match('/\b(hq|headquarter|headquarters|company performance|hq performance)\b/', $haystack)) {
            $metadata['scope'] = 'hq_performance';
            return $metadata;
        }
        if (preg_match('/\b(station|stations|pump|pumps|fuel stock|inventory)\b/', $haystack)) {
            $metadata['scope'] = 'stations';
            return $metadata;
        }
        if (preg_match('/\b(request|requests|approval|approvals|rejection|rejections|pending account)\b/', $haystack)) {
            $metadata['scope'] = 'requests';
            return $metadata;
        }
        if (preg_match('/\b(audit|security|system log|login|password|permission)\b/', $haystack)) {
            $metadata['scope'] = 'system';
            return $metadata;
        }
    }

    // Role-based fallback for operational reports that omit tags.
    if ($roleName !== '') {
        if (in_array($roleName, ['hq', 'headquarter', 'headquarters'], true) || $roleId === 3) {
            $metadata['scope'] = 'hq_performance';
            return $metadata;
        }
        if (in_array($roleName, ['branch_manager', 'branch manager', 'manager', 'staff'], true) || in_array($roleId, [4, 5], true)) {
            // Branch/staff feedback is usually station-level unless title said otherwise.
            $metadata['scope'] = 'stations';
            return $metadata;
        }
    } elseif (in_array($roleId, [3], true)) {
        $metadata['scope'] = 'hq_performance';
        return $metadata;
    } elseif (in_array($roleId, [4, 5], true)) {
        $metadata['scope'] = 'stations';
        return $metadata;
    }

    $metadata['scope'] = 'system';
    return $metadata;
}

function admin_scope_label(string $scope): string
{
    $scope = admin_normalize_report_scope($scope);
    return match ($scope) {
        'hq_performance' => 'HQ Performance',
        'stations' => 'Station Logs',
        'requests' => 'Request Audit Logs',
        'audit_logs' => 'System Audit Logs',
        'system' => 'System Security',
        default => 'Stored Report',
    };
}

/**
 * Return one selectable request filter for each user-facing request category.
 * Legacy request_type values remain accepted by the filtering code, while
 * aliases that share a filter no longer produce repeated options.
 *
 * @param list<string> $types
 * @return list<string>
 */
function admin_request_type_filter_options(array $types): array
{
    $options = [];
    foreach ($types as $type) {
        $value = strtolower(trim($type));
        $canonical = match ($value) {
            'station', 'new_station', 'new_station_registration' => 'station',
            'license', 'license_modification' => 'license',
            'deactivation', 'account_deactivation' => 'deactivation',
            default => $value,
        };
        if ($canonical !== '' && !in_array($canonical, $options, true)) {
            $options[] = $canonical;
        }
    }

    return $options;
}

function admin_request_type_filter_value(string $type): string
{
    $value = strtolower(trim($type));
    return match ($value) {
        'station', 'new_station', 'new_station_registration' => 'station',
        'license', 'license_modification' => 'license',
        'deactivation', 'account_deactivation' => 'deactivation',
        default => $value,
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


/**
 * Run a single-column SELECT and return sorted unique non-empty lowercase strings.
 *
 * @return list<string>
 */
function admin_distinct_values(string $sql): array
{
    global $conn;
    if (!hq_db_available()) {
        return [];
    }
    $values = [];
    $query = $conn->query($sql);
    if (!$query) {
        return [];
    }
    while ($row = $query->fetch_assoc()) {
        $raw = strtolower(trim((string) (array_values($row)[0] ?? '')));
        if ($raw === '') {
            continue;
        }
        $values[$raw] = $raw;
    }
    $list = array_values($values);
    sort($list, SORT_STRING);
    return $list;
}

/**
 * Status keywords present on user accounts (optionally scoped by SQL fragment on users u).
 *
 * @return list<string>
 */
function admin_filter_user_statuses(string $extraWhere = '1=1'): array
{
    $sql = "SELECT DISTINCT LOWER(TRIM(u.status)) AS status
            FROM users u
            WHERE u.status IS NOT NULL AND TRIM(u.status) <> ''
              AND ({$extraWhere})
            ORDER BY status ASC";
    return admin_distinct_values($sql);
}

/**
 * Distinct HQ location keywords for region filter (from headquarter.location).
 *
 * @return list<string>
 */
function admin_filter_hq_regions(): array
{
    return admin_distinct_values(
        "SELECT DISTINCT LOWER(TRIM(location)) AS region
         FROM headquarter
         WHERE location IS NOT NULL AND TRIM(location) <> ''
         ORDER BY region ASC"
    );
}

/**
 * Distinct system_requests.status values.
 *
 * @return list<string>
 */
function admin_filter_request_statuses(): array
{
    return admin_distinct_values(
        "SELECT DISTINCT LOWER(TRIM(status)) AS status
         FROM system_requests
         WHERE status IS NOT NULL AND TRIM(status) <> ''
         ORDER BY status ASC"
    );
}

/**
 * Distinct system_requests.request_type values (raw keywords from DB).
 *
 * @return list<string>
 */
function admin_filter_request_types(): array
{
    return admin_distinct_values(
        "SELECT DISTINCT LOWER(TRIM(request_type)) AS request_type
         FROM system_requests
         WHERE request_type IS NOT NULL AND TRIM(request_type) <> ''
         ORDER BY request_type ASC"
    );
}

/**
 * Human label for a free-form filter keyword (status, region, request type).
 */
function admin_filter_label(string $value): string
{
    $value = strtolower(trim($value));
    $known = [
        'approved' => 'Approved',
        'active' => 'Active',
        'pending' => 'Pending',
        'pending_verification' => 'Pending verification',
        'suspended' => 'Suspended',
        'removed' => 'Removed',
        'rejected' => 'Rejected',
        'flagged' => 'Flagged',
        'yangon' => 'Yangon',
        'mandalay' => 'Mandalay',
        'naypyidaw' => 'Naypyidaw',
        'account' => 'Account verification',
        'station' => 'New station registration',
        'new_station' => 'New station registration',
        'new_station_registration' => 'New station registration',
        'license' => 'License modification',
        'license_modification' => 'License modification',
        'deactivation' => 'Account deactivation',
        'account_deactivation' => 'Account deactivation',
    ];
    if (isset($known[$value])) {
        return $known[$value];
    }
    return ucwords(str_replace(['_', '-'], ' ', $value));
}
