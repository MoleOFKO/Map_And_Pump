<?php
/**
 * Shared customer-user registry helpers for admin and moderator pages.
 */
require_once __DIR__ . '/hq_account_common.php';

function customer_account_rows(string $search = '', string $status = ''): array
{
    global $conn;
    $rows = [];

    if (!hq_db_available() || !hq_registry_schema_ready()) {
        return $rows;
    }

    $where = [
        "LOWER(TRIM(r.role_name)) NOT IN ('admin', 'moderator', 'hq', 'headquarter', 'headquarters')",
        hq_manageable_status_sql('u.status'),
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
        if (strtolower($status) === 'approved') {
            $where[] = "LOWER(u.status) IN ('approved', 'active')";
        } else {
            $where[] = 'LOWER(u.status) = ?';
            $params[] = strtolower($status);
            $types .= 's';
        }
    }

    $sql = <<<'SQL'
SELECT u.user_id, u.user_name, u.email, u.status, u.created_at, u.updated_at,
       u.headquarter_id, COALESCE(u.current_role_id, u.business_role_id, u.role_id) AS role_id, r.role_name,
       h.company_name AS headquarter_name, h.location AS headquarter_location
FROM users u
INNER JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
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
SELECT u.user_id, u.user_name, u.email, u.status, u.created_at, u.updated_at,
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

    // Only attempt DDL when the table is missing.
    $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS user_account_actions (
    action_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    action_type VARCHAR(20) NOT NULL,
    reason TEXT NOT NULL,
    notification_status VARCHAR(30) NOT NULL DEFAULT 'not_configured',
    notification_note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (action_id),
    KEY idx_user_account_actions_user (user_id),
    KEY idx_user_account_actions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL;

    $ok = (bool) @$conn->query($sql);
    if (!$ok) {
        error_log('[user_account_actions] create failed: ' . $conn->error);
        // Re-check in case another process created it.
        try {
            $exists = $conn->query(
                "SELECT COUNT(*) AS c
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'user_account_actions'"
            );
            $ok = $exists && (int) ($exists->fetch_assoc()['c'] ?? 0) > 0;
        } catch (Throwable $e) {
            $ok = false;
        }
    }

    return $ready = $ok;
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
