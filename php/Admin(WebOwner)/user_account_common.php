<?php
/**
 * Shared customer-user registry helpers for admin and moderator pages.
 */
require_once __DIR__ . '/hq_account_common.php';

function customer_account_rows(string $search = '', string $status = '', string $expiryFilter = ''): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available() || !hq_registry_schema_ready()) {
        return $rows;
    }

    $where = [
        "u.role_id = 6",
        "LOWER(TRIM(base_role.role_name)) = 'customer'",
        '(u.business_role_id IS NULL OR u.business_role_id = 6)',
        '(u.current_role_id IS NULL OR u.current_role_id = 6)',
        '(' . hq_manageable_status_sql('u.status') . " OR LOWER(TRIM(u.status)) = 'pending_verification')",
    ];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(LOWER(u.user_name) LIKE ? OR LOWER(u.email) LIKE ? OR LOWER(COALESCE(h.company_name, \'\')) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }

    if ($status !== '') {
        $statusKey = strtolower($status);
        if (in_array($statusKey, ['approved', 'active'], true)) {
            $where[] = "LOWER(u.status) IN ('approved', 'active')";
        } elseif ($statusKey === 'pending_verification') {
            $where[] = "LOWER(TRIM(u.status)) = 'pending_verification'";
        } else {
            $where[] = 'LOWER(u.status) = ?';
            $params[] = $statusKey;
            $types .= 's';
        }
    }

    $expiryFilter = strtolower(trim($expiryFilter));
    if ($expiryFilter !== '' && function_exists('expiry_user_filter_sql')) {
        $expirySql = expiry_user_filter_sql($expiryFilter, 'l.license_last_valid_date');
        if ($expirySql !== '') {
            $where[] = $expirySql;
        }
    }

    $sql = <<<'SQL'
SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.status, u.created_at, u.updated_at,
       u.headquarter_id, u.role_id, base_role.role_name,
       h.company_name AS headquarter_name, h.location AS headquarter_location,
       l.license_last_valid_date
FROM users u
INNER JOIN roles base_role ON base_role.role_id = u.role_id
LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
LEFT JOIN user_license l ON l.license_id = u.license_id
SQL;
    $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.updated_at DESC, u.user_id DESC';

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

/**
 * Status filters for customer_account_rows(). Pending verifications are
 * included here as read-only rows; their approval/rejection remains in Requests.
 * `active` is an alias of `approved` in this view.
 *
 * @return list<string>
 */
function customer_account_status_options(): array
{
    return ['approved', 'pending', 'pending_verification', 'rejected', 'removed', 'suspended'];
}

function pending_account_rows(string $search = ''): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available() || !hq_registry_schema_ready()) {
        return $rows;
    }

    // Account Verification is reserved for accounts awaiting the explicit verification state.
    // Legacy generic "pending" accounts must not be shown or actioned from this review queue.
    $where = ["LOWER(TRIM(u.status)) = 'pending_verification'"];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(LOWER(u.user_name) LIKE ? OR LOWER(u.email) LIKE ? OR LOWER(COALESCE(h.company_name, \'\')) LIKE ? OR LOWER(COALESCE(l.license_number, \'\')) LIKE ? OR LOWER(COALESCE(l.license_name, \'\')) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like, $like, $like);
        $types .= 'sssss';
    }

    $sql = <<<'SQL'
SELECT u.user_id, u.user_public_id, u.user_name, u.email, u.status, u.created_at, u.updated_at,
       u.license_id,
       u.headquarter_id, COALESCE(u.current_role_id, u.business_role_id, u.role_id) AS role_id, r.role_name,
       h.company_name AS headquarter_name, h.location AS headquarter_location,
       l.license_number, l.license_name, l.phone_number, l.nrc_number,
       l.date_of_birth, l.blood_type, l.license_last_valid_date,
       l.status AS license_status, l.license_image, l.face_image
FROM users u
INNER JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
LEFT JOIN user_license l ON l.license_id = u.license_id
SQL;
    $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.created_at DESC, u.user_id DESC';

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

function pending_account_count(): int
{
    global $conn;

    if (!hq_db_available() || !hq_registry_schema_ready()) {
        return 0;
    }

    $query = $conn->query(
        "SELECT COUNT(*) AS total
         FROM users u
         INNER JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
         WHERE LOWER(TRIM(u.status)) = 'pending_verification'"
    );

    return $query ? (int) ($query->fetch_assoc()['total'] ?? 0) : 0;
}

function user_action_table_ready(): bool
{
    global $conn;
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    if (!hq_db_available()) {
        return $ready = false;
    }

    try {
        $exists = $conn->query(
            "SELECT COUNT(*) AS c
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'user_account_actions'"
        );
        if ($exists && (int) ($exists->fetch_assoc()['c'] ?? 0) > 0) {
            return $ready = true;
        }
    } catch (Throwable $e) {
        error_log('[user_account_actions] existence check failed: ' . $e->getMessage());
    }

    return $ready = false;
}


function user_action_rows(int $userId): array
{
    global $conn;
    $rows = [];

    if (!user_action_table_ready()) {
        return $rows;
    }

    $statement = $conn->prepare(
        'SELECT action_type, reason, notification_status, notification_note, created_at
         FROM user_account_actions WHERE user_id = ? ORDER BY created_at DESC, action_id DESC'
    );
    if (!$statement) {
        return $rows;
    }

    $statement->bind_param('i', $userId);
    $statement->execute();
    $result = $statement->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    $statement->close();

    return $rows;
}
