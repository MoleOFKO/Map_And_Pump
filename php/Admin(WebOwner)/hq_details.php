<?php
require_once __DIR__ . '/hq_account_common.php';

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
$hqQuery = $conn->prepare(
    'SELECT h.headquarter_id, h.company_name, h.location, h.contact_number,
            h.headquarter_email, h.company_logo, h.business_license,
            h.created_at, h.expire_at,
            owner.user_name AS owner_name, owner.email AS owner_email
     FROM headquarter h
     LEFT JOIN users owner ON owner.user_id = (
         SELECT MIN(u2.user_id)
         FROM users u2
         WHERE u2.headquarter_id = h.headquarter_id
           AND (u2.role_id = 3 OR u2.business_role_id = 3)
     )
     WHERE h.headquarter_id = ?
     LIMIT 1'
);

if (!$hqQuery) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => hq_db_error_message()]);
    exit;
}

$hqQuery->bind_param('i', $headquarterId);
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

// Linked user accounts for this HQ (any role under headquarter_id).
$accounts = [];
$accountQuery = $conn->prepare(
    "SELECT u.user_id, u.user_name AS username, u.email, u.status, u.created_at,
            COALESCE(br.role_name, r.role_name) AS role_name
     FROM users u
     LEFT JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN roles br ON br.role_id = u.business_role_id
     WHERE u.headquarter_id = ?
     ORDER BY u.created_at DESC, u.user_id DESC
     LIMIT 100"
);
if ($accountQuery) {
    $accountQuery->bind_param('i', $headquarterId);
    if ($accountQuery->execute()) {
        $result = $accountQuery->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $accounts[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'username' => (string) ($row['username'] ?? ''),
                'email' => (string) ($row['email'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'role_name' => (string) ($row['role_name'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
            ];
        }
    }
    $accountQuery->close();
}

// Stations linked via station_business_scope (preferred) or users under this HQ.
$stations = [];
$stationQuery = $conn->prepare(
    "SELECT s.station_id, s.station_name, s.location, s.station_address, s.status, s.created_at,
            s.business_user_id, manager.user_name AS manager_name
     FROM stations s
     INNER JOIN station_business_scope sbs ON sbs.station_id = s.station_id
     LEFT JOIN users manager ON manager.user_id = s.business_user_id
     WHERE sbs.headquarter_id = ?
     ORDER BY s.station_name ASC, s.station_id ASC
     LIMIT 100"
);
if ($stationQuery) {
    $stationQuery->bind_param('i', $headquarterId);
    if ($stationQuery->execute()) {
        $result = $stationQuery->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
            $stations[] = [
                'station_id' => (int) ($row['station_id'] ?? 0),
                'station_name' => (string) ($row['station_name'] ?? ''),
                'location' => (string) (($row['station_address'] ?? '') !== '' ? $row['station_address'] : ($row['location'] ?? '')),
                'status' => (string) ($row['status'] ?? ''),
                'manager_name' => (string) ($row['manager_name'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
            ];
        }
    }
    $stationQuery->close();
}

// Fallback: stations owned by managers linked to this HQ.
if (!$stations) {
    $fallback = $conn->prepare(
        "SELECT DISTINCT s.station_id, s.station_name, s.location, s.station_address, s.status, s.created_at,
                s.business_user_id, manager.user_name AS manager_name
         FROM stations s
         INNER JOIN users manager ON manager.user_id = s.business_user_id
         WHERE manager.headquarter_id = ?
         ORDER BY s.station_name ASC, s.station_id ASC
         LIMIT 100"
    );
    if ($fallback) {
        $fallback->bind_param('i', $headquarterId);
        if ($fallback->execute()) {
            $result = $fallback->get_result();
            while ($result && ($row = $result->fetch_assoc())) {
                $stations[] = [
                    'station_id' => (int) ($row['station_id'] ?? 0),
                    'station_name' => (string) ($row['station_name'] ?? ''),
                    'location' => (string) (($row['station_address'] ?? '') !== '' ? $row['station_address'] : ($row['location'] ?? '')),
                    'status' => (string) ($row['status'] ?? ''),
                    'manager_name' => (string) ($row['manager_name'] ?? ''),
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }
        $fallback->close();
    }
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
