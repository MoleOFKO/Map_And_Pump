<?php
/**
 * Shared customer-user registry helpers for admin and moderator pages.
 */
require_once __DIR__ . '/hq_account_common.php';

/**
 * Resolve the Customer role_id from the live roles table (fallback: 6).
 */
function customer_role_id(): int
{
    global $conn;
    static $roleId = null;
    if ($roleId !== null) {
        return $roleId;
    }

    $roleId = 6;
    if (!hq_db_available()) {
        return $roleId;
    }

    try {
        $statement = $conn->prepare(
            "SELECT role_id FROM roles WHERE LOWER(TRIM(role_name)) = 'customer' ORDER BY role_id ASC LIMIT 1"
        );
        if ($statement && $statement->execute()) {
            $row = $statement->get_result()?->fetch_assoc();
            if ($row && (int) ($row['role_id'] ?? 0) > 0) {
                $roleId = (int) $row['role_id'];
            }
        }
        $statement?->close();
    } catch (Throwable $e) {
        // Keep the historical default of 6.
    }

    return $roleId;
}

/**
 * SQL visibility rule for the driver registry and its detail endpoint.
 * A Customer role in either active or business role slot identifies the
 * driver account even when the other role slot differs.
 */
function customer_account_role_guard_sql(int $customerRoleId, string $userAlias = 'u'): string
{
    $customerRoleChecks = ["{$userAlias}.business_role_id = {$customerRoleId}"];
    if (user_account_column_exists('users', 'current_role_id')) {
        $customerRoleChecks[] = "{$userAlias}.current_role_id = {$customerRoleId}";
    }

    return '(' . implode(' OR ', $customerRoleChecks) . ')';
}

/** SQL predicate for accounts with any elevated staff role. */
function customer_account_has_staff_role_sql(string $userAlias = 'u'): string
{
    $roleSlots = ["{$userAlias}.role_id", "{$userAlias}.business_role_id"];
    if (user_account_column_exists('users', 'current_role_id')) {
        $roleSlots[] = "{$userAlias}.current_role_id";
    }

    return "EXISTS (
            SELECT 1
            FROM roles registry_privileged_role
            WHERE registry_privileged_role.role_id IN (" . implode(', ', $roleSlots) . ")
              AND LOWER(TRIM(registry_privileged_role.role_name)) IN ('admin', 'moderator')
        )";
}

/** SQL predicate for management actions that could affect staff access. */
function customer_account_management_guard_sql(int $customerRoleId, string $userAlias = 'u'): string
{
    return customer_account_role_guard_sql($customerRoleId, $userAlias)
        . ' AND NOT (' . customer_account_has_staff_role_sql($userAlias) . ')';
}

function customer_account_rows(string $search = '', string $status = '', string $expiryFilter = ''): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available() || !hq_registry_schema_ready()) {
        return $rows;
    }

    $customerRoleId = customer_role_id();
    $where = [customer_account_role_guard_sql($customerRoleId)];
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
    $hasLicenseRecord = user_account_column_exists('user_license', 'license_id');
    $hasLicenseExpiry = $hasLicenseRecord && user_account_column_exists('user_license', 'license_last_valid_date');
    if ($expiryFilter !== '' && $hasLicenseExpiry && function_exists('expiry_user_filter_sql')) {
        $expirySql = expiry_user_filter_sql($expiryFilter, 'l.license_last_valid_date');
        if ($expirySql !== '') {
            $where[] = $expirySql;
        }
    }

    $publicIdSelect = user_account_column_exists('users', 'user_public_id')
        ? 'u.user_public_id'
        : 'NULL AS user_public_id';
    $licenseExpirySelect = $hasLicenseExpiry
        ? 'l.license_last_valid_date'
        : 'NULL AS license_last_valid_date';
    $licenseJoin = $hasLicenseRecord
        ? 'LEFT JOIN user_license l ON l.license_id = u.license_id'
        : '';
    $licenseConflictSelect = $hasLicenseRecord
        && user_account_column_exists('user_license', 'license_number')
        && user_account_column_exists('user_license', 'nrc_number')
        ? "(SELECT COUNT(*) FROM user_license duplicate
            WHERE duplicate.license_id <> l.license_id
              AND ((TRIM(COALESCE(l.license_number, '')) <> ''
                    AND UPPER(REPLACE(TRIM(duplicate.license_number), ' ', '')) = UPPER(REPLACE(TRIM(l.license_number), ' ', '')))
                OR (TRIM(COALESCE(l.nrc_number, '')) <> ''
                    AND UPPER(REPLACE(TRIM(duplicate.nrc_number), ' ', '')) = UPPER(REPLACE(TRIM(l.nrc_number), ' ', ''))))) AS license_conflict_count"
        : '0 AS license_conflict_count';
    $staffRoleSql = customer_account_has_staff_role_sql();
    $sql = <<<SQL
SELECT u.user_id, {$publicIdSelect}, u.user_name, u.email, u.status, u.created_at, u.updated_at,
    CASE WHEN {$staffRoleSql} THEN 1 ELSE 0 END AS staff_access_protected,
    {$licenseConflictSelect},
    bas.headquarter_id, {$customerRoleId} AS role_id, customer_role.role_name,
    h.company_name AS headquarter_name, h.location AS headquarter_location,
    {$licenseExpirySelect}
FROM users u
INNER JOIN roles customer_role ON customer_role.role_id = {$customerRoleId}
LEFT JOIN (
    SELECT user_id, MIN(headquarter_id) AS headquarter_id
    FROM business_account_scope
    WHERE headquarter_id IS NOT NULL
    GROUP BY user_id
) bas ON bas.user_id = u.user_id
LEFT JOIN headquarter h ON h.headquarter_id = bas.headquarter_id
{$licenseJoin}
SQL;
    $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY u.updated_at DESC, u.user_id DESC';

    $statement = $conn->prepare($sql);
    if (!$statement) {
        error_log('[Account review] Pending account rows could not be prepared: ' . $conn->error);
        return $rows;
    }

    if ($types !== '') {
        $bound = array_values($params);
        if (!hq_bind($statement, $types, $bound)) {
            error_log('[Account review] Pending account row filters could not be bound: ' . $statement->error);
            $statement->close();
            return $rows;
        }
    }

    if (!$statement->execute()) {
        error_log('[Account review] Pending account rows could not be loaded: ' . $statement->error);
        $statement->close();
        return $rows;
    }
    $result = $statement->get_result();
    if (!$result) {
        error_log('[Account review] Pending account rows returned no result set: ' . $statement->error);
    } else {
        while ($row = $result->fetch_assoc()) {
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

/**
 * Check optional account columns without assuming every deployed schema has
 * already received the latest role and license migrations.
 */
function user_account_column_exists(string $table, string $column): bool
{
    global $conn;
    static $cache = [];

    $allowedColumns = [
        'users' => ['current_role_id', 'user_public_id', 'last_login_at'],
        'user_license' => ['license_id', 'license_last_valid_date', 'status', 'face_image', 'license_number', 'nrc_number'],
        'car_license' => [
            'car_license_id', 'status', 'car_plate_number', 'car_owner_name',
            'vehicle_name', 'vehicle_type', 'chassis_number', 'location',
            'created_at', 'car_license_front_image', 'car_license_back_image',
        ],
        'user_car' => ['car_id', 'user_id'],
    ];
    if (!isset($allowedColumns[$table]) || !in_array($column, $allowedColumns[$table], true)) {
        return false;
    }

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!hq_db_available()) {
        return $cache[$key] = false;
    }

    $statement = $conn->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$statement) {
        return $cache[$key] = false;
    }
    $statement->bind_param('ss', $table, $column);
    $exists = $statement->execute() && (bool) $statement->get_result()?->fetch_row();
    $statement->close();

    return $cache[$key] = $exists;
}

function user_account_schema_ready(): bool
{
    global $conn;
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }
    if (!hq_db_available()) {
        return $ready = false;
    }

    $requiredColumns = [
        'roles' => ['role_id', 'role_name'],
        'headquarter' => ['headquarter_id', 'company_name', 'location'],
        'users' => ['user_id', 'user_name', 'email', 'role_id', 'license_id', 'status', 'created_at', 'updated_at'],
        'user_license' => ['license_id', 'license_image', 'license_number', 'license_name', 'phone_number', 'nrc_number', 'date_of_birth', 'blood_type', 'license_last_valid_date'],
    ];
    $tableSql = "'" . implode("','", array_keys($requiredColumns)) . "'";
    $result = $conn->query(
        "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($tableSql)"
    );
    if (!$result) {
        error_log('[Account review] Schema check failed: ' . $conn->error);
        return $ready = false;
    }

    $available = [];
    while ($column = $result->fetch_assoc()) {
        $available[$column['TABLE_NAME']][$column['COLUMN_NAME']] = true;
    }
    foreach ($requiredColumns as $table => $columns) {
        foreach ($columns as $column) {
            if (empty($available[$table][$column])) {
                error_log(sprintf('[Account review] Required schema column is missing: %s.%s', $table, $column));
                return $ready = false;
            }
        }
    }

    return $ready = true;
}

function user_account_review_schema_ready(): bool
{
    global $conn;
    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }
    if (!user_account_schema_ready()) {
        return $ready = false;
    }

    $requiredColumns = [
        'request_id', 'recipient_user_id', 'recipient_email', 'notification_type',
        'subject', 'message', 'delivery_status', 'delivery_note',
    ];
    $result = $conn->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'"
    );
    if (!$result) {
        error_log('[Account review] Notification schema check failed: ' . $conn->error);
        return $ready = false;
    }

    $available = [];
    while ($column = $result->fetch_assoc()) {
        $available[$column['COLUMN_NAME']] = true;
    }
    foreach ($requiredColumns as $column) {
        if (empty($available[$column])) {
            error_log('[Account review] Required schema column is missing: notifications.' . $column);
            return $ready = false;
        }
    }

    return $ready = true;
}

function pending_account_rows(string $search = '', string $status = 'pending'): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available() || !user_account_schema_ready() || !user_account_column_exists('user_license', 'status')) {
        return $rows;
    }

    $status = strtolower(trim($status));
    $licenseStatusValues = match ($status) {
        'approved' => ['approved', 'active'],
        'reject' => ['rejected'],
        default => ['pending'],
    };
    $where = [
        'LOWER(TRIM(l.status)) IN (' . implode(',', array_fill(0, count($licenseStatusValues), '?')) . ')',
    ];
    $params = $licenseStatusValues;
    $types = str_repeat('s', count($params));
    if ($search !== '') {
        $where[] = '(LOWER(u.user_name) LIKE ? OR LOWER(u.email) LIKE ? OR LOWER(COALESCE(h.company_name, \'\')) LIKE ? OR LOWER(COALESCE(l.license_number, \'\')) LIKE ? OR LOWER(COALESCE(l.license_name, \'\')) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like, $like, $like);
        $types .= 'sssss';
    }

    $publicIdSelect = user_account_column_exists('users', 'user_public_id')
        ? 'u.user_public_id'
        : 'NULL AS user_public_id';
    $licenseStatusSelect = 'l.status AS license_status';
    $faceImageSelect = user_account_column_exists('user_license', 'face_image')
        ? 'l.face_image'
        : 'NULL AS face_image';

    $sql = <<<SQL
SELECT u.user_id, {$publicIdSelect}, u.user_name, u.email, u.status, u.created_at, u.updated_at,
       u.license_id,
    bas.headquarter_id, u.role_id AS role_id, r.role_name,
       h.company_name AS headquarter_name, h.location AS headquarter_location,
       l.license_number, l.license_name, l.phone_number, l.nrc_number,
       l.date_of_birth, l.blood_type, l.license_last_valid_date,
             (SELECT COUNT(*) FROM user_license duplicate
                                WHERE duplicate.license_id <> l.license_id
                                    AND ((UPPER(REPLACE(TRIM(duplicate.license_number), ' ', '')) = UPPER(REPLACE(TRIM(l.license_number), ' ', '')))
                                        OR (UPPER(REPLACE(TRIM(duplicate.nrc_number), ' ', '')) = UPPER(REPLACE(TRIM(l.nrc_number), ' ', ''))))) AS license_conflict_count,
       {$licenseStatusSelect}, l.license_image, {$faceImageSelect}
FROM users u
LEFT JOIN roles r ON r.role_id = u.role_id
LEFT JOIN business_account_scope bas ON bas.user_id = u.user_id
LEFT JOIN headquarter h ON h.headquarter_id = bas.headquarter_id
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

function pending_car_license_rows(string $search = '', string $status = 'pending'): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available()
        || !user_account_column_exists('car_license', 'car_license_id')
        || !user_account_column_exists('car_license', 'status')) {
        return $rows;
    }

    $carFields = [
        'car_plate_number', 'car_owner_name', 'vehicle_name', 'vehicle_type',
        'chassis_number', 'location', 'created_at',
        'car_license_front_image', 'car_license_back_image',
    ];
    $selectFields = [];
    foreach ($carFields as $field) {
        $selectFields[] = user_account_column_exists('car_license', $field)
            ? "c.{$field} AS {$field}"
            : "NULL AS {$field}";
    }

    $hasPlateNumber = user_account_column_exists('car_license', 'car_plate_number');
    $hasEligibleCarOwner = user_account_column_exists('user_car', 'car_id')
        && user_account_column_exists('user_car', 'user_id');
    $conflictSelect = $hasPlateNumber && $hasEligibleCarOwner
        ? "(SELECT COUNT(*) FROM car_license duplicate
             WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(duplicate.car_plate_number), '/', ''), ' ', ''), '-', '')) =
                   UPPER(REPLACE(REPLACE(REPLACE(TRIM(c.car_plate_number), '/', ''), ' ', ''), '-', ''))
               AND duplicate.car_license_id <> c.car_license_id
               AND EXISTS (SELECT 1 FROM user_car duplicate_link
                           INNER JOIN users duplicate_user ON duplicate_user.user_id = duplicate_link.user_id
                           WHERE duplicate_link.car_id = duplicate.car_license_id
                             AND LOWER(TRIM(COALESCE(duplicate_user.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'deleted'))) AS conflict_count"
        : '0 AS conflict_count';

    // Car ownership is helpful detail, but it is not required to list or
    // review the license itself. Older deployments may not have user_car yet.
    $ownerSelect = 'NULL AS user_id, NULL AS user_name, NULL AS email';
    $ownerJoin = '';
    if (user_account_column_exists('user_car', 'car_id')
        && user_account_column_exists('user_car', 'user_id')
        && user_account_schema_ready()) {
        $ownerSelect = 'owner.user_id, owner.user_name, owner.email';
        $ownerJoin = "LEFT JOIN (
                SELECT uc.car_id, MIN(u.user_id) AS user_id
                FROM user_car uc INNER JOIN users u ON u.user_id = uc.user_id
                GROUP BY uc.car_id
            ) owner_link ON owner_link.car_id = c.car_license_id
            LEFT JOIN users owner ON owner.user_id = owner_link.user_id";
    }

    $orderSql = user_account_column_exists('car_license', 'created_at')
        ? 'c.created_at DESC, c.car_license_id DESC'
        : 'c.car_license_id DESC';
    $status = strtolower(trim($status));
    $statusValues = match ($status) {
        'approved' => ['approved'],
        'reject' => ['rejected'],
        default => ['pending'],
    };
    $sql = 'SELECT c.car_license_id, ' . implode(', ', $selectFields) . ', c.status,
                   ' . $ownerSelect . ', ' . $conflictSelect . '
            FROM car_license c ' . $ownerJoin . "
            WHERE LOWER(TRIM(c.status)) IN (" . implode(',', array_fill(0, count($statusValues), '?')) . ')';
    $params = $statusValues;
    $types = str_repeat('s', count($statusValues));
    if ($search !== '') {
        $searchableFields = [];
        foreach (['car_plate_number', 'car_owner_name', 'vehicle_name'] as $field) {
            if (user_account_column_exists('car_license', $field)) {
                $searchableFields[] = "c.{$field}";
            }
        }
        if ($ownerJoin !== '') {
            $searchableFields[] = 'owner.user_name';
            $searchableFields[] = 'owner.email';
        }
        $like = '%' . strtolower($search) . '%';
        if ($searchableFields) {
            $sql .= ' AND (' . implode(' OR ', array_map(
                static fn (string $field): string => "LOWER(COALESCE({$field}, '')) LIKE ?",
                $searchableFields
            )) . ')';
            foreach ($searchableFields as $_) {
                $params[] = $like;
                $types .= 's';
            }
        } else {
            $sql .= ' AND 1 = 0';
        }
    }
    $sql .= ' ORDER BY ' . $orderSql;

    $statement = $conn->prepare($sql);
    if (!$statement) {
        error_log('[Account review] Pending car-license rows could not be prepared: ' . $conn->error);
        return $rows;
    }
    if ($types !== '') {
        $bound = array_values($params);
        if (!hq_bind($statement, $types, $bound)) {
            error_log('[Account review] Pending car-license row filters could not be bound: ' . $statement->error);
            $statement->close();
            return $rows;
        }
    }
    if (!$statement->execute()) {
        error_log('[Account review] Pending car-license rows could not be loaded: ' . $statement->error);
        $statement->close();
        return $rows;
    }
    $result = $statement->get_result();
    if (!$result) {
        error_log('[Account review] Pending car-license rows returned no result set: ' . $statement->error);
    } else {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $statement->close();
    return $rows;
}

/**
 * All-time car-license review outcomes used by the admin request counters.
 *
 * @return array{pending:int,approved:int,rejected:int}
 */
function car_license_review_status_summary(): array
{
    global $conn;
    $summary = ['pending' => 0, 'approved' => 0, 'rejected' => 0];

    if (!hq_db_available()) {
        return $summary;
    }

    if (!user_account_column_exists('car_license', 'car_license_id')
        || !user_account_column_exists('car_license', 'status')) {
        return $summary;
    }

    $countQuery = $conn->query(
        "SELECT
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) = 'pending' THEN 1 ELSE 0 END), 0) AS pending,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) IN ('approved', 'active') THEN 1 ELSE 0 END), 0) AS approved,
            COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) IN ('rejected', 'flagged') THEN 1 ELSE 0 END), 0) AS rejected
         FROM car_license"
    );
    if (!$countQuery) {
        error_log('[Account review] Car-license status summary failed: ' . $conn->error);
        return $summary;
    }

    $counts = $countQuery->fetch_assoc() ?: [];
    foreach (['pending', 'approved', 'rejected'] as $status) {
        $summary[$status] = (int) ($counts[$status] ?? 0);
    }
    return $summary;
}

function pending_car_license_count(): int
{
    return car_license_review_status_summary()['pending'];
}

function duplicate_license_record_count(string $licenseType, int $recordId): int
{
    global $conn;
    if ($recordId <= 0 || !in_array($licenseType, ['driver', 'car'], true)) {
        return 0;
    }

    $sql = $licenseType === 'driver'
           ? "SELECT COUNT(*) FROM user_license current_license
           INNER JOIN user_license duplicate
               ON ((TRIM(COALESCE(current_license.license_number, '')) <> ''
                   AND UPPER(REPLACE(TRIM(duplicate.license_number), ' ', '')) = UPPER(REPLACE(TRIM(current_license.license_number), ' ', '')))
                OR (TRIM(COALESCE(current_license.nrc_number, '')) <> ''
                   AND UPPER(REPLACE(TRIM(duplicate.nrc_number), ' ', '')) = UPPER(REPLACE(TRIM(current_license.nrc_number), ' ', ''))))
              AND duplicate.license_id <> current_license.license_id
             INNER JOIN users duplicate_user ON duplicate_user.license_id = duplicate.license_id
             WHERE current_license.license_id = ?
               AND LOWER(TRIM(COALESCE(duplicate_user.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'deleted')"
        : "SELECT COUNT(*) FROM car_license current_license
           INNER JOIN car_license duplicate
             ON UPPER(REPLACE(REPLACE(REPLACE(TRIM(duplicate.car_plate_number), '/', ''), ' ', ''), '-', '')) =
                UPPER(REPLACE(REPLACE(REPLACE(TRIM(current_license.car_plate_number), '/', ''), ' ', ''), '-', ''))
            AND duplicate.car_license_id <> current_license.car_license_id
                     WHERE current_license.car_license_id = ?
                         AND EXISTS (SELECT 1 FROM user_car duplicate_link
                                                 INNER JOIN users duplicate_user ON duplicate_user.user_id = duplicate_link.user_id
                                                 WHERE duplicate_link.car_id = duplicate.car_license_id
                                                     AND LOWER(TRIM(COALESCE(duplicate_user.status, ''))) NOT IN ('banned', 'disabled', 'suspended', 'removed', 'deleted'))";
    $statement = $conn->prepare($sql);
    if (!$statement) {
        return -1;
    }
    $statement->bind_param('i', $recordId);
    if (!$statement->execute()) {
        $statement->close();
        return -1;
    }
    $row = $statement->get_result()?->fetch_row();
    $statement->close();
    return is_array($row) ? (int) $row[0] : -1;
}

function pending_account_count(): int
{
    global $conn;

    if (!hq_db_available() || !user_account_schema_ready() || !user_account_column_exists('user_license', 'status')) {
        return 0;
    }

    $query = $conn->query(
        "SELECT COUNT(*) AS total
         FROM users u
         INNER JOIN user_license l ON l.license_id = u.license_id
                 WHERE LOWER(TRIM(l.status)) = 'pending'"
    );

    return $query ? (int) ($query->fetch_assoc()['total'] ?? 0) : 0;
}

/**
 * Shared live totals for the items that need review in View Requests.
 *
 * System requests, pending user and vehicle-license reviews, and pending
 * payment transfers all contribute to Pending. Completed vehicle-license and
 * payment reviews contribute to the all-time Approved and Rejected totals.
 * Approved today remains available separately in request_live_status_summary().
 *
 * @return array{pending:int,approved:int,approved_today:int,declined_flagged:int,error:?string,pending_accounts:int,pending_car_licenses:int,pending_payment_verifications:int,pending_payment_rows:int}
 */
function request_review_queue_summary(string $reviewerRole = 'admin'): array
{
    global $pdo;

    $summary = function_exists('request_live_status_summary')
        ? request_live_status_summary($reviewerRole)
        : ['pending' => 0, 'approved' => 0, 'approved_today' => 0, 'declined_flagged' => 0, 'error' => null];

    $summary['pending_accounts'] = pending_account_count();
    $carLicenseSummary = car_license_review_status_summary();
    $summary['pending_car_licenses'] = $carLicenseSummary['pending'];
    $summary['approved'] += $carLicenseSummary['approved'];
    $summary['declined_flagged'] += $carLicenseSummary['rejected'];
    $summary['pending_payment_verifications'] = 0;
    $summary['pending_payment_rows'] = 0;

    if (function_exists('request_payment_verification_rows')) {
        $paymentRows = request_payment_verification_rows(
            $reviewerRole,
            '',
            'pending',
            strtolower(trim($reviewerRole)) !== 'moderator'
        );
        $uniquePendingPaymentRows = [];
        foreach ($paymentRows as $paymentRow) {
            $paymentId = (int) ($paymentRow['payment_transfer_id'] ?? 0);
            if ($paymentId > 0) {
                $uniquePendingPaymentRows[$paymentId] = $paymentRow;
            }
        }
        $summary['pending_payment_rows'] = count($uniquePendingPaymentRows);
        $summary['pending_payment_verifications'] = count($uniquePendingPaymentRows);

        if (isset($pdo) && $pdo instanceof PDO && function_exists('request_payment_types_for_reviewer')
            && payment_transfer_schema_ready($pdo)) {
            $paymentTypes = request_payment_types_for_reviewer(
                $reviewerRole,
                strtolower(trim($reviewerRole)) !== 'moderator'
            );
            $typeMarks = implode(',', array_fill(0, count($paymentTypes), '?'));
            try {
                $paymentCountQuery = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM payment_transfers
                     WHERE payment_type IN ({$typeMarks})
                       AND LOWER(TRIM(status)) IN ('pending_payment', 'payer_reported_paid')"
                );
                $paymentCountQuery->execute($paymentTypes);
                $databasePaymentCount = (int) $paymentCountQuery->fetchColumn();
                $summary['pending_payment_rows'] = $databasePaymentCount;
                $summary['pending_payment_verifications'] = $databasePaymentCount;
                if ($databasePaymentCount !== count($uniquePendingPaymentRows)) {
                    error_log(sprintf(
                        '[Request registry] Pending payment row mismatch: database=%d rows=%d',
                        $databasePaymentCount,
                        count($uniquePendingPaymentRows)
                    ));
                    $summary['error'] ??= 'Some pending payment reviews could not be loaded.';
                }
            } catch (Throwable $e) {
                error_log('[Request registry] Pending payment count query failed: ' . $e->getMessage());
                $summary['error'] ??= 'Pending payment totals could not be loaded.';
            }
        }
    }

    $summary['pending'] += $summary['pending_accounts']
        + $summary['pending_car_licenses']
        + $summary['pending_payment_verifications'];

    return $summary;
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
