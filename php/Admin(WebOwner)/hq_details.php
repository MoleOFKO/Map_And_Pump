<?php
require_once __DIR__ . '/admin_common.php';

admin_require_staff();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$headquarterId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$headquarterId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid HQ id is required.']);
    exit;
}
if (!hq_db_available() || !hq_registry_schema_ready()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => hq_db_error_message()]);
    exit;
}

global $conn;
$hqRoleId = hq_hq_role_id();
if ($hqRoleId <= 0) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'The HQ role is unavailable.']);
    exit;
}

try {
    /* TiDB does not allow a subquery inside a JOIN condition. */
    $hqQuery = $conn->prepare(
        'SELECT h.headquarter_id, h.company_name, h.location, h.contact_number,
                h.headquarter_email, h.company_logo, h.business_license,
                h.created_at, h.expire_at,
                owner.user_name AS owner_name, owner.email AS owner_email
         FROM headquarter h
         LEFT JOIN (
             SELECT u.headquarter_id, MIN(u.user_id) AS owner_user_id
             FROM users u
             WHERE u.role_id = ? OR u.business_role_id = ?
             GROUP BY u.headquarter_id
         ) owner_link ON owner_link.headquarter_id = h.headquarter_id
         LEFT JOIN users owner ON owner.user_id = owner_link.owner_user_id
         WHERE h.headquarter_id = ?
         LIMIT 1'
    );
    if (!$hqQuery) {
        throw new RuntimeException('The HQ details query could not be prepared.');
    }
    $hqQuery->bind_param('iii', $hqRoleId, $hqRoleId, $headquarterId);
    $hqQuery->execute();
    $hq = $hqQuery->get_result()?->fetch_assoc();
    $hqQuery->close();

    if (!$hq) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'HQ account not found.']);
        exit;
    }

    $actions = [];
    if (hq_action_table_ready()) {
        $actionQuery = $conn->prepare(
            'SELECT action_type, reason, notification_status, notification_note, created_at
             FROM hq_account_actions
             WHERE headquarter_id = ?
             ORDER BY created_at DESC, action_id DESC'
        );
        if ($actionQuery) {
            $actionQuery->bind_param('i', $headquarterId);
            $actionQuery->execute();
            $result = $actionQuery->get_result();
            while ($result && ($action = $result->fetch_assoc())) {
                $actions[] = $action;
            }
            $actionQuery->close();
        }
    }

    // Only users belonging to the selected headquarter_id are returned.
    $accounts = [];
    $accountQuery = $conn->prepare(
        'SELECT u.user_id, u.user_name AS username, u.email, u.status, u.created_at,
                u.role_id, u.business_role_id,
                COALESCE(business_role.role_name, base_role.role_name) AS role_name
         FROM users u
         LEFT JOIN roles base_role ON base_role.role_id = u.role_id
         LEFT JOIN roles business_role ON business_role.role_id = u.business_role_id
         WHERE u.headquarter_id = ?
         ORDER BY u.created_at DESC, u.user_id DESC
         LIMIT 100'
    );
    if (!$accountQuery) {
        throw new RuntimeException('The related user accounts query could not be prepared.');
    }
    $accountQuery->bind_param('i', $headquarterId);
    $accountQuery->execute();
    $result = $accountQuery->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $accounts[] = [
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'email' => (string) $row['email'],
            'status' => (string) $row['status'],
            'role_id' => $row['role_id'] !== null ? (int) $row['role_id'] : null,
            'business_role_id' => $row['business_role_id'] !== null ? (int) $row['business_role_id'] : null,
            'role_name' => (string) ($row['role_name'] ?? ''),
            'created_at' => $row['created_at'],
        ];
    }
    $accountQuery->close();

    // Prefer explicit station-to-HQ mappings, then fall back to an HQ manager.
    $stations = [];
    $stationQuery = $conn->prepare(
        'SELECT s.branch_id AS station_id, s.branch_name AS station_name,
            CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
                 THEN CONCAT(s.latitude, \', \', s.longitude) ELSE \'\' END AS location,
            s.address AS station_address, s.status, s.created_at,
                manager.user_name AS manager_name
         FROM branches s
         INNER JOIN station_business_scope scope ON scope.station_id = s.branch_id
         LEFT JOIN users manager ON manager.user_id = s.manager_user_id
         WHERE scope.headquarter_id = ?
        ORDER BY s.branch_name ASC, s.branch_id ASC
         LIMIT 100'
    );
    if (!$stationQuery) {
        throw new RuntimeException('The related stations query could not be prepared.');
    }
    $stationQuery->bind_param('i', $headquarterId);
    $stationQuery->execute();
    $result = $stationQuery->get_result();
    while ($result && ($row = $result->fetch_assoc())) {
        $stations[] = [
            'station_id' => (int) $row['station_id'],
            'station_name' => (string) $row['station_name'],
            'location' => (string) (($row['station_address'] ?? '') !== '' ? $row['station_address'] : ($row['location'] ?? '')),
            'status' => (string) $row['status'],
            'manager_name' => (string) ($row['manager_name'] ?? ''),
            'created_at' => $row['created_at'],
        ];
    }
    $stationQuery->close();

    if (!$stations) {
        $fallbackQuery = $conn->prepare(
                'SELECT DISTINCT s.branch_id AS station_id, s.branch_name AS station_name,
                    CASE WHEN s.latitude IS NOT NULL AND s.longitude IS NOT NULL
                     THEN CONCAT(s.latitude, \', \', s.longitude) ELSE \'\' END AS location,
                    s.address AS station_address, s.status, s.created_at,
                    manager.user_name AS manager_name
                 FROM branches s
                 INNER JOIN users manager ON manager.user_id = s.manager_user_id
             WHERE manager.headquarter_id = ?
            ORDER BY s.branch_name ASC, s.branch_id ASC
             LIMIT 100'
        );
        if (!$fallbackQuery) {
            throw new RuntimeException('The fallback station query could not be prepared.');
        }
        $fallbackQuery->bind_param('i', $headquarterId);
        $fallbackQuery->execute();
        $result = $fallbackQuery->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $stations[] = [
                'station_id' => (int) $row['station_id'],
                'station_name' => (string) $row['station_name'],
                'location' => (string) (($row['station_address'] ?? '') !== '' ? $row['station_address'] : ($row['location'] ?? '')),
                'status' => (string) $row['status'],
                'manager_name' => (string) ($row['manager_name'] ?? ''),
                'created_at' => $row['created_at'],
            ];
        }
        $fallbackQuery->close();
    }

    echo json_encode([
        'success' => true,
        'hq' => [
            'headquarter_id' => (int) $hq['headquarter_id'],
            'company_name' => $hq['company_name'],
            'location' => $hq['location'],
            'contact_number' => $hq['contact_number'],
            'headquarter_email' => $hq['headquarter_email'],
            'company_logo' => $hq['company_logo'],
            'business_license' => $hq['business_license'],
            'created_at' => $hq['created_at'],
            'expire_at' => $hq['expire_at'],
            'owner_name' => $hq['owner_name'],
            'owner_email' => $hq['owner_email'],
        ],
        'accounts' => $accounts,
        'stations' => $stations,
        'actions' => $actions,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('[HQ details] ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The selected HQ details could not be loaded.']);
}
