<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Clear/export are admin-only. View remains available to moderators via their pages.
$user = admin_current_user(false);
$role = strtolower(trim((string) ($user['role_name'] ?? '')));
$isStaff = $user && in_array($role, ['admin', 'moderator'], true);
$isAdmin = admin_is_admin($user);

global $conn;
$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));
$returnRaw = basename(trim((string) ($_POST['return_to'] ?? $_GET['return_to'] ?? 'view_logs.php')));
$returnTo = $returnRaw === 'view_logs.php' ? 'view_logs.php' : 'view_logs.php';

/**
 * Build actor SQL fragment for log queries.
 */
function admin_log_actor_sql(string $actor): string
{
    return match ($actor) {
        'admin' => '(l.role_id = 1 OR (l.role_id IS NULL AND (u.role_id = 1 OR u.business_role_id = 1 OR LOWER(TRIM(COALESCE(r.role_name, \'\'))) = \'admin\')))',
        'moderator' => '(l.role_id = 2 OR (l.role_id IS NULL AND (u.role_id = 2 OR u.business_role_id = 2 OR u.current_role_id = 2 OR LOWER(TRIM(COALESCE(r.role_name, \'\'))) = \'moderator\')))',
        default => '1=1',
    };
}

/**
 * Load filtered audit rows for export/clear preview.
 *
 * @return list<array<string, mixed>>
 */
function admin_load_logs(
    string $search = '',
    string $level = 'all',
    string $actor = 'all',
    string $dateFrom = '',
    string $dateTo = ''
): array {
    global $conn;
    $logs = [];

    $where = [admin_log_actor_sql($actor)];
    $params = [];
    $types = '';

    if ($search !== '') {
        $where[] = '(LOWER(l.action) LIKE ? OR LOWER(COALESCE(u.user_name, \'\')) LIKE ? OR LOWER(COALESCE(u.email, \'\')) LIKE ?)';
        $like = '%' . strtolower($search) . '%';
        array_push($params, $like, $like, $like);
        $types .= 'sss';
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(l.created_at) >= ?';
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(l.created_at) <= ?';
        $params[] = $dateTo;
        $types .= 's';
    }

    $sql = 'SELECT l.log_id, l.user_id, l.action, l.created_at, u.user_name, u.email
            FROM log l
            LEFT JOIN users u ON u.user_id = l.user_id
            LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY l.created_at DESC, l.log_id DESC
            LIMIT 5000';

    $statement = $conn->prepare($sql);
    if (!$statement) {
        return [];
    }
    if ($types !== '') {
        $bound = array_values($params);
        hq_bind($statement, $types, $bound);
    }
    $statement->execute();
    $result = $statement->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $row['level'] = admin_log_level((string) $row['action']);
        if ($level === 'all' || $row['level'] === $level) {
            $logs[] = $row;
        }
    }
    $statement->close();
    return $logs;
}

if (!$isStaff) {
    header('Location: ../Login/login.php');
    exit;
}

if ($action === 'export') {
    if (!$isAdmin) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('Only administrators can export audit logs.');
    }
    if (!hq_db_available()) {
        http_response_code(503);
        exit('The log database is unavailable.');
    }
    $search = trim((string) ($_GET['search'] ?? $_POST['search'] ?? ''));
    $level = strtolower(trim((string) ($_GET['log_level'] ?? $_POST['log_level'] ?? 'all')));
    $actor = strtolower(trim((string) ($_GET['actor'] ?? $_POST['actor'] ?? 'all')));
    $dateFrom = trim((string) ($_GET['date_from'] ?? $_POST['date_from'] ?? ''));
    $dateTo = trim((string) ($_GET['date_to'] ?? $_POST['date_to'] ?? ''));
    if (!in_array($level, ['all', 'info', 'warn', 'error', 'security'], true)) {
        $level = 'all';
    }
    if (!in_array($actor, ['all', 'admin', 'moderator'], true)) {
        $actor = 'all';
    }
    $logs = admin_load_logs($search, $level, $actor, $dateFrom, $dateTo);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="map-and-pump-audit-' . date('Ymd-His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Log ID', 'Level', 'User ID', 'User', 'Email', 'Action', 'Created At'], ',', '"', '\\');
    foreach ($logs as $log) {
        fputcsv(
            $output,
            [
                $log['log_id'],
                strtoupper((string) $log['level']),
                $log['user_id'],
                $log['user_name'] ?: 'Unknown',
                $log['email'] ?: '',
                $log['action'],
                $log['created_at'],
            ],
            ',',
            '"',
            '\\'
        );
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    admin_redirect($returnTo);
}

if ($action === 'clear') {
    if (!$isAdmin) {
        admin_flash('danger', 'Only administrators can clear audit logs.');
        admin_redirect($returnTo);
    }
    if (!hq_db_available()) {
        admin_flash('danger', 'The log database is unavailable.');
        admin_redirect($returnTo);
    }

    $actor = strtolower(trim((string) ($_POST['actor'] ?? 'moderator')));
    if (!in_array($actor, ['all', 'admin', 'moderator'], true)) {
        $actor = 'moderator';
    }
    // Default clear remains safer moderator-only unless explicitly "all".
    $actorSql = admin_log_actor_sql($actor);

    $statement = $conn->prepare(
        "DELETE l FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
         WHERE {$actorSql}"
    );
    if (!$statement || !$statement->execute()) {
        $statement?->close();
        admin_flash('danger', 'The audit log could not be cleared.');
        admin_redirect($returnTo);
    }
    $deleted = $statement->affected_rows;
    $statement->close();
    admin_record_log((int) ($user['user_id'] ?? 0), 'Cleared ' . $deleted . ' audit log entries (actor=' . $actor . ').');
    admin_flash('success', $deleted . ' audit log entries were cleared (' . $actor . ' scope).');
    admin_redirect($returnTo);
}

admin_flash('danger', 'The requested log action is not supported.');
admin_redirect($returnTo);
