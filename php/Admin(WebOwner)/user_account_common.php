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
        $where[] = 'LOWER(u.status) = ?';
        $params[] = strtolower($status);
        $types .= 's';
    }

    $sql = <<<'SQL'
SELECT u.user_id, u.user_name, u.email, u.status, u.created_at, u.updated_at,
       u.headquarter_id, u.role_id, r.role_name,
       h.company_name AS headquarter_name, h.location AS headquarter_location
FROM users u
INNER JOIN roles r ON r.role_id = u.role_id
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

    $where = [hq_pending_status_sql('u.status')];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(LOWER(u.user_name) LIKE ? OR LOWER(u.email) LIKE ? OR LOWER(COALESCE(h.company_name, \'\')) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }

    $sql = <<<'SQL'
SELECT u.user_id, u.user_name, u.email, u.status, u.created_at, u.updated_at,
       u.headquarter_id, u.role_id, r.role_name,
       h.company_name AS headquarter_name, h.location AS headquarter_location
FROM users u
INNER JOIN roles r ON r.role_id = u.role_id
LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
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

    if (!hq_db_available()) {
        return false;
    }

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

    return (bool) $conn->query($sql);
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
