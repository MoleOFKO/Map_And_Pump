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

function customer_account_rows(string $search = '', string $status = '', string $expiryFilter = ''): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available() || !hq_registry_schema_ready()) {
        return $rows;
    }

    $customerRoleId = customer_role_id();
    $where = [
        "u.role_id = {$customerRoleId}",
        "LOWER(TRIM(base_role.role_name)) = 'customer'",
        "(u.business_role_id IS NULL OR u.business_role_id = {$customerRoleId})",
        "(u.current_role_id IS NULL OR u.current_role_id = {$customerRoleId})",
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
        'user_license' => ['status', 'face_image'],
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
        'users' => ['user_id', 'user_name', 'email', 'headquarter_id', 'role_id', 'license_id', 'status', 'created_at', 'updated_at'],
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

function pending_account_rows(string $search = ''): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available() || !user_account_schema_ready()) {
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

    $publicIdSelect = user_account_column_exists('users', 'user_public_id')
        ? 'u.user_public_id'
        : 'NULL AS user_public_id';
    $licenseStatusSelect = user_account_column_exists('user_license', 'status')
        ? 'l.status AS license_status'
        : 'NULL AS license_status';
    $faceImageSelect = user_account_column_exists('user_license', 'face_image')
        ? 'l.face_image'
        : 'NULL AS face_image';

    $sql = <<<SQL
SELECT u.user_id, {$publicIdSelect}, u.user_name, u.email, u.status, u.created_at, u.updated_at,
       u.license_id,
       u.headquarter_id, u.role_id AS role_id, r.role_name,
       h.company_name AS headquarter_name, h.location AS headquarter_location,
       l.license_number, l.license_name, l.phone_number, l.nrc_number,
       l.date_of_birth, l.blood_type, l.license_last_valid_date,
             (SELECT COUNT(*) FROM user_license duplicate
                WHERE UPPER(REPLACE(TRIM(duplicate.license_number), ' ', '')) = UPPER(REPLACE(TRIM(l.license_number), ' ', ''))
                    AND duplicate.license_id <> l.license_id) AS license_conflict_count,
       {$licenseStatusSelect}, l.license_image, {$faceImageSelect}
FROM users u
LEFT JOIN roles r ON r.role_id = u.role_id
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

function pending_car_license_rows(string $search = ''): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available()
        || !user_account_column_exists('car_license', 'car_license_id')
        || !user_account_column_exists('user_car', 'car_id')
        || !user_account_column_exists('user_car', 'user_id')) {
        return $rows;
    }

    $sql = "SELECT c.car_license_id, c.car_plate_number, c.car_owner_name,
                   c.vehicle_name, c.vehicle_type, c.chassis_number, c.location,
                   c.status, c.created_at, c.car_license_front_image, c.car_license_back_image,
                   owner.user_id, owner.user_name, owner.email,
                   (SELECT COUNT(*) FROM car_license duplicate
                    WHERE UPPER(REPLACE(REPLACE(REPLACE(TRIM(duplicate.car_plate_number), '/', ''), ' ', ''), '-', '')) =
                          UPPER(REPLACE(REPLACE(REPLACE(TRIM(c.car_plate_number), '/', ''), ' ', ''), '-', ''))
                      AND duplicate.car_license_id <> c.car_license_id) AS conflict_count
            FROM car_license c
            LEFT JOIN (
                SELECT uc.car_id, MIN(u.user_id) AS user_id
                FROM user_car uc INNER JOIN users u ON u.user_id = uc.user_id
                GROUP BY uc.car_id
            ) owner_link ON owner_link.car_id = c.car_license_id
            LEFT JOIN users owner ON owner.user_id = owner_link.user_id
            WHERE LOWER(TRIM(c.status)) IN ('pending', 'pending_verification')";
    $params = [];
    $types = '';
    if ($search !== '') {
        $sql .= " AND (LOWER(c.car_plate_number) LIKE ? OR LOWER(COALESCE(c.car_owner_name, '')) LIKE ?
                       OR LOWER(COALESCE(c.vehicle_name, '')) LIKE ? OR LOWER(COALESCE(owner.user_name, '')) LIKE ?
                       OR LOWER(COALESCE(owner.email, '')) LIKE ?)";
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like, $like, $like);
        $types = 'sssss';
    }
    $sql .= ' ORDER BY c.created_at DESC, c.car_license_id DESC';

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

function pending_car_license_count(): int
{
    global $conn;

    if (!hq_db_available()) {
        return 0;
    }

    $schemaQuery = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'car_license'
           AND COLUMN_NAME IN ('car_license_id', 'status')"
    );
    if (!$schemaQuery) {
        error_log('[Account review] Car-license schema check failed: ' . $conn->error);
        return 0;
    }
    if ((int) ($schemaQuery->fetch_assoc()['total'] ?? 0) !== 2) {
        return 0;
    }

    $countQuery = $conn->query(
        "SELECT COUNT(*) AS total
         FROM car_license
         WHERE LOWER(TRIM(status)) IN ('pending', 'pending_verification')"
    );
    if (!$countQuery) {
        error_log('[Account review] Pending car-license count failed: ' . $conn->error);
        return 0;
    }

    return (int) ($countQuery->fetch_assoc()['total'] ?? 0);
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
             ON UPPER(REPLACE(TRIM(duplicate.license_number), ' ', '')) = UPPER(REPLACE(TRIM(current_license.license_number), ' ', ''))
            AND duplicate.license_id <> current_license.license_id
           WHERE current_license.license_id = ?"
        : "SELECT COUNT(*) FROM car_license current_license
           INNER JOIN car_license duplicate
             ON UPPER(REPLACE(REPLACE(REPLACE(TRIM(duplicate.car_plate_number), '/', ''), ' ', ''), '-', '')) =
                UPPER(REPLACE(REPLACE(REPLACE(TRIM(current_license.car_plate_number), '/', ''), ' ', ''), '-', ''))
            AND duplicate.car_license_id <> current_license.car_license_id
           WHERE current_license.car_license_id = ?";
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

    if (!hq_db_available() || !user_account_schema_ready()) {
        return 0;
    }

    $query = $conn->query(
        "SELECT COUNT(*) AS total
         FROM users u
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
