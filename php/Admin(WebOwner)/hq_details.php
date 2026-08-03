<?php
require_once __DIR__ . '/hq_account_common.php';

header('Content-Type: application/json; charset=utf-8');

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
            h.business_license, h.station_id, h.created_at, h.expire_at,
            s.station_name, s.location AS station_location, s.created_at AS station_created_at
     FROM headquarter h
     LEFT JOIN stations s ON s.station_id = h.station_id
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

$accountQuery = $conn->prepare(
    'SELECT user_id, username, email, status, created_at, updated_at
     FROM users
     WHERE headquarter_id = ?
     ORDER BY user_id ASC'
);
$accounts = [];

if ($accountQuery) {
    $accountQuery->bind_param('i', $headquarterId);
    $accountQuery->execute();
    $result = $accountQuery->get_result();
    while ($result && ($account = $result->fetch_assoc())) {
        $accounts[] = $account;
    }
    $accountQuery->close();
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

$stations = [];
if (!empty($hq['station_id'])) {
    $stations[] = [
        'station_id' => (int) $hq['station_id'],
        'station_name' => $hq['station_name'] ?: 'Linked station',
        'location' => $hq['station_location'] ?: 'Location not available',
        'created_at' => $hq['station_created_at'],
    ];
}

echo json_encode([
    'success' => true,
    'hq' => [
        'headquarter_id' => (int) $hq['headquarter_id'],
        'company_name' => $hq['company_name'],
        'location' => $hq['location'],
        'contact_number' => $hq['contact_number'],
        'business_license' => $hq['business_license'],
        'created_at' => $hq['created_at'],
        'expire_at' => $hq['expire_at'],
    ],
    'accounts' => $accounts,
    'stations' => $stations,
    'actions' => $actions,
]);
