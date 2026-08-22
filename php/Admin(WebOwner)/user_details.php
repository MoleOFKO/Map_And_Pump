<?php
require_once __DIR__ . '/user_account_common.php';

header('Content-Type: application/json; charset=utf-8');

$userId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid user id is required.']);
    exit;
}

if (!hq_db_available() || !hq_registry_schema_ready()) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => hq_db_error_message()]);
    exit;
}

global $conn;
$statement = $conn->prepare(
    'SELECT u.user_id, u.user_name, u.email, u.status, u.created_at, u.updated_at, u.last_login_at, u.headquarter_id, u.role_id, r.role_name, h.company_name AS headquarter_name, h.location AS headquarter_location
     FROM users u
     LEFT JOIN roles r ON r.role_id = u.role_id
     LEFT JOIN headquarter h ON h.headquarter_id = u.headquarter_id
     WHERE u.user_id = ? LIMIT 1'
);

if (!$statement) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => hq_db_error_message()]);
    exit;
}

$statement->bind_param('i', $userId);
$statement->execute();
$user = $statement->get_result()?->fetch_assoc();
$statement->close();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

$actions = user_action_rows((int) $userId);

echo json_encode([
    'success' => true,
    'user' => [
        'user_id' => (int) $user['user_id'],
        'user_name' => $user['user_name'],
        'email' => $user['email'],
        'status' => $user['status'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
        'last_login_at' => $user['last_login_at'],
        'headquarter_id' => isset($user['headquarter_id']) ? (int) $user['headquarter_id'] : null,
        'headquarter_name' => $user['headquarter_name'],
        'headquarter_location' => $user['headquarter_location'],
        'role_id' => isset($user['role_id']) ? (int) $user['role_id'] : null,
        'role_name' => $user['role_name'],
    ],
    'actions' => $actions,
]);
