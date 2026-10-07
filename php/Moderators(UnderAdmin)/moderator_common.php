<?php
/**
 * Shared access, flash, and database helpers for the moderator portal.
 */
require_once __DIR__ . '/../Admin(WebOwner)/admin_common.php';
require_once __DIR__ . '/../Admin(WebOwner)/request_common.php';
require_once __DIR__ . '/../Admin(WebOwner)/user_account_common.php';

function moderator_require_user(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $user = admin_current_user(false);
    $role = strtolower(trim((string) ($user['role_name'] ?? '')));
    $status = strtolower(trim((string) ($user['status'] ?? '')));

    if (!$user || $role !== 'moderator' || in_array($status, ['suspended', 'removed', 'rejected'], true)) {
        header('Location: ../Login/login.php');
        exit;
    }

    return $user;
}

function moderator_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function moderator_flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['moderator_registry_flash'] = ['type' => $type, 'message' => $message];
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

function moderator_redirect(string $location = 'moderator_dashboard.php'): never
{
    header('Location: ' . $location);
    exit;
}

function moderator_return_target(string $default): string
{
    $target = trim((string) ($_POST['return_to'] ?? $_GET['return_to'] ?? ''));
    $allowed = [
        'moderator_dashboard.php',
        'manage_hq(M).php',
        'manage_users(M).php',
        'view_hq_and_user_account(M).php',
        'view_users(M).php',
        'view_requests(M).php',
        'view_report(M).php',
        'edit_profile(M).php',
        'view_logs(M).php',
    ];

    return in_array($target, $allowed, true) ? $target : $default;
}

function moderator_format_date(?string $value): string
{
    if (!$value) {
        return 'Not available';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('M j, Y · g:i A', $timestamp) : $value;
}

function moderator_hq_rows(string $search, string $region, string $status, string $expiryFilter = ''): array
{
    global $conn;
    $rows = [];
    if (!hq_db_available() || !hq_registry_schema_ready()) {
        return $rows;
    }

    // Do not hide historic HQ records solely because their owner was not linked.
    // Keep unlinked HQ records visible and classify them as pending below.
    $where = ['(owner.user_id IS NULL OR ' . hq_manageable_status_sql("COALESCE(owner.status, 'pending')") . ')'];
    $params = [];
    $types = '';
    if ($search !== '') {
        $where[] = '(LOWER(h.company_name) LIKE ? OR LOWER(owner.user_name) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        $params[] = $like;
        $params[] = $like;
        $types .= 'ss';
    }
    if ($region !== '') {
        $where[] = 'LOWER(h.location) LIKE ?';
        $params[] = '%' . strtolower($region) . '%';
        $types .= 's';
    }
    if ($status !== '') {
        if (in_array($status, ['approved', 'active'], true)) {
            $where[] = "LOWER(COALESCE(owner.status, 'pending')) IN ('approved', 'active')";
        } elseif (in_array($status, ['pending', 'pending_verification'], true)) {
            $where[] = "LOWER(COALESCE(owner.status, 'pending')) IN ('pending', 'pending_verification')";
        } else {
            $where[] = "LOWER(COALESCE(owner.status, 'pending')) = ?";
            $params[] = $status;
            $types .= 's';
        }
    }
    $expiryFilter = strtolower(trim($expiryFilter));
    if ($expiryFilter !== '' && function_exists('expiry_hq_filter_sql')) {
        $expirySql = expiry_hq_filter_sql($expiryFilter, 'h.expire_at');
        if ($expirySql !== '') {
            $where[] = $expirySql;
        }
    }

    // Station totals from `branches` via station_business_scope (never dummy `stations` view).
    $sql = "SELECT h.headquarter_id, h.company_name, h.company_logo, h.location, h.headquarter_email,
       h.contact_number, h.business_license, h.created_at, h.expire_at,
       owner.user_name AS owner_name, owner.email AS owner_email,
       COALESCE(owner.status, 'pending') AS account_status,
       COALESCE(user_counts.user_count, 0) AS user_count,
       COALESCE(station_counts.station_count, 0) AS station_count
   FROM headquarter h
   LEFT JOIN (
       SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id, COUNT(*) AS user_count
       FROM users u
       WHERE (u.role_id = 3 OR u.business_role_id = 3)
       GROUP BY headquarter_id
   ) user_counts ON user_counts.headquarter_id = h.headquarter_id
   LEFT JOIN users owner ON owner.user_id = user_counts.owner_user_id
   LEFT JOIN (
       SELECT scope.headquarter_id, COUNT(DISTINCT scope.station_id) AS station_count
       FROM station_business_scope scope
       INNER JOIN branches b ON b.branch_id = scope.station_id
       GROUP BY scope.headquarter_id
   ) station_counts ON station_counts.headquarter_id = h.headquarter_id";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY h.created_at DESC, h.headquarter_id DESC';

   $statement = $conn->prepare($sql);
   if (!$statement) {
       return $rows;
   }
   if ($types !== '') {
       $bound = array_values($params);
       hq_bind($statement, $types, $bound);
   }
   if ($statement->execute()) {
       $result = $statement->get_result();
       while ($result && ($row = $result->fetch_assoc())) {
           $rows[] = $row;
       }
   }
   $statement->close();

    return $rows;
}

function moderator_request_rows(string $search, string $requestType, string $status): array
{
    global $conn;
    $rows = [];
    if (!hq_db_available() || !request_schema_ready()) {
        return $rows;
    }

    $where = [];
    $params = [];
    $types = '';
    if ($search !== '') {
        $where[] = '(LOWER(r.reference_code) LIKE ? OR LOWER(r.sender_entity) LIKE ? OR LOWER(r.sender_name) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($requestType !== '') {
        $aliases = [
            'station' => ['station', 'new_station', 'new_station_registration'],
            'license' => ['license', 'license_modification'],
            'deactivation' => ['deactivation', 'account_deactivation'],
        ][$requestType] ?? [$requestType];
        $where[] = 'LOWER(r.request_type) IN (' . implode(',', array_fill(0, count($aliases), '?')) . ')';
        foreach ($aliases as $alias) {
            $params[] = $alias;
            $types .= 's';
        }
    }
    if ($status !== '') {
        $where[] = 'LOWER(r.status) = ?';
        $params[] = $status;
        $types .= 's';
    }

    $sql = 'SELECT r.request_id, r.reference_code, r.request_type, r.status, r.sender_entity,
                   r.sender_name, r.sender_email, r.location, r.created_at, r.review_reason
            FROM system_requests r';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY CASE WHEN LOWER(r.status) = \'pending\' THEN 0 ELSE 1 END, r.created_at DESC, r.request_id DESC';

    $statement = $conn->prepare($sql);
    if (!$statement) {
        return $rows;
    }
    if ($types !== '') {
        $bound = array_values($params);
        hq_bind($statement, $types, $bound);
    }
    if ($statement->execute()) {
        $result = $statement->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $rows[] = $row;
        }
    }
    $statement->close();

    return $rows;
}

function moderator_request_summary(): array
{
    $summary = request_live_status_summary();
    return [
        'pending' => $summary['pending'],
        'approved_today' => $summary['approved_today'],
        'declined_flagged' => $summary['declined_flagged'],
        'error' => $summary['error'],
    ];
}
