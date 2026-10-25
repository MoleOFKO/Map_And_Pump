<?php
require_once __DIR__ . '/admin_common.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Log maintenance (clear / export) is Admin-only.
// Moderators may only view logs via Moderators(UnderAdmin)/view_logs(M).php
// and must never reach this endpoint.
$user = admin_require_admin();
$isAdmin = true;

global $conn;
$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));
$returnRaw = basename(trim((string) ($_POST['return_to'] ?? $_GET['return_to'] ?? 'view_logs.php')));
$returnTo = $returnRaw === 'view_logs.php' ? 'view_logs.php' : 'view_logs.php';

/**
 * Build actor SQL fragment for log queries.
 * Default ("all") is Admin + Moderator only — not Branch Manager / HQ / Customer.
 */
function admin_log_actor_sql(string $actor): string
{
    $adminSql = '(l.role_id = 1 OR (l.role_id IS NULL AND (COALESCE(u.current_role_id, u.business_role_id, u.role_id) = 1 OR LOWER(TRIM(COALESCE(r.role_name, \'\'))) = \'admin\')))';
    $moderatorSql = '(l.role_id = 2 OR (l.role_id IS NULL AND (COALESCE(u.current_role_id, u.business_role_id, u.role_id) = 2 OR LOWER(TRIM(COALESCE(r.role_name, \'\'))) = \'moderator\')))';

    return match ($actor) {
        'admin' => $adminSql,
        'moderator' => $moderatorSql,
        default => '(' . $adminSql . ' OR ' . $moderatorSql . ')',
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
    $search = trim((string) ($_POST['search'] ?? ''));
    $level = strtolower(trim((string) ($_POST['log_level'] ?? 'all')));
    if (!in_array($level, ['all', 'info', 'warn', 'error', 'security'], true)) {
        $level = 'all';
    }
    $dateFrom = trim((string) ($_POST['date_from'] ?? ''));
    $dateTo = trim((string) ($_POST['date_to'] ?? ''));
    $validDate = static function (string $value): bool {
        if ($value === '') {
            return true;
        }
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        $errors = DateTime::getLastErrors();
        return $date !== false
            && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    };
    if (!$validDate($dateFrom)) {
        $dateFrom = '';
    }
    if (!$validDate($dateTo)) {
        $dateTo = '';
    }
    if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

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

    // Keep the clear filter in sync with admin_log_level()'s priority order.
    $securitySql = "(LOWER(l.action) LIKE '%security%' OR LOWER(l.action) LIKE '%auth%' OR LOWER(l.action) LIKE '%login%' OR LOWER(l.action) LIKE '%password%' OR LOWER(l.action) LIKE '%permission%' OR LOWER(l.action) LIKE '%suspend%' OR LOWER(l.action) LIKE '%remove%' OR LOWER(l.action) LIKE '%approve%' OR LOWER(l.action) LIKE '%reject%')";
    $errorSql = "(LOWER(l.action) LIKE '%error%' OR LOWER(l.action) LIKE '%failed%' OR LOWER(l.action) LIKE '%exception%' OR LOWER(l.action) LIKE '%fatal%')";
    $warnSql = "(LOWER(l.action) LIKE '%warn%' OR LOWER(l.action) LIKE '%warning%' OR LOWER(l.action) LIKE '%latency%' OR LOWER(l.action) LIKE '%expired%')";
    if ($level === 'security') {
        $where[] = $securitySql;
    } elseif ($level === 'error') {
        $where[] = "NOT {$securitySql} AND {$errorSql}";
    } elseif ($level === 'warn') {
        $where[] = "NOT {$securitySql} AND NOT {$errorSql} AND {$warnSql}";
    } elseif ($level === 'info') {
        $where[] = "NOT {$securitySql} AND NOT {$errorSql} AND NOT {$warnSql}";
    }
    $whereSql = implode(' AND ', $where);

    $statement = $conn->prepare(
        "DELETE l FROM log l
         LEFT JOIN users u ON u.user_id = l.user_id
         LEFT JOIN roles r ON r.role_id = COALESCE(u.current_role_id, u.business_role_id, u.role_id)
         WHERE {$whereSql}"
    );
    if ($statement && $types !== '') {
        $bound = array_values($params);
        hq_bind($statement, $types, $bound);
    }
    if (!$statement || !$statement->execute()) {
        $statement?->close();
        admin_flash('danger', 'The audit log could not be cleared.');
        admin_redirect($returnTo);
    }
    $deleted = $statement->affected_rows;
    $statement->close();
    admin_record_log((int) ($user['user_id'] ?? 0), 'Cleared ' . $deleted . ' audit log entries (actor=' . $actor . ').');
    admin_flash('success', $deleted . ' audit log entries were cleared (' . $actor . ' scope).');
    $filterQuery = http_build_query([
        'search' => $search,
        'log_level' => $level,
        'actor' => $actor,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ]);
    header('Location: view_logs.php?' . $filterQuery);
    exit;
}

admin_flash('danger', 'The requested log action is not supported.');
admin_redirect($returnTo);
